<?php

declare(strict_types=1);

namespace Blue\Modules\Pdf;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\FileType;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Files\ZipStream;
use Blue\Core\Pdf\PdfError;
use Blue\Core\Pdf\Reader;
use Blue\Core\Pdf\Security;
use Blue\Core\Pdf\Toolkit;
use Blue\Core\Support\Ulid;

/**
 * S01 PDF toolkit jobs. Two steps so the person sees what will happen before anything is produced:
 *   stage() — files are checked (real type, size, pages, encryption, active content) and kept encrypted on disk;
 *   run()   — the operation runs once on the staged files and writes new files (derivatives).
 * Nothing is kept unless the person presses Save (0.39.0, item 8.2): the originals are removed as soon as the result is
 * made, the result stays only pdf.result_minutes for downloading, and only saved results are listed under "my jobs".
 * Passwords are used in memory only — never stored, logged or audited.
 */
final class PdfService
{
    public const IMAGE_TYPES = ['jpeg', 'png', 'webp', 'gif'];

    public function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    public function features(): PdfFeatures
    {
        return new PdfFeatures($this->app);
    }

    /**
     * @param list<UploadedFile> $files
     * @return string job public id
     */
    public function stage(Authorizer $az, string $op, array $files, string $password, ?int $legalEntityId): string
    {
        $this->guard($az);
        $feat = $this->features();
        if (!$feat->usable($op)) {
            throw new BusinessException('pdf.error.feature_off', [], 409);
        }
        [$input, $multi] = PdfFeatures::BUILT_IN[$op];
        $lim = $feat->limits();
        $files = array_values(array_filter($files, static fn (UploadedFile $f): bool => $f->error !== UPLOAD_ERR_NO_FILE));
        if ($files === []) {
            throw new ValidationException(['files' => 'pdf.error.no_input']);
        }
        if (!$multi && count($files) > 1) {
            throw new ValidationException(['files' => 'pdf.error.one_file']);
        }
        if ($op === 'merge' && count($files) < 2) {
            throw new ValidationException(['files' => 'pdf.error.need_two']);
        }
        if (count($files) > $lim['max_files']) {
            throw new ValidationException(['files' => 'pdf.error.too_many_files']);
        }
        $pid = Ulid::generate($this->app->clock);
        $inputs = [];
        $blobs = [];
        $totalPages = 0;
        foreach ($files as $i => $f) {
            $name = mb_substr($f->safeName(), 0, 120);
            if (!$f->ok()) {
                throw new ValidationException(['files' => $f->errorKey()]);
            }
            $bytes = $f->bytes();
            if (strlen($bytes) > $lim['max_mb'] * 1024 * 1024) {
                throw new ValidationException(['files' => 'pdf.error.file_too_big']);
            }
            $type = FileType::sniff($bytes, $name);
            $row = ['name' => $name, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'type' => (string) $type['type'], 'pages' => 1,
                'encrypted' => false, 'warnings' => [], 'version' => null, 'sizes' => []];
            if ($input === 'images') {
                if (!in_array($type['type'], self::IMAGE_TYPES, true)) {
                    throw new ValidationException(['files' => 'pdf.error.not_image']);
                }
            } else {
                if ($type['type'] !== 'pdf') {
                    throw new ValidationException(['files' => 'pdf.error.not_pdf']);
                }
                if ($type['problem'] === 'file.error.pdf_active') {
                    throw new ValidationException(['files' => 'pdf.error.active']);
                }
                try {
                    $info = Toolkit::info($bytes, $password);
                    if ($info['encrypted']) {
                        $row['encrypted'] = true;
                        $row['method'] = $info['method'];
                        $bytes = Toolkit::unlock($bytes, $password)['bytes']; // work on a decrypted copy; the password is not kept
                    }
                } catch (PdfError $e) {
                    throw new ValidationException(['files' => $e->messageKey === 'pdf.error.password' ? ($password === '' ? 'pdf.error.password_needed' : 'pdf.error.password') : $e->messageKey]);
                }
                if ($op === 'unlock' && !$info['encrypted']) {
                    throw new ValidationException(['files' => 'pdf.error.not_encrypted']);
                }
                $row['pages'] = $info['pages'];
                $row['warnings'] = $info['warnings'];
                $row['version'] = $info['version'];
                $row['sizes'] = array_slice($info['sizes'], 0, $lim['max_pages']);
                if ($op === 'metadata') {
                    $row['metadata'] = $info['metadata'];
                    $row['xmp'] = $info['xmp'];
                }
                if ($info['pages'] < 1) {
                    throw new ValidationException(['files' => 'pdf.error.no_pages']);
                }
            }
            $totalPages += $row['pages'];
            if ($totalPages > $lim['max_pages']) {
                throw new ValidationException(['files' => 'pdf.error.too_many_pages']);
            }
            $inputs[] = $row;
            $blobs[$i] = $bytes;
        }
        foreach ($blobs as $i => $b) {
            $this->put($pid, 'in' . $i, $b);
        }
        $now = $this->app->clock->now();
        // a visitor (0.8.1) owns nothing: owner 0 + the random key of their browser session
        $actor = $az->isGuest() ? null : $az->userId;
        $guestKey = $az->isGuest() ? $az->guestKey : null;
        $this->app->db()->run(
            'INSERT INTO pdf_jobs (public_id, created_at, created_by, updated_at, updated_by, owner_user_id, guest_key, legal_entity_id, operation, status, inputs_json, engine, correlation_id, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'staged\', ?, ?, ?, ?)',
            [$pid, $this->ts($now), $actor, $this->ts($now), $actor, $az->userId, $guestKey, $az->isGuest() ? null : $legalEntityId, $op, (string) json_encode($inputs, JSON_UNESCAPED_UNICODE),
                Toolkit::VERSION, $this->app->logger->correlationId(), $this->ts($now->modify('+' . $lim['result_minutes'] . ' minutes'))]
        );
        $this->app->audit()->record('pdf.staged', 'success', 'pdf_job', $pid, $legalEntityId, null, ['operation' => $op, 'files' => count($inputs),
            'sha256' => array_column($inputs, 'sha256')]);
        return $pid;
    }

    /** @param array<string, mixed> $opt options from the form (passwords are used and dropped) */
    public function run(Authorizer $az, string $pid, array $opt): void
    {
        $job = $this->job($az, $pid);
        if ($job['status'] === 'done') {
            return; // a second click or a refresh shows the same result
        }
        if (!in_array($job['status'], ['staged', 'failed'], true) || (string) $job['expires_at'] <= $this->ts($this->app->clock->now())) {
            throw new BusinessException('pdf.error.expired', [], 410);
        }
        if (!$this->features()->usable((string) $job['operation'])) {
            throw new BusinessException('pdf.error.feature_off', [], 409);
        }
        [$params, $call] = $this->prepare((string) $job['operation'], $opt, $job['inputs']);
        $claimed = $this->app->db()->run("UPDATE pdf_jobs SET status = 'running', updated_at = ? WHERE id = ? AND status IN ('staged','failed')", [$this->ts($this->app->clock->now()), $job['id']])->rowCount();
        if ($claimed !== 1) {
            return; // someone (another tab) is already running it
        }
        $inputs = [];
        foreach (array_keys($job['inputs']) as $i) {
            $inputs[$i] = $this->get($pid, 'in' . $i);
        }
        try {
            $res = $call($inputs);
        } catch (PdfError $e) {
            $this->app->db()->run(
                "UPDATE pdf_jobs SET status = 'failed', error_key = ?, params_json = ?, updated_at = ? WHERE id = ?",
                [$e->messageKey, json_encode($params, JSON_UNESCAPED_UNICODE), $this->ts($this->app->clock->now()), $job['id']]
            );
            $this->app->audit()->record('pdf.processed', 'failure', 'pdf_job', $pid, $this->le($job), $e->messageKey, ['operation' => $job['operation']]);
            throw new ValidationException(['run' => $e->messageKey]);
        }
        $base = self::baseName((string) ($job['inputs'][0]['name'] ?? 'document'));
        $parts = $res['parts'] !== [] ? $res['parts'] : [['name' => $this->suffix((string) $job['operation']), 'bytes' => $res['bytes'], 'pages' => $res['pages']]];
        $outputs = [];
        foreach ($parts as $k => $p) {
            $this->put($pid, 'out' . $k, $p['bytes']);
            $outputs[] = ['name' => $base . '-' . $p['name'] . '.pdf', 'size' => strlen($p['bytes']), 'sha256' => hash('sha256', $p['bytes']), 'pages' => $p['pages'], 'document' => null];
        }
        foreach (array_keys($job['inputs']) as $i) {
            @unlink($this->file($pid, 'in' . $i)); // 8.2: the originals are not kept once the result exists
        }
        $clock = $this->app->clock->now();
        $now = $this->ts($clock);
        $this->app->db()->run(
            "UPDATE pdf_jobs SET status = 'done', params_json = ?, outputs_json = ?, warnings_json = ?, notes_json = ?, error_key = NULL, finished_at = ?, expires_at = ?, updated_at = ?,
                updated_by = ?, row_version = row_version + 1 WHERE id = ?",
            [json_encode($params, JSON_UNESCAPED_UNICODE), json_encode($outputs, JSON_UNESCAPED_UNICODE), json_encode($res['warnings']), json_encode($res['notes'], JSON_UNESCAPED_UNICODE),
                $now, $this->ts($clock->modify('+' . $this->features()->limits()['result_minutes'] . ' minutes')), $now, $az->userId, $job['id']]
        );
        $this->app->audit()->record('pdf.processed', 'success', 'pdf_job', $pid, $this->le($job), null, ['operation' => $job['operation'], 'params' => $params,
            'inputs' => array_column($job['inputs'], 'sha256'), 'outputs' => array_column($outputs, 'sha256'), 'engine' => Toolkit::VERSION]);
    }

    /** @return array{bytes: string, name: string} */
    public function output(Authorizer $az, string $pid, int $n): array
    {
        $job = $this->ready($az, $pid);
        $o = $job['outputs'][$n] ?? throw HttpException::notFound();
        $bytes = $this->get($pid, 'out' . $n);
        $this->app->audit()->record('pdf.downloaded', 'success', 'pdf_job', $pid, $this->le($job), null, ['file' => $n, 'sha256' => $o['sha256']]);
        return ['bytes' => $bytes, 'name' => (string) $o['name']];
    }

    /**
     * The bytes behind a page preview (0.39.0): a staged original (before the result is made) or a result. Drawn in the
     * person's browser by PDF.js; not a download, so not audited as one.
     * @return array{bytes: string, mime: string}
     */
    public function preview(Authorizer $az, string $pid, string $kind, int $n): array
    {
        $job = $this->job($az, $pid);
        if ($job['cleaned_at'] !== null || (string) $job['expires_at'] <= $this->ts($this->app->clock->now())) {
            throw new BusinessException('pdf.error.expired', [], 410);
        }
        if ($kind === 'out') {
            if ($job['status'] !== 'done' || !isset($job['outputs'][$n])) {
                throw HttpException::notFound();
            }
            return ['bytes' => $this->get($pid, 'out' . $n), 'mime' => 'application/pdf'];
        }
        $in = $job['inputs'][$n] ?? throw HttpException::notFound();
        if (!in_array($job['status'], ['staged', 'failed'], true)) {
            throw HttpException::notFound(); // the originals are gone once the result exists
        }
        $type = (string) $in['type'];
        return ['bytes' => $this->get($pid, 'in' . $n), 'mime' => $type === 'pdf' ? 'application/pdf' : 'image/' . $type];
    }

    /** @return array{bytes: string, name: string} every output in one ZIP (split) */
    public function outputsZip(Authorizer $az, string $pid): array
    {
        $job = $this->ready($az, $pid);
        $tmp = tempnam(sys_get_temp_dir(), 'bluepdf');
        if ($tmp === false) {
            throw new \RuntimeException('temp file');
        }
        $zip = ZipStream::create($tmp);
        foreach ($job['outputs'] as $n => $o) {
            $zip->addString((string) $o['name'], $this->get($pid, 'out' . $n));
        }
        $zip->finish();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        $this->app->audit()->record('pdf.downloaded', 'success', 'pdf_job', $pid, $this->le($job), null, ['file' => 'zip', 'count' => count($job['outputs'])]);
        return ['bytes' => $bytes, 'name' => self::baseName((string) ($job['inputs'][0]['name'] ?? 'document')) . '-parts.zip'];
    }

    /**
     * Saves an output in the document register (a derivative with provenance; the register's retention applies, not the TTL).
     */
    public function saveToRecords(Authorizer $az, string $pid, int $n, string $class, int $legalEntityId, string $title): string
    {
        $job = $this->ready($az, $pid);
        $o = $job['outputs'][$n] ?? throw HttpException::notFound();
        if ($o['document'] !== null) {
            return (string) $o['document'];
        }
        if (!in_array($class, ['core.general', 'core.confidential'], true)) {
            throw new ValidationException(['class' => 'pdf.error.class']);
        }
        $def = $this->app->documents()->classDef($class);
        if (!$az->scope((string) $def['manage_permission'])->coversLegalEntity($legalEntityId)) {
            throw new ValidationException(['class' => 'pdf.error.no_record_right']);
        }
        $title = trim($title) !== '' ? mb_substr(trim($title), 0, 200) : (string) $o['name'];
        $desc = sprintf(
            'S01 %s · job %s · %s · from %s · output SHA-256 %s',
            $job['operation'],
            $pid,
            Toolkit::VERSION,
            implode(', ', array_map(static fn (array $i): string => $i['name'] . ' (' . substr((string) $i['sha256'], 0, 16) . '…)', $job['inputs'])),
            $o['sha256']
        );
        $doc = $this->app->documents()->storeGenerated(
            $az->userId,
            $class,
            $this->get($pid, 'out' . $n),
            (string) $o['name'],
            ['title' => $title, 'description' => mb_substr($desc, 0, 500), 'legal_entity_id' => $legalEntityId, 'related_type' => 'pdf_job', 'related_id' => $pid],
            'system',
            false
        );
        $job['outputs'][$n]['document'] = $doc['document'];
        $now = $this->ts($this->app->clock->now());
        $this->app->db()->run(
            'UPDATE pdf_jobs SET outputs_json = ?, saved_at = IFNULL(saved_at, ?), updated_at = ?, row_version = row_version + 1 WHERE id = ?',
            [json_encode($job['outputs'], JSON_UNESCAPED_UNICODE), $now, $now, $job['id']]
        );
        $this->app->audit()->record('pdf.saved_to_records', 'success', 'pdf_job', $pid, $legalEntityId, null, ['file' => $n, 'document' => $doc['document'], 'class' => $class]);
        return (string) $doc['document'];
    }

    /** Removes the temporary files now (the job history stays). */
    public function discard(Authorizer $az, string $pid): void
    {
        $job = $this->job($az, $pid);
        $this->clean($job);
        $this->app->audit()->record('pdf.discarded', 'success', 'pdf_job', $pid, $this->le($job));
    }

    /** @return array<string, mixed> */
    public function job(Authorizer $az, string $pid): array
    {
        $this->guard($az);
        $j = $this->app->db()->one('SELECT * FROM pdf_jobs WHERE public_id = ?', [$pid]);
        if ($j === null || (int) $j['owner_user_id'] !== $az->userId || ($az->isGuest() && !hash_equals((string) ($j['guest_key'] ?? ''), (string) $az->guestKey))) {
            throw HttpException::notFound(); // jobs are private to the person who made them
        }
        foreach (['inputs', 'params', 'outputs', 'warnings', 'notes'] as $k) {
            $j[$k] = (array) json_decode((string) ($j[$k . '_json'] ?? ''), true);
        }
        return $j;
    }

    /**
     * Results the person saved (0.39.0, item 8.2): unsaved jobs are not listed — they disappear with their files.
     * Visitors cannot save, so they never have a list.
     * @return list<array<string, mixed>>
     */
    public function myJobs(Authorizer $az): array
    {
        if ($az->isGuest()) {
            return [];
        }
        return $this->app->db()->all('SELECT public_id, operation, status, created_at, saved_at, expires_at, cleaned_at, outputs_json FROM pdf_jobs
            WHERE owner_user_id = ? AND saved_at IS NOT NULL ORDER BY saved_at DESC LIMIT 20', [$az->userId]);
    }

    /** Deletes expired temporary files (job history stays). @return int jobs cleaned */
    public function cleanup(): int
    {
        $rows = $this->app->db()->all('SELECT * FROM pdf_jobs WHERE cleaned_at IS NULL AND expires_at <= ? LIMIT 200', [$this->ts($this->app->clock->now())]);
        foreach ($rows as $r) {
            $r['inputs'] = (array) json_decode((string) $r['inputs_json'], true);
            $r['outputs'] = (array) json_decode((string) ($r['outputs_json'] ?? ''), true);
            $this->clean($r);
        }
        if ($rows !== []) {
            $this->app->audit()->record('pdf.temp_cleanup', 'success', 'pdf_job', null, null, null, ['jobs' => count($rows)]);
        }
        return count($rows);
    }

    public static function cleanupJob(App $app): void
    {
        self::of($app)->cleanup();
    }

    // ---- options per operation ----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $o
     * @param list<array<string, mixed>> $inputs
     * @return array{0: array<string, mixed>, 1: callable(array<int, string>): array<string, mixed>} [parameters to record, operation]
     */
    private function prepare(string $op, array $o, array $inputs): array
    {
        $s = static fn (string $k, int $max = 200): string => mb_substr(trim(is_scalar($o[$k] ?? null) ? (string) $o[$k] : ''), 0, $max);
        $pages = (int) ($inputs[0]['pages'] ?? 0);
        $range = static function (string $spec) use ($pages): string {
            try {
                Toolkit::pageList($spec, $pages);
            } catch (PdfError) {
                throw new ValidationException(['pages' => 'pdf.error.page_range']);
            }
            return $spec;
        };
        switch ($op) {
            case 'merge':
                $order = $this->order($o['order'] ?? [], count($inputs));
                return [['order' => $order], static fn (array $in): array => Toolkit::merge(array_map(static fn (int $i): array => [$in[$i], ''], $order))];
            case 'split':
                $mode = in_array($s('mode'), ['every', 'chunk', 'ranges'], true) ? $s('mode') : 'every';
                $chunk = max(1, min(1000, (int) $s('chunk')));
                $ranges = $s('ranges', 300);
                if ($mode === 'ranges') {
                    foreach (explode(',', $ranges) as $part) {
                        if (trim($part) !== '') {
                            $range($part);
                        }
                    }
                }
                return [['mode' => $mode, 'chunk' => $chunk, 'ranges' => $ranges], static fn (array $in): array => Toolkit::split($in[0], '', $mode, $ranges, $chunk)];
            case 'extract':
                $spec = $range($s('pages', 300));
                if (trim($spec) === '') {
                    throw new ValidationException(['pages' => 'pdf.error.page_range']);
                }
                return [['pages' => $spec], static fn (array $in): array => Toolkit::extract($in[0], '', $spec)];
            case 'organize':
                $rows = [];
                for ($i = 0; $i < $pages; $i++) {
                    if (($o['keep'][$i] ?? '') !== '1') {
                        continue;
                    }
                    $deg = (int) ($o['rot'][$i] ?? 0);
                    $rows[] = [(int) ($o['pos'][$i] ?? $i + 1), $i, in_array($deg, [0, 90, 180, 270], true) ? $deg : 0];
                }
                if ($rows === []) {
                    throw new ValidationException(['pages' => 'pdf.error.keep_one']);
                }
                usort($rows, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
                $order = array_map(static fn (array $r): array => [$r[1], $r[2]], $rows);
                return [['order' => array_map(static fn (array $x): string => ($x[0] + 1) . ($x[1] ? '@' . $x[1] : ''), $order)], static fn (array $in): array => Toolkit::organize($in[0], '', $order)];
            case 'rotate':
                $deg = (int) $s('degrees');
                if (!in_array($deg, [90, 180, 270], true)) {
                    throw new ValidationException(['degrees' => 'pdf.error.rotation']);
                }
                $spec = $range($s('pages', 300));
                return [['degrees' => $deg, 'pages' => $spec], static fn (array $in): array => Toolkit::rotate($in[0], '', $deg, $spec)];
            case 'compress':
                $strip = $s('strip_metadata') === '1';
                return [['strip_metadata' => $strip], static fn (array $in): array => Toolkit::compress($in[0], '', $strip)];
            case 'watermark':
                $text = $s('text', 120);
                if ($text === '') {
                    throw new ValidationException(['text' => 'pdf.error.text']);
                }
                $size = (float) max(8, min(200, (int) $s('size')));
                $opacity = max(5, min(100, (int) $s('opacity'))) / 100;
                $angle = $s('angle') === '0' ? 0 : 45;
                $spec = $range($s('pages', 300));
                return [['text' => $text, 'size' => $size, 'opacity' => $opacity, 'angle' => $angle, 'pages' => $spec],
                    static fn (array $in): array => Toolkit::watermark($in[0], '', $text, $size, $opacity, $angle, $spec)];
            case 'page_numbers':
                $tpl = $s('template', 120) ?: '{n}';
                $pos = in_array($s('position'), Toolkit::POSITIONS, true) ? $s('position') : 'bottom-center';
                $start = max(0, min(100000, (int) ($s('start') === '' ? 1 : $s('start'))));
                $size = (float) max(6, min(48, (int) ($s('size') ?: 10)));
                $spec = $range($s('pages', 300));
                return [['template' => $tpl, 'position' => $pos, 'start' => $start, 'size' => $size, 'pages' => $spec],
                    static fn (array $in): array => Toolkit::pageNumbers($in[0], '', $tpl, $pos, $start, $size, $spec)];
            case 'images':
                $order = $this->order($o['order'] ?? [], count($inputs));
                $size = array_key_exists($s('page_size'), Toolkit::PAGE_SIZES) ? $s('page_size') : 'A4';
                $margin = max(0, min(25, (int) $s('margin'))) * 72 / 25.4;
                return [['order' => $order, 'page_size' => $size, 'margin_mm' => (int) $s('margin')],
                    static fn (array $in): array => Toolkit::imagesToPdf(array_map(static fn (int $i): string => $in[$i], $order), $size, $margin)];
            case 'protect':
                $user = is_string($o['user_password'] ?? null) ? $o['user_password'] : '';
                $owner = is_string($o['owner_password'] ?? null) ? $o['owner_password'] : '';
                if (mb_strlen($user) < 6 || mb_strlen($user) > 100) {
                    throw new ValidationException(['user_password' => 'pdf.error.password_short']);
                }
                if (!hash_equals($user, is_string($o['user_password_confirm'] ?? null) ? $o['user_password_confirm'] : '')) {
                    throw new ValidationException(['user_password_confirm' => 'pdf.error.password_mismatch']);
                }
                $perm = 0;
                foreach (
                    ['allow_print' => Security::PERM_PRINT | Security::PERM_PRINT_HQ, 'allow_copy' => Security::PERM_COPY | Security::PERM_ACCESSIBILITY,
                    'allow_modify' => Security::PERM_MODIFY | Security::PERM_ANNOTATE | Security::PERM_FILL | Security::PERM_ASSEMBLE] as $k => $bits
                ) {
                    if ($s($k) === '1') {
                        $perm |= $bits;
                    }
                }
                $perm |= Security::PERM_ACCESSIBILITY;
                return [['permissions' => $perm, 'owner_password' => $owner !== '' ? 'set' : 'random', 'user_password' => 'set'],
                    static fn (array $in): array => Toolkit::protect($in[0], '', $user, $owner, $perm)];
            case 'unlock':
                return [[], static fn (array $in): array => ['bytes' => $in[0], 'pages' => (int) ($inputs[0]['pages'] ?? 0), 'warnings' => [],
                    'notes' => [['pdf.note.unlocked', []]], 'parts' => []]]; // decrypted when staged with the password
            case 'metadata':
                if ($s('confirm') !== '1') {
                    throw new ValidationException(['confirm' => 'pdf.error.confirm']);
                }
                return [[], static fn (array $in): array => Toolkit::stripMetadata($in[0], '')];
            case 'repair':
                return [[], static fn (array $in): array => Toolkit::repair($in[0], '')];
        }
        throw HttpException::notFound();
    }

    /** @return list<int> 0-based file order from position inputs */
    private function order(mixed $raw, int $n): array
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = [is_array($raw) && is_numeric($raw[$i] ?? null) ? (int) $raw[$i] : $i + 1, $i];
        }
        usort($rows, static fn (array $a, array $b): int => $a <=> $b);
        return array_column($rows, 1);
    }

    // ---- storage -----------------------------------------------------------------------------------

    private function dir(): string
    {
        $d = $this->app->path($this->app->config->string('PDF_TEMP_PATH', 'storage/pdf-temp'));
        if (!is_dir($d)) {
            @mkdir($d, 0700, true);
        }
        return $d;
    }

    private function file(string $pid, string $name): string
    {
        return $this->dir() . '/' . $pid . '-' . $name . '.bin';
    }

    private function put(string $pid, string $name, string $bytes): void
    {
        if (file_put_contents($this->file($pid, $name), $this->app->crypto()->encrypt($bytes)) === false) {
            throw new \RuntimeException('cannot write temporary file');
        }
    }

    private function get(string $pid, string $name): string
    {
        $f = $this->file($pid, $name);
        if (!is_file($f)) {
            throw new BusinessException('pdf.error.expired', [], 410);
        }
        return $this->app->crypto()->decrypt((string) file_get_contents($f));
    }

    /** @param array<string, mixed> $job */
    private function clean(array $job): void
    {
        foreach (glob($this->dir() . '/' . $job['public_id'] . '-*.bin') ?: [] as $f) {
            @unlink($f);
        }
        $this->app->db()->run(
            "UPDATE pdf_jobs SET cleaned_at = ?, status = IF(status IN ('staged','running','failed'), 'expired', status), updated_at = ? WHERE id = ?",
            [$this->ts($this->app->clock->now()), $this->ts($this->app->clock->now()), $job['id']]
        );
    }

    /** @return array<string, mixed> a finished job whose files are still there */
    private function ready(Authorizer $az, string $pid): array
    {
        $job = $this->job($az, $pid);
        if ($job['status'] !== 'done' || $job['cleaned_at'] !== null || (string) $job['expires_at'] <= $this->ts($this->app->clock->now())) {
            throw new BusinessException('pdf.error.expired', [], 410);
        }
        return $job;
    }

    private function guard(Authorizer $az): void
    {
        if (!$az->can('pdf.use')) {
            throw HttpException::forbidden();
        }
    }

    /** @param array<string, mixed> $job */
    private function le(array $job): ?int
    {
        return $job['legal_entity_id'] !== null ? (int) $job['legal_entity_id'] : null;
    }

    private function suffix(string $op): string
    {
        return ['merge' => 'merged', 'extract' => 'extract', 'organize' => 'organized', 'rotate' => 'rotated', 'compress' => 'compressed', 'watermark' => 'watermark',
            'page_numbers' => 'numbered', 'images' => 'images', 'protect' => 'protected', 'unlock' => 'unlocked', 'metadata' => 'clean', 'repair' => 'repaired'][$op] ?? $op;
    }

    public static function baseName(string $name): string
    {
        $b = (string) preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', $name);
        $b = (string) preg_replace('/[^\p{L}\p{M}\p{N}._-]+/u', '_', $b);
        return mb_substr(trim($b, '._'), 0, 60) ?: 'document';
    }

    private function ts(\DateTimeImmutable $d): string
    {
        return $d->format('Y-m-d H:i:s.u');
    }
}

<?php

declare(strict_types=1);

namespace Blue\Modules\Fonts;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\FontInfo;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Files\Zip;
use Blue\Core\Files\ZipStream;
use Blue\Core\Support\Ulid;

/**
 * M30 Company fonts. Administrators (fonts.manage) add families with licence information and files (one by one or as a
 * ZIP) and publish them — the uploader may publish their own (0.37.0, DEC-102; before: maker ≠ checker). People with fonts.use search, preview, download single files or a ZIP bundle that carries
 * LICENSE.txt. Files are never deleted: a new version marks the old file "replaced" and keeps it (7B item 3).
 * Families marked "restricted" (licence limits who may use them) are visible only to people with fonts.restricted.
 */
final class FontsService
{
    public const CATEGORIES = ['sans', 'serif', 'display', 'mono', 'script', 'other'];
    public const VISIBLE = ['published', 'deprecated'];
    public const ZIP_MAX_FONTS = 60;

    public function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    // ---- reading -------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> families the person may see, with their active files */
    public function library(Authorizer $az, string $q = '', string $category = ''): array
    {
        $this->guard($az);
        $where = ["f.status IN ('published','deprecated')"];
        $args = [];
        if (!$az->can('fonts.restricted') && !$az->can('fonts.manage')) {
            $where[] = 'f.restricted = 0';
        }
        if ($q !== '') {
            $where[] = '(f.name LIKE ? OR f.description LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($args, $like, $like);
        }
        if (in_array($category, self::CATEGORIES, true)) {
            $where[] = 'f.category = ?';
            $args[] = $category;
        }
        $rows = $this->app->db()->all('SELECT f.* FROM font_families f WHERE ' . implode(' AND ', $where) . " ORDER BY f.status = 'deprecated', f.name LIMIT 200", $args);
        return $this->withFiles($rows, true);
    }

    /** @return list<array<string, mixed>> every family (administrators) */
    public function all(Authorizer $az, string $status = ''): array
    {
        $this->manager($az);
        $args = [];
        $sql = 'SELECT f.*, (SELECT COUNT(*) FROM font_files x WHERE x.family_id = f.id AND x.status = \'active\') AS file_count FROM font_families f';
        if (in_array($status, ['draft', 'published', 'deprecated', 'archived'], true)) {
            $sql .= ' WHERE f.status = ?';
            $args[] = $status;
        }
        return $this->app->db()->all($sql . ' ORDER BY f.updated_at DESC LIMIT 500', $args);
    }

    /** @return array<string, mixed> a family the person may see (404 otherwise — no hint that it exists) */
    public function family(Authorizer $az, string $pid): array
    {
        $this->guard($az);
        $f = $this->app->db()->one('SELECT * FROM font_families WHERE public_id = ?', [$pid]);
        if ($f === null || !$this->canSee($az, $f)) {
            throw HttpException::notFound();
        }
        $f['replacement'] = $f['replacement_family_id'] !== null
            ? $this->app->db()->one('SELECT public_id, name, status FROM font_families WHERE id = ?', [$f['replacement_family_id']]) : null;
        return $f;
    }

    /** @return list<array<string, mixed>> */
    public function files(int $familyId, bool $activeOnly): array
    {
        return $this->app->db()->all(
            'SELECT x.*, u.display_name AS uploaded_name FROM font_files x LEFT JOIN users u ON u.id = x.created_by WHERE x.family_id = ?'
                . ($activeOnly ? " AND x.status = 'active'" : '') . ' ORDER BY x.status, x.weight IS NULL, x.weight, x.italic, x.id',
            [$familyId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function events(int $familyId): array
    {
        return $this->app->db()->all(
            'SELECT e.*, u.display_name AS actor_name FROM font_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.family_id = ? ORDER BY e.id DESC LIMIT 100',
            [$familyId]
        );
    }

    /** @return array<string, int> family count per status (administrators) */
    public function counts(): array
    {
        $out = ['draft' => 0, 'published' => 0, 'deprecated' => 0, 'archived' => 0];
        foreach ($this->app->db()->all('SELECT status, COUNT(*) AS n FROM font_families GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }

    // ---- administration ---------------------------------------------------------------------------

    /** @param array<string, mixed> $in */
    public function create(Authorizer $az, array $in): string
    {
        $this->manager($az);
        $d = $this->validate($in, null);
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $this->app->db()->run(
            'INSERT INTO font_families (public_id, created_at, created_by, updated_at, updated_by, name, category, description, dos, donts, license_name, license_source,
                license_terms, restricted, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'draft\')',
            [$pid, $now, $az->userId, $now, $az->userId, $d['name'], $d['category'], $d['description'], $d['dos'], $d['donts'], $d['license_name'], $d['license_source'],
                $d['license_terms'], $d['restricted']]
        );
        $id = (int) $this->app->db()->scalar('SELECT id FROM font_families WHERE public_id = ?', [$pid]);
        $this->event($id, 'created', ['name' => $d['name']], $az->userId);
        $this->app->audit()->record('fonts.created', 'success', 'font_family', $pid);
        return $pid;
    }

    /** @param array<string, mixed> $in */
    public function update(Authorizer $az, string $pid, array $in): void
    {
        $f = $this->managed($az, $pid);
        $d = $this->validate($in, (int) $f['id']);
        $changed = [];
        foreach ($d as $k => $v) {
            if ((string) ($f[$k] ?? '') !== (string) ($v ?? '')) {
                $changed[] = $k;
            }
        }
        if ($changed === []) {
            return;
        }
        $this->app->db()->run(
            'UPDATE font_families SET name = ?, category = ?, description = ?, dos = ?, donts = ?, license_name = ?, license_source = ?, license_terms = ?, restricted = ?,
                updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$d['name'], $d['category'], $d['description'], $d['dos'], $d['donts'], $d['license_name'], $d['license_source'], $d['license_terms'], $d['restricted'],
                $this->now(), $az->userId, $f['id']]
        );
        $this->event((int) $f['id'], 'edited', ['fields' => $changed], $az->userId);
        $this->app->audit()->record('fonts.edited', 'success', 'font_family', $pid, null, null, ['fields' => $changed]);
    }

    /**
     * Adds a font file (or a new version of one: $replaces = file public id). Real type, structure and duplicates are
     * checked before anything is stored.
     *
     * @return array{file: string, warnings: list<string>}
     */
    public function addFile(Authorizer $az, string $familyPid, ?UploadedFile $file, string $replaces = ''): array
    {
        $f = $this->managed($az, $familyPid);
        if ((string) $f['status'] === 'archived') {
            throw new BusinessException('fonts.error.archived', [], 409);
        }
        if ($file === null || !$file->ok()) {
            throw new ValidationException(['file' => $file?->errorKey() ?? 'file.error.missing']);
        }
        $old = null;
        if ($replaces !== '') {
            $old = $this->app->db()->one("SELECT * FROM font_files WHERE public_id = ? AND family_id = ? AND status = 'active'", [$replaces, $f['id']]);
            if ($old === null) {
                throw new ValidationException(['replaces' => 'fonts.error.replace_target']);
            }
        }
        $bytes = $file->bytes();
        $sha = hash('sha256', $bytes);
        $dup = $this->app->db()->one('SELECT x.public_id, f.name FROM font_files x JOIN font_families f ON f.id = x.family_id WHERE x.sha256 = ?', [$sha]);
        if ($dup !== null) {
            throw new ValidationException(['file' => 'fonts.error.duplicate']);
        }
        $kind = FontInfo::kind($bytes);
        $stored = $this->app->documents()->storeGenerated($az->userId, 'fonts.file', $bytes, $file->safeName(), [
            'title' => mb_substr((string) $f['name'] . ' — ' . $file->safeName(), 0, 200), 'related_type' => 'font_family', 'related_id' => $familyPid,
        ], 'upload', true); // real type / structure checks run here (FileType + FontInfo::sane) and throw before storing
        $info = FontInfo::read($bytes);
        $warnings = [];
        if ($info['embedding'] === 'restricted') {
            $warnings[] = 'fonts.warn.embedding_restricted';
        }
        $existing = $this->app->db()->scalar("SELECT font_family FROM font_files WHERE family_id = ? AND status = 'active' AND font_family IS NOT NULL LIMIT 1", [$f['id']]);
        if (is_string($existing) && $info['family'] !== null && mb_strtolower($existing) !== mb_strtolower((string) $info['family'])) {
            $warnings[] = 'fonts.warn.family_mismatch';
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $db = $this->app->db();
        $db->transactional(function () use ($db, $pid, $now, $az, $f, $stored, $file, $sha, $bytes, $kind, $info, $old): void {
            $db->run(
                'INSERT INTO font_files (public_id, created_at, created_by, updated_at, updated_by, family_id, document_pid, original_name, sha256, size_bytes, format,
                    font_family, style_name, weight, italic, font_version, embedding) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $f['id'], $stored['document'], mb_substr($file->safeName(), 0, 200), $sha, strlen($bytes), (string) $kind,
                    self::cut($info['family'], 120), self::cut($info['style'], 80), $info['weight'], $info['italic'] ? 1 : 0, self::cut($info['version'], 80), $info['embedding']]
            );
            if ($old !== null) {
                $newId = (int) $db->scalar('SELECT id FROM font_files WHERE public_id = ?', [$pid]);
                $db->run(
                    "UPDATE font_files SET status = 'replaced', replaced_by_file_id = ?, replaced_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?",
                    [$newId, $now, $now, $az->userId, $old['id']]
                );
            }
            $db->run('UPDATE font_families SET updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$now, $az->userId, $f['id']]);
        });
        $this->event((int) $f['id'], $old !== null ? 'file_replaced' : 'file_added', ['file' => $file->safeName(), 'sha256' => $sha, 'version' => $info['version'],
            'replaces' => $old['original_name'] ?? null], $az->userId);
        $this->app->audit()->record($old !== null ? 'fonts.file_replaced' : 'fonts.file_added', 'success', 'font_file', $pid, null, null, ['family' => $familyPid, 'sha256' => $sha]);
        return ['file' => $pid, 'warnings' => $warnings];
    }

    /**
     * 0.37.0: a ZIP of font files (a whole family at once). Each .ttf/.otf/.woff/.woff2 inside goes through addFile —
     * the same real-type, structure and duplicate checks — and is reported as added or skipped with the reason; the
     * ZIP itself is not kept. Folders, macOS resource forks and other file types are ignored. The archive reader refuses
     * bombs (entry count, total size, ratio — Core\Files\Zip).
     *
     * @return array{added: list<string>, skipped: list<array{name: string, reason: string}>, warnings: list<string>}
     */
    public function addZip(Authorizer $az, string $familyPid, ?UploadedFile $file): array
    {
        $f = $this->managed($az, $familyPid);
        if ((string) $f['status'] === 'archived') {
            throw new BusinessException('fonts.error.archived', [], 409);
        }
        if ($file === null || !$file->ok()) {
            throw new ValidationException(['file' => $file?->errorKey() ?? 'file.error.missing']);
        }
        $bytes = $file->bytes();
        if (!Zip::looksLikeZip($bytes)) {
            throw new ValidationException(['file' => 'fonts.error.zip_invalid']);
        }
        try {
            $zip = Zip::open($bytes);
        } catch (BusinessException $e) {
            throw new ValidationException(['file' => $e->messageKey]);
        }
        $fonts = [];
        foreach ($zip->names() as $name) {
            $base = basename(str_replace('\\', '/', $name));
            if (str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/') || str_starts_with($base, '.') || $base === '') {
                continue;
            }
            if (preg_match('/\.(ttf|otf|woff2?)$/i', $base) === 1) {
                $fonts[$name] = $base;
            }
        }
        if ($fonts === []) {
            throw new ValidationException(['file' => 'fonts.error.zip_empty']);
        }
        if (count($fonts) > self::ZIP_MAX_FONTS) {
            throw new ValidationException(['file' => 'fonts.error.zip_too_many']);
        }
        $tmpDir = sys_get_temp_dir();
        $out = ['added' => [], 'skipped' => [], 'warnings' => []];
        $i = 0;
        foreach ($fonts as $name => $base) {
            $i++;
            if (!mb_check_encoding($base, 'UTF-8')) {
                $conv = @iconv('TIS-620', 'UTF-8//IGNORE', $base); // Thai names zipped on Windows without the UTF-8 flag
                $base = is_string($conv) && $conv !== '' ? $conv : 'font-' . $i . '.' . strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
            }
            $one = null;
            try {
                $content = $zip->get($name, 20 * 1024 * 1024);
                if ($content === null) {
                    continue;
                }
                $one = UploadedFile::fromBytes($base, $content, $tmpDir);
                $res = $this->addFile($az, $familyPid, $one);
                $out['added'][] = $base;
                foreach ($res['warnings'] as $w) {
                    $out['warnings'][$w] = $w;
                }
            } catch (ValidationException $e) {
                $out['skipped'][] = ['name' => $base, 'reason' => (string) (array_values($e->errors)[0] ?? 'form.has_errors')];
            } catch (BusinessException $e) {
                if ($e instanceof HttpException) {
                    throw $e;
                }
                $out['skipped'][] = ['name' => $base, 'reason' => $e->messageKey];
            } finally {
                if ($one !== null && is_file($one->path)) {
                    @unlink($one->path);
                }
            }
        }
        $out['warnings'] = array_values($out['warnings']);
        $this->app->audit()->record('fonts.zip_imported', 'success', 'font_family', $familyPid, null, null, ['added' => count($out['added']), 'skipped' => count($out['skipped'])]);
        return $out;
    }

    /** Proof of licence (receipt, licence certificate, EULA page) — kept as a document; a new one replaces the link, the old document stays. */
    public function addLicense(Authorizer $az, string $familyPid, ?UploadedFile $file): void
    {
        $f = $this->managed($az, $familyPid);
        if ($file === null || !$file->ok()) {
            throw new ValidationException(['license_file' => $file?->errorKey() ?? 'file.error.missing']);
        }
        try {
            $stored = $this->app->documents()->storeGenerated($az->userId, 'fonts.license', $file->bytes(), $file->safeName(), [
                'title' => mb_substr('License — ' . (string) $f['name'], 0, 200), 'related_type' => 'font_family', 'related_id' => $familyPid,
            ], 'upload', true);
        } catch (ValidationException $e) {
            throw new ValidationException(['license_file' => (string) (array_values($e->errors)[0] ?? 'file.error.type_not_allowed')]);
        }
        $this->app->db()->run(
            'UPDATE font_families SET license_doc_pid = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$stored['document'], $this->now(), $az->userId, $f['id']]
        );
        $this->event((int) $f['id'], 'license_added', ['file' => $file->safeName()], $az->userId);
        $this->app->audit()->record('fonts.license_added', 'success', 'font_family', $familyPid);
    }

    /**
     * Draft/Deprecated → Published. Needs licence name + source and at least one file; the person publishing must not be
     * the one who created the family or uploaded any of its current files (maker ≠ checker).
     */
    public function publish(Authorizer $az, string $pid): void
    {
        $f = $this->managed($az, $pid);
        if (!in_array($f['status'], ['draft', 'deprecated'], true)) {
            throw new BusinessException('fonts.error.status', [], 409);
        }
        $missing = [];
        if (trim((string) $f['license_name']) === '') {
            $missing['license_name'] = 'fonts.error.license_required';
        }
        if (trim((string) $f['license_source']) === '') {
            $missing['license_source'] = 'fonts.error.license_required';
        }
        $makers = array_map('intval', array_column($this->app->db()->all("SELECT DISTINCT created_by FROM font_files WHERE family_id = ? AND status = 'active'", [$f['id']]), 'created_by'));
        if ($makers === []) {
            $missing['file'] = 'fonts.error.no_files';
        }
        if ($missing !== []) {
            throw new ValidationException($missing);
        }
        // 0.37.0 (DEC-102): the person who created the family or uploaded its files may publish it themselves — the
        // licence name, source and file checks above still apply, and the audit record says whether it was self-published
        $self = (int) $f['created_by'] === $az->userId || in_array($az->userId, $makers, true);
        $now = $this->now();
        $this->app->db()->run(
            "UPDATE font_families SET status = 'published', status_note = NULL, published_at = ?, published_by = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?",
            [$now, $az->userId, $now, $az->userId, $f['id']]
        );
        $this->event((int) $f['id'], 'published', ['self' => $self], $az->userId);
        $this->app->audit()->record('fonts.published', 'success', 'font_family', $pid, null, null, ['self_published' => $self]);
    }

    /** Published → Deprecated (still downloadable for existing documents, with a notice) / any → Archived (hidden, files kept) / Archived → Draft. */
    public function setStatus(Authorizer $az, string $pid, string $to, string $reason, string $replacementPid = ''): void
    {
        $f = $this->managed($az, $pid);
        $allowed = ['deprecated' => ['published'], 'archived' => ['draft', 'published', 'deprecated'], 'draft' => ['archived']];
        if (!isset($allowed[$to]) || !in_array($f['status'], $allowed[$to], true)) {
            throw new BusinessException('fonts.error.status', [], 409);
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        $replacement = null;
        if ($to === 'deprecated' && $replacementPid !== '') {
            $replacement = $this->app->db()->scalar("SELECT id FROM font_families WHERE public_id = ? AND status = 'published' AND id <> ?", [$replacementPid, $f['id']]);
            if ($replacement === null) {
                throw new ValidationException(['replacement' => 'fonts.error.replacement']);
            }
        }
        $this->app->db()->run(
            'UPDATE font_families SET status = ?, status_note = ?, replacement_family_id = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$to, mb_substr(trim($reason), 0, 300), $replacement !== null ? (int) $replacement : ($to === 'deprecated' ? null : $f['replacement_family_id']), $this->now(), $az->userId, $f['id']]
        );
        $this->event((int) $f['id'], $to === 'draft' ? 'restored' : $to, ['reason' => mb_substr(trim($reason), 0, 300)], $az->userId);
        $this->app->audit()->record('fonts.' . ($to === 'draft' ? 'restored' : $to), 'success', 'font_family', $pid, null, mb_substr(trim($reason), 0, 200));
    }

    // ---- files out ----------------------------------------------------------------------------------

    /**
     * Font bytes for the in-page preview (FontFace API): not counted as a download, not audited.
     *
     * @return array{bytes: string, mime: string}
     */
    public function preview(Authorizer $az, string $filePid): array
    {
        [$file] = $this->visibleFile($az, $filePid);
        $doc = $this->app->documents()->moduleRead((string) $file['document_pid'], 'fonts', $az->userId, 'preview', false);
        return ['bytes' => $doc['bytes'], 'mime' => self::mime((string) $file['format'])];
    }

    /** @return array{bytes: string, mime: string, filename: string} one file — counted and audited */
    public function download(Authorizer $az, string $filePid): array
    {
        [$file, $fam] = $this->visibleFile($az, $filePid);
        $doc = $this->app->documents()->moduleRead((string) $file['document_pid'], 'fonts', $az->userId, 'download', true);
        $this->app->db()->run('UPDATE font_files SET download_count = download_count + 1 WHERE id = ?', [$file['id']]);
        $this->app->db()->run('UPDATE font_families SET download_count = download_count + 1 WHERE id = ?', [$fam['id']]);
        $this->app->audit()->record('fonts.downloaded', 'success', 'font_file', $filePid, null, null, ['family' => $fam['public_id']]);
        return ['bytes' => $doc['bytes'], 'mime' => self::mime((string) $file['format']), 'filename' => (string) $file['original_name']];
    }

    /** @return array{bytes: string, filename: string} every current file of a family + LICENSE.txt, as a ZIP */
    public function bundle(Authorizer $az, string $familyPid): array
    {
        $fam = $this->family($az, $familyPid);
        if (!in_array($fam['status'], self::VISIBLE, true) && !$az->can('fonts.manage')) {
            throw HttpException::notFound();
        }
        $files = $this->files((int) $fam['id'], true);
        if ($files === []) {
            throw new BusinessException('fonts.error.no_files', [], 409);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'bluefont');
        if ($tmp === false) {
            throw new \RuntimeException('temp file');
        }
        $zip = ZipStream::create($tmp);
        $used = [];
        foreach ($files as $x) {
            $doc = $this->app->documents()->moduleRead((string) $x['document_pid'], 'fonts', $az->userId, 'download', true);
            $name = self::fileName((string) $x['original_name']);
            while (isset($used[$name])) {
                $name = '_' . $name;
            }
            $used[$name] = true;
            $zip->addString($name, $doc['bytes']);
        }
        $zip->addString('LICENSE.txt', $this->licenseText($fam, $files));
        $zip->finish();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        $this->app->db()->run('UPDATE font_families SET download_count = download_count + 1 WHERE id = ?', [$fam['id']]);
        $this->app->audit()->record('fonts.bundle_downloaded', 'success', 'font_family', $familyPid, null, null, ['files' => count($files)]);
        return ['bytes' => $bytes, 'filename' => self::fileName((string) $fam['name']) . '.zip'];
    }

    /** @return array{bytes: string, mime: string, filename: string} proof of licence (administrators) */
    public function licenseFile(Authorizer $az, string $familyPid): array
    {
        $f = $this->managed($az, $familyPid);
        if ($f['license_doc_pid'] === null) {
            throw HttpException::notFound();
        }
        $doc = $this->app->documents()->moduleRead((string) $f['license_doc_pid'], 'fonts', $az->userId, 'download', true);
        return ['bytes' => $doc['bytes'], 'mime' => $doc['mime'], 'filename' => $doc['filename']];
    }

    /**
     * @param array<string, mixed> $fam
     * @param list<array<string, mixed>> $files
     */
    public function licenseText(array $fam, array $files): string
    {
        $t = $this->app->translator();
        $lines = [
            (string) $fam['name'],
            str_repeat('=', 60),
            $t->get('fonts.license_txt.license') . ': ' . (string) ($fam['license_name'] ?? '—'),
            $t->get('fonts.license_txt.source') . ': ' . (string) ($fam['license_source'] ?? '—'),
            '',
            $t->get('fonts.license_txt.terms') . ':',
            trim((string) ($fam['license_terms'] ?? '')) !== '' ? (string) $fam['license_terms'] : $t->get('fonts.license_txt.no_terms'),
            '',
            $t->get('fonts.license_txt.internal'),
            '',
            $t->get('fonts.license_txt.files') . ':',
        ];
        foreach ($files as $x) {
            $lines[] = '- ' . (string) $x['original_name'] . ' · ' . (string) ($x['font_version'] ?? '') . ' · SHA-256 ' . (string) $x['sha256'];
        }
        $lines[] = '';
        $lines[] = $t->get('fonts.license_txt.generated', ['at' => $this->app->clock->now()->format('Y-m-d H:i') . ' UTC']);
        return implode("\r\n", $lines) . "\r\n";
    }

    // ---- helpers --------------------------------------------------------------------------------------

    /** @param array<string, mixed> $f */
    public function canSee(Authorizer $az, array $f): bool
    {
        if ($az->can('fonts.manage')) {
            return true;
        }
        return in_array($f['status'], self::VISIBLE, true) && ((int) $f['restricted'] === 0 || $az->can('fonts.restricted'));
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} [file, family]; users only see current files of visible families */
    private function visibleFile(Authorizer $az, string $filePid): array
    {
        $this->guard($az);
        $file = $this->app->db()->one('SELECT * FROM font_files WHERE public_id = ?', [$filePid]);
        $fam = $file !== null ? $this->app->db()->one('SELECT * FROM font_families WHERE id = ?', [$file['family_id']]) : null;
        if ($file === null || $fam === null || !$this->canSee($az, $fam) || ($file['status'] !== 'active' && !$az->can('fonts.manage'))) {
            throw HttpException::notFound();
        }
        return [$file, $fam];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withFiles(array $rows, bool $activeOnly): array
    {
        foreach ($rows as &$r) {
            $r['files'] = $this->files((int) $r['id'], $activeOnly);
        }
        unset($r);
        return $rows;
    }

    /**
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    private function validate(array $in, ?int $selfId): array
    {
        $e = [];
        $s = static fn (string $k, int $max): ?string => ($v = trim((string) ($in[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
        $d = [
            'name' => $s('name', 120), 'category' => (string) ($in['category'] ?? ''), 'description' => $s('description', 2000), 'dos' => $s('dos', 2000),
            'donts' => $s('donts', 2000), 'license_name' => $s('license_name', 120), 'license_source' => $s('license_source', 255), 'license_terms' => $s('license_terms', 4000),
            'restricted' => ($in['restricted'] ?? '') === '1' ? 1 : 0,
        ];
        if ($d['name'] === null || mb_strlen((string) $d['name']) < 2) {
            $e['name'] = 'fonts.error.name';
        } elseif ($this->app->db()->scalar('SELECT id FROM font_families WHERE name = ?' . ($selfId !== null ? ' AND id <> ' . $selfId : ''), [$d['name']]) !== null) {
            $e['name'] = 'fonts.error.name_taken';
        }
        if (!in_array($d['category'], self::CATEGORIES, true)) {
            $e['category'] = 'fonts.error.category';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        return $d;
    }

    /** @return array<string, mixed> */
    private function managed(Authorizer $az, string $pid): array
    {
        $this->manager($az);
        $f = $this->app->db()->one('SELECT * FROM font_families WHERE public_id = ?', [$pid]);
        if ($f === null) {
            throw HttpException::notFound();
        }
        return $f;
    }

    /** @param array<string, mixed> $detail */
    private function event(int $familyId, string $action, array $detail, int $actorId): void
    {
        $this->app->db()->run(
            'INSERT INTO font_events (family_id, action, detail_json, actor_id, occurred_at) VALUES (?, ?, ?, ?, ?)',
            [$familyId, $action, $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE), $actorId, $this->now()]
        );
    }

    private function guard(Authorizer $az): void
    {
        if (!$az->can('fonts.use') && !$az->can('fonts.manage')) {
            throw HttpException::forbidden();
        }
    }

    private function manager(Authorizer $az): void
    {
        if (!$az->can('fonts.manage')) {
            throw HttpException::forbidden();
        }
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    private static function cut(?string $s, int $max): ?string
    {
        return $s === null || trim($s) === '' ? null : mb_substr(trim($s), 0, $max);
    }

    public static function mime(string $format): string
    {
        return ['otf' => 'font/otf', 'woff' => 'font/woff', 'woff2' => 'font/woff2'][$format] ?? 'font/ttf';
    }

    public static function fileName(string $name): string
    {
        $s = (string) preg_replace('/[^\p{L}\p{M}\p{N}._-]+/u', '_', $name);
        return mb_substr(trim($s, '._'), 0, 80) ?: 'font';
    }
}

<?php

declare(strict_types=1);

namespace Blue\Modules\Esign;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Org\Translations;
use Blue\Core\Pdf\PdfError;
use Blue\Core\Pdf\Toolkit;
use Blue\Core\Support\Ulid;

/**
 * M20 Online signing (phase 5F, DEC-81) — a shared engine other modules can call (fromBytes) and its own screens.
 *
 *  request    one exact PDF (kept as a frozen document, SHA-256), title, category, message, signers in order (or all at
 *             once), the page and corner where each signature box goes, and a last day. Draft → sent → partially
 *             signed → completed; or declined / expired / cancelled (kept with everything signed so far) / voided
 *             (after completion, by a manager with a reason). Changing the document = a new request.
 *  signing    only the signer whose turn it is, before the last day, after opening the document: they type their name
 *             exactly as the system knows it and confirm with their password. The hash of the document they saw must
 *             still be the stored one, and the stored file is re-hashed. No drawn or uploaded signature pictures.
 *  completed  the system stamps a text box per signer (name, role, time, reference) where the sender placed it and
 *             appends an evidence page (who, how, when, from where, hashes); the signed PDF is stored as a new frozen
 *             document with its own hash, which anyone involved can check on the verify page.
 */
final class EsignService
{
    public const ANCHORS = Toolkit::POSITIONS;
    public const MAX_SIGNERS = 10;

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    public function today(): string
    {
        return $this->app->clock->now()->setTimezone(new \DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok')))->format('Y-m-d');
    }

    private function plusDays(int $n): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->today(), new \DateTimeZone('UTC'));
        return $d === false ? $this->today() : $d->modify('+' . $n . ' days')->format('Y-m-d');
    }

    /** @return list<int> companies the person may send from */
    public function companies(Authorizer $az): array
    {
        $s = $az->scope('esign.send');
        return array_values(array_filter(array_map('intval', array_column($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code'), 'id')), static fn (int $id): bool => $s->coversLegalEntity($id)));
    }

    // ---- create -------------------------------------------------------------------------------------------------------------

    /**
     * Engine entry: a draft request for a PDF (bytes) — used by the upload screen and by other modules.
     *
     * @param array<string, mixed> $in title, category, message, sequential ('1'), expires_on, signers: list<array{username, role, page, anchor}>
     */
    public function fromBytes(Authorizer $az, int $le, array $in, ?string $bytes, string $fileName, ?string $pid = null): string
    {
        if (!$az->scope('esign.send')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        $cur = $pid !== null ? $db->one('SELECT * FROM sig_requests WHERE public_id = ? AND legal_entity_id = ?', [$pid, $le]) : null;
        if ($pid !== null && ($cur === null || ((int) $cur['created_by'] !== $az->userId && !$az->scope('esign.manage')->coversLegalEntity($le)))) {
            throw HttpException::notFound();
        }
        if ($cur !== null && $cur['status'] !== 'draft') {
            throw new BusinessException('esign.error.not_draft', [], 409);
        }
        $errors = [];
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 200);
        if (mb_strlen($title) < 3) {
            $errors['title'] = 'esign.error.title';
        }
        $expires = trim((string) ($in['expires_on'] ?? ''));
        if (!$this->isDate($expires) || $expires <= $this->today() || $expires > $this->plusDays(90)) {
            $errors['expires_on'] = 'esign.error.expires';
        }
        $pages = $cur !== null ? (int) $cur['pages'] : 0;
        if ($bytes !== null) {
            try {
                $info = Toolkit::info($bytes);
                if ($info['encrypted']) {
                    $errors['file'] = 'esign.error.encrypted';
                } elseif ($info['pages'] < 1 || $info['pages'] > 200) {
                    $errors['file'] = 'esign.error.pages';
                }
                $pages = (int) $info['pages'];
            } catch (PdfError $e) {
                $errors['file'] = in_array($e->messageKey, ['pdf.error.password', 'pdf.error.encryption_unsupported'], true) ? 'esign.error.encrypted' : 'esign.error.pdf';
            }
        } elseif ($cur === null) {
            $errors['file'] = 'esign.error.file';
        }
        $signers = [];
        $seen = [];
        foreach ((array) ($in['signers'] ?? []) as $i => $s) {
            $s = (array) $s;
            $u = trim((string) ($s['username'] ?? ''));
            $role = mb_substr(trim((string) ($s['role'] ?? '')), 0, 80);
            if ($u === '' && $role === '') {
                continue;
            }
            $uid = $db->scalar("SELECT id FROM users WHERE username = ? AND status = 'active'", [$u]);
            $page = trim((string) ($s['page'] ?? 'last'));
            $p = $page === 'last' || $page === '' ? $pages : (ctype_digit($page) ? (int) $page : 0);
            $anchor = (string) ($s['anchor'] ?? 'bottom-right');
            if ($uid === null || isset($seen[(int) $uid])) {
                $errors['signer_' . $i] = $uid === null ? 'esign.error.user' : 'esign.error.user_twice';
            } elseif (mb_strlen($role) < 2) {
                $errors['signer_' . $i] = 'esign.error.role';
            } elseif ($pages > 0 && ($p < 1 || $p > $pages)) {
                $errors['signer_' . $i] = 'esign.error.page';
            } elseif (!in_array($anchor, self::ANCHORS, true)) {
                $errors['signer_' . $i] = 'form.invalid_choice';
            }
            if ($uid !== null) {
                $seen[(int) $uid] = true;
            }
            $signers[] = ['user_id' => (int) $uid, 'role' => $role, 'page' => $p, 'anchor' => $anchor];
        }
        if ($signers === []) {
            $errors['signers'] = 'esign.error.no_signers';
        } elseif (count($signers) > self::MAX_SIGNERS) {
            $errors['signers'] = 'esign.error.too_many';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $now = $this->now();
        $meta = ['title' => (string) ($in['category'] ?? '') !== '' ? mb_substr($in['category'] . ' · ' . $title, 0, 200) : $title, 'legal_entity_id' => $le, 'related_type' => 'sig_request'];
        $vals = [$title, mb_substr(trim((string) ($in['category'] ?? '')), 0, 60) ?: null, mb_substr(trim((string) ($in['message'] ?? '')), 0, 1000) ?: null, ($in['sequential'] ?? '1') === '1' ? 1 : 0, $expires];
        $id = null;
        $db->transactional(function () use ($db, $cur, $bytes, $fileName, $meta, $vals, $pages, $now, $az, $le, $signers, &$pid, &$id): void {
            $doc = null;
            if ($bytes !== null) {
                $stored = $this->app->documents()->storeGenerated($az->userId, 'esign.source', $bytes, $fileName, $meta, 'upload', true);
                $doc = ['pid' => (string) $stored['document'], 'sha' => hash('sha256', $bytes), 'name' => mb_substr($fileName, 0, 200)];
            }
            if ($cur === null) {
                $pid = Ulid::generate($this->app->clock);
                $db->run('INSERT INTO sig_requests (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, title, category, message, sequential, expires_on, source_document_pid, source_sha256, source_name, pages)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$pid, $now, $az->userId, $now, $az->userId, $le, ...$vals, $doc['pid'] ?? '', $doc['sha'] ?? '', $doc['name'] ?? '', $pages]);
                $id = (int) $db->scalar('SELECT id FROM sig_requests WHERE public_id = ?', [$pid]);
                $this->event($id, 'created', null, $az->userId, $doc['sha'] ?? null);
            } else {
                $id = (int) $cur['id'];
                $db->run('UPDATE sig_requests SET title = ?, category = ?, message = ?, sequential = ?, expires_on = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [...$vals, $now, $az->userId, $id]);
                if ($doc !== null) {
                    $db->run('UPDATE sig_requests SET source_document_pid = ?, source_sha256 = ?, source_name = ?, pages = ? WHERE id = ?', [$doc['pid'], $doc['sha'], $doc['name'], $pages, $id]);
                    $this->event($id, 'file_replaced', null, $az->userId, $doc['sha']);
                }
                $db->run('DELETE FROM sig_signers WHERE request_id = ?', [$id]);
            }
            foreach ($signers as $n => $s) {
                $db->run(
                    'INSERT INTO sig_signers (public_id, request_id, seq, user_id, role_label, page, anchor) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [Ulid::generate($this->app->clock), $id, $n + 1, $s['user_id'], $s['role'], $s['page'], $s['anchor']]
                );
            }
        });
        $this->app->audit()->record($cur === null ? 'esign.created' : 'esign.draft_saved', 'success', 'sig_request', (string) $pid, $le);
        return (string) $pid;
    }

    /** Draft → sent: number, first signer(s) asked. */
    public function send(Authorizer $az, string $pid): void
    {
        $r = $this->request($az, $pid);
        if (!$r['is_sender'] || $r['status'] !== 'draft') {
            throw new BusinessException('esign.error.state', [], 409);
        }
        if ((string) $r['expires_on'] <= $this->today()) {
            throw new BusinessException('esign.error.expires', [], 409);
        }
        $db = $this->app->db();
        $no = $this->app->numbering()->next((int) $r['legal_entity_id'], null, 'esign.request', substr($this->today(), 0, 4), 'ES-{YYYY}-{SEQ:5}', 'sig_request', $pid, $az->userId);
        $db->run("UPDATE sig_requests SET status = 'sent', request_no = ?, sent_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$no, $this->now(), $this->now(), $r['id']]);
        $db->run("UPDATE sig_signers SET status = 'pending' WHERE request_id = ?" . ((int) $r['sequential'] === 1 ? ' AND seq = 1' : ''), [$r['id']]);
        $this->event((int) $r['id'], 'sent', $no, $az->userId, (string) $r['source_sha256']);
        $this->askPending((int) $r['id']);
        $this->app->audit()->record('esign.sent', 'success', 'sig_request', $pid, (int) $r['legal_entity_id'], null, ['no' => $no]);
    }

    private function askPending(int $requestId): void
    {
        $r = $this->app->db()->one('SELECT * FROM sig_requests WHERE id = ?', [$requestId]);
        foreach ($this->app->db()->all("SELECT * FROM sig_signers WHERE request_id = ? AND status = 'pending'", [$requestId]) as $s) {
            $this->app->notifier()->notify((int) $s['user_id'], 'esign.sign', 'esign.notify.sign', ['title' => (string) $r['title'], 'date' => (string) $r['expires_on']], '/esign/r/' . $r['public_id'], 'esign.ask:' . $s['public_id']);
        }
    }

    // ---- read ---------------------------------------------------------------------------------------------------------------

    /**
     * A request the person may see (sender, signer, manager of the company) + flags and signers.
     *
     * @return array<string, mixed>
     */
    public function request(Authorizer $az, string $pid): array
    {
        $db = $this->app->db();
        $r = $db->one('SELECT r.*, u.display_name AS sender_name FROM sig_requests r LEFT JOIN users u ON u.id = r.created_by WHERE r.public_id = ?', [$pid]);
        if ($r === null) {
            throw HttpException::notFound();
        }
        $r['signers'] = $db->all('SELECT s.*, u.display_name, u.username FROM sig_signers s JOIN users u ON u.id = s.user_id WHERE s.request_id = ? ORDER BY s.seq', [$r['id']]);
        $r['is_sender'] = (int) $r['created_by'] === $az->userId;
        $r['is_manager'] = $az->scope('esign.manage')->coversLegalEntity((int) $r['legal_entity_id']);
        $r['me'] = null;
        foreach ($r['signers'] as $s) {
            if ((int) $s['user_id'] === $az->userId) {
                $r['me'] = $s;
            }
        }
        if (!$r['is_sender'] && !$r['is_manager'] && ($r['me'] === null || $r['status'] === 'draft')) {
            throw HttpException::notFound();
        }
        return $r;
    }

    /** @return list<array<string, mixed>> */
    public function events(int $requestId): array
    {
        return $this->app->db()->all('SELECT e.*, u.display_name AS actor FROM sig_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.request_id = ? ORDER BY e.id', [$requestId]);
    }

    /** @return array{todo: list<array<string, mixed>>, mine: list<array<string, mixed>>, sent: list<array<string, mixed>>} */
    public function inbox(Authorizer $az): array
    {
        $db = $this->app->db();
        $sel = 'SELECT r.*, s.status AS my_status, s.public_id AS signer_pid, (SELECT COUNT(*) FROM sig_signers x WHERE x.request_id = r.id) AS n_signers,
                       (SELECT COUNT(*) FROM sig_signers x WHERE x.request_id = r.id AND x.status = \'signed\') AS n_signed
                  FROM sig_requests r ';
        return [
            'todo' => $db->all($sel . "JOIN sig_signers s ON s.request_id = r.id AND s.user_id = ? WHERE s.status = 'pending' AND r.status IN ('sent','partial') ORDER BY r.expires_on", [$az->userId]),
            'mine' => $db->all($sel . "JOIN sig_signers s ON s.request_id = r.id AND s.user_id = ? WHERE r.status <> 'draft' AND s.status <> 'pending' ORDER BY r.id DESC LIMIT 50", [$az->userId]),
            'sent' => $db->all(str_replace('s.status AS my_status, s.public_id AS signer_pid', 'NULL AS my_status, NULL AS signer_pid', $sel) . 'WHERE r.created_by = ? ORDER BY r.id DESC LIMIT 100', [$az->userId]),
        ];
    }

    /** @return list<array<string, mixed>> requests of a company (manager) */
    public function company(int $le, string $status = ''): array
    {
        return $this->app->db()->all(
            "SELECT r.*, u.display_name AS sender_name, (SELECT COUNT(*) FROM sig_signers x WHERE x.request_id = r.id) AS n_signers,
                    (SELECT COUNT(*) FROM sig_signers x WHERE x.request_id = r.id AND x.status = 'signed') AS n_signed
               FROM sig_requests r LEFT JOIN users u ON u.id = r.created_by WHERE r.legal_entity_id = ? AND r.status <> 'draft'" . ($status !== '' ? ' AND r.status = ?' : '') . ' ORDER BY r.id DESC LIMIT 200',
            $status !== '' ? [$le, $status] : [$le]
        );
    }

    /**
     * The original or the signed file, for someone who may see the request (audited).
     *
     * @return array{bytes: string, mime: string, filename: string}
     */
    public function file(Authorizer $az, string $pid, string $which): array
    {
        $r = $this->request($az, $pid);
        $doc = $which === 'signed' ? $r['signed_document_pid'] : $r['source_document_pid'];
        if ($doc === null || $doc === '') {
            throw HttpException::notFound();
        }
        $f = $this->app->documents()->moduleRead((string) $doc, 'esign', $az->userId, 'esign.' . $which);
        if ($r['me'] !== null && $which === 'source') {
            $this->event((int) $r['id'], 'opened', null, $az->userId, hash('sha256', $f['bytes']));
        }
        return ['bytes' => $f['bytes'], 'mime' => 'application/pdf', 'filename' => ($r['request_no'] ?? 'draft') . ($which === 'signed' ? '-signed' : '') . '.pdf'];
    }

    // ---- sign / decline -----------------------------------------------------------------------------------------------------

    public function sign(Authorizer $az, string $pid, string $typedName, string $password, string $hashSeen, bool $read, string $ip): void
    {
        $r = $this->request($az, $pid);
        $me = $r['me'];
        $this->expireIfDue($r);
        if ($me === null || $me['status'] !== 'pending' || !in_array($r['status'], ['sent', 'partial'], true)) {
            throw new BusinessException('esign.error.not_your_turn', [], 409);
        }
        $db = $this->app->db();
        $user = (array) $db->one('SELECT * FROM users WHERE id = ?', [$az->userId]);
        $errors = [];
        if (!$read) {
            $errors['read'] = 'esign.error.read';
        }
        if (self::norm($typedName) !== self::norm((string) $user['display_name'])) {
            $errors['typed_name'] = 'esign.error.typed_name';
        }
        if (!$this->app->auth()->verifyPassword($user, $password)) {
            $errors['password'] = 'esign.error.password';
            $this->app->audit()->record('esign.sign_password', 'failure', 'sig_request', $pid, (int) $r['legal_entity_id']);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        // the document seen must be the one stored, and the stored one must still hash the same
        $stored = $this->app->documents()->moduleRead((string) $r['source_document_pid'], 'esign', $az->userId, 'esign.sign_check', false);
        if (!hash_equals((string) $r['source_sha256'], $hashSeen) || !hash_equals((string) $r['source_sha256'], hash('sha256', $stored['bytes']))) {
            $this->event((int) $r['id'], 'hash_mismatch', null, $az->userId, $hashSeen !== '' ? mb_substr($hashSeen, 0, 64) : null);
            throw new BusinessException('esign.error.hash', [], 409);
        }
        $now = $this->now();
        $db->run(
            "UPDATE sig_signers SET status = 'signed', typed_name = ?, auth_method = 'password', signed_sha256 = ?, signed_at = ?, ip = ? WHERE id = ? AND status = 'pending'",
            [mb_substr(trim($typedName), 0, 150), $r['source_sha256'], $now, mb_substr($ip, 0, 45), $me['id']]
        );
        $this->event((int) $r['id'], 'signed', (string) $me['role_label'], $az->userId, (string) $r['source_sha256']);
        $this->app->audit()->record('esign.signed', 'success', 'sig_request', $pid, (int) $r['legal_entity_id'], null, ['seq' => (int) $me['seq']]);
        $left = (int) $db->scalar("SELECT COUNT(*) FROM sig_signers WHERE request_id = ? AND status <> 'signed'", [$r['id']]);
        if ($left === 0) {
            $this->complete((int) $r['id']);
            return;
        }
        $db->run("UPDATE sig_requests SET status = 'partial', updated_at = ? WHERE id = ?", [$now, $r['id']]);
        if ((int) $r['sequential'] === 1) {
            $db->run("UPDATE sig_signers SET status = 'pending' WHERE request_id = ? AND status = 'waiting' AND seq = (SELECT m FROM (SELECT MIN(seq) AS m FROM sig_signers WHERE request_id = ? AND status = 'waiting') x)", [$r['id'], $r['id']]);
            $this->askPending((int) $r['id']);
        }
    }

    public static function norm(string $s): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($s)));
    }

    /** All signed: stamp the boxes, add the evidence page, store the signed PDF (frozen) and tell everyone. */
    private function complete(int $requestId): void
    {
        $db = $this->app->db();
        $r = (array) $db->one('SELECT r.*, u.display_name AS sender_name, u.username AS sender_username FROM sig_requests r LEFT JOIN users u ON u.id = r.created_by WHERE r.id = ?', [$requestId]);
        $signers = $db->all('SELECT s.*, u.display_name, u.username FROM sig_signers s JOIN users u ON u.id = s.user_id WHERE s.request_id = ? ORDER BY s.seq', [$requestId]);
        $t = $this->app->translator();
        $src = $this->app->documents()->moduleRead((string) $r['source_document_pid'], 'esign', (int) $r['created_by'], 'esign.complete', false);
        $tz = new \DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
        $nowDt = $this->app->clock->now();
        $when = static fn (string $utc): string => (\DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $utc, new \DateTimeZone('UTC')) ?: $nowDt)->setTimezone($tz)->format('Y-m-d H:i');
        $slots = [];
        $boxes = [];
        $evidence = [
            $t->get('esign.ev.document', ['title' => (string) $r['title'], 'no' => (string) $r['request_no']]),
            $t->get('esign.ev.company', ['name' => $this->companyName((int) $r['legal_entity_id'])]),
            $t->get('esign.ev.sender', ['name' => (string) $r['sender_name'], 'user' => (string) $r['sender_username'], 'at' => $when((string) $r['sent_at'])]),
            $t->get('esign.ev.source', ['name' => (string) $r['source_name'], 'pages' => (string) $r['pages']]),
            'SHA-256: ' . $r['source_sha256'],
            $t->get('esign.ev.order', ['mode' => $t->get((int) $r['sequential'] === 1 ? 'esign.sequential' : 'esign.parallel')]),
            '',
        ];
        foreach ($signers as $s) {
            $key = $s['page'] . '|' . $s['anchor'];
            $slot = $slots[$key] ?? 0;
            $slots[$key] = $slot + 1;
            $boxes[] = ['page' => (int) $s['page'], 'anchor' => (string) $s['anchor'], 'slot' => $slot, 'lines' => [
                (string) $s['typed_name'], (string) $s['role_label'], $t->get('esign.box.signed', ['at' => $when((string) $s['signed_at'])]), $t->get('esign.box.ref', ['no' => (string) $r['request_no']]),
            ]];
            $evidence[] = $t->get('esign.ev.signer', ['n' => (string) $s['seq'], 'name' => (string) $s['typed_name'], 'user' => (string) $s['username'], 'role' => (string) $s['role_label']]);
            $evidence[] = '    ' . $t->get('esign.ev.how', ['at' => $when((string) $s['signed_at']), 'ip' => (string) ($s['ip'] ?? '-'), 'page' => (string) $s['page']]);
        }
        $evidence[] = '';
        $evidence[] = $t->get('esign.ev.completed', ['at' => $when($this->now())]);
        $evidence[] = $t->get('esign.ev.note');
        $out = Toolkit::signedCopy($src['bytes'], '', $boxes, $t->get('esign.ev.title'), $evidence);
        $sha = hash('sha256', $out['bytes']);
        $stored = $this->app->documents()->storeGenerated((int) $r['created_by'], 'esign.signed', $out['bytes'], $r['request_no'] . '-signed.pdf', [
            'title' => mb_substr($r['title'] . ' (' . $t->get('esign.signed_suffix') . ')', 0, 200), 'legal_entity_id' => (int) $r['legal_entity_id'], 'related_type' => 'sig_request',
            'related_id' => (string) $r['public_id'], 'document_number' => (string) $r['request_no'],
        ], 'system', true);
        $now = $this->now();
        $db->run("UPDATE sig_requests SET status = 'completed', signed_document_pid = ?, signed_sha256 = ?, completed_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$stored['document'], $sha, $now, $now, $requestId]);
        $this->event($requestId, 'completed', null, null, $sha);
        $this->app->audit()->record('esign.completed', 'success', 'sig_request', (string) $r['public_id'], (int) $r['legal_entity_id'], null, ['sha256' => $sha]);
        foreach (array_unique([(int) $r['created_by'], ...array_map(static fn (array $s): int => (int) $s['user_id'], $signers)]) as $uid) {
            $this->app->notifier()->notify($uid, 'esign.done', 'esign.notify.completed', ['title' => (string) $r['title']], '/esign/r/' . $r['public_id'], 'esign.done:' . $r['public_id'] . ':' . $uid);
        }
    }

    private function companyName(int $le): string
    {
        $n = Translations::pick((new Translations($this->app->db()))->many('legal_entity', [$le])[$le] ?? [], 'th');
        return $n !== '' ? $n : (string) $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$le]);
    }

    public function decline(Authorizer $az, string $pid, string $reason): void
    {
        $r = $this->request($az, $pid);
        $me = $r['me'];
        if ($me === null || $me['status'] !== 'pending' || !in_array($r['status'], ['sent', 'partial'], true)) {
            throw new BusinessException('esign.error.not_your_turn', [], 409);
        }
        $reason = mb_substr(trim($reason), 0, 500);
        if (mb_strlen($reason) < 5) {
            throw new ValidationException(['reason' => 'esign.error.reason']);
        }
        $db = $this->app->db();
        $db->run("UPDATE sig_signers SET status = 'declined', decline_reason = ?, signed_at = ? WHERE id = ?", [$reason, $this->now(), $me['id']]);
        $this->close($r, 'declined', $reason, $az->userId);
    }

    /** Sender (or manager) stops a request that is not completed; what was signed so far is kept. */
    public function cancel(Authorizer $az, string $pid, string $reason): void
    {
        $r = $this->request($az, $pid);
        if ((!$r['is_sender'] && !$r['is_manager']) || !in_array($r['status'], ['draft', 'sent', 'partial'], true)) {
            throw new BusinessException('esign.error.state', [], 409);
        }
        $reason = mb_substr(trim($reason), 0, 500);
        if (mb_strlen($reason) < 5) {
            throw new ValidationException(['reason' => 'esign.error.reason']);
        }
        $this->close($r, 'cancelled', $reason, $az->userId);
    }

    /** Manager voids a completed request (e.g. replaced) — both files stay, marked void. */
    public function void(Authorizer $az, string $pid, string $reason): void
    {
        $r = $this->request($az, $pid);
        if (!$r['is_manager'] || $r['status'] !== 'completed') {
            throw new BusinessException('esign.error.state', [], 409);
        }
        $reason = mb_substr(trim($reason), 0, 500);
        if (mb_strlen($reason) < 5) {
            throw new ValidationException(['reason' => 'esign.error.reason']);
        }
        $this->close($r, 'voided', $reason, $az->userId);
    }

    /** @param array<string, mixed> $r */
    private function close(array $r, string $status, string $reason, ?int $actor): void
    {
        $db = $this->app->db();
        $db->run('UPDATE sig_requests SET status = ?, closed_reason = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?', [$status, $reason, $this->now(), $r['id']]);
        $db->run("UPDATE sig_signers SET status = 'waiting' WHERE request_id = ? AND status = 'pending'", [$r['id']]);
        $this->event((int) $r['id'], $status, $reason, $actor, null);
        $this->app->audit()->record('esign.' . $status, 'success', 'sig_request', (string) $r['public_id'], (int) $r['legal_entity_id'], $reason);
        $to = [(int) $r['created_by']];
        foreach ($db->all("SELECT user_id FROM sig_signers WHERE request_id = ? AND status IN ('signed','declined')", [$r['id']]) as $s) {
            $to[] = (int) $s['user_id'];
        }
        foreach (array_unique($to) as $uid) {
            if ($uid !== $actor) {
                $this->app->notifier()->notify($uid, 'esign.done', 'esign.notify.' . $status, ['title' => (string) $r['title']], '/esign/r/' . $r['public_id'], 'esign.close:' . $r['public_id'] . ':' . $uid);
            }
        }
    }

    /** @param array<string, mixed> $r */
    private function expireIfDue(array $r): void
    {
        if (in_array($r['status'], ['sent', 'partial'], true) && (string) $r['expires_on'] < $this->today()) {
            $this->close($r, 'expired', 'expires_on ' . $r['expires_on'], null);
            throw new BusinessException('esign.error.expired', [], 409);
        }
    }

    /** Job: expire past the last day; remind pending signers once a day after the first day. */
    public static function housekeepingJob(App $app): int
    {
        $svc = self::of($app);
        $db = $app->db();
        $today = $svc->today();
        $n = 0;
        foreach ($db->all("SELECT * FROM sig_requests WHERE status IN ('sent','partial') AND expires_on < ?", [$today]) as $r) {
            $svc->close($r, 'expired', 'expires_on ' . $r['expires_on'], null);
            $n++;
        }
        $dayAgo = $app->clock->now()->modify('-1 day')->format('Y-m-d H:i:s.u');
        foreach (
            $db->all("SELECT s.*, r.title, r.public_id AS rpid, r.expires_on FROM sig_signers s JOIN sig_requests r ON r.id = s.request_id
                       WHERE s.status = 'pending' AND r.status IN ('sent','partial') AND r.sent_at < ? AND (s.reminded_on IS NULL OR s.reminded_on < ?)", [$dayAgo, $today]) as $s
        ) {
            $app->notifier()->notify((int) $s['user_id'], 'esign.sign', 'esign.notify.reminder', ['title' => (string) $s['title'], 'date' => (string) $s['expires_on']], '/esign/r/' . $s['rpid'], 'esign.remind:' . $s['public_id'] . ':' . $today);
            $db->run('UPDATE sig_signers SET reminded_on = ? WHERE id = ?', [$today, $s['id']]);
            $n++;
        }
        return $n;
    }

    /**
     * Is this file one of ours? Matches the SHA-256 against completed (signed) and original files the person may see.
     *
     * @return array{match: string, request: array<string, mixed>}|null match = signed | source
     */
    public function verify(Authorizer $az, string $bytes): ?array
    {
        $sha = hash('sha256', $bytes);
        foreach ($this->app->db()->all('SELECT public_id, signed_sha256 FROM sig_requests WHERE signed_sha256 = ? OR source_sha256 = ? ORDER BY id DESC', [$sha, $sha]) as $row) {
            try {
                $r = $this->request($az, (string) $row['public_id']);
            } catch (HttpException) {
                continue;
            }
            $this->app->audit()->record('esign.verified', 'success', 'sig_request', (string) $row['public_id'], (int) $r['legal_entity_id']);
            return ['match' => $row['signed_sha256'] === $sha ? 'signed' : 'source', 'request' => $r];
        }
        return null;
    }

    private function event(int $requestId, string $action, ?string $note, ?int $actor, ?string $sha): void
    {
        $this->app->db()->run('INSERT INTO sig_events (request_id, action, actor_id, note, sha256, occurred_at) VALUES (?, ?, ?, ?, ?, ?)', [$requestId, $action, $actor, $note !== null ? mb_substr($note, 0, 500) : null, $sha, $this->now()]);
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}

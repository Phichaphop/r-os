<?php

declare(strict_types=1);

namespace Blue\Core\Records;

use DateTimeImmutable;
use Blue\Core\Audit\AuditLogger;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Authz\ScopeSet;
use Blue\Core\Database\Connection;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\FileType;
use Blue\Core\Files\PdfInspector;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Registry\ConfigRegistry;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * Central Document / Records Service (04 §6.17) — the ONE place modules store files (no module file stores).
 *
 * - capture: content-type check from the bytes, size/page limits, SHA-256 of the original, private storage
 * - classify: document class → classification, permissions, encryption, record class (retention)
 * - lifecycle: draft (new versions allowed) → final (immutable; changes = a new document that supersedes it)
 * - access: class permission ∩ company/site scope, checked on every read and download; refused = 404 (no leak)
 * - integrity: the hash is re-checked on every download; a mismatch is refused and audited
 * - retention: trigger date + approved rule → scheduled disposition date; legal holds stop disposal
 */
final class DocumentService
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $classes = null;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly DocumentStorage $storage,
        private readonly ?ConfigRegistry $registry = null,
    ) {
    }

    /** Writes DocumentCatalog into document_classes (new classes only; never overrides an edited row). */
    /** @param array<string, array<string, mixed>> $modules manifests — their `document_classes` (same 8 fields) are added too */
    public static function sync(Connection $db, Clock $clock, array $modules = []): int
    {
        $n = 0;
        $classes = DocumentCatalog::CLASSES;
        foreach ($modules as $m) {
            foreach ((array) ($m['document_classes'] ?? []) as $code => $def) {
                if (str_starts_with((string) $code, $m['code'] . '.') && is_array($def) && count($def) === 8) {
                    $classes[(string) $code] = array_values($def);
                }
            }
        }
        foreach ($classes as $code => [$cls, $view, $manage, $types, $mb, $enc, $record, $trigger]) {
            $n += $db->run(
                'INSERT IGNORE INTO document_classes (code, module_code, classification, view_permission, manage_permission, allowed_types, max_mb,
                    encrypt_at_rest, record_class, retention_trigger, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$code, explode('.', $code)[0], $cls, $view, $manage, implode(',', $types), $mb, (int) $enc, $record, $trigger, $clock->now()->format('Y-m-d H:i:s.u')]
            )->rowCount();
        }
        return $n;
    }

    // ---- classes ---------------------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public function classes(): array
    {
        if ($this->classes === null) {
            $this->classes = [];
            foreach ($this->db->all("SELECT * FROM document_classes WHERE status = 'active' ORDER BY code") as $c) {
                $c['types'] = explode(',', (string) $c['allowed_types']);
                $this->classes[(string) $c['code']] = $c;
            }
        }
        return $this->classes;
    }

    /** @return array<string, mixed> */
    public function classDef(string $code): array
    {
        return $this->classes()[$code] ?? throw new BusinessException('document.error.class', [], 422);
    }

    /**
     * Classes the user may upload into (manage permission in some scope).
     *
     * @return array<string, array<string, mixed>>
     */
    public function uploadableClasses(Authorizer $az): array
    {
        $generatedOnly = ['core.brand_asset', 'core.letter', 'core.payment_file']; // created by their own services, never uploaded by hand
        return array_filter($this->classes(), static fn (array $c): bool => !in_array($c['code'], $generatedOnly, true) && $az->can((string) $c['manage_permission']));
    }

    // ---- capture ---------------------------------------------------------------------------------

    /**
     * Upload a new document (or a new version of a DRAFT document when $documentPid is given).
     *
     * @param array{title?: string, description?: string, legal_entity_id?: ?int, site_id?: ?int, related_type?: ?string, related_id?: ?string,
     *              subject_user_id?: ?int, document_number?: ?string, language?: ?string} $meta
     * @return array{document: string, version: int, duplicates: list<array{public_id: string, title: string}>}
     */
    public function upload(Authorizer $az, string $classCode, UploadedFile $file, array $meta, ?string $documentPid = null, string $changeNote = ''): array
    {
        $doc = null;
        $class = $documentPid === null ? $this->classDef($classCode) : [];
        if ($documentPid !== null) {
            $doc = $this->row($documentPid);
            $classCode = (string) $doc['document_class'];
            $class = $this->classDef($classCode);
            $meta['legal_entity_id'] = $doc['legal_entity_id'] !== null ? (int) $doc['legal_entity_id'] : null;
            $meta['site_id'] = $doc['site_id'] !== null ? (int) $doc['site_id'] : null;
            if (!$this->inScope($az->scope((string) $class['manage_permission']), $doc)) {
                throw HttpException::notFound();
            }
            if ($doc['lifecycle_status'] !== 'draft') {
                throw new BusinessException('document.error.final_immutable', [], 409);
            }
        }
        $le = isset($meta['legal_entity_id']) ? (int) $meta['legal_entity_id'] : null;
        $site = isset($meta['site_id']) ? (int) $meta['site_id'] : null;
        if ($doc === null) {
            $this->requireManage($az, $class, $le, $site);
        }
        $errors = [];
        $title = trim((string) ($meta['title'] ?? ''));
        if ($doc === null && ($title === '' || mb_strlen($title) > 200)) {
            $errors['title'] = 'document.error.title';
        }
        if (!$file->ok()) {
            $errors['file'] = $file->errorKey();
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $bytes = $file->bytes();
        $check = $this->checkContent($class, $bytes, $file->safeName());
        if ($doc !== null) {
            $current = $this->db->scalar('SELECT sha256 FROM document_versions WHERE document_id = ? AND version_no = ?', [$doc['id'], $doc['current_version_no']]);
            if ($current === $check['sha256']) {
                throw new ValidationException(['file' => 'document.error.same_as_current']);
            }
        }
        $result = $this->store($az->userId, $class, $bytes, $check, $file->safeName(), $meta + ['title' => $title], $doc, $changeNote, 'upload');
        $result['duplicates'] = $this->duplicates($az, $check['sha256'], $result['document'], $le);
        return $result;
    }

    /**
     * Server-side producers (template issue, brand assets, system exports) — the caller has already checked permission.
     *
     * @param array<string, mixed> $meta
     * @return array{document: string, version: int}
     */
    public function storeGenerated(int $actorId, string $classCode, string $bytes, string $filename, array $meta, string $channel, bool $final, ?int $templateVersionId = null): array
    {
        $class = $this->classDef($classCode);
        $check = $this->checkContent($class, $bytes, $filename);
        $out = $this->store($actorId, $class, $bytes, $check, $filename, $meta, null, '', $channel, $templateVersionId);
        if ($final) {
            $this->markFinal((int) $this->db->scalar('SELECT id FROM documents WHERE public_id = ?', [$out['document']]), $actorId);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $class
     * @return array{type: string, mime: string, ext: string, sha256: string, size: int, pages: ?int}
     */
    private function checkContent(array $class, string $bytes, string $name): array
    {
        $size = strlen($bytes);
        $maxMb = (int) $class['max_mb'];
        $cfg = $this->registry?->resolveOrNull('documents.max_upload_mb');
        if ($cfg !== null && is_int($cfg->value) && $class['code'] !== 'core.brand_asset') {
            $maxMb = $cfg->value;
        }
        if ($size > $maxMb * 1024 * 1024) {
            throw new ValidationException(['file' => 'file.error.too_large']);
        }
        $sniff = FileType::sniff($bytes, $name);
        if ($sniff['problem'] !== null) {
            throw new ValidationException(['file' => $sniff['problem']]);
        }
        if ($sniff['type'] === null || !in_array($sniff['type'], (array) $class['types'], true)) {
            throw new ValidationException(['file' => 'file.error.type_not_allowed']);
        }
        // the name claims a binary format the bytes are not (e.g. a text file called .pdf) → refuse, do not guess
        $claimed = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $family = ['pdf' => 'pdf', 'png' => 'png', 'jpg' => 'jpeg', 'jpeg' => 'jpeg', 'gif' => 'gif', 'webp' => 'webp', 'ico' => 'ico', 'xlsx' => 'xlsx', 'docx' => 'docx', 'pptx' => 'pptx'];
        if (isset($family[$claimed]) && $family[$claimed] !== $sniff['type']) {
            throw new ValidationException(['file' => 'file.error.extension_mismatch']);
        }
        $pages = null;
        if ($sniff['type'] === 'pdf') {
            $info = PdfInspector::inspect($bytes);
            if ($info['encrypted']) {
                throw new ValidationException(['file' => 'file.error.pdf_encrypted']);
            }
            $maxPages = $this->registry?->resolveOrNull('documents.max_pdf_pages');
            $limit = $maxPages !== null && is_int($maxPages->value) ? $maxPages->value : 1000;
            if ($info['pages'] !== null && $info['pages'] > $limit) {
                throw new ValidationException(['file' => 'file.error.too_many_pages']);
            }
            $pages = $info['pages'];
        }
        if (in_array($sniff['type'], ['png', 'jpeg', 'gif', 'webp'], true)) {
            $img = @getimagesizefromstring($bytes);
            if ($img === false || $img[0] < 1 || $img[1] < 1 || $img[0] * $img[1] > 40_000_000) {
                throw new ValidationException(['file' => 'file.error.image']); // broken image or a pixel bomb
            }
        }
        return ['type' => (string) $sniff['type'], 'mime' => (string) $sniff['mime'], 'ext' => (string) $sniff['ext'], 'sha256' => hash('sha256', $bytes), 'size' => $size, 'pages' => $pages];
    }

    /**
     * @param array<string, mixed> $class
     * @param array{type: string, mime: string, ext: string, sha256: string, size: int, pages: ?int} $check
     * @param array<string, mixed> $meta
     * @param array<string, mixed>|null $doc
     * @return array{document: string, version: int}
     */
    private function store(int $actorId, array $class, string $bytes, array $check, string $filename, array $meta, ?array $doc, string $note, string $channel, ?int $templateVersionId = null): array
    {
        $encrypt = (int) $class['encrypt_at_rest'] === 1;
        $key = $this->storage->put($bytes, $encrypt);
        try {
            return $this->db->transactional(function () use ($actorId, $class, $check, $filename, $meta, $doc, $note, $channel, $templateVersionId, $key, $encrypt): array {
                $now = $this->now();
                if ($doc === null) {
                    $pid = Ulid::generate($this->clock);
                    $this->db->run(
                        'INSERT INTO documents (public_id, created_at, created_by, updated_at, updated_by, document_class, title, description, legal_entity_id, site_id,
                            related_type, related_id, subject_user_id, document_number, classification, lifecycle_status, current_version_no, language, source_channel,
                            template_version_id, supersedes_document_id, is_demo)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'draft\', 0, ?, ?, ?, ?, ?)',
                        [$pid, $now, $actorId, $now, $actorId, $class['code'], mb_substr((string) $meta['title'], 0, 200), self::nullable($meta['description'] ?? null, 500),
                            $meta['legal_entity_id'] ?? null, $meta['site_id'] ?? null, self::nullable($meta['related_type'] ?? null, 40), self::nullable($meta['related_id'] ?? null, 40),
                            $meta['subject_user_id'] ?? null, self::nullable($meta['document_number'] ?? null, 60), $class['classification'], self::nullable($meta['language'] ?? null, 10),
                            $channel, $templateVersionId, $meta['supersedes_document_id'] ?? null, (int) ($meta['is_demo'] ?? 0)]
                    );
                    $doc = $this->row($pid);
                }
                $no = (int) $this->db->scalar('SELECT COALESCE(MAX(version_no), 0) + 1 FROM document_versions WHERE document_id = ? FOR UPDATE', [$doc['id']]);
                $this->db->run(
                    'INSERT INTO document_versions (public_id, document_id, version_no, storage_key, original_filename, mime_type, file_ext, size_bytes, sha256, page_count,
                        encrypted, change_note, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [Ulid::generate($this->clock), $doc['id'], $no, $key, mb_substr($filename, -255), $check['mime'], $check['ext'], $check['size'], $check['sha256'],
                        $check['pages'], (int) $encrypt, self::nullable($note, 500), $now, $actorId]
                );
                $this->db->run('UPDATE documents SET current_version_no = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$no, $now, $actorId, $doc['id']]);
                $this->audit->record(
                    'document.uploaded',
                    'success',
                    'document',
                    (string) $doc['public_id'],
                    $doc['legal_entity_id'] !== null ? (int) $doc['legal_entity_id'] : null,
                    null,
                    ['class' => $class['code'], 'version' => $no, 'sha256' => $check['sha256'], 'size' => $check['size'], 'type' => $check['type'], 'channel' => $channel],
                    $actorId
                );
                return ['document' => (string) $doc['public_id'], 'version' => $no];
            });
        } catch (\Throwable $e) {
            $this->storage->delete($key); // nothing points at the file — do not leave it behind
            throw $e;
        }
    }

    /** @return list<array{public_id: string, title: string}> same bytes already stored elsewhere (only ones the user may see) */
    private function duplicates(Authorizer $az, string $sha, string $exceptPid, ?int $le): array
    {
        $rows = $this->db->all(
            "SELECT DISTINCT d.* FROM document_versions v JOIN documents d ON d.id = v.document_id
              WHERE v.sha256 = ? AND d.public_id <> ? AND d.lifecycle_status <> 'disposed' AND d.legal_entity_id <=> ? LIMIT 20",
            [$sha, $exceptPid, $le]
        );
        $out = [];
        foreach ($rows as $d) {
            if ($this->canView($az, $d)) {
                $out[] = ['public_id' => (string) $d['public_id'], 'title' => (string) $d['title']];
            }
        }
        return $out;
    }

    // ---- lifecycle -------------------------------------------------------------------------------

    public function finalize(Authorizer $az, string $pid): void
    {
        $doc = $this->row($pid);
        $class = $this->classDef((string) $doc['document_class']);
        if (!$this->inScope($az->scope((string) $class['manage_permission']), $doc)) {
            throw HttpException::notFound();
        }
        if ($doc['lifecycle_status'] !== 'draft' || (int) $doc['current_version_no'] === 0) {
            throw new BusinessException('document.error.not_draft', [], 409);
        }
        $this->markFinal((int) $doc['id'], $az->userId);
    }

    private function markFinal(int $docId, int $actorId): void
    {
        $now = $this->now();
        $doc = $this->db->one('SELECT d.*, c.retention_trigger FROM documents d JOIN document_classes c ON c.code = d.document_class WHERE d.id = ?', [$docId]) ?? throw HttpException::notFound();
        $trigger = $doc['retention_trigger'] === 'finalized' ? $this->clock->now()->format('Y-m-d') : null;
        $this->db->run(
            "UPDATE documents SET lifecycle_status = 'final', finalized_at = ?, finalized_by = ?, retention_trigger_date = COALESCE(retention_trigger_date, ?),
                updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND lifecycle_status = 'draft'",
            [$now, $actorId, $trigger, $now, $actorId, $docId]
        );
        $this->applySchedule($docId);
        $this->audit->record('document.finalized', 'success', 'document', (string) $doc['public_id'], $doc['legal_entity_id'] !== null ? (int) $doc['legal_entity_id'] : null, null, [], $actorId);
    }

    /**
     * Final documents are never changed: a correction is a NEW document that supersedes the old one (6.17.2 step 9).
     *
     * @return array{document: string, version: int, duplicates: list<array{public_id: string, title: string}>}
     */
    public function supersede(Authorizer $az, string $oldPid, UploadedFile $file, string $reason): array
    {
        $old = $this->row($oldPid);
        $class = $this->classDef((string) $old['document_class']);
        if (!$this->inScope($az->scope((string) $class['manage_permission']), $old)) {
            throw HttpException::notFound();
        }
        if ($old['lifecycle_status'] !== 'final') {
            throw new BusinessException('document.error.not_final', [], 409);
        }
        if ($old['document_class'] === 'core.payment_file') {
            throw new BusinessException('document.error.generated_only', [], 409); // replaced only through the payment batch (new file, old one superseded there)
        }
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        if ($this->activeHolds($old) !== []) {
            // a held record may still be superseded (the old one is kept), but make the reason visible
            $reason .= ' [legal hold active]';
        }
        $out = $this->upload($az, (string) $old['document_class'], $file, [
            'title' => (string) $old['title'], 'description' => $old['description'], 'legal_entity_id' => $old['legal_entity_id'] !== null ? (int) $old['legal_entity_id'] : null,
            'site_id' => $old['site_id'] !== null ? (int) $old['site_id'] : null, 'related_type' => $old['related_type'], 'related_id' => $old['related_id'],
            'subject_user_id' => $old['subject_user_id'] !== null ? (int) $old['subject_user_id'] : null, 'document_number' => $old['document_number'],
            'supersedes_document_id' => (int) $old['id'],
        ], null, $reason);
        $this->finalize($az, $out['document']);
        $now = $this->now();
        $this->db->run("UPDATE documents SET lifecycle_status = 'superseded', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND lifecycle_status = 'final'", [$now, $az->userId, $old['id']]);
        $this->audit->record('document.superseded', 'success', 'document', $oldPid, $old['legal_entity_id'] !== null ? (int) $old['legal_entity_id'] : null, $reason, ['by' => $out['document']]);
        return $out;
    }

    // ---- read / download -------------------------------------------------------------------------

    /** @return array<string, mixed> document row with class, versions, holds and what the user may do */
    public function get(Authorizer $az, string $pid): array
    {
        $doc = $this->row($pid);
        if (!$this->canView($az, $doc)) {
            $this->audit->record('authz.denied', 'denied', 'document', $pid, null, 'document.view');
            throw HttpException::notFound();
        }
        $class = $this->classDef((string) $doc['document_class']);
        $manage = $this->inScope($az->scope((string) $class['manage_permission']), $doc);
        $doc['class'] = $class;
        $doc['versions'] = $this->db->all('SELECT v.*, u.display_name AS created_by_name FROM document_versions v LEFT JOIN users u ON u.id = v.created_by WHERE v.document_id = ? ORDER BY v.version_no DESC', [$doc['id']]);
        $doc['holds'] = $this->activeHolds($doc);
        $doc['supersedes'] = $doc['supersedes_document_id'] !== null ? $this->db->one('SELECT public_id, title FROM documents WHERE id = ?', [$doc['supersedes_document_id']]) : null;
        $doc['superseded_by'] = $this->db->one('SELECT public_id, title FROM documents WHERE supersedes_document_id = ? ORDER BY id DESC LIMIT 1', [$doc['id']]);
        $doc['can'] = [
            'manage' => $manage && $class['code'] !== 'core.brand_asset',
            'new_version' => $manage && $doc['lifecycle_status'] === 'draft' && $doc['source_channel'] === 'upload',
            'finalize' => $manage && $doc['lifecycle_status'] === 'draft' && (int) $doc['current_version_no'] > 0,
            'supersede' => $manage && $doc['lifecycle_status'] === 'final' && $doc['source_channel'] === 'upload',
            'hold' => $az->can('core.records.manage') && $this->inScope($az->scope('core.records.manage'), $doc),
        ];
        return $doc;
    }

    /**
     * Returns the bytes after checking access, lifecycle and integrity. Audited.
     *
     * @return array{bytes: string, mime: string, filename: string, classification: string, ext: string}
     */
    public function download(Authorizer $az, string $pid, ?int $versionNo = null, string $purpose = 'download'): array
    {
        $doc = $this->row($pid);
        if (!$this->canView($az, $doc)) {
            $this->audit->record('authz.denied', 'denied', 'document', $pid, null, 'document.download');
            throw HttpException::notFound();
        }
        return $this->readVersion($doc, $versionNo, $purpose, $az->userId);
    }

    /**
     * A module reads a file of ITS OWN document class after doing its own permission check (e.g. M30 lets every
     * employee download published company fonts that are stored organization-wide). Integrity-checked and audited.
     *
     * @return array{bytes: string, mime: string, filename: string, classification: string, ext: string}
     */
    public function moduleRead(string $pid, string $moduleCode, int $actorId, string $purpose = 'download', bool $audit = true): array
    {
        $doc = $this->row($pid);
        if (!str_starts_with((string) $doc['document_class'], $moduleCode . '.') || $moduleCode === 'core') {
            throw HttpException::notFound();
        }
        return $this->readVersion($doc, null, $purpose, $actorId, $audit);
    }

    /**
     * Public brand assets only (logo/favicon on the sign-in page): no session, but only class core.brand_asset.
     *
     * @return array{bytes: string, mime: string, filename: string, classification: string, ext: string}|null
     */
    public function publicAsset(string $pid): ?array
    {
        $doc = $this->db->one("SELECT * FROM documents WHERE public_id = ? AND document_class = 'core.brand_asset' AND classification = 'P' AND lifecycle_status = 'final'", [$pid]);
        if ($doc === null) {
            return null;
        }
        try {
            return $this->readVersion($doc, null, 'public', null, false);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $doc
     * @return array{bytes: string, mime: string, filename: string, classification: string, ext: string}
     */
    private function readVersion(array $doc, ?int $versionNo, string $purpose, ?int $actorId, bool $audit = true): array
    {
        if ($doc['lifecycle_status'] === 'disposed') {
            throw new BusinessException('document.error.disposed', [], 410);
        }
        $no = $versionNo ?? (int) $doc['current_version_no'];
        $v = $this->db->one('SELECT * FROM document_versions WHERE document_id = ? AND version_no = ?', [$doc['id'], $no]) ?? throw HttpException::notFound();
        if ($v['storage_key'] === null || $v['purged_at'] !== null) {
            throw new BusinessException('document.error.disposed', [], 410);
        }
        $bytes = $this->storage->get((string) $v['storage_key'], (int) $v['encrypted'] === 1);
        $le = $doc['legal_entity_id'] !== null ? (int) $doc['legal_entity_id'] : null;
        if ($bytes === null || !hash_equals((string) $v['sha256'], hash('sha256', $bytes))) {
            $this->audit->record('document.integrity_failed', 'failure', 'document', (string) $doc['public_id'], $le, 'version ' . $no, [], $actorId);
            throw new BusinessException('document.error.integrity', [], 500);
        }
        if ($audit) {
            $this->audit->record('document.' . ($purpose === 'print' ? 'printed' : 'downloaded'), 'success', 'document', (string) $doc['public_id'], $le, null, ['version' => $no], $actorId);
        }
        return ['bytes' => $bytes, 'mime' => (string) $v['mime_type'], 'filename' => (string) $v['original_filename'], 'classification' => (string) $doc['classification'], 'ext' => (string) $v['file_ext']];
    }

    /**
     * Re-hashes every stored version (TC-REC "hash"): ok | missing | mismatch | purged.
     *
     * @return list<array{version: int, result: string}>
     */
    public function verify(Authorizer $az, string $pid): array
    {
        $doc = $this->get($az, $pid);
        $out = [];
        foreach (array_reverse($doc['versions']) as $v) {
            if ($v['purged_at'] !== null || $v['storage_key'] === null) {
                $out[] = ['version' => (int) $v['version_no'], 'result' => 'purged'];
                continue;
            }
            try {
                $bytes = $this->storage->get((string) $v['storage_key'], (int) $v['encrypted'] === 1);
            } catch (\Throwable) {
                $bytes = null;
            }
            $out[] = ['version' => (int) $v['version_no'], 'result' => $bytes === null ? 'missing' : (hash_equals((string) $v['sha256'], hash('sha256', $bytes)) ? 'ok' : 'mismatch')];
        }
        $bad = array_filter($out, static fn (array $r): bool => in_array($r['result'], ['missing', 'mismatch'], true));
        $this->audit->record('document.verified', $bad === [] ? 'success' : 'failure', 'document', $pid, $doc['legal_entity_id'] !== null ? (int) $doc['legal_entity_id'] : null, null, ['results' => $out]);
        return $out;
    }

    /**
     * Documents the user may see (class view/manage permission ∩ scope), newest first.
     *
     * @param array{q?: string, class?: string, status?: string, legal_entity?: int} $f
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function list(Authorizer $az, array $f, int $page, int $per): array
    {
        [$where, $params] = $this->visibleSql($az);
        $where = "({$where}) AND d.document_class <> 'core.brand_asset'";
        if (($f['q'] ?? '') !== '') {
            $where .= ' AND (d.title LIKE ? OR d.document_number LIKE ?)';
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            array_push($params, $like, $like);
        }
        if (($f['class'] ?? '') !== '') {
            $where .= ' AND d.document_class = ?';
            $params[] = $f['class'];
        }
        if (($f['status'] ?? '') !== '') {
            $where .= ' AND d.lifecycle_status = ?';
            $params[] = $f['status'];
        }
        if (!empty($f['legal_entity'])) {
            $where .= ' AND d.legal_entity_id = ?';
            $params[] = (int) $f['legal_entity'];
        }
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM documents d WHERE {$where}", $params);
        $rows = $this->db->all(
            "SELECT d.*, l.code AS le_code, v.original_filename, v.size_bytes, v.file_ext
               FROM documents d LEFT JOIN legal_entities l ON l.id = d.legal_entity_id
               LEFT JOIN document_versions v ON v.document_id = d.id AND v.version_no = d.current_version_no
              WHERE {$where} ORDER BY d.id DESC LIMIT " . max(1, $per) . ' OFFSET ' . max(0, ($page - 1) * $per),
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array{0: string, 1: list<int|string>} SQL over documents d that the user may view */
    public function visibleSql(Authorizer $az): array
    {
        $parts = [];
        $params = [];
        foreach ($this->classes() as $code => $c) {
            if ((string) $c['module_code'] !== 'core') {
                continue; // module documents (e.g. draft or restricted fonts) never appear in the central list/search
            }
            foreach ([(string) $c['view_permission'], (string) $c['manage_permission']] as $perm) {
                $scope = $az->scope($perm);
                if ($scope->isEmpty()) {
                    continue;
                }
                [$sql, $p] = $scope->sql('d.legal_entity_id', 'd.site_id');
                $parts[] = "(d.document_class = ? AND {$sql})";
                array_push($params, $code, ...$p);
            }
        }
        return $parts === [] ? ['1=0', []] : ['(' . implode(' OR ', $parts) . ')', $params];
    }

    // ---- retention & holds -----------------------------------------------------------------------

    /** Modules call this when the event that starts the retention clock happens (contract ended, case closed …). */
    public function setRetentionTrigger(string $pid, DateTimeImmutable $date, int $actorId): void
    {
        $doc = $this->row($pid);
        $this->db->run('UPDATE documents SET retention_trigger_date = ?, updated_at = ?, updated_by = ? WHERE id = ?', [$date->format('Y-m-d'), $this->now(), $actorId, $doc['id']]);
        $this->applySchedule((int) $doc['id']);
    }

    /** Scheduled disposition date = trigger date + approved rule duration; no approved rule → no date (never guessed). */
    public function applySchedule(int $docId): void
    {
        $d = $this->db->one(
            "SELECT d.id, d.retention_trigger_date, d.lifecycle_status, c.record_class FROM documents d JOIN document_classes c ON c.code = d.document_class WHERE d.id = ?",
            [$docId]
        );
        if ($d === null || $d['lifecycle_status'] === 'disposed') {
            return;
        }
        $rule = $d['record_class'] !== null ? $this->db->one(
            "SELECT id, duration_months FROM retention_rules WHERE record_class = ? AND status = 'approved' AND effective_from <= ? ORDER BY version_no DESC LIMIT 1",
            [$d['record_class'], $this->clock->now()->format('Y-m-d')]
        ) : null;
        $date = null;
        if ($rule !== null && $rule['duration_months'] !== null && $d['retention_trigger_date'] !== null) {
            $date = (new DateTimeImmutable((string) $d['retention_trigger_date']))->modify('+' . (int) $rule['duration_months'] . ' months')->format('Y-m-d');
        }
        $this->db->run('UPDATE documents SET scheduled_disposition_date = ?, retention_rule_id = ? WHERE id = ?', [$date, $rule['id'] ?? null, $docId]);
    }

    /** Recomputes every open document's schedule (after a rule is approved). Returns the number of documents touched. */
    public function recomputeSchedules(?string $recordClass = null): int
    {
        $sql = "SELECT d.id FROM documents d JOIN document_classes c ON c.code = d.document_class WHERE d.lifecycle_status <> 'disposed'";
        $params = [];
        if ($recordClass !== null) {
            $sql .= ' AND c.record_class = ?';
            $params[] = $recordClass;
        }
        $ids = $this->db->all($sql, $params);
        foreach ($ids as $r) {
            $this->applySchedule((int) $r['id']);
        }
        return count($ids);
    }

    /**
     * Active (or release-requested) holds that cover a document: on the document, on its related record, or on its record class.
     *
     * @param array<string, mixed> $doc
     * @return list<array<string, mixed>>
     */
    public function activeHolds(array $doc): array
    {
        $recordClass = $this->db->scalar('SELECT record_class FROM document_classes WHERE code = ?', [$doc['document_class']]);
        return $this->db->all(
            "SELECT * FROM legal_holds WHERE status IN ('active','release_requested') AND (
                (scope_type = 'document' AND document_id = ?)
             OR (scope_type = 'related' AND related_type = ? AND related_id = ?)
             OR (scope_type = 'record_class' AND record_class = ? AND (legal_entity_id IS NULL OR legal_entity_id <=> ?)))",
            [$doc['id'], $doc['related_type'], $doc['related_id'], $recordClass, $doc['legal_entity_id']]
        );
    }

    /** Physical deletion of every version (disposition execution only). */
    public function purge(int $docId, int $actorId): int
    {
        $n = 0;
        foreach ($this->db->all('SELECT id, storage_key FROM document_versions WHERE document_id = ? AND purged_at IS NULL', [$docId]) as $v) {
            if ($v['storage_key'] !== null) {
                $this->storage->delete((string) $v['storage_key']);
            }
            $this->db->run('UPDATE document_versions SET storage_key = NULL, purged_at = ? WHERE id = ?', [$this->now(), $v['id']]);
            $n++;
        }
        // keep only what the certificate needs: class, number, dates — not the title / description / person
        $this->db->run(
            "UPDATE documents SET lifecycle_status = 'disposed', disposition_status = 'disposed', disposed_at = ?, title = '—', description = NULL, subject_user_id = NULL,
                updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?",
            [$this->now(), $this->now(), $actorId, $docId]
        );
        return $n;
    }

    // ---- helpers ---------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function row(string $pid): array
    {
        return $this->db->one('SELECT * FROM documents WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /** @param array<string, mixed> $doc */
    public function canView(Authorizer $az, array $doc): bool
    {
        $class = $this->classes()[(string) $doc['document_class']] ?? null;
        if ($class === null || (string) $class['module_code'] !== 'core') {
            return false; // a module's documents are served only through that module (its own visibility rules — moduleRead)
        }
        return $this->inScope($az->scope((string) $class['view_permission']), $doc) || $this->inScope($az->scope((string) $class['manage_permission']), $doc);
    }

    /** @param array<string, mixed> $doc */
    public function inScope(ScopeSet $s, array $doc): bool
    {
        if ($s->all) {
            return true;
        }
        if ($doc['legal_entity_id'] === null) {
            return false;
        }
        return in_array((int) $doc['legal_entity_id'], $s->legalEntityIds, true)
            || ($doc['site_id'] !== null && in_array((int) $doc['site_id'], $s->siteIds, true));
    }

    /** @param array<string, mixed> $class */
    private function requireManage(Authorizer $az, array $class, ?int $le, ?int $site): void
    {
        $scope = $az->scope((string) $class['manage_permission']);
        $ok = $scope->all || ($le !== null && (in_array($le, $scope->legalEntityIds, true) || ($site !== null && in_array($site, $scope->siteIds, true))));
        if (!$ok) {
            $this->audit->record('authz.denied', 'denied', 'document_class', (string) $class['code'], $le, (string) $class['manage_permission']);
            throw HttpException::forbidden();
        }
    }

    private static function nullable(mixed $v, int $max): ?string
    {
        $s = is_scalar($v) ? trim((string) $v) : '';
        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}

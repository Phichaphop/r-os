<?php

declare(strict_types=1);

namespace Blue\Core\Records;

use DateTimeZone;
use Blue\Core\Audit\AuditLogger;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Database\Connection;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\I18n\DateFormat;
use Blue\Core\Numbering\NumberingService;
use Blue\Core\Org\Translations;
use Blue\Core\Registry\ConfigRegistry;
use Blue\Core\Support\Clock;
use Blue\Core\Support\HtmlSanitizer;
use Blue\Core\Support\LineDiff;
use Blue\Core\Support\Ulid;

/**
 * Document templates with versions (03 §6.15) — the central helper every module uses to issue letters/contracts.
 *
 * - editing a template = editing its DRAFT version; publishing freezes that version (published versions are never
 *   edited); new documents always use the latest published version
 * - issuing a document renders the published version with the values given, adds the company header/footer and the
 *   document font (DEC-28: per legal entity in the Config Registry, Country Pack TH default "TH Sarabun New"), and
 *   stores the complete page as a FINAL document — later template or company changes never alter it (freeze)
 * - placeholder values are HTML-escaped; template bodies pass an allow-list sanitizer
 */
final class TemplateService
{
    /** Country Pack TH default document font (DEC-18, DEC-28) and its fallbacks. */
    public const DEFAULT_FONT = 'TH Sarabun New';
    public const FONT_FALLBACK = "'TH Sarabun New', 'TH SarabunPSK', 'Sarabun', Tahoma, sans-serif";

    public const SYSTEM_FIELDS = ['company.name', 'company.name_en', 'company.registration_no', 'site.name', 'site.branch_no', 'doc.number', 'doc.date', 'doc.date_long', 'issuer.name'];
    public const FIELD_TYPES = ['text', 'multiline', 'date', 'number'];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly DocumentService $documents,
        private readonly NumberingService $numbering,
        private readonly ?ConfigRegistry $registry,
        private readonly string $timezone = 'Asia/Bangkok',
    ) {
    }

    // ---- read --------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->db->all(
            "SELECT t.*, l.code AS le_code,
                    (SELECT MAX(version_no) FROM document_template_versions v WHERE v.template_id = t.id AND v.status = 'published') AS published_no,
                    (SELECT COUNT(*) FROM document_template_versions v WHERE v.template_id = t.id AND v.status = 'draft') AS has_draft,
                    (SELECT COUNT(*) FROM documents d JOIN document_template_versions v ON v.id = d.template_version_id WHERE v.template_id = t.id) AS issued
               FROM document_templates t LEFT JOIN legal_entities l ON l.id = t.legal_entity_id WHERE t.status = 'active' ORDER BY t.code"
        );
    }

    /** @return array<string, mixed> template with versions */
    public function get(string $pid): array
    {
        $t = $this->db->one('SELECT t.*, l.code AS le_code FROM document_templates t LEFT JOIN legal_entities l ON l.id = t.legal_entity_id WHERE t.public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $t['versions'] = $this->db->all(
            'SELECT v.*, c.display_name AS created_name, p.display_name AS published_name FROM document_template_versions v
               LEFT JOIN users c ON c.id = v.created_by LEFT JOIN users p ON p.id = v.published_by WHERE v.template_id = ? ORDER BY v.version_no DESC',
            [$t['id']]
        );
        $t['draft'] = null;
        $t['published'] = null;
        foreach ($t['versions'] as $v) {
            if ($v['status'] === 'draft' && $t['draft'] === null) {
                $t['draft'] = $v;
            }
            if ($v['status'] === 'published' && $t['published'] === null) {
                $t['published'] = $v;
            }
        }
        return $t;
    }

    /** @return array<string, mixed> */
    public function version(string $pid): array
    {
        return $this->db->one('SELECT v.*, t.code, t.public_id AS template_public_id, t.name AS template_name FROM document_template_versions v JOIN document_templates t ON t.id = v.template_id WHERE v.public_id = ?', [$pid])
            ?? throw HttpException::notFound();
    }

    /** @return list<array{0: string, 1: string}> */
    public function compare(string $fromPid, string $toPid): array
    {
        $a = $this->version($fromPid);
        $b = $this->version($toPid);
        if ($a['template_id'] !== $b['template_id']) {
            throw HttpException::notFound();
        }
        return LineDiff::compare((string) $a['body_html'], (string) $b['body_html']);
    }

    // ---- edit --------------------------------------------------------------------------------------

    /** @param array{code: string, name: string, document_class: string, locale: string, number_pattern: string, legal_entity_id: ?int} $in */
    public function create(Authorizer $az, array $in): string
    {
        $this->requireManage($az);
        $errors = [];
        if (!preg_match('/^[A-Z][A-Z0-9_]{1,39}$/', $in['code'])) {
            $errors['code'] = 'template.error.code';
        } elseif ($this->db->scalar('SELECT id FROM document_templates WHERE code = ?', [$in['code']]) !== null) {
            $errors['code'] = 'template.error.code_taken';
        }
        if (trim($in['name']) === '') {
            $errors['name'] = 'template.error.name';
        }
        if (!in_array($in['document_class'], ['core.letter', 'core.confidential', 'core.general'], true)) {
            $errors['document_class'] = 'document.error.class';
        }
        if ($in['number_pattern'] !== '' && !preg_match('/\{SEQ:([1-9]|1[0-2])\}/', $in['number_pattern'])) {
            $errors['number_pattern'] = 'template.error.pattern';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $pid = Ulid::generate($this->clock);
        $now = $this->now();
        $this->db->run(
            'INSERT INTO document_templates (public_id, created_at, created_by, updated_at, updated_by, code, document_class, legal_entity_id, name, locale, number_pattern)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $now, $az->userId, $now, $az->userId, $in['code'], $in['document_class'], $in['legal_entity_id'], mb_substr(trim($in['name']), 0, 150),
                in_array($in['locale'], ['th', 'en'], true) ? $in['locale'] : 'th', $in['number_pattern'] !== '' ? $in['number_pattern'] : null]
        );
        $this->audit->record('template.created', 'success', 'document_template', $pid, $in['legal_entity_id'], null, ['code' => $in['code']]);
        return $pid;
    }

    /**
     * Saves the draft version (creates it when there is none). Published versions are never touched.
     *
     * @param array{title: string, body: string, fields: string, font_family: string, show_header: bool, show_footer: bool, reason: string} $in
     */
    public function saveDraft(Authorizer $az, string $templatePid, array $in): string
    {
        $this->requireManage($az);
        $t = $this->get($templatePid);
        $body = HtmlSanitizer::clean($in['body']);
        [$fields, $fieldErrors] = self::parseFields($in['fields']);
        $errors = $fieldErrors;
        if (trim($in['title']) === '') {
            $errors['title'] = 'template.error.title';
        }
        if ($body === '') {
            $errors['body'] = 'template.error.body';
        }
        $unknown = array_diff(self::placeholders($body), array_merge(self::SYSTEM_FIELDS, array_column($fields, 'key')));
        if ($unknown !== []) {
            $errors['body'] = 'template.error.unknown_placeholders';
        }
        if ($in['font_family'] !== '' && !preg_match('/^[\p{L}\p{N} ._-]{2,80}$/u', $in['font_family'])) {
            $errors['font_family'] = 'template.error.font';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $now = $this->now();
        $values = [mb_substr(trim($in['title']), 0, 200), $body, json_encode($fields, JSON_UNESCAPED_UNICODE), $in['font_family'] !== '' ? $in['font_family'] : null,
            (int) $in['show_header'], (int) $in['show_footer'], hash('sha256', $body), mb_substr(trim($in['reason']), 0, 500) ?: null, $now];
        if ($t['draft'] !== null) {
            $this->db->run(
                "UPDATE document_template_versions SET title = ?, body_html = ?, fields_json = ?, font_family = ?, show_header = ?, show_footer = ?, body_sha256 = ?, change_reason = ?, updated_at = ?
                  WHERE id = ? AND status = 'draft'",
                [...$values, $t['draft']['id']]
            );
            $pid = (string) $t['draft']['public_id'];
        } else {
            $pid = Ulid::generate($this->clock);
            $no = (int) $this->db->scalar('SELECT COALESCE(MAX(version_no), 0) + 1 FROM document_template_versions WHERE template_id = ?', [$t['id']]);
            $this->db->run(
                "INSERT INTO document_template_versions (title, body_html, fields_json, font_family, show_header, show_footer, body_sha256, change_reason, updated_at,
                    public_id, template_id, version_no, status, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)",
                [...$values, $pid, $t['id'], $no, $now, $az->userId]
            );
        }
        $this->audit->record('template.draft_saved', 'success', 'document_template', $templatePid, $t['legal_entity_id'] !== null ? (int) $t['legal_entity_id'] : null, null, ['version' => $pid]);
        return $pid;
    }

    /** Publishes the draft: it becomes the version new documents use; the previous published version is superseded. */
    public function publish(Authorizer $az, string $templatePid, string $reason): int
    {
        $this->requireManage($az);
        $t = $this->get($templatePid);
        if ($t['draft'] === null) {
            throw new BusinessException('template.error.no_draft', [], 409);
        }
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        $now = $this->now();
        $this->db->transactional(function () use ($t, $az, $reason, $now): void {
            $this->db->run("UPDATE document_template_versions SET status = 'superseded', updated_at = ? WHERE template_id = ? AND status = 'published'", [$now, $t['id']]);
            $this->db->run(
                "UPDATE document_template_versions SET status = 'published', published_at = ?, published_by = ?, change_reason = ?, updated_at = ? WHERE id = ? AND status = 'draft'",
                [$now, $az->userId, mb_substr(trim($reason), 0, 500), $now, $t['draft']['id']]
            );
        });
        $this->audit->record('template.published', 'success', 'document_template', $templatePid, $t['legal_entity_id'] !== null ? (int) $t['legal_entity_id'] : null, $reason, ['version_no' => (int) $t['draft']['version_no']]);
        return (int) $t['draft']['version_no'];
    }

    public function discardDraft(Authorizer $az, string $templatePid): void
    {
        $this->requireManage($az);
        $t = $this->get($templatePid);
        if ($t['draft'] !== null) {
            $this->db->run("UPDATE document_template_versions SET status = 'discarded', updated_at = ? WHERE id = ? AND status = 'draft'", [$this->now(), $t['draft']['id']]);
            $this->audit->record('template.draft_discarded', 'success', 'document_template', $templatePid);
        }
    }

    // ---- issue -------------------------------------------------------------------------------------

    /**
     * Issues a document from the latest published version and freezes it.
     * A module may store it under its own document class ($documentClass, e.g. "certs.certificate") so that only the
     * module's screens and permissions give access to it (the class must be registered in the catalog).
     *
     * @param array<string, string> $values field key => value
     * @return array{document: string, number: ?string}
     */
    public function issue(Authorizer $az, string $templatePid, array $values, int $legalEntityId, ?int $siteId, string $title, ?string $relatedType = null, ?string $relatedId = null, ?int $subjectUserId = null, ?string $documentClass = null): array
    {
        $t = $this->get($templatePid);
        $scope = $az->scope('core.template.issue');
        if (!$scope->all && !in_array($legalEntityId, $scope->legalEntityIds, true)) {
            throw HttpException::forbidden();
        }
        if ($t['legal_entity_id'] !== null && (int) $t['legal_entity_id'] !== $legalEntityId) {
            throw new BusinessException('template.error.other_company', [], 422);
        }
        $v = $t['published'] ?? throw new BusinessException('template.error.not_published', [], 409);
        $fields = (array) json_decode((string) $v['fields_json'], true);
        $clean = [];
        $errors = [];
        foreach ($fields as $f) {
            $key = (string) $f['key'];
            $val = trim((string) ($values[$key] ?? ''));
            if ($val === '' && !empty($f['required'])) {
                $errors['f_' . $key] = 'form.required';
                continue;
            }
            if ($val !== '' && $f['type'] === 'date') {
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $val);
                if ($d === false) {
                    $errors['f_' . $key] = 'template.error.date';
                    continue;
                }
                $val = DateFormat::day($val, (string) $t['locale'], true);
            }
            if ($val !== '' && $f['type'] === 'number' && !preg_match('/^-?\d{1,15}(\.\d{1,4})?$/', str_replace(',', '', $val))) {
                $errors['f_' . $key] = 'template.error.number';
                continue;
            }
            $clean[$key] = mb_substr($val, 0, $f['type'] === 'multiline' ? 4000 : 300);
        }
        if (trim($title) === '') {
            $errors['title'] = 'document.error.title';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $today = $this->clock->now()->setTimezone(new DateTimeZone($this->timezone));
        $number = null;
        if ($t['number_pattern'] !== null) {
            $number = $this->numbering->next($legalEntityId, $siteId, 'tpl.' . strtolower((string) $t['code']), $today->format('Y'), (string) $t['number_pattern'], 'document_template', (string) $t['public_id'], $az->userId);
        }
        $issuer = (string) ($this->db->scalar('SELECT display_name FROM users WHERE id = ?', [$az->userId]) ?? '');
        $html = $this->renderPage($t, $v, $clean, $fields, $legalEntityId, $siteId, $number, $today->format('Y-m-d'), $issuer, mb_substr(trim($title), 0, 200));
        $doc = $this->documents->storeGenerated($az->userId, $documentClass ?? (string) $t['document_class'], $html, ($number ?? $t['code']) . '.html', [
            'title' => mb_substr(trim($title), 0, 200), 'legal_entity_id' => $legalEntityId, 'site_id' => $siteId, 'related_type' => $relatedType, 'related_id' => $relatedId,
            'subject_user_id' => $subjectUserId, 'document_number' => $number, 'language' => $t['locale'], 'is_demo' => (int) $t['is_demo'],
        ], 'template', true, (int) $v['id']);
        $this->audit->record('template.issued', 'success', 'document', $doc['document'], $legalEntityId, null, ['template' => $t['code'], 'version_no' => (int) $v['version_no'], 'number' => $number]);
        return ['document' => $doc['document'], 'number' => $number];
    }

    /** Preview of a version with sample values (no number issued, nothing stored). */
    public function preview(string $versionPid, int $legalEntityId, string $issuer): string
    {
        $v = $this->version($versionPid);
        $t = $this->db->one('SELECT * FROM document_templates WHERE id = ?', [$v['template_id']]) ?? throw HttpException::notFound();
        $fields = (array) json_decode((string) $v['fields_json'], true);
        $sample = [];
        foreach ($fields as $f) {
            $sample[(string) $f['key']] = '[' . $f['label'] . ']';
        }
        return $this->renderPage($t, $v, $sample, $fields, $legalEntityId, null, 'PREVIEW', $this->clock->now()->setTimezone(new DateTimeZone($this->timezone))->format('Y-m-d'), $issuer, (string) $v['title'], true);
    }

    /**
     * @param array<string, mixed> $t
     * @param array<string, mixed> $v
     * @param array<string, string> $values
     * @param list<array<string, mixed>> $fields
     */
    private function renderPage(array $t, array $v, array $values, array $fields, int $le, ?int $site, ?string $number, string $date, string $issuer, string $title, bool $preview = false): string
    {
        $locale = (string) $t['locale'];
        $tr = new Translations($this->db);
        $leNames = $tr->many('legal_entity', [$le])[$le] ?? [];
        $leRow = $this->db->one('SELECT code, registration_no FROM legal_entities WHERE id = ?', [$le]) ?? [];
        $siteName = '';
        $branch = '';
        if ($site !== null) {
            $siteName = Translations::pick($tr->many('site', [$site])[$site] ?? [], $locale);
            $branch = (string) ($this->db->scalar('SELECT branch_no FROM sites WHERE id = ?', [$site]) ?? '');
        }
        $system = [
            'company.name' => Translations::pick($leNames, $locale), 'company.name_en' => Translations::pick($leNames, 'en'),
            'company.registration_no' => (string) ($leRow['registration_no'] ?? ''), 'site.name' => $siteName, 'site.branch_no' => $branch,
            'doc.number' => (string) $number, 'doc.date' => DateFormat::day($date, $locale), 'doc.date_long' => DateFormat::day($date, $locale, true), 'issuer.name' => $issuer,
        ];
        $types = [];
        foreach ($fields as $f) {
            $types[(string) $f['key']] = (string) $f['type'];
        }
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $body = (string) preg_replace_callback('/\{\{\s*([a-z][a-z0-9_.]{0,59})\s*\}\}/', static function (array $m) use ($values, $system, $types, $esc): string {
            $key = $m[1];
            $val = $values[$key] ?? $system[$key] ?? '';
            return ($types[$key] ?? '') === 'multiline' ? nl2br($esc($val), false) : $esc($val);
        }, (string) $v['body_html']);

        $font = $v['font_family'] !== null ? (string) $v['font_family'] : $this->fontFor($le);
        $stack = "'" . str_replace("'", '', $font) . "', " . self::FONT_FALLBACK;
        $size = stripos($font, 'sarabun') !== false ? '16pt' : '12pt';
        $logo = $this->logoDataUri();
        $header = '';
        if ((int) $v['show_header'] === 1) {
            $header = '<header class="doc-header">' . ($logo !== null ? '<img class="doc-logo" src="' . $logo . '" alt="">' : '')
                . '<div class="doc-company"><strong>' . $esc($system['company.name']) . '</strong>'
                . ($system['company.registration_no'] !== '' ? '<div>' . $esc($locale === 'th' ? 'เลขทะเบียน ' : 'Reg. no. ') . $esc($system['company.registration_no']) . '</div>' : '')
                . ($siteName !== '' ? '<div>' . $esc($siteName) . ($branch !== '' ? ' (' . $esc($branch) . ')' : '') . '</div>' : '')
                . '</div><div class="doc-meta">' . ($number !== null && $number !== '' ? '<div>' . $esc($number) . '</div>' : '') . '<div>' . $esc($system['doc.date_long']) . '</div></div></header>';
        }
        $footer = (int) $v['show_footer'] === 1
            ? '<footer class="doc-footer">' . $esc((string) $t['code']) . ' v' . (int) $v['version_no'] . ($number !== null ? ' · ' . $esc($number) : '') . ' · Blue OS</footer>'
            : '';
        $css = "@page{size:A4;margin:18mm 18mm 22mm 20mm}*{box-sizing:border-box}html{background:#fff;color:#111}"
            . "body{margin:0;font-family:{$stack};font-size:{$size};line-height:1.45}"
            . '.page{max-width:210mm;margin:0 auto;padding:0}'
            . '.doc-header{display:flex;gap:12px;align-items:flex-start;border-bottom:1px solid #999;padding-bottom:8px;margin-bottom:16px}'
            . '.doc-logo{width:56px;height:56px;object-fit:contain}.doc-company{flex:1}.doc-meta{text-align:right}'
            . '.doc-body h1{font-size:1.4em;text-align:center;margin:.4em 0}.doc-body h2{font-size:1.2em}.doc-body p{margin:.4em 0}'
            . '.doc-body table{border-collapse:collapse;width:100%}.doc-body td,.doc-body th{border:1px solid #999;padding:4px 6px;vertical-align:top}'
            . '.doc-body table.no-border td,.doc-body table.no-border th{border:0}.text-center{text-align:center}.text-right{text-align:right}'
            . '.text-justify{text-align:justify}.indent{text-indent:2.5em}.signature{margin-top:3em;text-align:center}.small{font-size:.85em}.muted{color:#555}'
            . '.page-break{break-after:page}.doc-footer{margin-top:24px;border-top:1px solid #ccc;padding-top:4px;font-size:9pt;color:#555;text-align:right;font-family:Tahoma,sans-serif}'
            . '@media print{.doc-footer{position:fixed;bottom:0;left:0;right:0;margin:0}}'
            . ($preview ? '.page::before{content:"PREVIEW";position:fixed;top:40%;left:20%;font-size:72pt;color:rgba(0,0,0,.08);transform:rotate(-30deg)}' : '');
        return '<!doctype html><html lang="' . $esc($locale) . '"><head><meta charset="utf-8"><title>' . $esc($title) . '</title><style>' . $css . '</style></head>'
            . '<body><div class="page">' . $header . '<main class="doc-body">' . $body . '</main>' . $footer . '</div></body></html>';
    }

    private function fontFor(int $le): string
    {
        $r = $this->registry?->resolveOrNull('documents.font_family', $le);
        return $r !== null && is_string($r->value) && $r->value !== '' ? $r->value : self::DEFAULT_FONT;
    }

    private function logoDataUri(): ?string
    {
        $pid = $this->registry?->resolveOrNull('brand.logo_document');
        if ($pid === null || !is_string($pid->value) || $pid->value === '') {
            return null;
        }
        $a = $this->documents->publicAsset($pid->value);
        return $a === null ? null : 'data:' . $a['mime'] . ';base64,' . base64_encode($a['bytes']);
    }

    // ---- helpers -----------------------------------------------------------------------------------

    /**
     * "key | label | type | required" — one field per line.
     *
     * @return array{0: list<array{key: string, label: string, type: string, required: bool}>, 1: array<string, string>}
     */
    public static function parseFields(string $text): array
    {
        $fields = [];
        $errors = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $p = array_map('trim', explode('|', $line));
            $key = $p[0];
            $type = $p[2] ?? 'text';
            if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', $key) || in_array($key, array_column($fields, 'key'), true) || !in_array($type, self::FIELD_TYPES, true)) {
                $errors['fields'] = 'template.error.fields';
                continue;
            }
            $fields[] = ['key' => $key, 'label' => mb_substr(($p[1] ?? '') !== '' ? $p[1] : $key, 0, 80), 'type' => $type, 'required' => strtolower($p[3] ?? '') === 'required'];
        }
        return [$fields, $errors];
    }

    /** @param list<array<string, mixed>> $fields */
    public static function fieldsText(array $fields): string
    {
        return implode("\n", array_map(static fn (array $f): string => $f['key'] . ' | ' . $f['label'] . ' | ' . $f['type'] . (!empty($f['required']) ? ' | required' : ''), $fields));
    }

    /** @return list<string> */
    public static function placeholders(string $body): array
    {
        preg_match_all('/\{\{\s*([a-z][a-z0-9_.]{0,59})\s*\}\}/', $body, $m);
        return array_values(array_unique($m[1]));
    }

    private function requireManage(Authorizer $az): void
    {
        if (!$az->scope('core.template.manage')->all) {
            throw HttpException::forbidden();
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}

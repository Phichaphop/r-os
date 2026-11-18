<?php

declare(strict_types=1);

namespace Blue\Modules\Employees;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Support\Ulid;

/**
 * The logical personnel file (04 §6.19.1–6.19.2, M09 A2/A3/★ — DEC-67). Files live in the Records service (class
 * employees.file / employees.medical: Restricted, encrypted, never in the central document list); this table says which
 * employee, which category A–J, which document type, whether HR verified the original, and when it expires.
 *
 *  - every category has its own permission (FileRules::CATEGORIES): payroll (E), employee relations (H) and health (I)
 *    are invisible to general HR, and nobody sees a category just because they may see the employee
 *  - an upload is "pending verification" until another look by HR marks it verified (uploading is not verifying)
 *  - completeness % = required types (company rules, by employment type) that have a VERIFIED, not expired, current file
 *  - expiry: a daily job notifies HR once per file when it is within the company's notice days
 *  - the employee sees only files HR marked visible, in the categories self-service may show
 */
final class PersonnelFileService
{
    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    /**
     * The company's approved file rules on $date (null = none approved yet — nothing is required).
     *
     * @return array{required: array<string, list<string>>, expiry_notice_days: int, offboarding: list<array<string, mixed>>}|null
     */
    public function rules(int $legalEntityId, ?string $date = null): ?array
    {
        $at = null;
        if ($date !== null) {
            $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' 12:00:00', new DateTimeZone($this->tz())) ?: null;
        }
        $r = $this->app->registry()->resolveOrNull('employees.file_rules', $legalEntityId, null, $at);
        if ($r === null || !is_array($r->value)) {
            return null;
        }
        $v = $r->value;
        return ['required' => (array) ($v['required'] ?? []), 'expiry_notice_days' => (int) ($v['expiry_notice_days'] ?? 0), 'offboarding' => (array) ($v['offboarding'] ?? [])];
    }

    /**
     * Files of an employee the viewer may see.
     *
     * @param array<string, mixed> $e employee with 'job'
     * @param array<string, mixed> $acc ProfileService::access()
     * @return list<array<string, mixed>>
     */
    public function items(array $e, array $acc, bool $selfView = false): array
    {
        $cats = $selfView ? FileRules::SELF_VISIBLE : (array) $acc['categories'];
        if ($cats === []) {
            return [];
        }
        $place = implode(',', array_fill(0, count($cats), '?'));
        $sql = "SELECT f.*, v.display_name AS verified_name FROM personnel_file_items f LEFT JOIN users v ON v.id = f.verified_by
                 WHERE f.employee_id = ? AND f.category IN ({$place})" . ($selfView ? " AND f.employee_visible = 1 AND f.status = 'current'" : '') . '
                 ORDER BY f.category, f.status, f.doc_type, f.id DESC';
        return $this->app->db()->all($sql, array_merge([(int) $e['id']], $cats));
    }

    /**
     * Completeness against the approved rules: per required type → ok | pending | expired | missing.
     *
     * @param array<string, mixed> $e employee with 'job'
     * @return array{configured: bool, percent: ?int, required: list<array{type: string, state: string}>}
     */
    public function completeness(array $e): array
    {
        $job = $e['job'] ?? null;
        if (!is_array($job)) {
            return ['configured' => false, 'percent' => null, 'required' => []];
        }
        $rules = $this->rules((int) $job['legal_entity_id']);
        if ($rules === null) {
            return ['configured' => false, 'percent' => null, 'required' => []];
        }
        $types = array_values(array_unique(array_merge((array) ($rules['required']['*'] ?? []), (array) ($rules['required'][(string) $job['employment_type']] ?? []))));
        if ($types === []) {
            return ['configured' => true, 'percent' => 100, 'required' => []];
        }
        $today = EmployeeService::of($this->app)->today();
        $have = [];
        foreach ($this->app->db()->all("SELECT doc_type, verify_status, expiry_date FROM personnel_file_items WHERE employee_id = ? AND status = 'current'", [(int) $e['id']]) as $f) {
            $state = $f['verify_status'] !== 'verified' ? 'pending' : ($f['expiry_date'] !== null && (string) $f['expiry_date'] < $today ? 'expired' : 'ok');
            $rank = ['ok' => 3, 'pending' => 2, 'expired' => 1];
            if (!isset($have[$f['doc_type']]) || $rank[$state] > $rank[$have[$f['doc_type']]]) {
                $have[$f['doc_type']] = $state;
            }
        }
        $out = [];
        $ok = 0;
        foreach ($types as $t) {
            $state = $have[$t] ?? 'missing';
            $ok += $state === 'ok' ? 1 : 0;
            $out[] = ['type' => (string) $t, 'state' => $state];
        }
        return ['configured' => true, 'percent' => intdiv($ok * 100, count($types)), 'required' => $out];
    }

    /**
     * Adds a file (or a new version replacing an existing current file of the employee).
     *
     * @param array<string, mixed> $in doc_type, category (only for "other"), title, expiry_date, employee_visible, replaces
     */
    public function upload(Authorizer $az, string $pid, array $in, ?UploadedFile $file): string
    {
        $e = EmployeeService::of($this->app)->get($az, $pid);
        $acc = ProfileService::of($this->app)->access($az, $e);
        $errors = [];
        $type = (string) ($in['doc_type'] ?? '');
        $cat = FileRules::DOC_TYPES[$type] ?? null;
        if ($cat === '*') {
            $cat = strtoupper((string) ($in['category'] ?? ''));
            if (!isset(FileRules::CATEGORIES[$cat])) {
                $errors['category'] = 'form.invalid_choice';
            }
        }
        if ($cat === null) {
            $errors['doc_type'] = 'form.invalid_choice';
        }
        if ($cat !== null && isset(FileRules::CATEGORIES[$cat]) && !in_array($cat, $acc['categories'], true)) {
            throw HttpException::forbidden();
        }
        $title = trim((string) ($in['title'] ?? ''));
        if (mb_strlen($title) > 160 || ($type === 'other' && $title === '')) {
            $errors['title'] = 'employees.error.file_title';
        }
        $exp = trim((string) ($in['expiry_date'] ?? ''));
        if ($exp !== '' && (DateTimeImmutable::createFromFormat('!Y-m-d', $exp) === false || $exp < '2000-01-01')) {
            $errors['expiry_date'] = 'employees.error.date';
        }
        if ($file === null || !$file->ok() || $file->size === 0) {
            $errors['file'] = 'file.error.missing';
        }
        $replaces = null;
        if (($in['replaces'] ?? '') !== '') {
            $replaces = $this->app->db()->one("SELECT * FROM personnel_file_items WHERE public_id = ? AND employee_id = ? AND status = 'current'", [(string) $in['replaces'], $e['id']]);
            if ($replaces === null || !in_array((string) $replaces['category'], $acc['categories'], true)) {
                throw HttpException::notFound();
            }
        }
        if ($errors !== [] || $file === null || $cat === null) {
            throw new ValidationException($errors);
        }
        $visible = ($in['employee_visible'] ?? '') === '1' && in_array($cat, FileRules::SELF_VISIBLE, true);
        $class = $cat === 'I' ? 'employees.medical' : 'employees.file';
        try {
            $stored = $this->app->documents()->storeGenerated($az->userId, $class, $file->bytes(), $file->safeName(), [
                'title' => 'Personnel file ' . $e['employee_code'] . ' ' . $type, 'legal_entity_id' => (int) $e['job']['legal_entity_id'],
            ], 'upload', true);
        } catch (ValidationException $ex) {
            throw new ValidationException(['file' => array_values($ex->errors)[0] ?? 'file.error.type_not_allowed']);
        }
        $itemPid = Ulid::generate($this->app->clock);
        $db = $this->app->db();
        $db->transactional(function () use ($db, $e, $cat, $type, $title, $stored, $exp, $visible, $replaces, $itemPid, $az): void {
            $now = $this->now();
            $db->run(
                'INSERT INTO personnel_file_items (public_id, created_at, created_by, updated_at, updated_by, employee_id, legal_entity_id, category, doc_type, title, document_pid, expiry_date, employee_visible)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$itemPid, $now, $az->userId, $now, $az->userId, $e['id'], (int) $e['job']['legal_entity_id'], $cat, $type, $title === '' ? null : $title, (string) $stored['document'],
                    $exp === '' ? null : $exp, $visible ? 1 : 0]
            );
            if ($replaces !== null) {
                $newId = (int) $db->pdo()->lastInsertId();
                $db->run("UPDATE personnel_file_items SET status = 'superseded', superseded_by = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$newId, $now, $az->userId, $replaces['id']]);
            }
        });
        $this->app->audit()->record('employee.file_added', 'success', 'employee', $pid, (int) $e['job']['legal_entity_id'], null, [
            'category' => $cat, 'doc_type' => $type, 'item' => $itemPid, 'replaces' => $replaces['public_id'] ?? null,
        ]);
        return $itemPid;
    }

    /** HR records that the original was checked: verified or rejected, with how and a short note (6.19.2 ข้อ 4). */
    public function verify(Authorizer $az, string $pid, string $itemPid, string $result, string $method, string $note): void
    {
        [$e, $item] = $this->item($az, $pid, $itemPid);
        if (!ProfileService::of($this->app)->access($az, $e)['manage']) {
            throw HttpException::forbidden();
        }
        $errors = [];
        if (!in_array($result, ['verified', 'rejected'], true)) {
            $errors['result'] = 'form.invalid_choice';
        }
        if (!in_array($method, ['original', 'certified_copy', 'issuer_check', 'digital'], true)) {
            $errors['method'] = 'form.invalid_choice';
        }
        if ($result === 'rejected' && mb_strlen(trim($note)) < 3) {
            $errors['note'] = 'employees.error.reject_note';
        }
        if ((int) $item['created_by'] === $az->userId) {
            $errors['result'] = 'employees.error.verify_own'; // the person who uploaded does not also verify
        }
        if ($item['status'] !== 'current') {
            $errors['result'] = 'employees.error.not_current';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->app->db()->run(
            'UPDATE personnel_file_items SET verify_status = ?, verify_method = ?, verify_note = ?, verified_by = ?, verified_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$result, $method, trim($note) === '' ? null : mb_substr(trim($note), 0, 255), $az->userId, $this->now(), $this->now(), $az->userId, $item['id']]
        );
        $this->app->audit()->record('employee.file_' . $result, 'success', 'employee', $pid, (int) $item['legal_entity_id'], null, ['item' => $itemPid, 'doc_type' => $item['doc_type'], 'method' => $method]);
    }

    /** Archives a file put in by mistake (the document itself stays in Records under its retention rule). */
    public function archive(Authorizer $az, string $pid, string $itemPid, string $reason): void
    {
        [$e, $item] = $this->item($az, $pid, $itemPid);
        if (!ProfileService::of($this->app)->access($az, $e)['manage']) {
            throw HttpException::forbidden();
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        $this->app->db()->run("UPDATE personnel_file_items SET status = 'archived', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $az->userId, $item['id']]);
        $this->app->audit()->record('employee.file_archived', 'success', 'employee', $pid, (int) $item['legal_entity_id'], trim($reason), ['item' => $itemPid]);
    }

    /**
     * Opens a file: HR with the category's permission, or the employee for files marked visible to them.
     *
     * @return array{bytes: string, mime: string, filename: string, classification: string, ext: string}
     */
    public function download(Authorizer $az, string $pid, string $itemPid, bool $selfService): array
    {
        if ($selfService) {
            $e = EmployeeService::of($this->app)->forUser($az->userId) ?? throw HttpException::notFound();
            if ((string) $e['public_id'] !== $pid) {
                throw HttpException::notFound();
            }
            $item = $this->app->db()->one("SELECT * FROM personnel_file_items WHERE public_id = ? AND employee_id = ? AND employee_visible = 1 AND status = 'current'", [$itemPid, $e['id']]);
            if ($item === null || !in_array((string) $item['category'], FileRules::SELF_VISIBLE, true)) {
                throw HttpException::notFound();
            }
        } else {
            [$e, $item] = $this->item($az, $pid, $itemPid);
        }
        $module = 'employees';
        return $this->app->documents()->moduleRead((string) $item['document_pid'], $module, $az->userId, 'personnel file ' . $itemPid . ($selfService ? ' (self-service)' : ''), true);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} employee + file item, 404 when the category is not the viewer's
     */
    private function item(Authorizer $az, string $pid, string $itemPid): array
    {
        $e = EmployeeService::of($this->app)->get($az, $pid);
        $item = $this->app->db()->one('SELECT * FROM personnel_file_items WHERE public_id = ? AND employee_id = ?', [$itemPid, $e['id']]) ?? throw HttpException::notFound();
        if (!in_array((string) $item['category'], ProfileService::of($this->app)->access($az, $e)['categories'], true)) {
            throw HttpException::notFound();
        }
        return [$e, $item];
    }

    /**
     * Completeness of everyone in the viewer's scope (employees.report): counts only, no personal data or file contents.
     *
     * @return list<array<string, mixed>>
     */
    public function report(Authorizer $az, ?int $legalEntityId): array
    {
        $scope = $az->scope('employees.report');
        if ($scope->isEmpty()) {
            throw HttpException::forbidden();
        }
        [$sql, $params] = $scope->sql('h.legal_entity_id', 'h.site_id', 'h.department_id');
        $today = EmployeeService::of($this->app)->today();
        $args = array_merge([$today, $today], $params);
        $where = '';
        if ($legalEntityId !== null) {
            $where = ' AND h.legal_entity_id = ?';
            $args[] = $legalEntityId;
        }
        $rows = $this->app->db()->all(
            "SELECT e.id, e.public_id, e.employee_code, e.name_th, e.name_en, e.preferred_name, e.status, h.legal_entity_id, h.site_id, h.department_id, h.employment_type
               FROM employees e JOIN employment_history h ON h.employee_id = e.id
              WHERE h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?) AND e.status <> 'former' AND {$sql}{$where}
              ORDER BY e.employee_code LIMIT 5000",
            $args
        );
        $notice = [];
        $out = [];
        foreach ($rows as $r) {
            $job = ['legal_entity_id' => $r['legal_entity_id'], 'employment_type' => $r['employment_type'], 'site_id' => $r['site_id'], 'department_id' => $r['department_id']];
            $c = $this->completeness(['id' => $r['id'], 'job' => $job]);
            $le = (int) $r['legal_entity_id'];
            $notice[$le] ??= ($this->rules($le)['expiry_notice_days'] ?? 0);
            $soon = $notice[$le] > 0 ? (int) $this->app->db()->scalar(
                "SELECT COUNT(*) FROM personnel_file_items WHERE employee_id = ? AND status = 'current' AND expiry_date IS NOT NULL AND expiry_date <= ?",
                [$r['id'], EmployeeService::ymd($today)->modify('+' . $notice[$le] . ' days')->format('Y-m-d')]
            ) : 0;
            $state = static fn (string $s): int => count(array_filter($c['required'], static fn (array $x): bool => $x['state'] === $s));
            $out[] = $r + ['configured' => $c['configured'], 'percent' => $c['percent'], 'missing' => $state('missing'), 'pending' => $state('pending'),
                'expired' => $state('expired'), 'expiring' => $soon];
        }
        return $out;
    }

    /**
     * Daily: notify HR (employees.file in the employee's company) once per file entering the notice window; the
     * expiry of ID numbers is shown on the personal tab only (not notified yet). Returns notifications sent.
     */
    public static function expiryJob(App $app): int
    {
        $db = $app->db();
        if (!$db->tableExists('personnel_file_items')) {
            return 0;
        }
        $svc = self::of($app);
        $today = EmployeeService::of($app)->today();
        $sent = 0;
        $rules = [];
        $rows = $db->all(
            "SELECT f.id, f.public_id, f.legal_entity_id, f.doc_type, f.expiry_date, e.public_id AS emp_pid, e.employee_code
               FROM personnel_file_items f JOIN employees e ON e.id = f.employee_id
              WHERE f.status = 'current' AND f.expiry_date IS NOT NULL AND f.expiry_notified_at IS NULL AND e.status <> 'former' AND f.category <> 'I'
              ORDER BY f.expiry_date LIMIT 500"
        );
        foreach ($rows as $f) {
            $le = (int) $f['legal_entity_id'];
            $rules[$le] ??= $svc->rules($le);
            $days = (int) ($rules[$le]['expiry_notice_days'] ?? 0);
            if ($days <= 0 || (string) $f['expiry_date'] > EmployeeService::ymd($today)->modify('+' . $days . ' days')->format('Y-m-d')) {
                continue;
            }
            foreach ($svc->holders('employees.file', $le) as $uid) {
                $app->notifier()->notify($uid, 'employees.file_expiring', 'employees.notify.file_expiring', [
                    'code' => (string) $f['employee_code'], 'doc' => (string) $f['doc_type'], 'date' => (string) $f['expiry_date'],
                ], '/employees/' . $f['emp_pid'] . '?tab=file', 'emp-file-exp:' . $f['public_id']);
                $sent++;
            }
            $db->run('UPDATE personnel_file_items SET expiry_notified_at = ? WHERE id = ?', [$app->clock->now()->format('Y-m-d H:i:s.u'), $f['id']]);
        }
        return $sent;
    }

    /**
     * Active users holding $permission with a scope that covers the company (organization or that legal entity).
     *
     * @return list<int>
     */
    public function holders(string $permission, int $legalEntityId): array
    {
        $today = $this->app->clock->now()->format('Y-m-d');
        return array_map('intval', array_column($this->app->db()->all(
            "SELECT DISTINCT a.user_id FROM user_role_assignments a JOIN users u ON u.id = a.user_id JOIN access_scopes s ON s.assignment_id = a.id
              WHERE a.status = 'active' AND u.status = 'active' AND a.valid_from <= ? AND (a.valid_to IS NULL OR a.valid_to >= ?)
                AND a.role_id IN (SELECT role_id FROM role_permissions WHERE permission_code = ?)
                AND (s.scope_level = 'organization' OR (s.scope_level = 'legal_entity' AND s.scope_id = ?))",
            [$today, $today, $permission, $legalEntityId]
        ), 'user_id'));
    }

    private function tz(): string
    {
        return $this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok');
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }
}

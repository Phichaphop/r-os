<?php

declare(strict_types=1);

namespace Blue\Modules\Certs;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\EmployeeService;
use Blue\Modules\Employees\PersonnelFileService;

/**
 * M12 Certificate requests (phase 5E-2, DEC-77).
 *
 *  type      a certificate of a company (e.g. employment, salary): Thai and/or English template from the core template
 *            service (published versions only — the template designer is the template admin), signer, max copies.
 *  request   an employee asks — no approval: type, language, copies, purpose → submitted. HR takes it (preparing), may
 *            ask for more information (need info → the employee answers → submitted again), issues the document,
 *            then marks it ready → the employee is told and sees a 6-digit pickup code.
 *  issue     the document is rendered from the template with values from the employee record (name, code, position,
 *            unit, start date, years of service …) plus what HR types (e.g. salary — there is no payroll yet), and
 *            stored frozen under the module's own class with a running number. Issuing again voids the former one
 *            with a reason (both stay on record).
 *  handover  HR enters the pickup code and the name of the person collecting (self, or someone else with a note of
 *            the authorisation) → received. No drawn signatures are kept.
 *  rating    after receiving, the employee rates the service (1–5); a new request is refused while one is unrated.
 *            HR may waive the rating with a reason (e.g. the person cannot or will not rate, or there is a dispute).
 *  privacy   requesters see only their own requests and, once received, their own document; HR sees its companies.
 */
final class CertService
{
    public const OPEN = ['submitted', 'need_info', 'preparing', 'ready'];

    /** Template field keys filled from the employee record. */
    public const AUTO_FIELDS = ['employee_name', 'employee_name_en', 'employee_code', 'position', 'department', 'site_name', 'start_date', 'employment_type', 'years_of_service',
        'purpose', 'copies', 'signer_name', 'signer_title', 'request_no'];

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
        return EmployeeService::of($this->app)->today();
    }

    /** @return list<int> */
    public function companies(Authorizer $az): array
    {
        $scope = $az->scope('certs.manage');
        return array_values(array_filter(array_map('intval', array_column($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code'), 'id')), static fn (int $id): bool => $scope->coversLegalEntity($id)));
    }

    // ---- types ------------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function types(int $le, bool $activeOnly = true): array
    {
        return $this->app->db()->all(
            'SELECT t.*, th.public_id AS template_th_pid, th.name AS template_th_name, en.public_id AS template_en_pid, en.name AS template_en_name
               FROM cert_types t LEFT JOIN document_templates th ON th.id = t.template_th_id LEFT JOIN document_templates en ON en.id = t.template_en_id
              WHERE t.legal_entity_id = ?' . ($activeOnly ? " AND t.status = 'active'" : '') . ' ORDER BY t.status, t.code',
            [$le]
        );
    }

    /** @return array<string, string> published templates usable by a company (public id => name) */
    public function templateOptions(int $le): array
    {
        $out = [];
        foreach (
            $this->app->db()->all(
                "SELECT t.public_id, t.name, t.locale FROM document_templates t WHERE t.status = 'active' AND (t.legal_entity_id IS NULL OR t.legal_entity_id = ?)
                    AND EXISTS (SELECT 1 FROM document_template_versions v WHERE v.template_id = t.id AND v.status = 'published') ORDER BY t.name",
                [$le]
            ) as $t
        ) {
            $out[(string) $t['public_id']] = $t['name'] . ' (' . strtoupper((string) $t['locale']) . ')';
        }
        return $out;
    }

    /** @param array<string, string> $in */
    public function saveType(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        if (!$az->scope('certs.manage')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        $existing = $pid !== null ? $db->one('SELECT * FROM cert_types WHERE public_id = ? AND legal_entity_id = ?', [$pid, $le]) : null;
        if ($pid !== null && $existing === null) {
            throw HttpException::notFound();
        }
        $errors = [];
        $code = strtoupper(trim($in['code'] ?? ''));
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{1,29}$/', $code) !== 1) {
            $errors['code'] = 'certs.error.code';
        } elseif ($db->scalar('SELECT id FROM cert_types WHERE legal_entity_id = ? AND code = ? AND id <> ?', [$le, $code, (int) ($existing['id'] ?? 0)]) !== null) {
            $errors['code'] = 'certs.error.code_taken';
        }
        $name = mb_substr(trim($in['name_th'] ?? ''), 0, 150);
        if (mb_strlen($name) < 3) {
            $errors['name_th'] = 'certs.error.name';
        }
        $options = $this->templateOptions($le);
        $tpl = [];
        foreach (['th', 'en'] as $l) {
            $v = trim($in['template_' . $l] ?? '');
            if ($v !== '' && !isset($options[$v])) {
                $errors['template_' . $l] = 'form.invalid_choice';
            }
            $tpl[$l] = $v !== '' ? (int) $db->scalar('SELECT id FROM document_templates WHERE public_id = ?', [$v]) : null;
        }
        if ($tpl['th'] === null && $tpl['en'] === null) {
            $errors['template_th'] = 'certs.error.template';
        }
        $copies = (int) ($in['max_copies'] ?? 3);
        if ($copies < 1 || $copies > 10) {
            $errors['max_copies'] = 'certs.error.copies_max';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $now = $this->now();
        $vals = [$code, $name, mb_substr(trim($in['name_en'] ?? ''), 0, 150) ?: null, mb_substr(trim($in['description'] ?? ''), 0, 500) ?: null, $tpl['th'], $tpl['en'], $copies,
            mb_substr(trim($in['signer_name'] ?? ''), 0, 150) ?: null, mb_substr(trim($in['signer_title'] ?? ''), 0, 150) ?: null];
        if ($existing !== null) {
            $db->run(
                'UPDATE cert_types SET code = ?, name_th = ?, name_en = ?, description = ?, template_th_id = ?, template_en_id = ?, max_copies = ?, signer_name = ?, signer_title = ?,
                        status = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
                [...$vals, ($in['status'] ?? 'active') === 'archived' ? 'archived' : 'active', $now, $az->userId, $existing['id']]
            );
            $this->app->audit()->record('certs.type_saved', 'success', 'cert_type', (string) $pid, $le, null, ['code' => $code]);
            return (string) $pid;
        }
        $pid = Ulid::generate($this->app->clock);
        $db->run('INSERT INTO cert_types (code, name_th, name_en, description, template_th_id, template_en_id, max_copies, signer_name, signer_title, public_id, created_at, created_by, updated_at, updated_by, legal_entity_id)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$vals, $pid, $now, $az->userId, $now, $az->userId, $le]);
        $this->app->audit()->record('certs.type_created', 'success', 'cert_type', $pid, $le, null, ['code' => $code]);
        return $pid;
    }

    // ---- employee ------------------------------------------------------------------------------------------------------------

    /** @return array{employee: array<string, mixed>|null, types: list<array<string, mixed>>, requests: list<array<string, mixed>>, unrated: array<string, mixed>|null} */
    public function mine(Authorizer $az): array
    {
        $me = EmployeeService::of($this->app)->forUser($az->userId);
        if ($me === null || $me['job'] === null || $me['status'] === 'former') {
            return ['employee' => $me, 'types' => [], 'requests' => [], 'unrated' => null];
        }
        $requests = $this->app->db()->all(
            'SELECT r.*, t.name_th, t.name_en FROM cert_requests r JOIN cert_types t ON t.id = r.type_id WHERE r.employee_id = ? ORDER BY r.id DESC LIMIT 100',
            [$me['id']]
        );
        return ['employee' => $me, 'types' => $this->types((int) $me['job']['legal_entity_id']), 'requests' => $requests, 'unrated' => $this->unrated((int) $me['id'])];
    }

    /** @return array<string, mixed>|null a received request still waiting for the employee's rating */
    public function unrated(int $employeeId): ?array
    {
        return $this->app->db()->one(
            "SELECT * FROM cert_requests WHERE employee_id = ? AND status = 'received' AND rating IS NULL AND rating_waived_by IS NULL ORDER BY id LIMIT 1",
            [$employeeId]
        );
    }

    /** @param array<string, string> $in type (public id), language, copies, purpose */
    public function submit(Authorizer $az, array $in): string
    {
        if (!$az->can('certs.request')) {
            throw HttpException::forbidden();
        }
        $me = EmployeeService::of($this->app)->forUser($az->userId);
        if ($me === null || $me['job'] === null || $me['status'] === 'former') {
            throw new BusinessException('certs.error.not_employee', [], 409);
        }
        if ($this->unrated((int) $me['id']) !== null) {
            throw new BusinessException('certs.error.rate_first', [], 409);
        }
        $db = $this->app->db();
        $le = (int) $me['job']['legal_entity_id'];
        $type = $db->one("SELECT * FROM cert_types WHERE public_id = ? AND legal_entity_id = ? AND status = 'active'", [$in['type'] ?? '', $le]);
        $errors = [];
        if ($type === null) {
            $errors['type'] = 'certs.error.type';
        }
        $lang = ($in['language'] ?? 'th') === 'en' ? 'en' : 'th';
        if ($type !== null && $type['template_' . $lang . '_id'] === null) {
            $errors['language'] = 'certs.error.language';
        }
        $copies = (int) ($in['copies'] ?? 1);
        if ($copies < 1 || ($type !== null && $copies > (int) $type['max_copies'])) {
            $errors['copies'] = 'certs.error.copies';
        }
        $purpose = mb_substr(trim($in['purpose'] ?? ''), 0, 300);
        if (mb_strlen($purpose) < 3) {
            $errors['purpose'] = 'certs.error.purpose';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $no = $this->app->numbering()->next($le, null, 'certs.request', substr($this->today(), 0, 4), 'CR-{YYYY}-{SEQ:5}', 'cert_request', $pid, $az->userId);
        $db->run(
            'INSERT INTO cert_requests (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, request_no, employee_id, user_id, type_id, language, copies, purpose)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $now, $az->userId, $now, $az->userId, $le, $no, $me['id'], $az->userId, $type['id'], $lang, $copies, $purpose]
        );
        $id = (int) $db->scalar('SELECT id FROM cert_requests WHERE public_id = ?', [$pid]);
        $this->event($id, 'submitted', null, $az->userId);
        foreach (PersonnelFileService::of($this->app)->holders('certs.manage', $le) as $uid) {
            $this->app->notifier()->notify($uid, 'certs.new', 'certs.notify.new', ['no' => $no, 'type' => (string) $type['name_th']], '/certs/admin/r/' . $pid, 'cert.new:' . $pid . ':' . $uid);
        }
        $this->app->audit()->record('certs.requested', 'success', 'cert_request', $pid, $le, null, ['type' => $type['code']]);
        return $pid;
    }

    /** @return array<string, mixed> request + type + employee name, for its requester or HR of the company */
    public function request(Authorizer $az, string $pid): array
    {
        $r = $this->app->db()->one(
            'SELECT r.*, t.code AS type_code, t.name_th, t.name_en, t.signer_name, t.signer_title, t.template_th_id, t.template_en_id, e.employee_code, e.name_th AS emp_name, e.public_id AS emp_pid
               FROM cert_requests r JOIN cert_types t ON t.id = r.type_id JOIN employees e ON e.id = r.employee_id WHERE r.public_id = ?',
            [$pid]
        );
        if ($r === null) {
            throw HttpException::notFound();
        }
        $r['hr'] = $az->scope('certs.manage')->coversLegalEntity((int) $r['legal_entity_id']);
        $r['mine'] = (int) $r['user_id'] === $az->userId;
        if (!$r['hr'] && !$r['mine']) {
            throw HttpException::notFound();
        }
        return $r;
    }

    /** @return array<string, mixed> */
    private function hrRequest(Authorizer $az, string $pid): array
    {
        $r = $this->request($az, $pid);
        if (!$r['hr']) {
            throw HttpException::notFound();
        }
        return $r;
    }

    /** @return array<string, mixed> */
    private function myRequest(Authorizer $az, string $pid): array
    {
        $r = $this->request($az, $pid);
        if (!$r['mine']) {
            throw HttpException::notFound();
        }
        return $r;
    }

    public function answerInfo(Authorizer $az, string $pid, string $answer): void
    {
        $r = $this->myRequest($az, $pid);
        if ($r['status'] !== 'need_info') {
            throw new BusinessException('certs.error.state', [], 409);
        }
        $answer = mb_substr(trim($answer), 0, 500);
        if (mb_strlen($answer) < 2) {
            throw new ValidationException(['answer' => 'certs.error.answer']);
        }
        $this->setStatus($r, 'submitted', $az->userId);
        $this->event((int) $r['id'], 'answered', $answer, $az->userId);
        if ($r['handled_by'] !== null) {
            $this->app->notifier()->notify((int) $r['handled_by'], 'certs.new', 'certs.notify.answered', ['no' => (string) $r['request_no']], '/certs/admin/r/' . $pid, 'cert.ans:' . $pid . ':' . $this->now());
        }
    }

    public function cancelMine(Authorizer $az, string $pid): void
    {
        $r = $this->myRequest($az, $pid);
        if (!in_array($r['status'], ['submitted', 'need_info', 'preparing'], true)) {
            throw new BusinessException('certs.error.state', [], 409);
        }
        $this->voidAll($r, $az->userId, 'cancelled by the requester');
        $this->app->db()->run("UPDATE cert_requests SET status = 'cancelled', cancel_reason = ?, updated_at = ? WHERE id = ?", ['—', $this->now(), $r['id']]);
        $this->event((int) $r['id'], 'cancelled', null, $az->userId);
    }

    public function rate(Authorizer $az, string $pid, int $score, string $comment): void
    {
        $r = $this->myRequest($az, $pid);
        if ($r['status'] !== 'received' || $r['rating'] !== null || $r['rating_waived_by'] !== null) {
            throw new BusinessException('certs.error.state', [], 409);
        }
        if ($score < 1 || $score > 5) {
            throw new ValidationException(['rating' => 'certs.error.rating']);
        }
        $this->app->db()->run('UPDATE cert_requests SET rating = ?, rating_comment = ?, rated_at = ?, updated_at = ? WHERE id = ?', [$score, mb_substr(trim($comment), 0, 500) ?: null, $this->now(), $this->now(), $r['id']]);
        $this->event((int) $r['id'], 'rated', (string) $score, $az->userId);
    }

    /** @return array{bytes: string, mime: string, name: string} the current document: HR any time, the requester after receiving */
    public function document(Authorizer $az, string $pid): array
    {
        $r = $this->request($az, $pid);
        if (!$r['hr'] && $r['status'] !== 'received') {
            throw HttpException::notFound();
        }
        $i = $this->app->db()->one("SELECT * FROM cert_issues WHERE request_id = ? AND status = 'issued' ORDER BY id DESC LIMIT 1", [$r['id']]);
        if ($i === null) {
            throw HttpException::notFound();
        }
        $f = $this->app->documents()->moduleRead((string) $i['document_pid'], 'certs', $az->userId, $r['hr'] ? 'certificate check' : 'own certificate', true);
        return ['bytes' => $f['bytes'], 'mime' => $f['mime'], 'name' => ($i['document_number'] ?? $r['request_no']) . '.html'];
    }

    // ---- HR -----------------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> requests of a company (open first) */
    public function queue(int $le, string $status = 'open'): array
    {
        $where = $status === 'open' ? "r.status IN ('submitted','need_info','preparing','ready')" : (in_array($status, ['received', 'cancelled'], true) ? 'r.status = ?' : '1 = 1');
        $args = [$le];
        if (in_array($status, ['received', 'cancelled'], true)) {
            $args[] = $status;
        }
        return $this->app->db()->all(
            "SELECT r.*, t.name_th, e.employee_code, e.name_th AS emp_name FROM cert_requests r JOIN cert_types t ON t.id = r.type_id JOIN employees e ON e.id = r.employee_id
              WHERE r.legal_entity_id = ? AND {$where} ORDER BY FIELD(r.status, 'submitted', 'need_info', 'preparing', 'ready', 'received', 'cancelled'), r.id LIMIT 300",
            $args
        );
    }

    /** @return array{count: int, avg: float|null} ratings of a company */
    public function ratings(int $le): array
    {
        $r = $this->app->db()->one('SELECT COUNT(rating) AS n, AVG(rating) AS a FROM cert_requests WHERE legal_entity_id = ? AND rating IS NOT NULL', [$le]);
        return ['count' => (int) ($r['n'] ?? 0), 'avg' => $r !== null && $r['a'] !== null ? round((float) $r['a'], 2) : null];
    }

    public function take(Authorizer $az, string $pid): void
    {
        $r = $this->hrRequest($az, $pid);
        if ($r['status'] !== 'submitted') {
            throw new BusinessException('certs.error.state', [], 409);
        }
        $this->app->db()->run('UPDATE cert_requests SET handled_by = ? WHERE id = ?', [$az->userId, $r['id']]);
        $this->setStatus($r, 'preparing', $az->userId);
    }

    public function askInfo(Authorizer $az, string $pid, string $note): void
    {
        $r = $this->hrRequest($az, $pid);
        if (!in_array($r['status'], ['submitted', 'preparing'], true)) {
            throw new BusinessException('certs.error.state', [], 409);
        }
        $note = mb_substr(trim($note), 0, 500);
        if (mb_strlen($note) < 5) {
            throw new ValidationException(['note' => 'certs.error.note']);
        }
        $this->app->db()->run('UPDATE cert_requests SET info_note = ?, handled_by = ? WHERE id = ?', [$note, $az->userId, $r['id']]);
        $this->setStatus($r, 'need_info', $az->userId, $note);
        $this->app->notifier()->notify((int) $r['user_id'], 'certs.status', 'certs.notify.need_info', ['no' => (string) $r['request_no']], '/certs/r/' . $pid, 'cert.info:' . $pid . ':' . $this->now());
    }

    /**
     * Values for the template fields: from the employee record and the request; HR fills or corrects the rest.
     *
     * @return array{fields: list<array<string, mixed>>, values: array<string, string>, template: string|null}
     */
    public function prefill(Authorizer $az, string $pid): array
    {
        $r = $this->hrRequest($az, $pid);
        $tplId = $r['template_' . $r['language'] . '_id'];
        if ($tplId === null) {
            return ['fields' => [], 'values' => [], 'template' => null];
        }
        $db = $this->app->db();
        $tplPid = (string) $db->scalar('SELECT public_id FROM document_templates WHERE id = ?', [$tplId]);
        $ver = $db->one("SELECT fields_json FROM document_template_versions WHERE template_id = ? AND status = 'published' ORDER BY version_no DESC LIMIT 1", [$tplId]);
        $fields = $ver !== null ? (array) json_decode((string) $ver['fields_json'], true) : [];
        $emp = EmployeeService::of($this->app)->byId((int) $r['employee_id']);
        $job = $emp['job'] ?? null;
        $t = $this->app->translator();
        $years = '';
        if ($emp !== null && (string) $emp['hire_date'] !== '') {
            $from = EmployeeService::ymd((string) $emp['hire_date']);
            $diff = $from->diff(EmployeeService::ymd($this->today()));
            $years = $t->get('certs.years_months', ['y' => (string) $diff->y, 'm' => (string) $diff->m]);
        }
        $auto = [
            'employee_name' => (string) ($emp['name_th'] ?? ''), 'employee_name_en' => (string) ($emp['name_en'] ?? ''), 'employee_code' => (string) ($emp['employee_code'] ?? ''),
            'position' => (string) ($job['position_title'] ?? ''),
            'department' => $job !== null && $job['department_id'] !== null ? (string) $db->scalar('SELECT code FROM departments WHERE id = ?', [$job['department_id']]) : '',
            'site_name' => $job !== null && $job['site_id'] !== null ? (string) $db->scalar('SELECT code FROM sites WHERE id = ?', [$job['site_id']]) : '',
            'start_date' => (string) ($emp['hire_date'] ?? ''), 'employment_type' => $job !== null ? $t->get('employees.type.' . $job['employment_type']) : '',
            'years_of_service' => $years, 'purpose' => (string) $r['purpose'], 'copies' => (string) $r['copies'],
            'signer_name' => (string) ($r['signer_name'] ?? ''), 'signer_title' => (string) ($r['signer_title'] ?? ''), 'request_no' => (string) $r['request_no'],
        ];
        $values = [];
        foreach ($fields as $f) {
            $values[(string) $f['key']] = $auto[(string) $f['key']] ?? '';
        }
        return ['fields' => $fields, 'values' => $values, 'template' => $tplPid];
    }

    /**
     * Issues (or re-issues) the certificate from the published template — frozen, numbered, under class certs.certificate.
     *
     * @param array<string, string> $values
     */
    public function issue(Authorizer $az, string $pid, array $values, string $voidReason = ''): string
    {
        $r = $this->hrRequest($az, $pid);
        if (!in_array($r['status'], ['preparing', 'ready'], true)) {
            throw new BusinessException('certs.error.state', [], 409);
        }
        if (!$az->scope('core.template.issue')->coversLegalEntity((int) $r['legal_entity_id'])) {
            throw new BusinessException('certs.error.no_issue_right', [], 403);
        }
        $db = $this->app->db();
        $prev = $db->one("SELECT * FROM cert_issues WHERE request_id = ? AND status = 'issued' ORDER BY id DESC LIMIT 1", [$r['id']]);
        if ($prev !== null && mb_strlen(trim($voidReason)) < 5) {
            throw new ValidationException(['void_reason' => 'certs.error.void_reason']);
        }
        $pre = $this->prefill($az, $pid);
        if ($pre['template'] === null) {
            throw new BusinessException('certs.error.language', [], 409);
        }
        $clean = [];
        foreach ($pre['fields'] as $f) {
            $clean[(string) $f['key']] = mb_substr(trim((string) ($values[(string) $f['key']] ?? '')), 0, 4000);
        }
        $title = ($r['language'] === 'en' && $r['name_en'] !== null ? (string) $r['name_en'] : (string) $r['name_th']) . ' · ' . $r['emp_name'];
        $site = (int) ($db->scalar('SELECT site_id FROM employment_history WHERE employee_id = ? AND effective_to IS NULL ORDER BY effective_from DESC LIMIT 1', [$r['employee_id']]) ?? 0);
        $doc = $this->app->templates()->issue($az, $pre['template'], $clean, (int) $r['legal_entity_id'], $site > 0 ? $site : null, $title, 'cert_request', (string) $r['public_id'], (int) $r['user_id'], 'certs.certificate');
        $now = $this->now();
        if ($prev !== null) {
            $db->run("UPDATE cert_issues SET status = 'void', void_reason = ?, voided_by = ?, voided_at = ? WHERE id = ?", [mb_substr(trim($voidReason), 0, 300), $az->userId, $now, $prev['id']]);
            $this->event((int) $r['id'], 'voided', ($prev['document_number'] ?? '') . ' · ' . trim($voidReason), $az->userId);
        }
        $db->run('INSERT INTO cert_issues (request_id, document_pid, document_number, issued_at, issued_by) VALUES (?, ?, ?, ?, ?)', [$r['id'], $doc['document'], $doc['number'], $now, $az->userId]);
        $this->event((int) $r['id'], 'issued', $doc['number'], $az->userId);
        $this->app->audit()->record('certs.issued', 'success', 'cert_request', $pid, (int) $r['legal_entity_id'], null, ['number' => $doc['number'], 'reissue' => $prev !== null]);
        return $doc['document'];
    }

    /** @return list<array<string, mixed>> */
    public function issues(int $requestId): array
    {
        return $this->app->db()->all('SELECT i.*, u.display_name AS issuer FROM cert_issues i LEFT JOIN users u ON u.id = i.issued_by WHERE i.request_id = ? ORDER BY i.id DESC', [$requestId]);
    }

    public function markReady(Authorizer $az, string $pid): void
    {
        $r = $this->hrRequest($az, $pid);
        if ($r['status'] !== 'preparing') {
            throw new BusinessException('certs.error.state', [], 409);
        }
        if ($this->app->db()->scalar("SELECT id FROM cert_issues WHERE request_id = ? AND status = 'issued'", [$r['id']]) === null) {
            throw new BusinessException('certs.error.issue_first', [], 409);
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->app->db()->run('UPDATE cert_requests SET pickup_code = ?, ready_at = ? WHERE id = ?', [$code, $this->now(), $r['id']]);
        $this->setStatus($r, 'ready', $az->userId);
        $this->app->notifier()->notify((int) $r['user_id'], 'certs.status', 'certs.notify.ready', ['no' => (string) $r['request_no']], '/certs/r/' . $pid, 'cert.ready:' . $pid);
    }

    /** Handover: the pickup code the requester shows + who collects (self, or someone else with the authorisation noted). */
    public function handover(Authorizer $az, string $pid, string $code, string $receiver, string $relation, string $proxyNote): void
    {
        $r = $this->hrRequest($az, $pid);
        if ($r['status'] !== 'ready') {
            throw new BusinessException('certs.error.state', [], 409);
        }
        $errors = [];
        if ($r['pickup_code'] === null || !hash_equals((string) $r['pickup_code'], trim($code))) {
            $errors['code'] = 'certs.error.pickup_code';
        }
        $receiver = mb_substr(trim($receiver), 0, 150);
        if (mb_strlen($receiver) < 3) {
            $errors['receiver'] = 'certs.error.receiver';
        }
        $relation = $relation === 'proxy' ? 'proxy' : 'self';
        $proxyNote = mb_substr(trim($proxyNote), 0, 300);
        if ($relation === 'proxy' && mb_strlen($proxyNote) < 5) {
            $errors['proxy_note'] = 'certs.error.proxy';
        }
        if ($errors !== []) {
            $this->app->audit()->record('certs.handover_refused', 'failure', 'cert_request', $pid, (int) $r['legal_entity_id'], null, ['fields' => array_keys($errors)]);
            throw new ValidationException($errors);
        }
        $now = $this->now();
        $this->app->db()->run(
            "UPDATE cert_requests SET receiver_name = ?, receiver_relation = ?, proxy_note = ?, received_at = ?, handed_over_by = ?, pickup_code = NULL WHERE id = ?",
            [$receiver, $relation, $relation === 'proxy' ? $proxyNote : null, $now, $az->userId, $r['id']]
        );
        $this->setStatus($r, 'received', $az->userId, $relation === 'proxy' ? $receiver . ' · ' . $proxyNote : $receiver);
        $this->app->notifier()->notify((int) $r['user_id'], 'certs.status', 'certs.notify.received', ['no' => (string) $r['request_no']], '/certs/r/' . $pid, 'cert.recv:' . $pid);
    }

    public function cancelByHr(Authorizer $az, string $pid, string $reason): void
    {
        $r = $this->hrRequest($az, $pid);
        if (!in_array($r['status'], self::OPEN, true)) {
            throw new BusinessException('certs.error.state', [], 409);
        }
        $reason = mb_substr(trim($reason), 0, 300);
        if (mb_strlen($reason) < 5) {
            throw new ValidationException(['reason' => 'certs.error.reason']);
        }
        $this->voidAll($r, $az->userId, $reason);
        $this->app->db()->run("UPDATE cert_requests SET status = 'cancelled', cancel_reason = ?, pickup_code = NULL, updated_at = ? WHERE id = ?", [$reason, $this->now(), $r['id']]);
        $this->event((int) $r['id'], 'cancelled', $reason, $az->userId);
        $this->app->notifier()->notify((int) $r['user_id'], 'certs.status', 'certs.notify.cancelled', ['no' => (string) $r['request_no']], '/certs/r/' . $pid, 'cert.cancel:' . $pid);
    }

    public function waiveRating(Authorizer $az, string $pid, string $reason): void
    {
        $r = $this->hrRequest($az, $pid);
        if ($r['status'] !== 'received' || $r['rating'] !== null || $r['rating_waived_by'] !== null) {
            throw new BusinessException('certs.error.state', [], 409);
        }
        $reason = mb_substr(trim($reason), 0, 300);
        if (mb_strlen($reason) < 5) {
            throw new ValidationException(['waive_reason' => 'certs.error.reason']);
        }
        $this->app->db()->run('UPDATE cert_requests SET rating_waived_by = ?, rating_waive_reason = ?, updated_at = ? WHERE id = ?', [$az->userId, $reason, $this->now(), $r['id']]);
        $this->event((int) $r['id'], 'rating_waived', $reason, $az->userId);
        $this->app->audit()->record('certs.rating_waived', 'success', 'cert_request', $pid, (int) $r['legal_entity_id'], $reason);
    }

    /** @return list<array<string, mixed>> */
    public function events(int $requestId): array
    {
        return $this->app->db()->all('SELECT e.*, u.display_name AS actor FROM cert_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.request_id = ? ORDER BY e.id DESC', [$requestId]);
    }

    // ---- helpers -------------------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $r */
    private function setStatus(array $r, string $to, int $actor, ?string $note = null): void
    {
        $this->app->db()->run('UPDATE cert_requests SET status = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$to, $this->now(), $actor, $r['id']]);
        $this->event((int) $r['id'], $to, $note, $actor);
    }

    /** @param array<string, mixed> $r */
    private function voidAll(array $r, int $actor, string $reason): void
    {
        $this->app->db()->run("UPDATE cert_issues SET status = 'void', void_reason = ?, voided_by = ?, voided_at = ? WHERE request_id = ? AND status = 'issued'", [mb_substr($reason, 0, 300), $actor, $this->now(), $r['id']]);
    }

    private function event(int $requestId, string $action, ?string $note, ?int $actor): void
    {
        $this->app->db()->run('INSERT INTO cert_events (request_id, action, note, actor_id, occurred_at) VALUES (?, ?, ?, ?, ?)', [$requestId, $action, $note !== null ? mb_substr($note, 0, 500) : null, $actor, $this->now()]);
    }
}

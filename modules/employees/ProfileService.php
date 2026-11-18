<?php

declare(strict_types=1);

namespace Blue\Modules\Employees;

use DateTimeImmutable;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Ulid;

/**
 * M09 personal data (04 §6.19.1 A/C, §6.19.3, §6.19.4 — DEC-67):
 *  - who may see what is decided here, per employee, from the current employment's scope:
 *      employees.view       basic record (code, names, job, status)          — HR, HR Time, auditors
 *      employees.personal   birth date, gender, nationality, personal contacts, emergency contacts, education
 *      employees.identifiers ID numbers: last 4 always, the full number only after a fresh sign-in check (audited)
 *  - the employee sees and keeps their own contacts and emergency contacts (self-service); everything else is HR's
 *  - line managers never see personal data, ID numbers or files through this service
 * Values that identify a person are encrypted (birth date, ID numbers) and audit records only say THAT they changed.
 */
final class ProfileService
{
    public const CONTACTS = ['personal_phone', 'personal_email', 'address_current', 'address_registered'];
    public const EDUCATION = ['primary', 'secondary', 'vocational', 'high_vocational', 'bachelor', 'master', 'doctorate', 'other'];

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    /**
     * What $az may do with this employee (array from EmployeeService::get / byId with 'job').
     *
     * @param array<string, mixed> $e
     * @return array{self: bool, view: bool, manage: bool, approve: bool, personal: bool, identifiers: bool, report: bool,
     *               categories: list<string>}
     */
    public function access(Authorizer $az, array $e): array
    {
        $job = $e['job'] ?? null;
        $self = (int) ($this->app->db()->scalar('SELECT employee_id FROM users WHERE id = ?', [$az->userId]) ?? 0) === (int) $e['id'];
        $has = static fn (string $perm): bool => EmployeeService::covers($az->scope($perm), is_array($job) ? $job : null);
        $cats = [];
        foreach (FileRules::CATEGORIES as $c => $perm) {
            if ($has($perm)) {
                $cats[] = $c;
            }
        }
        return [
            'self' => $self, 'view' => $has('employees.view'), 'manage' => $has('employees.manage'), 'approve' => $has('employees.approve'),
            'personal' => $has('employees.personal'), 'identifiers' => $has('employees.identifiers'), 'report' => $has('employees.report'), 'categories' => $cats,
        ];
    }

    /**
     * Personal data for the screen. Sections the viewer may not see are null (not empty) so the view can say so.
     *
     * @param array<string, mixed> $e
     * @param array<string, mixed> $acc from access()
     * @return array<string, mixed>
     */
    public function profile(array $e, array $acc): array
    {
        $db = $this->app->db();
        $id = (int) $e['id'];
        $personal = $acc['personal'] || $acc['self'];
        $out = ['personal' => null, 'contacts' => null, 'emergency' => null, 'identifiers' => null, 'education' => null, 'prior_jobs' => null];
        if ($personal) {
            $out['personal'] = [
                'title' => $e['title'], 'birth_date' => $this->birthDate($e), 'gender' => $e['gender'], 'nationality' => $e['nationality'],
            ];
            $out['contacts'] = array_column($db->all('SELECT contact_type, value FROM employee_contacts WHERE employee_id = ?', [$id]), 'value', 'contact_type');
            $out['emergency'] = $db->all('SELECT public_id, name, relation, phone FROM employee_emergency_contacts WHERE employee_id = ? ORDER BY sort_order, id', [$id]);
            $out['education'] = $db->all('SELECT public_id, level, institution, field, graduated_year FROM employee_education WHERE employee_id = ? ORDER BY graduated_year DESC, id DESC', [$id]);
            $out['prior_jobs'] = $db->all('SELECT public_id, company, position, from_month, to_month FROM employee_prior_jobs WHERE employee_id = ? ORDER BY from_month DESC, id DESC', [$id]);
        }
        if ($acc['identifiers'] || $acc['personal'] || $acc['self']) {
            $out['identifiers'] = $db->all(
                "SELECT public_id, id_type, last4, issue_country, expiry_date, status, created_at FROM employee_identifiers WHERE employee_id = ? ORDER BY status = 'current' DESC, id DESC",
                [$id]
            );
        }
        return $out;
    }

    /** @param array<string, mixed> $e */
    public function birthDate(array $e): ?string
    {
        if (($e['birth_date_enc'] ?? null) === null || $e['birth_date_enc'] === '') {
            return null;
        }
        return $this->app->crypto()->decrypt((string) $e['birth_date_enc']);
    }

    /**
     * HR: title, birth date, gender, nationality, work e-mail/phone.
     *
     * @param array<string, mixed> $in
     */
    public function savePersonal(Authorizer $az, string $pid, array $in): void
    {
        $e = EmployeeService::of($this->app)->get($az, $pid, true);
        $acc = $this->access($az, $e);
        if (!$acc['personal']) {
            throw HttpException::forbidden();
        }
        $errors = [];
        $title = trim((string) ($in['title'] ?? ''));
        if (mb_strlen($title) > 20) {
            $errors['title'] = 'employees.error.title';
        }
        $birth = trim((string) ($in['birth_date'] ?? ''));
        $bd = $birth === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $birth);
        if ($birth !== '' && ($bd === false || $bd->format('Y-m-d') !== $birth || $birth >= (string) $e['hire_date'] || $birth < '1900-01-01')) {
            $errors['birth_date'] = 'employees.error.birth_date';
        }
        $gender = (string) ($in['gender'] ?? '');
        if ($gender !== '' && !in_array($gender, ['male', 'female', 'other', 'unspecified'], true)) {
            $errors['gender'] = 'form.invalid_choice';
        }
        $nat = strtoupper(trim((string) ($in['nationality'] ?? '')));
        if ($nat !== '' && preg_match('/^[A-Z]{2}$/', $nat) !== 1) {
            $errors['nationality'] = 'employees.error.country';
        }
        $wEmail = trim((string) ($in['work_email'] ?? ''));
        if ($wEmail !== '' && (filter_var($wEmail, FILTER_VALIDATE_EMAIL) === false || strlen($wEmail) > 190)) {
            $errors['work_email'] = 'employees.error.email';
        }
        $wPhone = trim((string) ($in['work_phone'] ?? ''));
        if ($wPhone !== '' && preg_match('/^[0-9+()#\- ]{3,40}$/', $wPhone) !== 1) {
            $errors['work_phone'] = 'employees.error.phone';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $oldBirth = $this->birthDate($e);
        $enc = $birth === '' ? null : $this->app->crypto()->encrypt($birth);
        $this->app->db()->run(
            'UPDATE employees SET title = ?, birth_date_enc = ?, gender = ?, nationality = ?, work_email = ?, work_phone = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$title === '' ? null : $title, $enc, $gender === '' ? null : $gender, $nat === '' ? null : $nat, $wEmail === '' ? null : $wEmail, $wPhone === '' ? null : $wPhone,
                $this->now(), $az->userId, $e['id']]
        );
        $diff = [];
        foreach (['title' => $title, 'gender' => $gender, 'nationality' => $nat, 'work_email' => $wEmail, 'work_phone' => $wPhone] as $k => $v) {
            if ((string) ($e[$k] ?? '') !== $v) {
                $diff[$k] = ['before' => $e[$k], 'after' => $v === '' ? null : $v];
            }
        }
        if ((string) $oldBirth !== $birth) {
            $diff['birth_date'] = 'changed'; // never the value itself in the audit trail
        }
        $this->app->audit()->record('employee.personal_updated', 'success', 'employee', $pid, (int) $e['job']['legal_entity_id'], null, $diff);
    }

    /**
     * Personal contacts + emergency contacts — by the employee (own record) or HR with employees.personal.
     *
     * @param array<string, mixed> $in contacts[type], emergency[n][name|relation|phone]
     */
    public function saveContacts(Authorizer $az, string $pid, array $in, bool $selfService): void
    {
        $svc = EmployeeService::of($this->app);
        if ($selfService) {
            $e = $svc->forUser($az->userId) ?? throw HttpException::notFound();
            if ((string) $e['public_id'] !== $pid) {
                throw HttpException::notFound();
            }
        } else {
            $e = $svc->get($az, $pid, true);
            if (!$this->access($az, $e)['personal']) {
                throw HttpException::forbidden();
            }
        }
        $errors = [];
        $contacts = [];
        foreach (self::CONTACTS as $type) {
            $v = trim((string) (((array) ($in['contacts'] ?? []))[$type] ?? ''));
            if ($v === '') {
                continue;
            }
            $ok = match ($type) {
                'personal_phone' => preg_match('/^[0-9+()#\- ]{3,40}$/', $v) === 1,
                'personal_email' => filter_var($v, FILTER_VALIDATE_EMAIL) !== false && strlen($v) <= 190,
                default => mb_strlen($v) <= 500,
            };
            if (!$ok) {
                $errors['contacts_' . $type] = 'employees.error.' . ($type === 'personal_email' ? 'email' : ($type === 'personal_phone' ? 'phone' : 'address'));
            }
            $contacts[$type] = $v;
        }
        $emergency = [];
        foreach (array_slice((array) ($in['emergency'] ?? []), 0, 3) as $i => $row) {
            $row = (array) $row;
            $name = trim((string) ($row['name'] ?? ''));
            $rel = trim((string) ($row['relation'] ?? ''));
            $phone = trim((string) ($row['phone'] ?? ''));
            if ($name === '' && $rel === '' && $phone === '') {
                continue;
            }
            if ($name === '' || mb_strlen($name) > 120 || $rel === '' || mb_strlen($rel) > 40 || preg_match('/^[0-9+()#\- ]{3,40}$/', $phone) !== 1) {
                $errors['emergency_' . $i] = 'employees.error.emergency';
            }
            $emergency[] = ['name' => $name, 'relation' => $rel, 'phone' => $phone];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $db = $this->app->db();
        $id = (int) $e['id'];
        $before = array_column($db->all('SELECT contact_type, value FROM employee_contacts WHERE employee_id = ?', [$id]), 'value', 'contact_type');
        $db->transactional(function () use ($db, $id, $contacts, $emergency, $az): void {
            $now = $this->now();
            foreach (self::CONTACTS as $type) {
                if (!isset($contacts[$type])) {
                    $db->run('DELETE FROM employee_contacts WHERE employee_id = ? AND contact_type = ?', [$id, $type]);
                    continue;
                }
                $db->run(
                    'INSERT INTO employee_contacts (public_id, created_at, created_by, updated_at, updated_by, employee_id, contact_type, value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by), row_version = row_version + 1',
                    [Ulid::generate($this->app->clock), $now, $az->userId, $now, $az->userId, $id, $type, $contacts[$type]]
                );
            }
            $db->run('DELETE FROM employee_emergency_contacts WHERE employee_id = ?', [$id]);
            foreach ($emergency as $n => $r) {
                $db->run(
                    'INSERT INTO employee_emergency_contacts (public_id, created_at, created_by, updated_at, updated_by, employee_id, name, relation, phone, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [Ulid::generate($this->app->clock), $now, $az->userId, $now, $az->userId, $id, $r['name'], $r['relation'], $r['phone'], $n + 1]
                );
            }
        });
        $changed = [];
        foreach (self::CONTACTS as $type) {
            if (($before[$type] ?? null) !== ($contacts[$type] ?? null)) {
                $changed[] = $type;
            }
        }
        $this->app->audit()->record(
            'employee.contacts_updated',
            'success',
            'employee',
            (string) $e['public_id'],
            $e['job'] !== null ? (int) $e['job']['legal_entity_id'] : null,
            $selfService ? 'self-service' : null,
            ['changed' => $changed, 'emergency_contacts' => count($emergency)]
        );
    }

    /**
     * Adds (or replaces the current one of the same type) an identity number. Thai citizen and tax IDs are checked with
     * the check digit; the same number on another employee is refused (keyed hash — the number is never searched in clear).
     */
    public function addIdentifier(Authorizer $az, string $pid, string $type, string $value, string $country, string $expiry): void
    {
        $e = EmployeeService::of($this->app)->get($az, $pid, true);
        if (!$this->access($az, $e)['identifiers']) {
            throw HttpException::forbidden();
        }
        $errors = [];
        if (!in_array($type, FileRules::IDENTIFIER_TYPES, true)) {
            $errors['id_type'] = 'form.invalid_choice';
        }
        $norm = strtoupper((string) preg_replace('/[\s\-]/', '', $value));
        if (in_array($type, ['TH_CITIZEN_ID', 'TH_TAX_ID'], true)) {
            if (!FileRules::thaiIdValid($norm)) {
                $errors['id_value'] = 'employees.error.thai_id';
            }
        } elseif (preg_match('/^[A-Z0-9]{4,30}$/', $norm) !== 1) {
            $errors['id_value'] = 'employees.error.id_value';
        }
        $country = strtoupper(trim($country));
        if ($country !== '' && preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            $errors['issue_country'] = 'employees.error.country';
        }
        $exp = trim($expiry);
        if ($exp !== '' && (DateTimeImmutable::createFromFormat('!Y-m-d', $exp) === false || $exp < '2000-01-01')) {
            $errors['expiry_date'] = 'employees.error.date';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $db = $this->app->db();
        $hash = $this->app->crypto()->keyedHash($type . '|' . $norm);
        $other = $db->scalar("SELECT employee_id FROM employee_identifiers WHERE id_type = ? AND value_hash = ? AND employee_id <> ? AND status = 'current' LIMIT 1", [$type, $hash, $e['id']]);
        if ($other !== null) {
            $this->app->audit()->record('employee.identifier_duplicate', 'denied', 'employee', $pid, (int) $e['job']['legal_entity_id'], null, ['id_type' => $type]);
            throw new ValidationException(['id_value' => 'employees.error.id_duplicate']);
        }
        $db->transactional(function () use ($db, $e, $type, $norm, $hash, $country, $exp, $az): void {
            $now = $this->now();
            $db->run("UPDATE employee_identifiers SET status = 'replaced', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE employee_id = ? AND id_type = ? AND status = 'current'", [$now, $az->userId, $e['id'], $type]);
            $db->run(
                'INSERT INTO employee_identifiers (public_id, created_at, created_by, updated_at, updated_by, employee_id, id_type, value_enc, value_hash, last4, issue_country, expiry_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [Ulid::generate($this->app->clock), $now, $az->userId, $now, $az->userId, $e['id'], $type, $this->app->crypto()->encrypt($norm), $hash, substr($norm, -4),
                    $country === '' ? null : $country, $exp === '' ? null : $exp]
            );
        });
        $this->app->audit()->record('employee.identifier_added', 'success', 'employee', $pid, (int) $e['job']['legal_entity_id'], null, ['id_type' => $type, 'last4' => substr($norm, -4)]);
    }

    /**
     * The full number for someone with employees.identifiers — the controller requires a fresh sign-in check first.
     */
    public function revealIdentifier(Authorizer $az, string $pid, string $identifierPid): string
    {
        $e = EmployeeService::of($this->app)->get($az, $pid);
        if (!$this->access($az, $e)['identifiers']) {
            throw HttpException::forbidden();
        }
        $row = $this->app->db()->one('SELECT * FROM employee_identifiers WHERE public_id = ? AND employee_id = ?', [$identifierPid, $e['id']]) ?? throw HttpException::notFound();
        $this->app->audit()->record('employee.identifier_revealed', 'success', 'employee', $pid, (int) $e['job']['legal_entity_id'], null, ['id_type' => $row['id_type'], 'last4' => $row['last4']]);
        return $this->app->crypto()->decrypt((string) $row['value_enc']);
    }

    /** @param array<string, mixed> $in level, institution, field, graduated_year */
    public function addEducation(Authorizer $az, string $pid, array $in): void
    {
        $e = $this->personalManage($az, $pid);
        $errors = [];
        $level = (string) ($in['level'] ?? '');
        if (!in_array($level, self::EDUCATION, true)) {
            $errors['level'] = 'form.invalid_choice';
        }
        $inst = trim((string) ($in['institution'] ?? ''));
        if ($inst === '' || mb_strlen($inst) > 160) {
            $errors['institution'] = 'employees.error.text';
        }
        $field = trim((string) ($in['field'] ?? ''));
        $year = trim((string) ($in['graduated_year'] ?? ''));
        if ($year !== '' && (preg_match('/^\d{4}$/', $year) !== 1 || (int) $year < 1950 || (int) $year > 2200)) {
            $errors['graduated_year'] = 'employees.error.year';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $y = $year === '' ? null : ((int) $year > 2400 ? (int) $year - 543 : (int) $year);
        $now = $this->now();
        $this->app->db()->run(
            'INSERT INTO employee_education (public_id, created_at, created_by, updated_at, updated_by, employee_id, level, institution, field, graduated_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [Ulid::generate($this->app->clock), $now, $az->userId, $now, $az->userId, $e['id'], $level, $inst, $field === '' ? null : mb_substr($field, 0, 160), $y]
        );
        $this->app->audit()->record('employee.education_added', 'success', 'employee', $pid, (int) $e['job']['legal_entity_id'], null, ['level' => $level]);
    }

    /** @param array<string, mixed> $in company, position, from_month, to_month (YYYY-MM) */
    public function addPriorJob(Authorizer $az, string $pid, array $in): void
    {
        $e = $this->personalManage($az, $pid);
        $errors = [];
        $company = trim((string) ($in['company'] ?? ''));
        if ($company === '' || mb_strlen($company) > 160) {
            $errors['company'] = 'employees.error.text';
        }
        $from = trim((string) ($in['from_month'] ?? ''));
        $to = trim((string) ($in['to_month'] ?? ''));
        foreach (['from_month' => $from, 'to_month' => $to] as $k => $v) {
            if ($v !== '' && preg_match('/^(19|20)\d\d-(0[1-9]|1[0-2])$/', $v) !== 1) {
                $errors[$k] = 'employees.error.month';
            }
        }
        if ($from !== '' && $to !== '' && $to < $from) {
            $errors['to_month'] = 'employees.error.month_order';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $pos = trim((string) ($in['position'] ?? ''));
        $now = $this->now();
        $this->app->db()->run(
            'INSERT INTO employee_prior_jobs (public_id, created_at, created_by, updated_at, updated_by, employee_id, company, position, from_month, to_month) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [Ulid::generate($this->app->clock), $now, $az->userId, $now, $az->userId, $e['id'], $company, $pos === '' ? null : mb_substr($pos, 0, 120), $from === '' ? null : $from, $to === '' ? null : $to]
        );
        $this->app->audit()->record('employee.prior_job_added', 'success', 'employee', $pid, (int) $e['job']['legal_entity_id']);
    }

    /** Removes one education / prior-job row (typing mistakes). */
    public function removeRow(Authorizer $az, string $pid, string $kind, string $rowPid): void
    {
        $e = $this->personalManage($az, $pid);
        $table = ['education' => 'employee_education', 'prior_job' => 'employee_prior_jobs'][$kind] ?? throw HttpException::notFound();
        $n = $this->app->db()->run("DELETE FROM {$table} WHERE public_id = ? AND employee_id = ?", [$rowPid, $e['id']])->rowCount();
        if ($n === 0) {
            throw HttpException::notFound();
        }
        $this->app->audit()->record('employee.' . $kind . '_removed', 'success', 'employee', $pid, (int) $e['job']['legal_entity_id']);
    }

    /** @return array<string, mixed> */
    private function personalManage(Authorizer $az, string $pid): array
    {
        $e = EmployeeService::of($this->app)->get($az, $pid, true);
        if (!$this->access($az, $e)['personal']) {
            throw HttpException::forbidden();
        }
        return $e;
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }
}

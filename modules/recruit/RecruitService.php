<?php

declare(strict_types=1);

namespace Blue\Modules\Recruit;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\EmployeeService;
use Blue\Modules\Employees\FileRules;
use Blue\Modules\Employees\PersonnelFileService;
use Blue\Modules\Employees\ProfileService;

/**
 * M08 Recruitment (phase 5E, DEC-76).
 *
 *  requisition  a manager asks for people: position, unit (their own — HR may pick any in scope), headcount, new or
 *               replacement (who leaves), salary range (internal), needed-by, why. Workflow recruit.requisition: the
 *               requester's line manager, then recruit.approve — steps are editable in the workflow settings.
 *  posting      only an APPROVED requisition can get a public job posting; the public page never shows salary range,
 *               budget or who asked. Publishing needs the company's applicant privacy notice.
 *  application  public, step by step on a phone: privacy notice + consent (typed name as signature, version kept),
 *               personal details (encrypted), ID number (encrypted + keyed hash + last 4 — repeats are caught), address,
 *               status, education, work history, languages and skills, start date, files. Status check with the
 *               application code + phone number.
 *  pipeline     applied → screening → interview → offer → hired, or rejected / withdrawn / on hold, each move with the
 *               person and (for the closing ones) a reason. Interviews; evaluators get a one-candidate link that expires.
 *  offer        salary + start date → workflow recruit.offer (recruit.approve, not the recruiter) → the candidate's answer.
 *  hire         only when the hire-readiness checklist is complete: consent, required files present and checked, an
 *               evaluation, an approved and accepted offer, a free place on the requisition, not already an employee.
 *               The employee is created in M09 (pre-hire until the start date) with the ID number and the files —
 *               never twice; the candidate keeps the link.
 *  retention    applications not hired are anonymised once the APPROVED rule for applicant_unsuccessful has passed
 *               (from the day they were closed); no approved rule = nothing is removed.
 */
final class RecruitService
{
    public const MAX_FILE_MB = 5;
    public const PUBLIC_STAGE = ['applied' => 'received', 'screening' => 'review', 'interview' => 'interview', 'offer' => 'offer', 'hired' => 'hired',
        'rejected' => 'closed', 'withdrawn' => 'withdrawn', 'on_hold' => 'review'];

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    public function emp(): EmployeeService
    {
        return EmployeeService::of($this->app);
    }

    public function today(): string
    {
        return $this->emp()->today();
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    /** @return list<int> companies where the person has $permission */
    public function companies(Authorizer $az, string $permission = 'recruit.manage'): array
    {
        $scope = $az->scope($permission);
        return array_values(array_filter(array_map('intval', array_column($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code'), 'id')), static fn (int $id): bool => $scope->coversLegalEntity($id)));
    }

    // ---- settings --------------------------------------------------------------------------------------------------------

    /** @return array{notice_text: string, notice_version: int, required_docs: list<string>} */
    public function settings(int $le): array
    {
        $r = $this->app->db()->one('SELECT * FROM rec_settings WHERE legal_entity_id = ?', [$le]);
        $req = array_values(array_intersect(array_keys(Lists::DOC_GROUPS), explode(',', (string) ($r['required_docs'] ?? 'education'))));
        return ['notice_text' => (string) ($r['notice_text'] ?? ''), 'notice_version' => (int) ($r['notice_version'] ?? 0), 'required_docs' => $req];
    }

    /** @param list<string> $required */
    public function saveSettings(Authorizer $az, int $le, string $notice, array $required): void
    {
        if (!$az->scope('recruit.settings')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $notice = trim(str_replace("\r\n", "\n", $notice));
        if ($notice !== '' && (mb_strlen($notice) < 50 || mb_strlen($notice) > 8000)) {
            throw new ValidationException(['notice_text' => 'recruit.error.notice']);
        }
        $required = array_values(array_intersect(array_keys(Lists::DOC_GROUPS), $required));
        $db = $this->app->db();
        $cur = $db->one('SELECT * FROM rec_settings WHERE legal_entity_id = ?', [$le]);
        $now = $this->now();
        if ($cur === null) {
            $db->run(
                'INSERT INTO rec_settings (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, notice_text, notice_version, required_docs) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [Ulid::generate($this->app->clock), $now, $az->userId, $now, $az->userId, $le, $notice !== '' ? $notice : null, $notice !== '' ? 1 : 0, implode(',', $required)]
            );
        } else {
            $changed = $notice !== (string) ($cur['notice_text'] ?? '');
            $db->run(
                'UPDATE rec_settings SET notice_text = ?, notice_version = notice_version + ?, required_docs = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
                [$notice !== '' ? $notice : null, $changed && $notice !== '' ? 1 : 0, implode(',', $required), $now, $az->userId, $cur['id']]
            );
        }
        $this->app->audit()->record('recruit.settings_saved', 'success', 'legal_entity', (string) $db->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]), $le, null, ['required' => $required]);
    }

    // ---- requisitions ---------------------------------------------------------------------------------------------------

    /**
     * Where the requester may ask for people: their own unit (managers), or any unit of a company in the HR scope.
     *
     * @return array{le: int|null, department: int|null, site: int|null, free: bool}
     */
    public function requestContext(Authorizer $az): array
    {
        $job = $this->emp()->forUser($az->userId);
        $free = $this->companies($az) !== [];
        return ['le' => isset($job['job']['legal_entity_id']) ? (int) $job['job']['legal_entity_id'] : null, 'department' => isset($job['job']['department_id']) ? (int) $job['job']['department_id'] : null,
            'site' => isset($job['job']['site_id']) ? (int) $job['job']['site_id'] : null, 'free' => $free];
    }

    /** @param array<string, mixed> $in */
    public function createRequisition(Authorizer $az, array $in): string
    {
        if (!$az->can('recruit.request')) {
            throw HttpException::forbidden();
        }
        $ctx = $this->requestContext($az);
        $db = $this->app->db();
        $errors = [];
        if ($ctx['free'] && ($in['department'] ?? '') !== '') {
            $dept = $db->one('SELECT id, legal_entity_id, site_id FROM departments WHERE public_id = ?', [(string) $in['department']]);
            if ($dept === null || !$az->scope('recruit.manage')->coversLegalEntity((int) $dept['legal_entity_id'])) {
                $errors['department'] = 'form.invalid_choice';
                $dept = null;
            }
            $le = (int) ($dept['legal_entity_id'] ?? 0);
            $deptId = $dept !== null ? (int) $dept['id'] : null;
            $site = $dept !== null && $dept['site_id'] !== null ? (int) $dept['site_id'] : null;
        } else {
            // "locked to the requester's unit" (M08 §1)
            $le = (int) ($ctx['le'] ?? 0);
            $deptId = $ctx['department'];
            $site = $ctx['site'];
            if ($le === 0) {
                $errors['department'] = 'recruit.error.no_unit';
            }
        }
        $title = mb_substr(trim((string) ($in['position_title'] ?? '')), 0, 120);
        if (mb_strlen($title) < 2) {
            $errors['position_title'] = 'recruit.error.title';
        }
        $hc = (string) ($in['headcount'] ?? '');
        if (!ctype_digit($hc) || (int) $hc < 1 || (int) $hc > 50) {
            $errors['headcount'] = 'recruit.error.headcount';
        }
        $reason = ($in['reason'] ?? '') === 'replacement' ? 'replacement' : 'new';
        $replaces = mb_substr(trim((string) ($in['replaces'] ?? '')), 0, 500);
        if ($reason === 'replacement' && mb_strlen($replaces) < 2) {
            $errors['replaces'] = 'recruit.error.replaces';
        }
        $type = (string) ($in['employment_type'] ?? '');
        if (!in_array($type, EmployeeService::TYPES, true)) {
            $errors['employment_type'] = 'form.invalid_choice';
        }
        $min = $this->money((string) ($in['salary_min'] ?? ''));
        $max = $this->money((string) ($in['salary_max'] ?? ''));
        if ($min === false || $max === false || ($min !== null && $max !== null && $min > $max)) {
            $errors['salary_max'] = 'recruit.error.salary';
        }
        $need = trim((string) ($in['needed_by'] ?? ''));
        if ($need !== '' && (!$this->isDate($need) || $need < $this->today())) {
            $errors['needed_by'] = 'recruit.error.needed_by';
        }
        $why = mb_substr(trim((string) ($in['justification'] ?? '')), 0, 1000);
        if (mb_strlen($why) < 10) {
            $errors['justification'] = 'recruit.error.justification';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        // M11 (when switched on): a request for NEW people is compared with the active manpower plan of the year
        $budget = null;
        if ($reason === 'new' && $this->app->modules()->isEnabled('manpower')) {
            $budget = \Blue\Modules\Manpower\ManpowerService::of($this->app)->checkRequest($le, $deptId, $title, (int) $hc, $need !== '' ? $need : $this->today());
            if ($budget['result'] === 'over' && $budget['mode'] === 'block') {
                $this->app->audit()->record('recruit.requisition_over_budget', 'failure', 'rec_requisition', null, $le, null, ['planned' => $budget['planned'], 'used' => (int) $budget['actual'] + (int) $budget['open'], 'asked' => (int) $hc]);
                throw new ValidationException(['headcount' => 'manpower.error.over_budget']);
            }
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $db->transactional(function () use ($db, $pid, $now, $az, $le, $site, $deptId, $title, $hc, $reason, $replaces, $type, $min, $max, $need, $why, $budget): void {
            $db->run(
                "INSERT INTO rec_requisitions (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, site_id, department_id, position_title, headcount, reason, replaces,
                    employment_type, salary_min, salary_max, needed_by, justification, requester_user_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                [$pid, $now, $az->userId, $now, $az->userId, $le, $site, $deptId, $title, (int) $hc, $reason, $reason === 'replacement' ? $replaces : null, $type, $min, $max, $need !== '' ? $need : null, $why, $az->userId]
            );
            if ($budget !== null) {
                \Blue\Modules\Manpower\ManpowerService::of($this->app)->recordCheck((int) $db->scalar('SELECT id FROM rec_requisitions WHERE public_id = ?', [$pid]), $budget);
            }
            $this->app->workflow()->start('recruit.requisition', $pid, $title . ' × ' . (int) $hc, $le, $az->userId, $site, $deptId);
        });
        $this->app->audit()->record('recruit.requisition_submitted', 'success', 'rec_requisition', $pid, $le, null, ['headcount' => (int) $hc]);
        return $pid;
    }

    /** @return string|null the manpower-budget check of a requisition in words (M11, when switched on) */
    public function budgetNote(int $requisitionId): ?string
    {
        if (!$this->app->modules()->isEnabled('manpower')) {
            return null;
        }
        $svc = \Blue\Modules\Manpower\ManpowerService::of($this->app);
        $c = $svc->checkFor($requisitionId);
        return $c !== null ? $svc->describeCheck($c) : null;
    }

    /** @return list<int> line manager(s) of the person who asked */
    public function managerUserIds(string $reqPid): array
    {
        $uid = $this->app->db()->scalar('SELECT requester_user_id FROM rec_requisitions WHERE public_id = ?', [$reqPid]);
        $e = $uid !== null ? $this->emp()->forUser((int) $uid) : null;
        return $e !== null ? $this->emp()->managerUserIds((int) $e['id'], $this->today()) : [];
    }

    public function onRequisitionApproved(string $pid): void
    {
        $db = $this->app->db();
        $r = $db->one('SELECT * FROM rec_requisitions WHERE public_id = ?', [$pid]);
        if ($r === null || !in_array($r['status'], ['pending', 'returned'], true)) {
            return;
        }
        $db->run("UPDATE rec_requisitions SET status = 'approved', approved_at = ?, updated_at = ? WHERE id = ?", [$this->now(), $this->now(), $r['id']]);
        $this->app->notifier()->notify((int) $r['requester_user_id'], 'recruit.requisition', 'recruit.notify.approved', ['title' => (string) $r['position_title']], '/recruit/requisitions', 'rec.req:' . $pid);
        foreach ($this->holders('recruit.manage', (int) $r['legal_entity_id']) as $uid) {
            $this->app->notifier()->notify($uid, 'recruit.requisition', 'recruit.notify.to_ta', ['title' => (string) $r['position_title']], '/recruit/r/' . $pid, 'rec.req.ta:' . $pid . ':' . $uid);
        }
    }

    public function onRequisitionClosed(string $pid, string $outcome): void
    {
        $status = $outcome === 'rejected' ? 'rejected' : 'cancelled';
        $this->app->db()->run("UPDATE rec_requisitions SET status = ?, updated_at = ? WHERE public_id = ? AND status IN ('pending','returned')", [$status, $this->now(), $pid]);
    }

    /**
     * Requisitions the person asked for, plus (recruiters) every requisition of their companies.
     *
     * @return list<array<string, mixed>>
     */
    public function requisitions(Authorizer $az): array
    {
        $les = $this->companies($az);
        $rows = $this->app->db()->all(
            'SELECT r.*, (SELECT COUNT(*) FROM rec_candidates c JOIN rec_postings p ON p.id = c.posting_id WHERE p.requisition_id = r.id AND c.stage = \'hired\') AS hired,
                    (SELECT COUNT(*) FROM rec_postings p WHERE p.requisition_id = r.id AND p.status = \'published\') AS published
               FROM rec_requisitions r WHERE r.requester_user_id = ?' . ($les !== [] ? ' OR r.legal_entity_id IN (' . implode(',', $les) . ')' : '') . ' ORDER BY r.id DESC LIMIT 300',
            [$az->userId]
        );
        return $rows;
    }

    /** @return array<string, mixed> a requisition the person may see (asked for it, or recruiter of the company) */
    public function requisition(Authorizer $az, string $pid): array
    {
        $r = $this->app->db()->one('SELECT * FROM rec_requisitions WHERE public_id = ?', [$pid]);
        if ($r === null || ((int) $r['requester_user_id'] !== $az->userId && !$az->scope('recruit.manage')->coversLegalEntity((int) $r['legal_entity_id']))) {
            throw HttpException::notFound();
        }
        return $r;
    }

    // ---- postings ------------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $in */
    public function savePosting(Authorizer $az, string $reqPid, array $in, ?string $postingPid = null): string
    {
        $r = $this->requisition($az, $reqPid);
        if (!$az->scope('recruit.manage')->coversLegalEntity((int) $r['legal_entity_id'])) {
            throw HttpException::forbidden();
        }
        if ($r['status'] !== 'approved') {
            throw new BusinessException('recruit.error.not_approved', [], 409);
        }
        $errors = [];
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 120);
        if (mb_strlen($title) < 2) {
            $errors['title'] = 'recruit.error.title';
        }
        $desc = mb_substr(trim(str_replace("\r\n", "\n", (string) ($in['description'] ?? ''))), 0, 8000);
        if (mb_strlen($desc) < 20) {
            $errors['description'] = 'recruit.error.description';
        }
        $close = trim((string) ($in['closes_on'] ?? ''));
        if ($close !== '' && (!$this->isDate($close) || $close < $this->today())) {
            $errors['closes_on'] = 'recruit.error.closes_on';
        }
        $loc = mb_substr(trim((string) ($in['location'] ?? '')), 0, 120);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $db = $this->app->db();
        $now = $this->now();
        if ($postingPid !== null) {
            $p = $this->posting($az, $postingPid);
            $db->run(
                'UPDATE rec_postings SET title = ?, description = ?, location = ?, closes_on = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
                [$title, $desc, $loc !== '' ? $loc : null, $close !== '' ? $close : null, $now, $az->userId, $p['id']]
            );
            $this->app->audit()->record('recruit.posting_edited', 'success', 'rec_posting', $postingPid, (int) $r['legal_entity_id']);
            return $postingPid;
        }
        $pid = Ulid::generate($this->app->clock);
        $db->run(
            'INSERT INTO rec_postings (public_id, created_at, created_by, updated_at, updated_by, requisition_id, legal_entity_id, title, description, location, closes_on) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $now, $az->userId, $now, $az->userId, $r['id'], $r['legal_entity_id'], $title, $desc, $loc !== '' ? $loc : null, $close !== '' ? $close : null]
        );
        $this->app->audit()->record('recruit.posting_created', 'success', 'rec_posting', $pid, (int) $r['legal_entity_id']);
        return $pid;
    }

    /** @return array<string, mixed> */
    public function posting(Authorizer $az, string $pid): array
    {
        $p = $this->app->db()->one('SELECT p.*, r.public_id AS req_pid, r.headcount, r.position_title, r.requester_user_id, r.status AS req_status FROM rec_postings p JOIN rec_requisitions r ON r.id = p.requisition_id WHERE p.public_id = ?', [$pid]);
        if ($p === null || ((int) $p['requester_user_id'] !== $az->userId && !$az->scope('recruit.manage')->coversLegalEntity((int) $p['legal_entity_id']))) {
            throw HttpException::notFound();
        }
        return $p;
    }

    /** @return list<array<string, mixed>> postings of a requisition */
    public function postings(int $reqId): array
    {
        return $this->app->db()->all(
            "SELECT p.*, (SELECT COUNT(*) FROM rec_candidates c WHERE c.posting_id = p.id) AS applicants FROM rec_postings p WHERE p.requisition_id = ? ORDER BY p.id",
            [$reqId]
        );
    }

    public function setPostingStatus(Authorizer $az, string $pid, string $to): void
    {
        $p = $this->posting($az, $pid);
        if (!$az->scope('recruit.manage')->coversLegalEntity((int) $p['legal_entity_id'])) {
            throw HttpException::forbidden();
        }
        if ($to === 'published') {
            if ($p['req_status'] !== 'approved') {
                throw new BusinessException('recruit.error.not_approved', [], 409);
            }
            if ($this->settings((int) $p['legal_entity_id'])['notice_text'] === '') {
                throw new BusinessException('recruit.error.no_notice', [], 409);
            }
        }
        if (!in_array($to, ['published', 'closed'], true) || $p['status'] === $to) {
            throw new BusinessException('recruit.error.status', [], 409);
        }
        $this->app->db()->run(
            'UPDATE rec_postings SET status = ?, published_at = COALESCE(published_at, ?), updated_at = ?, updated_by = ? WHERE id = ?',
            [$to, $to === 'published' ? $this->now() : null, $this->now(), $az->userId, $p['id']]
        );
        $this->app->audit()->record('recruit.posting_' . $to, 'success', 'rec_posting', $pid, (int) $p['legal_entity_id']);
    }

    // ---- the public side ----------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> open job postings (public — never salary, budget or the requester) */
    public function openPostings(string $q = ''): array
    {
        $args = [$this->today()];
        $where = '';
        if ($q !== '') {
            $where = ' AND (p.title LIKE ? OR p.description LIKE ? OR p.location LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($args, $like, $like, $like);
        }
        return $this->app->db()->all(
            "SELECT p.public_id, p.title, p.description, p.location, p.closes_on, p.published_at, l.code AS company FROM rec_postings p JOIN legal_entities l ON l.id = p.legal_entity_id
              WHERE p.status = 'published' AND (p.closes_on IS NULL OR p.closes_on >= ?){$where} ORDER BY p.published_at DESC LIMIT 200",
            $args
        );
    }

    /** @return array<string, mixed> an open posting + the company's applicant notice */
    public function openPosting(string $pid): array
    {
        $p = $this->app->db()->one(
            "SELECT p.id, p.public_id, p.title, p.description, p.location, p.closes_on, p.legal_entity_id, l.code AS company FROM rec_postings p JOIN legal_entities l ON l.id = p.legal_entity_id
              WHERE p.public_id = ? AND p.status = 'published' AND (p.closes_on IS NULL OR p.closes_on >= ?)",
            [$pid, $this->today()]
        );
        if ($p === null) {
            throw HttpException::notFound();
        }
        return $p + ['notice' => $this->settings((int) $p['legal_entity_id'])];
    }

    /**
     * A new application from the public form.
     *
     * @param array<string, mixed> $in
     * @param array<string, UploadedFile|null> $files group => file
     * @return string the application code (for the status check)
     */
    public function apply(string $postingPid, array $in, array $files, string $ip): string
    {
        $p = $this->openPosting($postingPid);
        [$row, $profile, $errors] = $this->validateApplication($in, (int) $p['notice']['notice_version']);
        foreach ($files as $g => $f) {
            if ($f !== null && $f->error !== UPLOAD_ERR_NO_FILE && (!isset(Lists::DOC_GROUPS[$g]) || !$f->ok() || $f->size > self::MAX_FILE_MB * 1048576)) {
                $errors['file_' . $g] = 'recruit.error.file';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $db = $this->app->db();
        if ($row['id_hash'] !== null && $db->scalar("SELECT id FROM rec_candidates WHERE posting_id = ? AND id_hash = ? AND stage NOT IN ('withdrawn')", [$p['id'], $row['id_hash']]) !== null) {
            throw new ValidationException(['id_number' => 'recruit.error.already_applied']);
        }
        $code = $this->newCode();
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $db->transactional(function () use ($db, $p, $row, $profile, $files, $code, $pid, $now, $ip): void {
            $db->run(
                'INSERT INTO rec_candidates (public_id, created_at, created_by, updated_at, updated_by, posting_id, legal_entity_id, app_code, name, email, phone, expected_salary, available_from,
                    profile_enc, id_type, id_enc, id_hash, id_last4, consent_at, consent_name, consent_ip, notice_version, talent_consent, source, stage, stage_at)
                 VALUES (?, ?, NULL, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'web\', \'applied\', ?)',
                [$pid, $now, $now, $p['id'], $p['legal_entity_id'], $code, $row['name'], $row['email'], $row['phone'], $row['expected_salary'], $row['available_from'],
                    base64_encode($this->app->crypto()->encrypt((string) json_encode($profile, JSON_UNESCAPED_UNICODE))), $row['id_type'], $row['id_enc'], $row['id_hash'], $row['id_last4'],
                    $now, $row['consent_name'], mb_substr($ip, 0, 45), $row['notice_version'], $row['talent_consent'], $now]
            );
            $cid = (int) $db->scalar('SELECT id FROM rec_candidates WHERE public_id = ?', [$pid]);
            foreach ($files as $g => $f) {
                if ($f !== null && $f->error !== UPLOAD_ERR_NO_FILE) {
                    $this->storeFile($cid, $g, $f, null, (string) $p['title']);
                }
            }
            $this->event($cid, 'applied', null, 'applied', null, null);
        });
        foreach ($this->holders('recruit.manage', (int) $p['legal_entity_id']) as $uid) {
            $this->app->notifier()->notify($uid, 'recruit.applied', 'recruit.notify.applied', ['title' => (string) $p['title']], '/recruit/c/' . $pid, 'rec.apply:' . $pid . ':' . $uid);
        }
        $this->app->audit()->record('recruit.applied', 'success', 'rec_candidate', $pid, (int) $p['legal_entity_id']);
        return $code;
    }

    /**
     * @param array<string, mixed> $in
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, string>}
     */
    private function validateApplication(array $in, int $noticeVersion): array
    {
        $s = static fn (string $k, int $max = 160): string => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        $errors = [];
        if (($in['consent'] ?? '') !== '1' || $noticeVersion < 1) {
            $errors['consent'] = 'recruit.error.consent';
        }
        $name = $s('full_name');
        if (mb_strlen($name) < 3) {
            $errors['full_name'] = 'recruit.error.name';
        }
        if (mb_strtolower(preg_replace('/\s+/u', ' ', $s('consent_name')) ?? '') !== mb_strtolower(preg_replace('/\s+/u', ' ', $name) ?? '')) {
            $errors['consent_name'] = 'recruit.error.consent_name';
        }
        $phone = preg_replace('/[^0-9+]/', '', $s('phone', 30)) ?? '';
        if (preg_match('/^\+?[0-9]{9,15}$/', $phone) !== 1) {
            $errors['phone'] = 'recruit.error.phone';
        }
        $email = $s('email');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'recruit.error.email';
        }
        $birth = $s('birth_date', 10);
        if ($birth !== '' && (!$this->isDate($birth) || $birth > $this->today() || $birth < '1940-01-01')) {
            $errors['birth_date'] = 'recruit.error.date';
        }
        $idType = in_array($in['id_type'] ?? '', ['TH_CITIZEN_ID', 'TH_PASSPORT', 'FOREIGN_PASSPORT', 'TH_PINK_CARD'], true) ? (string) $in['id_type'] : 'TH_CITIZEN_ID';
        $idNo = strtoupper((string) preg_replace('/[\s\-]/', '', $s('id_number', 30)));
        if ($idNo === '') {
            $errors['id_number'] = 'recruit.error.id_number';
        } elseif ($idType === 'TH_CITIZEN_ID' ? !FileRules::thaiIdValid($idNo) : preg_match('/^[A-Z0-9]{5,20}$/', $idNo) !== 1) {
            $errors['id_number'] = 'recruit.error.id_number';
        }
        $salary = $this->money($s('expected_salary', 20));
        if ($salary === false) {
            $errors['expected_salary'] = 'recruit.error.money';
        }
        $avail = $s('available_from', 10);
        if ($avail !== '' && (!$this->isDate($avail) || $avail < $this->today())) {
            $errors['available_from'] = 'recruit.error.date';
        }
        $province = $s('province', 60);
        if ($province !== '' && !in_array($province, Lists::PROVINCES, true)) {
            $errors['province'] = 'form.invalid_choice';
        }
        $pick = static fn (string $k, array $list): string => is_string($in[$k] ?? null) && in_array($in[$k], $list, true) ? $in[$k] : '';
        $education = [];
        foreach ((array) ($in['edu'] ?? []) as $row) {
            $row = (array) $row;
            $inst = mb_substr(trim((string) ($row['institution'] ?? '')), 0, 160);
            if ($inst === '') {
                continue;
            }
            $education[] = ['level' => in_array($row['level'] ?? '', Lists::EDU_LEVELS, true) ? (string) $row['level'] : '', 'institution' => $inst,
                'major' => mb_substr(trim((string) ($row['major'] ?? '')), 0, 120), 'year' => preg_match('/^(19|20)\d{2}$/', (string) ($row['year'] ?? '')) === 1 ? (string) $row['year'] : '',
                'gpa' => preg_match('/^[0-4](\.\d{1,2})?$/', (string) ($row['gpa'] ?? '')) === 1 ? (string) $row['gpa'] : ''];
        }
        $work = [];
        foreach ((array) ($in['work'] ?? []) as $row) {
            $row = (array) $row;
            $company = mb_substr(trim((string) ($row['company'] ?? '')), 0, 160);
            if ($company === '') {
                continue;
            }
            $from = preg_match('/^(19|20)\d{2}-(0[1-9]|1[0-2])$/', (string) ($row['from'] ?? '')) === 1 ? (string) $row['from'] : '';
            $to = preg_match('/^(19|20)\d{2}-(0[1-9]|1[0-2])$/', (string) ($row['to'] ?? '')) === 1 ? (string) $row['to'] : '';
            if ($from !== '' && $to !== '' && $to < $from) {
                $errors['work'] = 'recruit.error.work_dates';
            }
            $work[] = ['company' => $company, 'position' => mb_substr(trim((string) ($row['position'] ?? '')), 0, 120), 'from' => $from, 'to' => $to,
                'reason' => mb_substr(trim((string) ($row['reason'] ?? '')), 0, 200)];
        }
        $profile = [
            'nickname' => $s('nickname', 60), 'birth_date' => $birth, 'nationality' => $s('nationality', 60), 'gender' => $pick('gender', ['male', 'female', 'other', '']),
            'address' => $s('address', 300), 'province' => $province, 'postcode' => preg_match('/^\d{5}$/', $s('postcode', 5)) === 1 ? $s('postcode', 5) : '',
            'marital' => $pick('marital', Lists::MARITAL), 'military' => $pick('military', Lists::MILITARY),
            'emergency_name' => $s('emergency_name', 120), 'emergency_phone' => $s('emergency_phone', 30), 'emergency_relation' => $s('emergency_relation', 60),
            'education' => array_slice($education, 0, 5), 'work' => array_slice($work, 0, 8),
            'lang_th' => $pick('lang_th', Lists::LANG_LEVELS), 'lang_en' => $pick('lang_en', Lists::LANG_LEVELS), 'lang_other' => $s('lang_other', 120),
            'skills' => $s('skills', 1000), 'heard_from' => $s('heard_from', 120),
        ];
        $crypto = $this->app->crypto();
        return [[
            'name' => $name, 'email' => $email !== '' ? $email : null, 'phone' => $phone, 'expected_salary' => $salary === false ? null : $salary,
            'available_from' => $avail !== '' ? $avail : null, 'id_type' => $idNo !== '' ? $idType : null,
            'id_enc' => $idNo !== '' ? base64_encode($crypto->encrypt($idNo)) : null, 'id_hash' => $idNo !== '' ? $crypto->keyedHash($idType . '|' . $idNo) : null,
            'id_last4' => $idNo !== '' ? substr($idNo, -4) : null, 'consent_name' => $s('consent_name'), 'notice_version' => $noticeVersion,
            'talent_consent' => ($in['talent_consent'] ?? '') === '1' ? 1 : 0,
        ], $profile, $errors];
    }

    /**
     * A talent-pool person put forward for a posting (M49): an application with source talent_pool that points back to
     * the profile (nothing copied beyond name and contacts). Straight to screening.
     *
     * @param array{name: string, email: ?string, phone: ?string} $person
     */
    public function addFromTalent(Authorizer $az, string $postingPid, array $person, int $talentId): string
    {
        $p = $this->posting($az, $postingPid);
        if (!$az->scope('recruit.manage')->coversLegalEntity((int) $p['legal_entity_id'])) {
            throw HttpException::forbidden();
        }
        if ($p['status'] === 'closed') {
            throw new BusinessException('recruit.error.status', [], 409);
        }
        $db = $this->app->db();
        if ($db->scalar("SELECT id FROM rec_candidates WHERE posting_id = ? AND talent_id = ? AND stage NOT IN ('withdrawn','rejected')", [$p['id'], $talentId]) !== null) {
            throw new BusinessException('recruit.error.already_linked', [], 409);
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $db->run(
            "INSERT INTO rec_candidates (public_id, created_at, created_by, updated_at, updated_by, posting_id, legal_entity_id, app_code, name, email, phone, source, talent_id, stage, stage_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'talent_pool', ?, 'screening', ?)",
            [$pid, $now, $az->userId, $now, $az->userId, $p['id'], $p['legal_entity_id'], $this->newCode(), mb_substr($person['name'], 0, 160), $person['email'], (string) ($person['phone'] ?? ''), $talentId, $now]
        );
        $cid = (int) $db->scalar('SELECT id FROM rec_candidates WHERE public_id = ?', [$pid]);
        $this->event($cid, 'moved', null, 'screening', 'talent_pool', $az->userId);
        $this->app->audit()->record('recruit.from_talent', 'success', 'rec_candidate', $pid, (int) $p['legal_entity_id']);
        return $pid;
    }

    /** @return list<array<string, mixed>> postings a recruiter may put people forward to (not closed) */
    public function linkablePostings(Authorizer $az, int $le): array
    {
        if (!$az->scope('recruit.manage')->coversLegalEntity($le)) {
            return [];
        }
        return $this->app->db()->all("SELECT public_id, title, status FROM rec_postings WHERE legal_entity_id = ? AND status <> 'closed' ORDER BY id DESC LIMIT 100", [$le]);
    }

    /** @return array<string, mixed>|null the application behind a talent-pool consent (name, contacts, profile) for M49 */
    public function forTalent(Authorizer $az, string $candidatePid): ?array
    {
        $c = $this->candidate($az, $candidatePid);
        if (!$c['recruiter'] || (int) $c['talent_consent'] !== 1 || $c['anonymized_at'] !== null) {
            return null;
        }
        return $c;
    }

    /** @return array<string, mixed>|null posting title + public stage for an application code and the phone number given */
    public function statusCheck(string $code, string $phone): ?array
    {
        $phone = preg_replace('/[^0-9+]/', '', $phone) ?? '';
        $c = $this->app->db()->one(
            'SELECT c.stage, c.phone, c.created_at, p.title FROM rec_candidates c JOIN rec_postings p ON p.id = c.posting_id WHERE c.app_code = ? AND c.anonymized_at IS NULL',
            [strtoupper(trim($code))]
        );
        if ($c === null || $phone === '' || !hash_equals((string) $c['phone'], $phone)) {
            return null;
        }
        return ['title' => (string) $c['title'], 'stage' => self::PUBLIC_STAGE[(string) $c['stage']], 'since' => (string) $c['created_at']];
    }

    // ---- pipeline ------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> applications of a posting, with flags for the recruiter */
    public function pipeline(Authorizer $az, string $postingPid, string $stage = ''): array
    {
        $p = $this->posting($az, $postingPid);
        $args = [$p['id']];
        $where = '';
        if (in_array($stage, Lists::STAGES, true)) {
            $where = ' AND c.stage = ?';
            $args[] = $stage;
        }
        return $this->app->db()->all(
            "SELECT c.public_id, c.app_code, c.name, c.phone, c.email, c.stage, c.stage_at, c.created_at, c.expected_salary, c.source, c.anonymized_at,
                    (SELECT COUNT(*) FROM rec_candidates d WHERE d.id_hash = c.id_hash AND d.id <> c.id) AS other_applications,
                    (SELECT COUNT(*) FROM rec_files f WHERE f.candidate_id = c.id AND f.status = 'current') AS files,
                    (SELECT ROUND(AVG(e.score), 1) FROM rec_evaluations e WHERE e.candidate_id = c.id AND e.submitted_at IS NOT NULL) AS score
               FROM rec_candidates c WHERE c.posting_id = ?{$where} ORDER BY FIELD(c.stage, 'offer','interview','screening','applied','on_hold','hired','rejected','withdrawn'), c.id DESC",
            $args
        );
    }

    /**
     * One application. Recruiters see everything except the full ID number; the manager who asked sees the application
     * without contact details or the ID.
     *
     * @return array<string, mixed>
     */
    public function candidate(Authorizer $az, string $pid): array
    {
        $c = $this->app->db()->one(
            'SELECT c.*, p.public_id AS posting_pid, p.title AS posting_title, r.public_id AS req_pid, r.requester_user_id, r.position_title, r.employment_type AS req_type
               FROM rec_candidates c JOIN rec_postings p ON p.id = c.posting_id JOIN rec_requisitions r ON r.id = p.requisition_id WHERE c.public_id = ?',
            [$pid]
        );
        if ($c === null) {
            throw HttpException::notFound();
        }
        $recruiter = $az->scope('recruit.manage')->coversLegalEntity((int) $c['legal_entity_id']);
        if (!$recruiter && (int) $c['requester_user_id'] !== $az->userId) {
            throw HttpException::notFound();
        }
        $c['recruiter'] = $recruiter;
        $c['profile'] = $c['profile_enc'] !== null ? (array) json_decode($this->app->crypto()->decrypt((string) base64_decode((string) $c['profile_enc'], true)), true) : [];
        unset($c['profile_enc'], $c['id_enc'], $c['id_hash']);
        if (!$recruiter) {
            $c['email'] = null;
            $c['phone'] = '';
            $c['id_last4'] = null;
            unset($c['profile']['emergency_name'], $c['profile']['emergency_phone'], $c['profile']['address'], $c['profile']['birth_date']);
        }
        return $c;
    }

    /** @return array<string, mixed> recruiter-only access check */
    private function managedCandidate(Authorizer $az, string $pid): array
    {
        $c = $this->app->db()->one('SELECT * FROM rec_candidates WHERE public_id = ?', [$pid]);
        if ($c === null || !$az->scope('recruit.manage')->coversLegalEntity((int) $c['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        if ($c['anonymized_at'] !== null) {
            throw new BusinessException('recruit.error.anonymized', [], 409);
        }
        return $c;
    }

    public function move(Authorizer $az, string $pid, string $to, string $note): void
    {
        $c = $this->managedCandidate($az, $pid);
        if (!in_array($to, Lists::MOVES[(string) $c['stage']] ?? [], true)) {
            throw new BusinessException('recruit.error.move', [], 409);
        }
        $note = mb_substr(trim($note), 0, 500);
        if (in_array($to, ['rejected', 'withdrawn', 'on_hold'], true) && mb_strlen($note) < 3) {
            throw new ValidationException(['note' => 'recruit.error.reason']);
        }
        $now = $this->now();
        $this->app->db()->run(
            'UPDATE rec_candidates SET stage = ?, stage_at = ?, closed_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$to, $now, in_array($to, ['rejected', 'withdrawn'], true) ? $now : null, $now, $az->userId, $c['id']]
        );
        if (in_array($to, ['rejected', 'withdrawn'], true)) {
            $this->app->db()->run("UPDATE rec_offers SET status = 'cancelled', updated_at = ? WHERE candidate_id = ? AND status IN ('pending','approved','returned')", [$now, $c['id']]);
        }
        $this->event((int) $c['id'], 'moved', (string) $c['stage'], $to, $note !== '' ? $note : null, $az->userId);
        $this->app->audit()->record('recruit.stage_changed', 'success', 'rec_candidate', $pid, (int) $c['legal_entity_id'], null, ['from' => $c['stage'], 'to' => $to]);
    }

    public function note(Authorizer $az, string $pid, string $note): void
    {
        $c = $this->managedCandidate($az, $pid);
        $note = mb_substr(trim($note), 0, 500);
        if (mb_strlen($note) < 2) {
            throw new ValidationException(['note' => 'recruit.error.note']);
        }
        $this->event((int) $c['id'], 'note', null, null, $note, $az->userId);
    }

    /** @return list<array<string, mixed>> */
    public function events(int $candidateId): array
    {
        return $this->app->db()->all(
            "SELECT e.*, u.display_name AS actor FROM rec_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.candidate_id = ? ORDER BY e.id DESC",
            [$candidateId]
        );
    }

    // ---- files ----------------------------------------------------------------------------------------------------------

    private function storeFile(int $candidateId, string $group, UploadedFile $f, ?int $actor, string $title): void
    {
        $bytes = (string) file_get_contents($f->path);
        $stored = $this->app->documents()->storeGenerated($actor ?? 0, 'recruit.applicant', $bytes, $f->clientName, ['title' => mb_substr($title . ' · ' . $group, 0, 200)], 'upload', true);
        $this->app->db()->run("UPDATE rec_files SET status = 'removed' WHERE candidate_id = ? AND group_code = ? AND status = 'current'", [$candidateId, $group]);
        $this->app->db()->run(
            'INSERT INTO rec_files (candidate_id, group_code, document_pid, filename, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$candidateId, $group, $stored['document'], mb_substr($f->clientName, 0, 200), $actor, $this->now()]
        );
    }

    public function uploadFile(Authorizer $az, string $pid, string $group, ?UploadedFile $f): void
    {
        $c = $this->managedCandidate($az, $pid);
        if (!isset(Lists::DOC_GROUPS[$group])) {
            throw new ValidationException(['group' => 'form.invalid_choice']);
        }
        if ($f === null || !$f->ok() || $f->size === 0 || $f->size > self::MAX_FILE_MB * 1048576) {
            throw new ValidationException(['file' => 'recruit.error.file']);
        }
        $this->storeFile((int) $c['id'], $group, $f, $az->userId, (string) $c['name']);
        $this->event((int) $c['id'], 'file', null, null, $group, $az->userId);
    }

    public function verifyFile(Authorizer $az, string $pid, int $fileId): void
    {
        $c = $this->managedCandidate($az, $pid);
        $f = $this->app->db()->one("SELECT * FROM rec_files WHERE id = ? AND candidate_id = ? AND status = 'current'", [$fileId, $c['id']]);
        if ($f === null) {
            throw HttpException::notFound();
        }
        if ((int) ($f['uploaded_by'] ?? 0) === $az->userId) {
            throw new BusinessException('recruit.error.verify_own', [], 409); // maker ≠ checker
        }
        $this->app->db()->run('UPDATE rec_files SET verified_by = ?, verified_at = ? WHERE id = ?', [$az->userId, $this->now(), $fileId]);
        $this->event((int) $c['id'], 'verified', null, null, (string) $f['group_code'], $az->userId);
    }

    /** @return list<array<string, mixed>> */
    public function files(int $candidateId): array
    {
        return $this->app->db()->all("SELECT * FROM rec_files WHERE candidate_id = ? AND status = 'current' ORDER BY group_code", [$candidateId]);
    }

    /** @return array<string, mixed> bytes + mime + name (recruiters, audited) */
    public function fileRead(Authorizer $az, string $pid, int $fileId): array
    {
        $c = $this->managedCandidate($az, $pid);
        $f = $this->app->db()->one("SELECT * FROM rec_files WHERE id = ? AND candidate_id = ? AND status = 'current'", [$fileId, $c['id']]);
        if ($f === null) {
            throw HttpException::notFound();
        }
        return $this->app->documents()->moduleRead((string) $f['document_pid'], 'recruit', $az->userId, 'download', true) + ['name' => (string) $f['filename']];
    }

    // ---- interviews and evaluations --------------------------------------------------------------------------------------

    public function schedule(Authorizer $az, string $pid, string $kind, string $date, string $time, string $location): string
    {
        $c = $this->managedCandidate($az, $pid);
        if (!in_array($c['stage'], ['screening', 'interview'], true)) {
            throw new BusinessException('recruit.error.move', [], 409);
        }
        $errors = [];
        $kind = in_array($kind, ['interview', 'practical', 'external'], true) ? $kind : 'interview';
        $tz = new \DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', trim($date) . ' ' . trim($time), $tz);
        if ($at === false || $at->format('Y-m-d') < $this->today()) {
            $errors['when'] = 'recruit.error.when';
        }
        if ($errors !== [] || $at === false) {
            throw new ValidationException($errors);
        }
        $ipid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $this->app->db()->run(
            'INSERT INTO rec_interviews (public_id, created_at, created_by, updated_at, updated_by, candidate_id, kind, scheduled_at, location) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$ipid, $now, $az->userId, $now, $az->userId, $c['id'], $kind, $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'), mb_substr(trim($location), 0, 200) ?: null]
        );
        if ($c['stage'] === 'screening') {
            $this->app->db()->run("UPDATE rec_candidates SET stage = 'interview', stage_at = ?, updated_at = ? WHERE id = ?", [$now, $now, $c['id']]);
            $this->event((int) $c['id'], 'moved', 'screening', 'interview', null, $az->userId);
        }
        $this->event((int) $c['id'], 'scheduled', null, null, $kind . ' ' . $at->format('Y-m-d H:i'), $az->userId);
        return $ipid;
    }

    /** @return list<array<string, mixed>> */
    public function interviews(int $candidateId): array
    {
        $rows = $this->app->db()->all('SELECT * FROM rec_interviews WHERE candidate_id = ? ORDER BY scheduled_at', [$candidateId]);
        foreach ($rows as &$r) {
            $r['evaluations'] = $this->app->db()->all('SELECT id, evaluator_name, expires_at, submitted_at, score, recommendation, comment FROM rec_evaluations WHERE interview_id = ? ORDER BY id', [$r['id']]);
        }
        return $rows;
    }

    /**
     * A one-candidate evaluation link for a person (inside or outside the company). The token is shown once; only its
     * hash is kept. Valid for 7 days after the appointment.
     */
    public function addEvaluator(Authorizer $az, string $interviewPid, string $name): string
    {
        $db = $this->app->db();
        $i = $db->one('SELECT i.*, c.public_id AS cand_pid FROM rec_interviews i JOIN rec_candidates c ON c.id = i.candidate_id WHERE i.public_id = ?', [$interviewPid]);
        if ($i === null) {
            throw HttpException::notFound();
        }
        $c = $this->managedCandidate($az, (string) $i['cand_pid']);
        $name = mb_substr(trim($name), 0, 120);
        if (mb_strlen($name) < 2) {
            throw new ValidationException(['evaluator' => 'recruit.error.evaluator']);
        }
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $expires = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', (string) $i['scheduled_at'], new \DateTimeZone('UTC'));
        $expires = ($expires !== false ? $expires : $this->app->clock->now())->modify('+7 days')->format('Y-m-d H:i:s.u');
        $db->run(
            'INSERT INTO rec_evaluations (interview_id, candidate_id, evaluator_name, token_hash, expires_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$i['id'], $c['id'], $name, hash('sha256', $token), $expires, $az->userId, $this->now()]
        );
        $this->event((int) $c['id'], 'evaluator', null, null, $name, $az->userId);
        return $token;
    }

    /** @return array<string, mixed> the evaluation behind a link + a summary of the candidate (no contact details, no ID) */
    public function evaluation(string $token): array
    {
        $db = $this->app->db();
        $e = $db->one(
            'SELECT e.*, i.kind, i.scheduled_at, c.name, c.public_id AS cand_pid, c.profile_enc, c.anonymized_at, p.title FROM rec_evaluations e JOIN rec_interviews i ON i.id = e.interview_id
               JOIN rec_candidates c ON c.id = e.candidate_id JOIN rec_postings p ON p.id = c.posting_id WHERE e.token_hash = ?',
            [hash('sha256', $token)]
        );
        if ($e === null || $e['anonymized_at'] !== null) {
            throw HttpException::notFound();
        }
        $e['expired'] = (string) $e['expires_at'] < $this->now();
        $profile = $e['profile_enc'] !== null ? (array) json_decode($this->app->crypto()->decrypt((string) base64_decode((string) $e['profile_enc'], true)), true) : [];
        $e['summary'] = ['education' => $profile['education'] ?? [], 'work' => $profile['work'] ?? [], 'skills' => $profile['skills'] ?? '', 'lang_th' => $profile['lang_th'] ?? '', 'lang_en' => $profile['lang_en'] ?? ''];
        unset($e['profile_enc'], $e['token_hash']);
        return $e;
    }

    public function submitEvaluation(string $token, string $score, string $recommendation, string $comment): void
    {
        $e = $this->evaluation($token);
        if ($e['expired']) {
            throw new BusinessException('recruit.error.link_expired', [], 410);
        }
        if ($e['submitted_at'] !== null) {
            throw new BusinessException('recruit.error.already_evaluated', [], 409);
        }
        $errors = [];
        if (!in_array($score, ['1', '2', '3', '4', '5'], true)) {
            $errors['score'] = 'recruit.error.score';
        }
        if (!in_array($recommendation, ['hire', 'maybe', 'no'], true)) {
            $errors['recommendation'] = 'form.invalid_choice';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->app->db()->run(
            'UPDATE rec_evaluations SET submitted_at = ?, score = ?, recommendation = ?, comment = ? WHERE id = ? AND submitted_at IS NULL',
            [$this->now(), (int) $score, $recommendation, mb_substr(trim($comment), 0, 2000) ?: null, $e['id']]
        );
        $this->event((int) $e['candidate_id'], 'evaluated', null, null, $e['evaluator_name'] . ' · ' . $score . '/5', null);
    }

    // ---- offer and hire -------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $in */
    public function offer(Authorizer $az, string $pid, array $in): string
    {
        $c = $this->managedCandidate($az, $pid);
        if (!in_array($c['stage'], ['interview', 'offer'], true)) {
            throw new BusinessException('recruit.error.move', [], 409);
        }
        $db = $this->app->db();
        if ($db->scalar("SELECT id FROM rec_offers WHERE candidate_id = ? AND status IN ('pending','approved','returned','accepted')", [$c['id']]) !== null) {
            throw new BusinessException('recruit.error.offer_open', [], 409);
        }
        $errors = [];
        $salary = $this->money((string) ($in['salary'] ?? ''));
        if ($salary === false || $salary === null || $salary <= 0) {
            $errors['salary'] = 'recruit.error.money';
        }
        $start = trim((string) ($in['start_date'] ?? ''));
        if (!$this->isDate($start) || $start < $this->today()) {
            $errors['start_date'] = 'recruit.error.date';
        }
        $type = (string) ($in['employment_type'] ?? '');
        if (!in_array($type, EmployeeService::TYPES, true)) {
            $errors['employment_type'] = 'form.invalid_choice';
        }
        $title = mb_substr(trim((string) ($in['position_title'] ?? '')), 0, 120);
        if (mb_strlen($title) < 2) {
            $errors['position_title'] = 'recruit.error.title';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $opid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $db->transactional(function () use ($db, $opid, $now, $az, $c, $title, $salary, $start, $type, $in): void {
            $db->run(
                'INSERT INTO rec_offers (public_id, created_at, created_by, updated_at, updated_by, candidate_id, position_title, salary, start_date, employment_type, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$opid, $now, $az->userId, $now, $az->userId, $c['id'], $title, $salary, $start, $type, mb_substr(trim((string) ($in['note'] ?? '')), 0, 1000) ?: null]
            );
            if ($c['stage'] !== 'offer') {
                $db->run("UPDATE rec_candidates SET stage = 'offer', stage_at = ?, updated_at = ? WHERE id = ?", [$now, $now, $c['id']]);
                $this->event((int) $c['id'], 'moved', (string) $c['stage'], 'offer', null, $az->userId);
            }
            $this->app->workflow()->start('recruit.offer', $opid, (string) $c['name'] . ' · ' . $title, (int) $c['legal_entity_id'], $az->userId);
        });
        $this->event((int) $c['id'], 'offer', null, null, number_format((float) $salary, 2) . ' · ' . $start, $az->userId);
        $this->app->audit()->record('recruit.offer_submitted', 'success', 'rec_offer', $opid, (int) $c['legal_entity_id']);
        return $opid;
    }

    public function onOfferDecided(string $offerPid, string $outcome): void
    {
        $db = $this->app->db();
        $o = $db->one('SELECT o.*, c.created_by AS c_by, c.legal_entity_id FROM rec_offers o JOIN rec_candidates c ON c.id = o.candidate_id WHERE o.public_id = ?', [$offerPid]);
        if ($o === null || !in_array($o['status'], ['pending', 'returned'], true)) {
            return;
        }
        $status = $outcome === 'approved' ? 'approved' : ($outcome === 'rejected' ? 'rejected' : 'cancelled');
        $db->run('UPDATE rec_offers SET status = ?, decided_at = ?, updated_at = ? WHERE id = ?', [$status, $this->now(), $this->now(), $o['id']]);
        $this->event((int) $o['candidate_id'], 'offer_' . $status, null, null, null, null);
        if ((int) $o['created_by'] > 0) {
            $this->app->notifier()->notify(
                (int) $o['created_by'],
                'recruit.offer',
                'recruit.notify.offer_' . $status,
                ['title' => (string) $o['position_title']],
                '/recruit/c/' . $db->scalar('SELECT public_id FROM rec_candidates WHERE id = ?', [$o['candidate_id']]),
                'rec.offer:' . $offerPid
            );
        }
    }

    /** The candidate's answer to an approved offer (recorded by the recruiter). */
    public function offerAnswer(Authorizer $az, string $pid, string $answer, string $note): void
    {
        $c = $this->managedCandidate($az, $pid);
        $o = $this->app->db()->one("SELECT * FROM rec_offers WHERE candidate_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 1", [$c['id']]);
        if ($o === null || !in_array($answer, ['accepted', 'declined'], true)) {
            throw new BusinessException('recruit.error.offer_answer', [], 409);
        }
        $this->app->db()->run('UPDATE rec_offers SET status = ?, updated_at = ?, updated_by = ? WHERE id = ?', [$answer, $this->now(), $az->userId, $o['id']]);
        $this->event((int) $c['id'], 'offer_' . $answer, null, null, mb_substr(trim($note), 0, 500) ?: null, $az->userId);
    }

    /** @return list<array<string, mixed>> */
    public function offers(int $candidateId): array
    {
        return $this->app->db()->all('SELECT * FROM rec_offers WHERE candidate_id = ? ORDER BY id DESC', [$candidateId]);
    }

    /**
     * Hire-readiness checklist (M08 §9): every line must be true before the employee is created.
     *
     * @return array<string, bool>
     */
    public function readiness(int $candidateId): array
    {
        $db = $this->app->db();
        $c = $db->one('SELECT c.*, r.headcount, r.id AS req_id FROM rec_candidates c JOIN rec_postings p ON p.id = c.posting_id JOIN rec_requisitions r ON r.id = p.requisition_id WHERE c.id = ?', [$candidateId]) ?? [];
        $files = [];
        foreach ($this->files($candidateId) as $f) {
            $files[(string) $f['group_code']] = $f['verified_at'] !== null;
        }
        $required = $this->settings((int) $c['legal_entity_id'])['required_docs'];
        $docsOk = true;
        foreach ($required as $g) {
            $docsOk = $docsOk && ($files[$g] ?? false);
        }
        $hired = (int) $db->scalar("SELECT COUNT(*) FROM rec_candidates c2 JOIN rec_postings p2 ON p2.id = c2.posting_id WHERE p2.requisition_id = ? AND c2.stage = 'hired'", [$c['req_id']]);
        $dup = $c['id_hash'] !== null && $db->scalar("SELECT employee_id FROM employee_identifiers WHERE value_hash = ? AND status = 'current' LIMIT 1", [$c['id_hash']]) !== null;
        return [
            'consent' => $c['consent_at'] !== null || $c['source'] !== 'web',
            'documents' => $docsOk,
            'evaluation' => (int) $db->scalar('SELECT COUNT(*) FROM rec_evaluations WHERE candidate_id = ? AND submitted_at IS NOT NULL', [$candidateId]) > 0,
            'offer' => $db->scalar("SELECT id FROM rec_offers WHERE candidate_id = ? AND status = 'accepted'", [$candidateId]) !== null,
            'headcount' => $hired < (int) $c['headcount'],
            'not_employee' => !$dup && $c['employee_id'] === null,
        ];
    }

    /**
     * Creates the employee in M09 from a ready candidate (pre-hire until the start date): names, position and unit of
     * the requisition, the accepted offer's start date, the ID number and the files. Never twice.
     *
     * @param array<string, mixed> $in employee_code, employee_group, manager (employee public id, optional)
     * @return array{employee: string, warnings: list<string>}
     */
    public function hire(Authorizer $az, string $pid, array $in): array
    {
        $c = $this->managedCandidate($az, $pid);
        if ($c['stage'] !== 'offer' || in_array(false, $this->readiness((int) $c['id']), true)) {
            throw new BusinessException('recruit.error.not_ready', [], 409);
        }
        $db = $this->app->db();
        $req = $db->one('SELECT r.* FROM rec_requisitions r JOIN rec_postings p ON p.requisition_id = r.id WHERE p.id = ?', [$c['posting_id']]) ?? [];
        $offer = $db->one("SELECT * FROM rec_offers WHERE candidate_id = ? AND status = 'accepted' ORDER BY id DESC LIMIT 1", [$c['id']]) ?? [];
        $mgr = trim((string) ($in['manager'] ?? '')) !== '' ? $db->scalar('SELECT id FROM employees WHERE public_id = ?', [(string) $in['manager']]) : null;
        $emp = $this->emp()->create($az, [
            'employee_code' => (string) ($in['employee_code'] ?? ''), 'name_th' => (string) $c['name'], 'name_en' => '', 'preferred_name' => '',
            'hire_date' => (string) $offer['start_date'], 'legal_entity_id' => (int) $req['legal_entity_id'], 'site_id' => (int) ($req['site_id'] ?? 0),
            'department_id' => (int) ($req['department_id'] ?? 0), 'position_title' => (string) $offer['position_title'], 'manager_employee_id' => (int) ($mgr ?? 0),
            'employee_group' => (string) ($in['employee_group'] ?? ''), 'employment_type' => (string) $offer['employment_type'],
        ]);
        $now = $this->now();
        $eid = (int) $db->scalar('SELECT id FROM employees WHERE public_id = ?', [$emp]);
        $db->run(
            "UPDATE rec_candidates SET stage = 'hired', stage_at = ?, employee_id = ?, closed_at = ?, updated_at = ?, updated_by = ? WHERE id = ? AND employee_id IS NULL",
            [$now, $eid, $now, $now, $az->userId, $c['id']]
        );
        $this->event((int) $c['id'], 'hired', 'offer', 'hired', (string) ($in['employee_code'] ?? ''), $az->userId);
        $warnings = [];
        // the ID number and the files go to the employee record — each failure is reported, the hire stands
        if ($c['id_enc'] !== null) {
            try {
                ProfileService::of($this->app)->addIdentifier($az, $emp, (string) $c['id_type'], $this->app->crypto()->decrypt((string) base64_decode((string) $c['id_enc'], true)), '', '');
            } catch (\Throwable) {
                $warnings[] = 'recruit.warn.identifier';
            }
        }
        $dir = $this->app->path('storage/tmp');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        foreach ($this->files((int) $c['id']) as $f) {
            try {
                $doc = $this->app->documents()->moduleRead((string) $f['document_pid'], 'recruit', $az->userId, 'copy_to_employee', true);
                $type = Lists::DOC_GROUPS[(string) $f['group_code']] ?? 'application';
                $copy = UploadedFile::fromBytes((string) $f['filename'], (string) $doc['bytes'], $dir);
                try {
                    PersonnelFileService::of($this->app)->upload($az, $emp, ['doc_type' => $type, 'title' => (string) $f['filename'], 'category' => 'B'], $copy);
                } finally {
                    @unlink($copy->path); // the decrypted copy never stays on disk
                }
            } catch (\Throwable) {
                $warnings[] = 'recruit.warn.file';
            }
        }
        $hired = (int) $db->scalar("SELECT COUNT(*) FROM rec_candidates c2 JOIN rec_postings p2 ON p2.id = c2.posting_id WHERE p2.requisition_id = ? AND c2.stage = 'hired'", [$req['id']]);
        if ($hired >= (int) $req['headcount']) {
            $db->run("UPDATE rec_requisitions SET status = 'filled', updated_at = ? WHERE id = ?", [$now, $req['id']]);
            $db->run("UPDATE rec_postings SET status = 'closed', updated_at = ? WHERE requisition_id = ? AND status = 'published'", [$now, $req['id']]);
        }
        $this->app->audit()->record('recruit.hired', 'success', 'rec_candidate', $pid, (int) $c['legal_entity_id'], null, ['employee' => $emp, 'warnings' => count($warnings)]);
        return ['employee' => $emp, 'warnings' => array_values(array_unique($warnings))];
    }

    // ---- reporting and retention ---------------------------------------------------------------------------------------------

    /**
     * Funnel of a company: how many applications reached each stage, and the average days from applying.
     *
     * @return array{stages: array<string, array{count: int, days: float|null}>, postings: list<array<string, mixed>>}
     */
    public function funnel(Authorizer $az, int $le): array
    {
        if (!$az->scope('recruit.manage')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        $out = [];
        foreach (['applied', 'screening', 'interview', 'offer', 'hired'] as $s) {
            $r = $db->one(
                "SELECT COUNT(DISTINCT c.id) AS n, AVG(DATEDIFF(e.occurred_at, c.created_at)) AS d FROM rec_candidates c
                   LEFT JOIN rec_events e ON e.candidate_id = c.id AND e.to_stage = ? WHERE c.legal_entity_id = ? AND (c.stage = ? OR e.id IS NOT NULL)",
                [$s, $le, $s]
            ) ?? [];
            $out[$s] = ['count' => (int) ($r['n'] ?? 0), 'days' => $r['d'] !== null && $s !== 'applied' ? round((float) $r['d'], 1) : null];
        }
        $postings = $db->all(
            "SELECT p.public_id, p.title, p.status, r.headcount, COUNT(c.id) AS applicants, SUM(c.stage = 'hired') AS hired FROM rec_postings p JOIN rec_requisitions r ON r.id = p.requisition_id
               LEFT JOIN rec_candidates c ON c.posting_id = p.id WHERE p.legal_entity_id = ? GROUP BY p.id ORDER BY p.id DESC LIMIT 100",
            [$le]
        );
        return ['stages' => $out, 'postings' => $postings];
    }

    public static function retentionTask(App $app): int
    {
        return self::of($app)->retentionJob();
    }

    /** Daily: anonymise closed, not-hired applications past the APPROVED applicant_unsuccessful rule. */
    public function retentionJob(): int
    {
        $rule = $this->app->retention()->current('applicant_unsuccessful');
        if ($rule === null || !in_array((string) $rule['disposition_action'], ['anonymize', 'destroy'], true) || (int) $rule['duration_months'] < 1) {
            return 0;
        }
        $cutoff = EmployeeService::ymd($this->today())->modify('-' . (int) $rule['duration_months'] . ' months')->format('Y-m-d') . ' 00:00:00.000000';
        $db = $this->app->db();
        $ids = array_map('intval', array_column($db->all("SELECT id FROM rec_candidates WHERE stage IN ('rejected','withdrawn') AND closed_at < ? AND anonymized_at IS NULL LIMIT 500", [$cutoff]), 'id'));
        foreach ($ids as $id) {
            $this->anonymize($id, null, 'retention');
        }
        if ($ids !== []) {
            $this->app->audit()->record('recruit.anonymized', 'success', 'retention_rule', (string) $rule['public_id'], null, null, ['applications' => count($ids)]);
        }
        return count($ids);
    }

    /** Applicant asked to be removed (or retention): names, contacts, profile and ID go; stage history stays. */
    public function anonymize(int $candidateId, ?int $actor, string $why): void
    {
        $db = $this->app->db();
        $now = $this->now();
        $db->run("UPDATE rec_candidates SET name = '—', email = NULL, phone = '', profile_enc = NULL, id_enc = NULL, id_hash = NULL, id_last4 = NULL, consent_name = NULL, consent_ip = NULL,
            anonymized_at = ?, updated_at = ? WHERE id = ?", [$now, $now, $candidateId]);
        $db->run("UPDATE rec_files SET status = 'removed' WHERE candidate_id = ?", [$candidateId]);
        $db->run('UPDATE rec_evaluations SET comment = NULL WHERE candidate_id = ?', [$candidateId]);
        $this->event($candidateId, 'anonymized', null, null, $why, $actor);
    }

    public function eraseOnRequest(Authorizer $az, string $pid, string $reason): void
    {
        $c = $this->managedCandidate($az, $pid);
        if (!$az->scope('recruit.settings')->coversLegalEntity((int) $c['legal_entity_id'])) {
            throw HttpException::forbidden();
        }
        if ($c['stage'] === 'hired') {
            throw new BusinessException('recruit.error.hired_erase', [], 409);
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new ValidationException(['reason' => 'recruit.error.reason']);
        }
        $this->anonymize((int) $c['id'], $az->userId, 'request: ' . mb_substr(trim($reason), 0, 200));
        $this->app->audit()->record('recruit.erased', 'success', 'rec_candidate', $pid, (int) $c['legal_entity_id'], mb_substr(trim($reason), 0, 200));
    }

    // ---- helpers ------------------------------------------------------------------------------------------------------

    private function event(int $candidateId, string $action, ?string $from, ?string $to, ?string $note, ?int $actor): void
    {
        $this->app->db()->run(
            'INSERT INTO rec_events (candidate_id, action, from_stage, to_stage, note, actor_id, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$candidateId, $action, $from, $to, $note, $actor, $this->now()]
        );
    }

    /** @return list<int> active users holding $permission whose scope covers the company */
    public function holders(string $permission, int $le): array
    {
        return PersonnelFileService::of($this->app)->holders($permission, $le);
    }

    private function newCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while ($this->app->db()->scalar('SELECT id FROM rec_candidates WHERE app_code = ?', [$code]) !== null);
        return $code;
    }

    /** @return float|null|false empty → null, invalid → false */
    private function money(string $v): float|null|false
    {
        $v = str_replace([',', ' '], '', trim($v));
        if ($v === '') {
            return null;
        }
        return preg_match('/^\d{1,10}(\.\d{1,2})?$/', $v) === 1 ? (float) $v : false;
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}

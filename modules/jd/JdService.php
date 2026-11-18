<?php

declare(strict_types=1);

namespace Blue\Modules\Jd;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\EmployeeService;

/**
 * M56 Job descriptions (phase 5E-2, DEC-77).
 *
 *  position  a JD belongs to a position title of a company (the same text as the title in the employee's current
 *            employment, compared without case/spaces), optionally for one unit only — a unit-specific JD wins over
 *            the company-wide one with the same title.
 *  version   Thai content (summary, duties, qualifications, KPIs) + optional English, what changed, effective date:
 *            draft → review → another person with jd.approve approves → effective on its date → the former version is
 *            superseded. Effective/superseded versions are never edited (content hash kept).
 *  ack       everyone whose current position matches an effective JD gets a pending acknowledgement of that exact
 *            version (hourly + on every change): new starters, people who change position, and everyone again when a
 *            new version becomes effective. People who move away lose an open one; done ones stay as history.
 *            The employee must open the JD first, then confirm the statement — opening alone is never an
 *            acknowledgement; time, IP, browser, statement and the content hash are kept. HR may record an exception
 *            with a reason.
 */
final class JdService
{
    public const SECTIONS = ['summary', 'duties', 'qualifications', 'kpis'];

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

    public static function titleKey(string $title): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', $title));
    }

    /** @return list<int> */
    public function companies(Authorizer $az, string $permission = 'jd.manage'): array
    {
        $scope = $az->scope($permission);
        return array_values(array_filter(array_map('intval', array_column($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code'), 'id')), static fn (int $id): bool => $scope->coversLegalEntity($id)));
    }

    // ---- managing ------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> a JD position of a company the person manages */
    public function managed(Authorizer $az, string $pid): array
    {
        $p = $this->app->db()->one('SELECT p.*, u.display_name AS owner_name FROM jd_profiles p LEFT JOIN users u ON u.id = p.owner_user_id WHERE p.public_id = ?', [$pid]);
        if ($p === null || !$az->scope('jd.manage')->coversLegalEntity((int) $p['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        return $p;
    }

    /** @return list<array<string, mixed>> positions of a company with the numbers of the current version */
    public function adminList(int $le): array
    {
        return $this->app->db()->all(
            "SELECT p.*, d.code AS dept_code, v.version_no, v.effective_from,
                    (SELECT COUNT(*) FROM jd_acks a WHERE a.version_id = p.current_version_id) AS assigned,
                    (SELECT COUNT(*) FROM jd_acks a WHERE a.version_id = p.current_version_id AND a.status = 'pending') AS pending,
                    (SELECT COUNT(*) FROM jd_versions x WHERE x.profile_id = p.id AND x.status IN ('draft','review','approved')) AS open_versions
               FROM jd_profiles p LEFT JOIN departments d ON d.id = p.department_id LEFT JOIN jd_versions v ON v.id = p.current_version_id
              WHERE p.legal_entity_id = ? AND p.status = 'active' ORDER BY p.code",
            [$le]
        );
    }

    /** @param array<string, string> $in code, position_title, department (public id or '') */
    public function create(Authorizer $az, int $le, array $in): string
    {
        if (!$az->scope('jd.manage')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        $errors = [];
        $code = strtoupper(trim($in['code'] ?? ''));
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{1,29}$/', $code) !== 1) {
            $errors['code'] = 'jd.error.code';
        } elseif ($db->scalar('SELECT id FROM jd_profiles WHERE legal_entity_id = ? AND code = ?', [$le, $code]) !== null) {
            $errors['code'] = 'jd.error.code_taken';
        }
        $title = mb_substr(trim($in['position_title'] ?? ''), 0, 120);
        if (mb_strlen($title) < 2) {
            $errors['position_title'] = 'jd.error.title';
        }
        $dept = null;
        if (($in['department'] ?? '') !== '') {
            $dept = $db->scalar("SELECT id FROM departments WHERE public_id = ? AND legal_entity_id = ?", [$in['department'], $le]);
            if ($dept === null) {
                $errors['department'] = 'form.invalid_choice';
            }
        }
        if ($errors === [] && $db->scalar("SELECT id FROM jd_profiles WHERE legal_entity_id = ? AND title_key = ? AND department_id <=> ? AND status = 'active'", [$le, self::titleKey($title), $dept]) !== null) {
            $errors['position_title'] = 'jd.error.title_taken';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $db->run(
            'INSERT INTO jd_profiles (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, department_id, code, position_title, title_key, owner_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $now, $az->userId, $now, $az->userId, $le, $dept, $code, $title, self::titleKey($title), $az->userId]
        );
        $this->app->audit()->record('jd.created', 'success', 'jd_profile', $pid, $le, null, ['code' => $code]);
        return $pid;
    }

    /** @return list<array<string, mixed>> */
    public function versions(int $profileId): array
    {
        return $this->app->db()->all(
            'SELECT v.*, c.display_name AS created_name, a.display_name AS approved_name FROM jd_versions v LEFT JOIN users c ON c.id = v.created_by LEFT JOIN users a ON a.id = v.approved_by
              WHERE v.profile_id = ? ORDER BY v.version_no DESC',
            [$profileId]
        );
    }

    /**
     * Saves the open draft (or starts one, copying the current content).
     *
     * @param array<string, mixed> $in th_summary … en_kpis, change_summary, effective_from
     */
    public function saveDraft(Authorizer $az, string $profilePid, array $in): string
    {
        $p = $this->managed($az, $profilePid);
        if ($p['status'] !== 'active') {
            throw new BusinessException('jd.error.state', [], 409);
        }
        $db = $this->app->db();
        if ($db->scalar("SELECT id FROM jd_versions WHERE profile_id = ? AND status IN ('review','approved')", [$p['id']]) !== null) {
            throw new BusinessException('jd.error.in_review', [], 409);
        }
        $content = [];
        $errors = [];
        foreach (['th', 'en'] as $lang) {
            foreach (self::SECTIONS as $s) {
                $v = mb_substr(trim(str_replace("\r", '', (string) ($in[$lang . '_' . $s] ?? ''))), 0, $s === 'summary' ? 1000 : 6000);
                if ($v !== '') {
                    $content[$lang][$s] = $v;
                }
            }
        }
        foreach (['summary', 'duties'] as $s) {
            if (mb_strlen($content['th'][$s] ?? '') < 10) {
                $errors['th_' . $s] = 'jd.error.' . $s;
            }
        }
        $date = trim((string) ($in['effective_from'] ?? ''));
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date) {
            $errors['effective_from'] = 'form.invalid_date';
        }
        $change = mb_substr(trim((string) ($in['change_summary'] ?? '')), 0, 500);
        $hasEffective = $p['current_version_id'] !== null;
        if ($hasEffective && mb_strlen($change) < 5) {
            $errors['change_summary'] = 'jd.error.change';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $json = (string) json_encode($content, JSON_UNESCAPED_UNICODE);
        $now = $this->now();
        $draft = $db->one("SELECT * FROM jd_versions WHERE profile_id = ? AND status = 'draft'", [$p['id']]);
        if ($draft !== null) {
            $db->run(
                'UPDATE jd_versions SET content_json = ?, content_sha256 = ?, change_summary = ?, effective_from = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
                [$json, hash('sha256', $json), $change, $date, $now, $az->userId, $draft['id']]
            );
            return (string) $draft['public_id'];
        }
        $pid = Ulid::generate($this->app->clock);
        $no = (int) $db->scalar('SELECT COALESCE(MAX(version_no), 0) + 1 FROM jd_versions WHERE profile_id = ?', [$p['id']]);
        $db->run(
            'INSERT INTO jd_versions (public_id, created_at, created_by, updated_at, updated_by, profile_id, version_no, content_json, content_sha256, change_summary, effective_from) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $now, $az->userId, $now, $az->userId, $p['id'], $no, $json, hash('sha256', $json), $change, $date]
        );
        $this->app->audit()->record('jd.version_drafted', 'success', 'jd_version', $pid, (int) $p['legal_entity_id'], null, ['code' => $p['code'], 'version' => $no]);
        return $pid;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} version + its position */
    private function managedVersion(Authorizer $az, string $versionPid): array
    {
        $v = $this->app->db()->one('SELECT v.*, p.public_id AS profile_pid FROM jd_versions v JOIN jd_profiles p ON p.id = v.profile_id WHERE v.public_id = ?', [$versionPid]);
        if ($v === null) {
            throw HttpException::notFound();
        }
        return [$v, $this->managed($az, (string) $v['profile_pid'])];
    }

    public function submit(Authorizer $az, string $versionPid): void
    {
        [$v, $p] = $this->managedVersion($az, $versionPid);
        if ($v['status'] !== 'draft') {
            throw new BusinessException('jd.error.state', [], 409);
        }
        $db = $this->app->db();
        $db->transactional(function () use ($db, $az, $v, $p): void {
            $title = $this->app->translator()->get('jd.title_line', ['code' => (string) $p['code'], 'n' => (string) $v['version_no'], 'title' => (string) $p['position_title']]);
            $inst = $this->app->workflow()->start('jd.approve', (string) $v['public_id'], $title, (int) $p['legal_entity_id'], $az->userId);
            $db->run("UPDATE jd_versions SET status = 'review', workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?), updated_at = ? WHERE id = ?", [$inst, $this->now(), $v['id']]);
        });
        $this->app->audit()->record('jd.version_submitted', 'success', 'jd_version', $versionPid, (int) $p['legal_entity_id']);
    }

    public function withdraw(Authorizer $az, string $versionPid): void
    {
        [$v, $p] = $this->managedVersion($az, $versionPid);
        if (!in_array($v['status'], ['draft', 'approved'], true)) {
            throw new BusinessException('jd.error.state', [], 409);
        }
        $this->app->db()->run("UPDATE jd_versions SET status = 'withdrawn', updated_at = ? WHERE id = ?", [$this->now(), $v['id']]);
        $this->app->audit()->record('jd.version_withdrawn', 'success', 'jd_version', $versionPid, (int) $p['legal_entity_id']);
    }

    public function onApproved(string $versionPid, int $approverId): void
    {
        $now = $this->now();
        $this->app->db()->run("UPDATE jd_versions SET status = 'approved', approved_by = ?, approved_at = ?, updated_at = ? WHERE public_id = ? AND status = 'review'", [$approverId, $now, $now, $versionPid]);
        $this->activateDue();
    }

    public function onClosed(string $versionPid, string $outcome): void
    {
        $this->app->db()->run("UPDATE jd_versions SET status = ?, updated_at = ? WHERE public_id = ? AND status = 'review'", [$outcome === 'returned' ? 'draft' : 'rejected', $this->now(), $versionPid]);
    }

    public static function syncJob(App $app): int
    {
        $svc = self::of($app);
        $n = $svc->activateDue();
        foreach (array_map('intval', array_column($app->db()->all("SELECT DISTINCT legal_entity_id FROM jd_profiles WHERE status = 'active' AND current_version_id IS NOT NULL"), 'legal_entity_id')) as $le) {
            $n += $svc->syncAcks($le);
        }
        return $n;
    }

    /** Approved versions whose date has come become effective; the former one is superseded. */
    public function activateDue(): int
    {
        $db = $this->app->db();
        $n = 0;
        $les = [];
        foreach (
            $db->all("SELECT v.*, p.legal_entity_id, p.code FROM jd_versions v JOIN jd_profiles p ON p.id = v.profile_id
                       WHERE v.status = 'approved' AND v.effective_from <= ? ORDER BY v.effective_from, v.id", [$this->today()]) as $v
        ) {
            $db->transactional(function () use ($db, $v): void {
                $now = $this->now();
                $db->run("UPDATE jd_versions SET status = 'superseded', updated_at = ? WHERE profile_id = ? AND status = 'effective'", [$now, $v['profile_id']]);
                $db->run("UPDATE jd_versions SET status = 'effective', effective_at = ?, updated_at = ? WHERE id = ?", [$now, $now, $v['id']]);
                $db->run('UPDATE jd_profiles SET current_version_id = ?, updated_at = ? WHERE id = ?', [$v['id'], $now, $v['profile_id']]);
            });
            $this->app->audit()->record('jd.effective', 'success', 'jd_version', (string) $v['public_id'], (int) $v['legal_entity_id'], null, ['code' => $v['code'], 'version' => (int) $v['version_no']]);
            $les[(int) $v['legal_entity_id']] = true;
            $n++;
        }
        foreach (array_keys($les) as $le) {
            $this->syncAcks($le);
        }
        return $n;
    }

    /**
     * The effective JD version each current employee of the company must acknowledge (employee id => [version id, user id]).
     *
     * @return array<int, array{0: int, 1: int|null}>
     */
    public function assignments(int $le): array
    {
        $db = $this->app->db();
        $byKey = [];
        foreach ($db->all("SELECT id, department_id, title_key, current_version_id FROM jd_profiles WHERE legal_entity_id = ? AND status = 'active' AND current_version_id IS NOT NULL", [$le]) as $p) {
            $byKey[(string) $p['title_key']][$p['department_id'] !== null ? (int) $p['department_id'] : 0] = (int) $p['current_version_id'];
        }
        if ($byKey === []) {
            return [];
        }
        $today = $this->today();
        $out = [];
        foreach (
            $db->all("SELECT e.id, h.department_id, h.position_title, (SELECT u.id FROM users u WHERE u.employee_id = e.id AND u.status = 'active' LIMIT 1) AS uid
                        FROM employment_history h JOIN employees e ON e.id = h.employee_id
                       WHERE h.legal_entity_id = ? AND h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?) AND e.status = 'active'", [$le, $today, $today]) as $r
        ) {
            $set = $byKey[self::titleKey((string) $r['position_title'])] ?? null;
            if ($set === null) {
                continue;
            }
            $version = $set[(int) ($r['department_id'] ?? 0)] ?? $set[0] ?? null;
            if ($version !== null) {
                $out[(int) $r['id']] = [$version, $r['uid'] !== null ? (int) $r['uid'] : null];
            }
        }
        return $out;
    }

    /** Open acknowledgements follow the employees' current positions. Returns how many were added. */
    public function syncAcks(int $le): int
    {
        $db = $this->app->db();
        $want = $this->assignments($le);
        $now = $this->now();
        $added = 0;
        foreach ($want as $empId => [$versionId, $uid]) {
            $ins = $db->run("INSERT IGNORE INTO jd_acks (version_id, employee_id, user_id, status, assigned_at) VALUES (?, ?, ?, 'pending', ?)", [$versionId, $empId, $uid, $now])->rowCount();
            if ($ins === 1) {
                $added++;
                if ($uid !== null) {
                    $p = $db->one('SELECT p.position_title FROM jd_versions v JOIN jd_profiles p ON p.id = v.profile_id WHERE v.id = ?', [$versionId]);
                    $this->app->notifier()->notify($uid, 'jd.ack', 'jd.notify.ack', ['title' => (string) ($p['position_title'] ?? '')], '/jd', 'jd.ack:' . $versionId . ':' . $empId);
                }
            } elseif ($uid !== null) {
                $db->run('UPDATE jd_acks SET user_id = ? WHERE version_id = ? AND employee_id = ? AND user_id IS NULL', [$uid, $versionId, $empId]);
            }
        }
        foreach (
            $db->all("SELECT a.id, a.employee_id, a.version_id FROM jd_acks a JOIN jd_versions v ON v.id = a.version_id JOIN jd_profiles p ON p.id = v.profile_id
                       WHERE p.legal_entity_id = ? AND a.status = 'pending'", [$le]) as $a
        ) {
            if (($want[(int) $a['employee_id']][0] ?? null) !== (int) $a['version_id']) {
                $db->run("DELETE FROM jd_acks WHERE id = ? AND status = 'pending'", [$a['id']]);
            }
        }
        return $added;
    }

    /** @return list<array<string, mixed>> acknowledgements of a version with the employee and unit */
    public function report(int $versionId): array
    {
        return $this->app->db()->all(
            "SELECT a.*, e.employee_code, e.name_th, d.code AS dept_code, x.display_name AS exception_name
               FROM jd_acks a JOIN employees e ON e.id = a.employee_id
               LEFT JOIN employment_history h ON h.employee_id = e.id AND h.effective_to IS NULL
               LEFT JOIN departments d ON d.id = h.department_id LEFT JOIN users x ON x.id = a.exception_by
              WHERE a.version_id = ? ORDER BY FIELD(a.status, 'pending', 'exception', 'acknowledged'), d.code, e.employee_code",
            [$versionId]
        );
    }

    public function exception(Authorizer $az, string $profilePid, int $ackId, string $reason): void
    {
        $p = $this->managed($az, $profilePid);
        $reason = mb_substr(trim($reason), 0, 300);
        if (mb_strlen($reason) < 5) {
            throw new ValidationException(['reason' => 'jd.error.reason']);
        }
        $n = $this->app->db()->run(
            "UPDATE jd_acks a JOIN jd_versions v ON v.id = a.version_id SET a.status = 'exception', a.exception_reason = ?, a.exception_by = ? WHERE a.id = ? AND v.profile_id = ? AND a.status = 'pending'",
            [$reason, $az->userId, $ackId, $p['id']]
        )->rowCount();
        if ($n !== 1) {
            throw new BusinessException('jd.error.state', [], 409);
        }
        $this->app->audit()->record('jd.exception', 'success', 'jd_profile', $profilePid, (int) $p['legal_entity_id'], $reason, ['ack' => $ackId]);
    }

    public function remind(Authorizer $az, string $profilePid): int
    {
        $p = $this->managed($az, $profilePid);
        $n = 0;
        $day = $this->today();
        foreach ($this->app->db()->all("SELECT a.user_id, a.version_id FROM jd_acks a WHERE a.version_id = ? AND a.status = 'pending' AND a.user_id IS NOT NULL", [(int) ($p['current_version_id'] ?? 0)]) as $a) {
            $this->app->notifier()->notify((int) $a['user_id'], 'jd.ack', 'jd.notify.remind', ['title' => (string) $p['position_title']], '/jd', 'jd.remind:' . $a['version_id'] . ':' . $a['user_id'] . ':' . $day);
            $n++;
        }
        return $n;
    }

    public function archive(Authorizer $az, string $profilePid): void
    {
        $p = $this->managed($az, $profilePid);
        $db = $this->app->db();
        $db->run("UPDATE jd_profiles SET status = 'archived', updated_at = ? WHERE id = ?", [$this->now(), $p['id']]);
        $db->run("DELETE a FROM jd_acks a JOIN jd_versions v ON v.id = a.version_id WHERE v.profile_id = ? AND a.status = 'pending'", [$p['id']]);
        $this->app->audit()->record('jd.archived', 'success', 'jd_profile', $profilePid, (int) $p['legal_entity_id']);
    }

    // ---- employees ----------------------------------------------------------------------------------------------------------

    /** @return array{current: array<string, mixed>|null, history: list<array<string, mixed>>} my JD now + every JD I acknowledged */
    public function mine(Authorizer $az): array
    {
        $me = $this->emp()->forUser($az->userId);
        if ($me === null) {
            return ['current' => null, 'history' => []];
        }
        $db = $this->app->db();
        if ($me['job'] !== null) {
            $this->syncAcks((int) $me['job']['legal_entity_id']);
        }
        $want = $me['job'] !== null ? ($this->assignments((int) $me['job']['legal_entity_id'])[(int) $me['id']] ?? null) : null;
        $current = null;
        if ($want !== null) {
            $current = $db->one(
                'SELECT v.public_id, v.version_no, v.effective_from, p.code, p.position_title, a.status AS ack_status, a.acknowledged_at FROM jd_versions v JOIN jd_profiles p ON p.id = v.profile_id
                   LEFT JOIN jd_acks a ON a.version_id = v.id AND a.employee_id = ? WHERE v.id = ?',
                [$me['id'], $want[0]]
            );
        }
        $history = $db->all(
            "SELECT v.public_id, v.version_no, v.status AS version_status, p.code, p.position_title, a.status, a.acknowledged_at
               FROM jd_acks a JOIN jd_versions v ON v.id = a.version_id JOIN jd_profiles p ON p.id = v.profile_id
              WHERE a.employee_id = ? AND a.status IN ('acknowledged','exception') ORDER BY a.acknowledged_at DESC, a.id DESC",
            [$me['id']]
        );
        return ['current' => $current, 'history' => $history];
    }

    /**
     * A version for its reader (one assigned to them or acknowledged before — opening it is noted) or a manager.
     *
     * @return array<string, mixed> version + position + content + my_ack + can_manage
     */
    public function view(Authorizer $az, string $versionPid, bool $markOpened = true): array
    {
        $db = $this->app->db();
        $v = $db->one(
            'SELECT v.*, p.public_id AS profile_pid, p.code, p.position_title, p.legal_entity_id, p.department_id, d.code AS dept_code FROM jd_versions v JOIN jd_profiles p ON p.id = v.profile_id
               LEFT JOIN departments d ON d.id = p.department_id WHERE v.public_id = ?',
            [$versionPid]
        );
        if ($v === null) {
            throw HttpException::notFound();
        }
        $manage = $az->scope('jd.manage')->coversLegalEntity((int) $v['legal_entity_id']);
        $me = $this->emp()->forUser($az->userId);
        $ack = $me !== null ? $db->one('SELECT * FROM jd_acks WHERE version_id = ? AND employee_id = ?', [$v['id'], $me['id']]) : null;
        if (!$manage && $ack === null) {
            throw HttpException::notFound();
        }
        if ($markOpened && $ack !== null && $ack['status'] === 'pending' && $ack['opened_at'] === null) {
            $db->run('UPDATE jd_acks SET opened_at = ? WHERE id = ?', [$this->now(), $ack['id']]);
            $ack['opened_at'] = $this->now();
        }
        $v['content'] = (array) json_decode((string) $v['content_json'], true);
        $v['my_ack'] = $ack;
        $v['can_manage'] = $manage;
        return $v;
    }

    public function acknowledge(Authorizer $az, string $versionPid, bool $confirmed, string $ip, string $ua): void
    {
        $v = $this->view($az, $versionPid, false);
        $a = $v['my_ack'];
        if ($a === null || $a['status'] !== 'pending' || $v['status'] !== 'effective') {
            throw new BusinessException('jd.error.state', [], 409);
        }
        if ($a['opened_at'] === null) {
            throw new BusinessException('jd.error.open_first', [], 422);
        }
        if (!$confirmed) {
            throw new ValidationException(['confirm' => 'jd.error.confirm']);
        }
        $statement = $this->app->translator()->get('jd.statement', ['title' => (string) $v['position_title'], 'n' => (string) $v['version_no']]);
        $this->app->db()->run(
            "UPDATE jd_acks SET status = 'acknowledged', acknowledged_at = ?, statement = ?, content_sha256 = ?, ip = ?, user_agent = ? WHERE id = ? AND status = 'pending'",
            [$this->now(), mb_substr($statement, 0, 500), $v['content_sha256'], mb_substr($ip, 0, 45), mb_substr($ua, 0, 200), $a['id']]
        );
        $this->app->audit()->record('jd.acknowledged', 'success', 'jd_version', $versionPid, (int) $v['legal_entity_id'], null, ['version' => (int) $v['version_no']]);
    }
}

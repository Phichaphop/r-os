<?php

declare(strict_types=1);

namespace Blue\Modules\Celebrate;

use DateTimeImmutable;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\EmployeeService;
use Blue\Modules\Employees\PersonnelFileService;
use Blue\Modules\Employees\ProfileService;

/**
 * M45 Birthdays & work anniversaries (phase 5E-2, DEC-77).
 *
 *  off by default  nothing is calculated, shown or sent until HR of the company turns birthdays and/or anniversaries
 *                  on AND has written the notice employees see (purpose, who sees what, how to opt out) — the privacy
 *                  policy for birthdays (SP-06) is the company's to decide.
 *  source          birth date (encrypted in M09) and start date only — read when events are calculated; events keep
 *                  the day and month of this year's occurrence, never a birth year or an age. Anniversaries show the
 *                  number of years. 29 February falls on 28 February in other years.
 *  events          calculated hourly for this month and the next 45 days, at most once per person, kind and date
 *                  (a retry never sends twice): scheduled → sent on the day (the person gets a greeting, the line
 *                  manager and HR get a note if the company wants) → archived after 60 days; HR may cancel one with a
 *                  reason. People who opted out, left, or are not started are skipped.
 *  board           colleagues of the same company (or unit, by setting) see this month's birthdays (day and month)
 *                  and anniversaries, and may send one short wish per event.
 *  opt-out         each employee may hide their birthday and/or anniversary at any time; open events are cancelled.
 */
final class CelebrateService
{
    public const KINDS = ['birthday', 'anniversary'];

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    public function today(): string
    {
        return EmployeeService::of($this->app)->today();
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    /** @return list<int> */
    public function companies(Authorizer $az): array
    {
        $scope = $az->scope('celebrate.manage');
        return array_values(array_filter(array_map('intval', array_column($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code'), 'id')), static fn (int $id): bool => $scope->coversLegalEntity($id)));
    }

    // ---- settings ----------------------------------------------------------------------------------------------------------

    /** @return array{birthdays_enabled: bool, anniversaries_enabled: bool, notice_text: string, notice_version: int, notify_manager: bool, notify_hr: bool, board_scope: string, milestones: list<int>} */
    public function settings(int $le): array
    {
        $s = $this->app->db()->one('SELECT * FROM cel_settings WHERE legal_entity_id = ?', [$le]);
        $notice = (string) ($s['notice_text'] ?? '');
        return [
            'birthdays_enabled' => $s !== null && (int) $s['birthdays_enabled'] === 1 && $notice !== '',
            'anniversaries_enabled' => $s !== null && (int) $s['anniversaries_enabled'] === 1 && $notice !== '',
            'notice_text' => $notice, 'notice_version' => (int) ($s['notice_version'] ?? 0),
            'notify_manager' => $s === null || (int) $s['notify_manager'] === 1, 'notify_hr' => $s === null || (int) $s['notify_hr'] === 1,
            'board_scope' => (string) ($s['board_scope'] ?? 'company'),
            'milestones' => array_values(array_filter(array_map('intval', explode(',', (string) ($s['milestones'] ?? '5,10,15,20,25,30'))), static fn (int $x): bool => $x > 0)),
        ];
    }

    /** @param array<string, mixed> $in */
    public function saveSettings(Authorizer $az, int $le, array $in): void
    {
        if (!$az->scope('celebrate.manage')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $errors = [];
        $notice = mb_substr(trim(str_replace("\r", '', (string) ($in['notice_text'] ?? ''))), 0, 8000);
        $on = ($in['birthdays_enabled'] ?? '') === '1' || ($in['anniversaries_enabled'] ?? '') === '1';
        if ($on && mb_strlen($notice) < 30) {
            $errors['notice_text'] = 'celebrate.error.notice';
        }
        $ms = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,]+/', (string) ($in['milestones'] ?? '')) ?: []), static fn (int $x): bool => $x > 0 && $x <= 60)));
        sort($ms);
        if (trim((string) ($in['milestones'] ?? '')) !== '' && $ms === []) {
            $errors['milestones'] = 'celebrate.error.milestones';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $db = $this->app->db();
        $old = $db->one('SELECT notice_text, notice_version FROM cel_settings WHERE legal_entity_id = ?', [$le]);
        $version = (int) ($old['notice_version'] ?? 0) + (($old['notice_text'] ?? '') !== $notice && $notice !== '' ? 1 : 0);
        $db->run(
            'INSERT INTO cel_settings (legal_entity_id, birthdays_enabled, anniversaries_enabled, notice_text, notice_version, notify_manager, notify_hr, board_scope, milestones, updated_at, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE birthdays_enabled = VALUES(birthdays_enabled), anniversaries_enabled = VALUES(anniversaries_enabled),
             notice_text = VALUES(notice_text), notice_version = VALUES(notice_version), notify_manager = VALUES(notify_manager), notify_hr = VALUES(notify_hr), board_scope = VALUES(board_scope),
             milestones = VALUES(milestones), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [$le, ($in['birthdays_enabled'] ?? '') === '1' ? 1 : 0, ($in['anniversaries_enabled'] ?? '') === '1' ? 1 : 0, $notice !== '' ? $notice : null, $version,
                ($in['notify_manager'] ?? '') === '1' ? 1 : 0, ($in['notify_hr'] ?? '') === '1' ? 1 : 0, ($in['board_scope'] ?? '') === 'department' ? 'department' : 'company',
                implode(',', $ms !== [] ? $ms : [5, 10, 15, 20, 25, 30]), $this->now(), $az->userId]
        );
        $this->app->audit()->record('celebrate.settings_saved', 'success', 'cel_settings', (string) $le, $le, null, ['birthdays' => ($in['birthdays_enabled'] ?? '') === '1', 'anniversaries' => ($in['anniversaries_enabled'] ?? '') === '1', 'notice_version' => $version]);
        $this->generate($le);
    }

    // ---- calculation --------------------------------------------------------------------------------------------------------

    /** This year's date of a yearly event that happened on $md (MM-DD) — 29 Feb falls on 28 Feb in other years. */
    public static function occurrence(int $year, string $md): string
    {
        if ($md === '02-29' && !checkdate(2, 29, $year)) {
            return sprintf('%04d-02-28', $year);
        }
        return sprintf('%04d-%s', $year, $md);
    }

    public static function job(App $app): int
    {
        $svc = self::of($app);
        $n = 0;
        foreach (array_map('intval', array_column($app->db()->all('SELECT legal_entity_id FROM cel_settings WHERE birthdays_enabled = 1 OR anniversaries_enabled = 1'), 'legal_entity_id')) as $le) {
            $n += $svc->generate($le) + $svc->fire($le);
        }
        $app->db()->run("UPDATE cel_events SET status = 'archived' WHERE status = 'sent' AND event_date < ?", [EmployeeService::ymd($svc->today())->modify('-60 days')->format('Y-m-d')]);
        return $n;
    }

    /** Calculates the events of the window (first day of this month … today + 45 days). Returns events added. */
    public function generate(int $le): int
    {
        $s = $this->settings($le);
        $kinds = array_values(array_filter(self::KINDS, static fn (string $k): bool => $s[$k === 'birthday' ? 'birthdays_enabled' : 'anniversaries_enabled']));
        if ($kinds === []) {
            return 0;
        }
        $today = EmployeeService::ymd($this->today());
        $from = $today->modify('first day of this month')->format('Y-m-d');
        $to = $today->modify('+45 days')->format('Y-m-d');
        $db = $this->app->db();
        $profile = ProfileService::of($this->app);
        $added = 0;
        $people = $db->all(
            "SELECT e.* FROM employees e WHERE e.status IN ('active','on_leave') AND EXISTS (SELECT 1 FROM employment_history h WHERE h.employee_id = e.id AND h.legal_entity_id = ? AND h.effective_to IS NULL)",
            [$le]
        );
        $opt = [];
        foreach ($db->all('SELECT employee_id, kind FROM cel_optouts') as $o) {
            $opt[(int) $o['employee_id'] . ':' . $o['kind']] = true;
        }
        foreach ($people as $e) {
            foreach ($kinds as $kind) {
                if (isset($opt[(int) $e['id'] . ':' . $kind])) {
                    continue;
                }
                $src = $kind === 'birthday' ? $profile->birthDate($e) : (string) $e['hire_date'];
                if ($src === null || preg_match('/^(\d{4})-(\d{2}-\d{2})$/', $src, $m) !== 1) {
                    continue;
                }
                foreach ([(int) $today->format('Y'), (int) $today->format('Y') + 1] as $year) {
                    $date = self::occurrence($year, $m[2]);
                    if ($date < $from || $date > $to) {
                        continue;
                    }
                    $years = $kind === 'anniversary' ? $year - (int) $m[1] : null;
                    if ($years !== null && $years < 1) {
                        continue;
                    }
                    $added += $db->run(
                        'INSERT IGNORE INTO cel_events (public_id, legal_entity_id, employee_id, kind, event_date, years, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [Ulid::generate($this->app->clock), $le, $e['id'], $kind, $date, $years, $this->now()]
                    )->rowCount();
                }
            }
        }
        return $added;
    }

    /** Sends the events of today (and any missed ones of the last 7 days) once. */
    public function fire(int $le): int
    {
        $db = $this->app->db();
        $s = $this->settings($le);
        $today = $this->today();
        $since = EmployeeService::ymd($today)->modify('-7 days')->format('Y-m-d');
        $hr = $s['notify_hr'] ? PersonnelFileService::of($this->app)->holders('celebrate.manage', $le) : [];
        $sent = 0;
        foreach (
            $db->all("SELECT c.*, e.status AS emp_status, e.name_th, e.name_en, e.preferred_name FROM cel_events c JOIN employees e ON e.id = c.employee_id
                       WHERE c.legal_entity_id = ? AND c.status = 'scheduled' AND c.event_date <= ? ORDER BY c.event_date", [$le, $today]) as $ev
        ) {
            $enabled = $s[$ev['kind'] === 'birthday' ? 'birthdays_enabled' : 'anniversaries_enabled'];
            $opted = $db->scalar('SELECT 1 FROM cel_optouts WHERE employee_id = ? AND kind = ?', [$ev['employee_id'], $ev['kind']]) !== null;
            if (!$enabled || $opted || !in_array($ev['emp_status'], ['active', 'on_leave'], true) || $ev['event_date'] < $since) {
                $db->run("UPDATE cel_events SET status = 'cancelled', cancel_reason = ? WHERE id = ? AND status = 'scheduled'", [!$enabled ? 'turned off' : ($opted ? 'opted out' : ($ev['event_date'] < $since ? 'missed' : 'not employed')), $ev['id']]);
                continue;
            }
            $claimed = $db->run("UPDATE cel_events SET status = 'sent', sent_at = ? WHERE id = ? AND status = 'scheduled'", [$this->now(), $ev['id']])->rowCount();
            if ($claimed !== 1) {
                continue; // another run sent it
            }
            $name = (string) ($ev['preferred_name'] ?: $ev['name_th']);
            $params = ['name' => $name, 'years' => (string) ($ev['years'] ?? '')];
            $self = $db->scalar("SELECT id FROM users WHERE employee_id = ? AND status = 'active' LIMIT 1", [$ev['employee_id']]);
            if ($self !== null) {
                $this->app->notifier()->notify((int) $self, 'celebrate.greeting', 'celebrate.notify.self_' . $ev['kind'], $params, '/celebrate/me', 'cel:' . $ev['id'] . ':' . $self);
            }
            $others = $s['notify_manager'] ? EmployeeService::of($this->app)->managerUserIds((int) $ev['employee_id'], $today) : [];
            foreach (array_unique(array_merge($others, $hr)) as $uid) {
                if ($uid !== (int) $self) {
                    $this->app->notifier()->notify($uid, 'celebrate.note', 'celebrate.notify.' . $ev['kind'], $params, '/celebrate', 'cel:' . $ev['id'] . ':' . $uid);
                }
            }
            $sent++;
        }
        return $sent;
    }

    // ---- board ---------------------------------------------------------------------------------------------------------------

    /**
     * This month's events a colleague may see.
     *
     * @return array{settings: array<string, mixed>|null, month: string, birthdays: list<array<string, mixed>>, anniversaries: list<array<string, mixed>>, me: array<string, mixed>|null}
     */
    public function board(Authorizer $az, ?string $month = null): array
    {
        $me = EmployeeService::of($this->app)->forUser($az->userId);
        $month = $month !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 ? $month : substr($this->today(), 0, 7);
        if ($me === null || $me['job'] === null || $me['status'] === 'former') {
            return ['settings' => null, 'month' => $month, 'birthdays' => [], 'anniversaries' => [], 'me' => $me];
        }
        $le = (int) $me['job']['legal_entity_id'];
        $s = $this->settings($le);
        $this->generate($le);
        $out = ['settings' => $s, 'month' => $month, 'birthdays' => [], 'anniversaries' => [], 'me' => $me];
        $sql = "SELECT c.id, c.public_id, c.kind, c.event_date, c.years, c.employee_id, e.name_th, e.name_en, e.preferred_name, d.code AS dept_code, h.position_title,
                       (SELECT COUNT(*) FROM cel_wishes w WHERE w.event_id = c.id) AS wishes,
                       (SELECT COUNT(*) FROM cel_wishes w WHERE w.event_id = c.id AND w.from_user_id = ?) AS wished
                  FROM cel_events c JOIN employees e ON e.id = c.employee_id
                  JOIN employment_history h ON h.employee_id = e.id AND h.effective_to IS NULL LEFT JOIN departments d ON d.id = h.department_id
                 WHERE c.legal_entity_id = ? AND c.kind = ? AND c.status IN ('scheduled','sent','archived') AND c.event_date BETWEEN ? AND ?
                   AND e.status IN ('active','on_leave') AND NOT EXISTS (SELECT 1 FROM cel_optouts o WHERE o.employee_id = c.employee_id AND o.kind = c.kind)";
        $args = [$az->userId, $le];
        if ($s['board_scope'] === 'department') {
            $sql .= ' AND h.department_id <=> ?';
            $tail = [$me['job']['department_id']];
        } else {
            $tail = [];
        }
        $first = $month . '-01';
        $last = EmployeeService::ymd($first)->modify('last day of this month')->format('Y-m-d');
        foreach (self::KINDS as $kind) {
            if (!$s[$kind === 'birthday' ? 'birthdays_enabled' : 'anniversaries_enabled']) {
                continue;
            }
            $out[$kind === 'birthday' ? 'birthdays' : 'anniversaries'] = $this->app->db()->all($sql . ' ORDER BY c.event_date, e.name_th', array_merge($args, [$kind, $first, $last], $tail));
        }
        return $out;
    }

    public function wish(Authorizer $az, string $eventPid, string $message): void
    {
        $db = $this->app->db();
        $ev = $db->one('SELECT c.*, e.status AS emp_status FROM cel_events c JOIN employees e ON e.id = c.employee_id WHERE c.public_id = ?', [$eventPid]);
        if ($ev === null) {
            throw HttpException::notFound();
        }
        $board = $this->board($az, substr((string) $ev['event_date'], 0, 7));
        $visible = array_filter(array_merge($board['birthdays'], $board['anniversaries']), static fn (array $x): bool => $x['public_id'] === $eventPid);
        if ($visible === []) {
            throw HttpException::notFound();
        }
        $me = $board['me'];
        if ($me !== null && (int) $me['id'] === (int) $ev['employee_id']) {
            throw new BusinessException('celebrate.error.self', [], 409);
        }
        $message = mb_substr(trim($message), 0, 300);
        if (mb_strlen($message) < 2) {
            throw new ValidationException(['message' => 'celebrate.error.message']);
        }
        $n = $db->run('INSERT IGNORE INTO cel_wishes (event_id, from_user_id, message, created_at) VALUES (?, ?, ?, ?)', [$ev['id'], $az->userId, $message, $this->now()])->rowCount();
        if ($n !== 1) {
            throw new BusinessException('celebrate.error.once', [], 409);
        }
        $to = $db->scalar("SELECT id FROM users WHERE employee_id = ? AND status = 'active' LIMIT 1", [$ev['employee_id']]);
        if ($to !== null) {
            $from = (string) ($db->scalar('SELECT display_name FROM users WHERE id = ?', [$az->userId]) ?? '');
            $this->app->notifier()->notify((int) $to, 'celebrate.wish', 'celebrate.notify.wish', ['from' => $from], '/celebrate/me', 'cel.wish:' . $ev['id'] . ':' . $az->userId);
        }
    }

    /** @return array{settings: array<string, mixed>|null, optouts: list<string>, events: list<array<string, mixed>>, wishes: list<array<string, mixed>>} */
    public function mine(Authorizer $az): array
    {
        $me = EmployeeService::of($this->app)->forUser($az->userId);
        if ($me === null || $me['job'] === null) {
            return ['settings' => null, 'optouts' => [], 'events' => [], 'wishes' => []];
        }
        $db = $this->app->db();
        return [
            'settings' => $this->settings((int) $me['job']['legal_entity_id']),
            'optouts' => array_column($db->all('SELECT kind FROM cel_optouts WHERE employee_id = ?', [$me['id']]), 'kind'),
            'events' => $db->all("SELECT * FROM cel_events WHERE employee_id = ? AND status IN ('scheduled','sent','archived') ORDER BY event_date DESC LIMIT 10", [$me['id']]),
            'wishes' => $db->all(
                'SELECT w.message, w.created_at, u.display_name AS from_name, c.kind, c.event_date FROM cel_wishes w JOIN cel_events c ON c.id = w.event_id LEFT JOIN users u ON u.id = w.from_user_id
                  WHERE c.employee_id = ? ORDER BY w.id DESC LIMIT 100',
                [$me['id']]
            ),
        ];
    }

    /** @param list<string> $hidden kinds the employee hides */
    public function setOptouts(Authorizer $az, array $hidden): void
    {
        $me = EmployeeService::of($this->app)->forUser($az->userId);
        if ($me === null) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        foreach (self::KINDS as $kind) {
            if (in_array($kind, $hidden, true)) {
                $db->run('INSERT IGNORE INTO cel_optouts (employee_id, kind, created_at) VALUES (?, ?, ?)', [$me['id'], $kind, $this->now()]);
                $db->run("UPDATE cel_events SET status = 'cancelled', cancel_reason = 'opted out' WHERE employee_id = ? AND kind = ? AND status = 'scheduled'", [$me['id'], $kind]);
            } else {
                $db->run('DELETE FROM cel_optouts WHERE employee_id = ? AND kind = ?', [$me['id'], $kind]);
            }
        }
        $this->app->audit()->record('celebrate.optouts', 'success', 'employee', (string) $me['public_id'], null, null, ['hidden' => $hidden]);
    }

    // ---- HR ------------------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> events of the next $days days (HR preview), milestones marked */
    public function upcoming(int $le, int $days = 45): array
    {
        $this->generate($le);
        $s = $this->settings($le);
        $rows = $this->app->db()->all(
            "SELECT c.*, e.employee_code, e.name_th, d.code AS dept_code FROM cel_events c JOIN employees e ON e.id = c.employee_id
               LEFT JOIN employment_history h ON h.employee_id = e.id AND h.effective_to IS NULL LEFT JOIN departments d ON d.id = h.department_id
              WHERE c.legal_entity_id = ? AND c.event_date BETWEEN ? AND ? ORDER BY c.event_date, c.kind, e.employee_code",
            [$le, substr($this->today(), 0, 7) . '-01', EmployeeService::ymd($this->today())->modify('+' . $days . ' days')->format('Y-m-d')]
        );
        foreach ($rows as &$r) {
            $r['milestone'] = $r['kind'] === 'anniversary' && in_array((int) $r['years'], $s['milestones'], true);
        }
        return $rows;
    }

    public function cancel(Authorizer $az, string $eventPid, string $reason): void
    {
        $ev = $this->app->db()->one('SELECT * FROM cel_events WHERE public_id = ?', [$eventPid]);
        if ($ev === null || !$az->scope('celebrate.manage')->coversLegalEntity((int) $ev['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        $reason = mb_substr(trim($reason), 0, 300);
        if (mb_strlen($reason) < 5) {
            throw new ValidationException(['reason' => 'celebrate.error.reason']);
        }
        if ($this->app->db()->run("UPDATE cel_events SET status = 'cancelled', cancel_reason = ? WHERE id = ? AND status = 'scheduled'", [$reason, $ev['id']])->rowCount() !== 1) {
            throw new BusinessException('celebrate.error.state', [], 409);
        }
        $this->app->audit()->record('celebrate.cancelled', 'success', 'cel_event', $eventPid, (int) $ev['legal_entity_id'], $reason);
    }
}

<?php

declare(strict_types=1);

namespace Blue\Modules\Lockers;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\Xlsx;
use Blue\Core\Support\Ulid;

/**
 * M07 lockers (9A · DEC-89). Zones (a site's bank of lockers) are laid out as a grid; each locker has a status.
 * An employee reserves a free locker at once (no approval, the locker row is locked while it is taken) — one locker
 * per zone per person — and picks the key up from the workplace admin within the zone's hold days, else the
 * reservation lapses. The admin records the key handed over and, later, the return (key back, condition). When a
 * holder leaves the company the locker goes to "return pending" and the admins are told.
 */
final class LockerService
{
    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    public function tz(): DateTimeZone
    {
        return new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }

    public function today(): string
    {
        return $this->app->clock->now()->setTimezone($this->tz())->format('Y-m-d');
    }

    private function stamp(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    /** @return list<int> */
    public function companies(Authorizer $az, string $perm): array
    {
        $s = $az->scope($perm);
        return array_values(array_filter(array_map('intval', array_column($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code'), 'id')), static fn (int $id): bool => $s->coversLegalEntity($id)));
    }

    private function require(Authorizer $az, string $perm, int $le): void
    {
        if (!$az->scope($perm)->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
    }

    /** @return list<array<string, mixed>> sites of a company */
    public function sites(int $le): array
    {
        return $this->app->db()->all('SELECT id, public_id, code FROM sites WHERE legal_entity_id = ? ORDER BY code', [$le]);
    }

    // ---- zones and lockers -------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> zones of a company with counts per status */
    public function zones(int $le): array
    {
        return $this->app->db()->all(
            "SELECT z.*, s.code AS site_code, COUNT(l.id) AS lockers, SUM(l.status = 'available') AS free, SUM(l.status IN ('reserved','assigned','return_pending')) AS used,
                    SUM(l.status IN ('maintenance','blocked')) AS out_of_use
               FROM lk_zones z LEFT JOIN sites s ON s.id = z.site_id LEFT JOIN lk_lockers l ON l.zone_id = z.id WHERE z.legal_entity_id = ? GROUP BY z.id ORDER BY z.status = 'closed', s.code, z.code",
            [$le]
        );
    }

    /** @return array<string, mixed> */
    private function zoneRow(string $pid): array
    {
        return $this->app->db()->one('SELECT z.*, s.code AS site_code, l.code AS le_code FROM lk_zones z LEFT JOIN sites s ON s.id = z.site_id JOIN legal_entities l ON l.id = z.legal_entity_id WHERE z.public_id = ?', [$pid])
            ?? throw HttpException::notFound();
    }

    /**
     * A zone and its grid: cells [row][col] with the locker there (or none). Holders' names only for admins.
     *
     * @return array<string, mixed>
     */
    public function zone(Authorizer $az, string $pid): array
    {
        $z = $this->zoneRow($pid);
        $le = (int) $z['legal_entity_id'];
        if (!$az->scope('lockers.use')->coversLegalEntity($le)) {
            throw HttpException::notFound();
        }
        $this->tick();
        $z['can_manage'] = $az->scope('lockers.manage')->coversLegalEntity($le);
        $rows = $this->app->db()->all(
            "SELECT l.*, a.public_id AS assignment_pid, a.status AS a_status, a.user_id, a.hold_until, e.employee_code, e.name_th
               FROM lk_lockers l LEFT JOIN lk_assignments a ON a.locker_id = l.id AND a.status IN ('reserved','assigned','return_pending') LEFT JOIN employees e ON e.id = a.employee_id
              WHERE l.zone_id = ? ORDER BY l.row_no, l.col_no",
            [$z['id']]
        );
        $grid = [];
        foreach ($rows as $l) {
            $l['mine'] = $l['user_id'] !== null && (int) $l['user_id'] === $az->userId;
            if (!$z['can_manage'] && !$l['mine']) {
                $l['employee_code'] = null;
                $l['name_th'] = null;
            }
            $grid[(int) $l['row_no']][(int) $l['col_no']] = $l;
        }
        $z['grid'] = $grid;
        $z['has_one'] = $this->activeIn((int) $z['id'], $az->userId) !== null;
        return $z;
    }

    /** @return array<string, mixed>|null the person's reservation or locker in a zone */
    private function activeIn(int $zoneId, int $userId): ?array
    {
        return $this->app->db()->one(
            "SELECT a.* FROM lk_assignments a JOIN lk_lockers l ON l.id = a.locker_id WHERE l.zone_id = ? AND a.user_id = ? AND a.status IN ('reserved','assigned','return_pending') LIMIT 1",
            [$zoneId, $userId]
        );
    }

    /**
     * A new zone: its place, grid size and how many days a reservation is held for the key pickup (company rule — no
     * default); the lockers are made at once, coded prefix-row-col (e.g. A-01-03), all free, size M.
     *
     * @param array<string, mixed> $in code, name, site, grid_rows, grid_cols, hold_days, note
     */
    public function createZone(Authorizer $az, int $le, array $in): string
    {
        $this->require($az, 'lockers.manage', $le);
        $db = $this->app->db();
        $e = [];
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{0,9}$/', $code) !== 1) {
            $e['code'] = 'lockers.error.code';
        } elseif ($db->scalar('SELECT id FROM lk_zones WHERE legal_entity_id = ? AND code = ?', [$le, $code]) !== null) {
            $e['code'] = 'lockers.error.code_taken';
        }
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            $e['name'] = 'lockers.error.name';
        }
        $site = null;
        if ((string) ($in['site'] ?? '') !== '') {
            $site = $db->scalar('SELECT id FROM sites WHERE public_id = ? AND legal_entity_id = ?', [(string) $in['site'], $le]);
            if ($site === null) {
                $e['site'] = 'lockers.error.site';
            }
        }
        $num = static function (string $k, int $min, int $max) use ($in, &$e): int {
            $v = trim((string) ($in[$k] ?? ''));
            if (!ctype_digit($v) || (int) $v < $min || (int) $v > $max) {
                $e[$k] = 'lockers.error.number';
                return 0;
            }
            return (int) $v;
        };
        $rows = $num('grid_rows', 1, 20);
        $cols = $num('grid_cols', 1, 30);
        $hold = $num('hold_days', 1, 30);
        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($note) > 300) {
            $e['note'] = 'lockers.error.text';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        return $db->transactional(function () use ($db, $az, $le, $site, $code, $name, $rows, $cols, $hold, $note): string {
            $now = $this->stamp();
            $pid = Ulid::generate($this->app->clock);
            $db->run(
                'INSERT INTO lk_zones (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, site_id, code, name, grid_rows, grid_cols, hold_days, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $le, $site, $code, $name, $rows, $cols, $hold, $note === '' ? null : $note]
            );
            $zone = (int) $db->pdo()->lastInsertId();
            for ($r = 1; $r <= $rows; $r++) {
                for ($c = 1; $c <= $cols; $c++) {
                    $db->run(
                        'INSERT INTO lk_lockers (public_id, created_at, created_by, updated_at, updated_by, zone_id, code, row_no, col_no) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [Ulid::generate($this->app->clock), $now, $az->userId, $now, $az->userId, $zone, sprintf('%s-%02d-%02d', $code, $r, $c), $r, $c]
                    );
                }
            }
            $this->app->audit()->record('lockers.zone_created', 'success', 'lk_zone', $pid, $le, null, ['code' => $code, 'lockers' => $rows * $cols]);
            return $pid;
        });
    }

    /** @return array<string, mixed> a locker with its zone, current holder (admins and the holder), history (admins) */
    public function locker(Authorizer $az, string $pid): array
    {
        $this->tick();
        $db = $this->app->db();
        $l = $db->one(
            'SELECT l.*, z.public_id AS zone_pid, z.code AS zone_code, z.name AS zone_name, z.legal_entity_id, z.hold_days, z.status AS zone_status FROM lk_lockers l JOIN lk_zones z ON z.id = l.zone_id WHERE l.public_id = ?',
            [$pid]
        ) ?? throw HttpException::notFound();
        $le = (int) $l['legal_entity_id'];
        if (!$az->scope('lockers.use')->coversLegalEntity($le)) {
            throw HttpException::notFound();
        }
        $l['can_manage'] = $az->scope('lockers.manage')->coversLegalEntity($le);
        $l['current'] = $db->one(
            "SELECT a.*, e.employee_code, e.name_th, e.status AS employee_status, e.termination_date FROM lk_assignments a JOIN employees e ON e.id = a.employee_id
              WHERE a.locker_id = ? AND a.status IN ('reserved','assigned','return_pending') ORDER BY a.id DESC LIMIT 1",
            [$l['id']]
        );
        $l['mine'] = $l['current'] !== null && (int) $l['current']['user_id'] === $az->userId;
        if (!$l['can_manage'] && !$l['mine']) {
            $l['current'] = null;
        }
        $l['history'] = $l['can_manage'] ? $db->all(
            'SELECT a.public_id, a.status, a.created_at, a.key_issued_at, a.returned_at, a.key_returned, a.locker_condition, e.employee_code, e.name_th
               FROM lk_assignments a JOIN employees e ON e.id = a.employee_id WHERE a.locker_id = ? ORDER BY a.id DESC LIMIT 50',
            [$l['id']]
        ) : [];
        $l['events'] = $l['can_manage'] ? $db->all('SELECT v.event, v.note, v.created_at, COALESCE(u.display_name, u.username) AS actor FROM lk_events v LEFT JOIN users u ON u.id = v.actor_id WHERE v.locker_id = ? ORDER BY v.id DESC LIMIT 50', [$l['id']]) : [];
        $l['has_one'] = $this->activeIn((int) $l['zone_id'], $az->userId) !== null;
        return $l;
    }

    // ---- the employee -----------------------------------------------------------------------------------------------------

    /** Reserves a free locker for the person (no approval) — one per zone; the key is picked up within the hold days. */
    public function reserve(Authorizer $az, string $lockerPid): string
    {
        $l = $this->locker($az, $lockerPid);
        $db = $this->app->db();
        $emp = $db->one("SELECT e.id, e.status FROM users u JOIN employees e ON e.id = u.employee_id WHERE u.id = ?", [$az->userId]);
        if ($emp === null || in_array($emp['status'], ['former', 'pre_hire'], true)) {
            throw new BusinessException('lockers.error.no_employee', [], 409);
        }
        return $db->transactional(function () use ($db, $az, $l, $emp): string {
            $row = $db->one('SELECT status FROM lk_lockers WHERE id = ? FOR UPDATE', [$l['id']]) ?? throw HttpException::notFound();
            if ($row['status'] !== 'available' || $l['zone_status'] !== 'active') {
                throw new BusinessException('lockers.error.not_free', [], 409);
            }
            $db->one('SELECT id FROM lk_zones WHERE id = ? FOR UPDATE', [$l['zone_id']]); // one reservation per zone and person at a time
            if ($this->activeIn((int) $l['zone_id'], $az->userId) !== null) {
                throw new BusinessException('lockers.error.one_per_zone', [], 409);
            }
            $now = $this->stamp();
            $until = (DateTimeImmutable::createFromFormat('!Y-m-d', $this->today()) ?: $this->app->clock->now())->modify('+' . (int) $l['hold_days'] . ' days')->format('Y-m-d');
            $pid = Ulid::generate($this->app->clock);
            $db->run(
                'INSERT INTO lk_assignments (public_id, created_at, created_by, updated_at, updated_by, locker_id, legal_entity_id, employee_id, user_id, hold_until) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $l['id'], $l['legal_entity_id'], $emp['id'], $az->userId, $until]
            );
            $aid = (int) $db->pdo()->lastInsertId();
            $db->run("UPDATE lk_lockers SET status = 'reserved', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$now, $az->userId, $l['id']]);
            $this->event((int) $l['id'], $aid, 'reserved', $az->userId, null);
            $this->app->audit()->record('lockers.reserved', 'success', 'lk_locker', (string) $l['public_id'], (int) $l['legal_entity_id']);
            return $pid;
        });
    }

    /** The person cancels their reservation before the key was handed over. */
    public function cancelReservation(Authorizer $az, string $lockerPid): void
    {
        $l = $this->locker($az, $lockerPid);
        $a = $l['current'];
        if ($a === null || $a['status'] !== 'reserved' || (!$l['mine'] && !$l['can_manage'])) {
            throw new BusinessException('lockers.error.state', [], 409);
        }
        $this->close($l, $a, 'cancelled', 'available', $az->userId, $l['mine'] ? null : 'admin');
    }

    /** The person says they received the key (the record of the handover — no signature is drawn or stored). */
    public function acknowledge(Authorizer $az, string $lockerPid): void
    {
        $l = $this->locker($az, $lockerPid);
        $a = $l['current'];
        if (!$l['mine'] || $a === null || $a['status'] !== 'assigned' || $a['received_ack_at'] !== null) {
            throw new BusinessException('lockers.error.state', [], 409);
        }
        $this->app->db()->run('UPDATE lk_assignments SET received_ack_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$this->stamp(), $this->stamp(), $az->userId, $a['id']]);
        $this->event((int) $l['id'], (int) $a['id'], 'acknowledged', $az->userId, null);
    }

    /** The holder asks to give the locker back — the admin then records the return. */
    public function askReturn(Authorizer $az, string $lockerPid): void
    {
        $l = $this->locker($az, $lockerPid);
        $a = $l['current'];
        if (!$l['mine'] || $a === null || $a['status'] !== 'assigned') {
            throw new BusinessException('lockers.error.state', [], 409);
        }
        $this->toReturnPending($l, $a, 'asked', $az->userId);
    }

    // ---- the workplace admin ----------------------------------------------------------------------------------------------

    /** Hands the key of a reserved locker over (key number); the reservation becomes the holder's locker. */
    public function issueKey(Authorizer $az, string $lockerPid, string $keyNo): void
    {
        $l = $this->locker($az, $lockerPid);
        $this->require($az, 'lockers.manage', (int) $l['legal_entity_id']);
        $a = $l['current'];
        if ($a === null || $a['status'] !== 'reserved') {
            throw new BusinessException('lockers.error.state', [], 409);
        }
        $keyNo = trim($keyNo);
        if ($keyNo === '' || mb_strlen($keyNo) > 40) {
            throw new ValidationException(['key_no' => 'lockers.error.key_no']);
        }
        $db = $this->app->db();
        $now = $this->stamp();
        $db->run(
            "UPDATE lk_assignments SET status = 'assigned', key_no = ?, key_issued_at = ?, key_issued_by = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = 'reserved'",
            [$keyNo, $now, $az->userId, $now, $az->userId, $a['id']]
        );
        $db->run("UPDATE lk_lockers SET status = 'assigned', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$now, $az->userId, $l['id']]);
        $this->event((int) $l['id'], (int) $a['id'], 'key_issued', $az->userId, $keyNo);
        $this->app->notifier()->notify((int) $a['user_id'], 'lockers.key', 'lockers.notify.key_issued', ['locker' => (string) $l['code']], '/lockers/l/' . $l['public_id']);
        $this->app->audit()->record('lockers.key_issued', 'success', 'lk_locker', (string) $l['public_id'], (int) $l['legal_entity_id']);
    }

    /**
     * Records the locker given back: whether the key came back and the condition. Damaged → maintenance; key not back →
     * blocked (with the note); else free again.
     *
     * @param array<string, mixed> $in key_returned (1|0), condition (good|damaged), note
     */
    public function recordReturn(Authorizer $az, string $lockerPid, array $in): void
    {
        $l = $this->locker($az, $lockerPid);
        $this->require($az, 'lockers.manage', (int) $l['legal_entity_id']);
        $a = $l['current'];
        if ($a === null || !in_array($a['status'], ['assigned', 'return_pending'], true)) {
            throw new BusinessException('lockers.error.state', [], 409);
        }
        $key = (string) ($in['key_returned'] ?? '');
        $cond = (string) ($in['condition'] ?? '');
        $note = trim((string) ($in['note'] ?? ''));
        $e = [];
        if (!in_array($key, ['1', '0'], true)) {
            $e['key_returned'] = 'lockers.error.choose';
        }
        if (!in_array($cond, ['good', 'damaged'], true)) {
            $e['condition'] = 'lockers.error.choose';
        }
        if (($cond === 'damaged' || $key === '0') && mb_strlen($note) < 3) {
            $e['note'] = 'lockers.error.note_needed';
        }
        if (mb_strlen($note) > 300) {
            $e['note'] = 'lockers.error.text';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $next = $key === '0' ? 'blocked' : ($cond === 'damaged' ? 'maintenance' : 'available');
        $db = $this->app->db();
        $now = $this->stamp();
        $db->run(
            "UPDATE lk_assignments SET status = 'returned', returned_at = ?, returned_by = ?, key_returned = ?, locker_condition = ?, return_note = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?",
            [$now, $az->userId, (int) $key, $cond, $note === '' ? null : $note, $now, $az->userId, $a['id']]
        );
        $db->run('UPDATE lk_lockers SET status = ?, status_note = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$next, $next === 'available' ? null : $note, $now, $az->userId, $l['id']]);
        $this->event((int) $l['id'], (int) $a['id'], 'returned', $az->userId, $note === '' ? null : $note);
        $this->app->audit()->record('lockers.returned', 'success', 'lk_locker', (string) $l['public_id'], (int) $l['legal_entity_id'], null, ['key_returned' => $key === '1', 'condition' => $cond]);
    }

    /** Puts a locker that nobody holds out of use (repair / blocked) or back into use. */
    public function setStatus(Authorizer $az, string $lockerPid, string $status, string $note): void
    {
        $l = $this->locker($az, $lockerPid);
        $this->require($az, 'lockers.manage', (int) $l['legal_entity_id']);
        if (!in_array($status, ['available', 'maintenance', 'blocked'], true)) {
            throw HttpException::notFound();
        }
        if ($this->app->db()->scalar("SELECT id FROM lk_assignments WHERE locker_id = ? AND status IN ('reserved','assigned','return_pending') LIMIT 1", [$l['id']]) !== null) {
            throw new BusinessException('lockers.error.occupied', [], 409);
        }
        $note = trim($note);
        if ($status !== 'available' && (mb_strlen($note) < 3 || mb_strlen($note) > 300)) {
            throw new ValidationException(['status_note' => 'lockers.error.note_needed']);
        }
        $this->app->db()->run(
            'UPDATE lk_lockers SET status = ?, status_note = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$status, $status === 'available' ? null : $note, $this->stamp(), $az->userId, $l['id']]
        );
        $this->event((int) $l['id'], null, 'status_' . $status, $az->userId, $note === '' ? null : $note);
    }

    // ---- housekeeping --------------------------------------------------------------------------------------------------

    /**
     * Hourly (and before screens): reservations whose key was not picked up in time lapse; lockers of people who left
     * (or are leaving today) go to "return pending" (reservations are cancelled) and the zone's admins are told once.
     */
    public function tick(): int
    {
        $db = $this->app->db();
        $today = $this->today();
        $n = 0;
        foreach ($db->all("SELECT a.*, l.public_id AS locker_pid, l.code FROM lk_assignments a JOIN lk_lockers l ON l.id = a.locker_id WHERE a.status = 'reserved' AND a.hold_until < ? LIMIT 500", [$today]) as $a) {
            $this->close(['id' => $a['locker_id']], $a, 'expired', 'available', null, null);
            $this->app->notifier()->notify((int) $a['user_id'], 'lockers.expired', 'lockers.notify.expired', ['locker' => (string) $a['code']], '/lockers', 'lk.exp:' . $a['public_id']);
            $n++;
        }
        foreach (
            $db->all(
                "SELECT a.*, l.public_id AS locker_pid, l.code, l.zone_id FROM lk_assignments a JOIN lk_lockers l ON l.id = a.locker_id JOIN employees e ON e.id = a.employee_id
                  WHERE a.status IN ('reserved','assigned') AND (e.status = 'former' OR (e.termination_date IS NOT NULL AND e.termination_date <= ?)) LIMIT 500",
                [$today]
            ) as $a
        ) {
            if ($a['status'] === 'reserved') {
                $this->close(['id' => $a['locker_id']], $a, 'cancelled', 'available', null, 'left');
            } else {
                $this->toReturnPending(['id' => $a['locker_id'], 'public_id' => $a['locker_pid'], 'code' => $a['code'], 'legal_entity_id' => $a['legal_entity_id']], $a, 'left', null);
            }
            $n++;
        }
        return $n;
    }

    public static function tickJob(App $app): string
    {
        return 'lockers: ' . self::of($app)->tick();
    }

    /**
     * @param array<string, mixed> $l
     * @param array<string, mixed> $a
     */
    private function toReturnPending(array $l, array $a, string $why, ?int $actor): void
    {
        $db = $this->app->db();
        $now = $this->stamp();
        $db->run("UPDATE lk_assignments SET status = 'return_pending', return_reason = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'assigned'", [$why, $now, $a['id']]);
        $db->run("UPDATE lk_lockers SET status = 'return_pending', updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$now, $l['id']]);
        $this->event((int) $l['id'], (int) $a['id'], 'return_' . $why, $actor, null);
        if ($why === 'left') {
            foreach ($this->admins((int) $a['legal_entity_id']) as $u) {
                $this->app->notifier()->notify($u, 'lockers.left', 'lockers.notify.left', ['locker' => (string) $l['code']], '/lockers/l/' . $l['public_id'], 'lk.left:' . $a['public_id'] . ':' . $u);
            }
        }
    }

    /**
     * @param array<string, mixed> $l
     * @param array<string, mixed> $a
     */
    private function close(array $l, array $a, string $status, string $lockerStatus, ?int $actor, ?string $note): void
    {
        $db = $this->app->db();
        $now = $this->stamp();
        $db->run('UPDATE lk_assignments SET status = ?, cancel_reason = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?', [$status, $note, $now, $a['id']]);
        $db->run('UPDATE lk_lockers SET status = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?', [$lockerStatus, $now, $l['id']]);
        $this->event((int) $l['id'], (int) $a['id'], $status, $actor, $note);
    }

    /** @return list<int> users who manage lockers in a company */
    private function admins(int $le): array
    {
        $out = [];
        foreach ($this->app->db()->all("SELECT DISTINCT a.user_id FROM user_role_assignments a JOIN users u ON u.id = a.user_id WHERE a.status = 'active' AND u.status = 'active'") as $r) {
            $az = new Authorizer($this->app->db(), $this->app->clock, (int) $r['user_id']);
            if ($az->scope('lockers.manage')->coversLegalEntity($le)) {
                $out[] = (int) $r['user_id'];
            }
        }
        return $out;
    }

    // ---- reading for screens -----------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> my lockers (any company) */
    public function mine(Authorizer $az): array
    {
        $this->tick();
        return $this->app->db()->all(
            "SELECT a.status, a.hold_until, a.key_no, a.key_issued_at, a.received_ack_at, l.public_id AS locker_pid, l.code, z.name AS zone_name, s.code AS site_code, le.code AS le_code
               FROM lk_assignments a JOIN lk_lockers l ON l.id = a.locker_id JOIN lk_zones z ON z.id = l.zone_id LEFT JOIN sites s ON s.id = z.site_id JOIN legal_entities le ON le.id = a.legal_entity_id
              WHERE a.user_id = ? AND a.status IN ('reserved','assigned','return_pending') ORDER BY a.id",
            [$az->userId]
        );
    }

    /** @return list<array<string, mixed>> lockers that need the admin: reservations waiting for the key, returns pending */
    public function todo(Authorizer $az, int $le): array
    {
        $this->require($az, 'lockers.manage', $le);
        $this->tick();
        return $this->app->db()->all(
            "SELECT a.status, a.hold_until, a.return_reason, l.public_id AS locker_pid, l.code, z.name AS zone_name, e.employee_code, e.name_th
               FROM lk_assignments a JOIN lk_lockers l ON l.id = a.locker_id JOIN lk_zones z ON z.id = l.zone_id JOIN employees e ON e.id = a.employee_id
              WHERE a.legal_entity_id = ? AND a.status IN ('reserved','return_pending') ORDER BY a.status = 'reserved', a.hold_until, l.code",
            [$le]
        );
    }

    /** @return array{bytes: string, name: string} lockers of a company by zone and status (counts only) */
    public function export(Authorizer $az, int $le): array
    {
        $this->require($az, 'lockers.manage', $le);
        $t = $this->app->translator();
        $rows = [];
        foreach ($this->zones($le) as $z) {
            $rows[] = [(string) $z['code'], (string) $z['name'], (string) ($z['site_code'] ?? ''), (string) $z['lockers'], (string) $z['free'], (string) $z['used'], (string) $z['out_of_use']];
        }
        $code = (string) $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$le]);
        $bytes = Xlsx::write(['lockers' => [
            'headers' => [$t->get('lockers.col.zone'), $t->get('lockers.field.name'), $t->get('lockers.field.site'), $t->get('lockers.col.lockers'), $t->get('lockers.col.free'), $t->get('lockers.col.used'), $t->get('lockers.col.out_of_use')],
            'rows' => $rows, 'numeric' => [3, 4, 5, 6], 'widths' => [10, 30, 10, 10, 10, 10, 12],
        ]], 'Blue OS lockers');
        $this->app->audit()->record('lockers.report_exported', 'success', 'lk_report', $code, $le, null, ['rows' => count($rows), 'sha256' => hash('sha256', $bytes)]);
        return ['bytes' => $bytes, 'name' => 'lockers-' . $code . '-' . $this->today() . '.xlsx'];
    }

    private function event(int $lockerId, ?int $assignmentId, string $event, ?int $actor, ?string $note): void
    {
        $this->app->db()->run('INSERT INTO lk_events (locker_id, assignment_id, event, actor_id, note, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$lockerId, $assignmentId, $event, $actor, $note === null ? null : mb_substr($note, 0, 300), $this->stamp()]);
    }
}

<?php

declare(strict_types=1);

namespace Blue\Modules\Rooms;

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
 * M04 meeting rooms (9A · DEC-89). Rooms belong to a company (and optionally a site) and carry their own booking
 * rules, set by the workplace admin (no defaults). Anyone with rooms.use books a free slot at once — no approval,
 * first come first served: the room row is locked while the slot (with the room's buffer) is checked and written.
 * Weekly series check every date; dates that clash are listed (or skipped when the person asks). Rooms that need a
 * check-in are released as no-show when nobody checks in in time. Times are wall-clock times of the display zone.
 *
 * Others see a booked slot as "busy" — the title and who booked are shown to the booker, the people invited and the
 * room admins only (privacy minimisation).
 */
final class RoomService
{
    public const STEP = 15;

    /** a check-in opens this many minutes before the start (fixed — DEC-89) */
    public const CHECKIN_EARLY = 15;

    public const MAX_OCCURRENCES = 52;

    private const LIVE = ['confirmed', 'checked_in'];

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

    /** now as a wall-clock time of the display zone */
    public function nowLocal(): DateTimeImmutable
    {
        $n = $this->app->clock->now()->setTimezone($this->tz());
        return DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $n->format('Y-m-d H:i:s')) ?: $n;
    }

    public function today(): string
    {
        return $this->nowLocal()->format('Y-m-d');
    }

    private function stamp(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    /** @return list<int> companies the person covers with a permission */
    public function companies(Authorizer $az, string $perm): array
    {
        $s = $az->scope($perm);
        return array_values(array_filter(array_map('intval', array_column($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code'), 'id')), static fn (int $id): bool => $s->coversLegalEntity($id)));
    }

    /** @param array<string, mixed> $room */
    private function canManage(Authorizer $az, array $room): bool
    {
        return $az->scope('rooms.manage')->coversLegalEntity((int) $room['legal_entity_id']);
    }

    private function require(Authorizer $az, string $perm, int $le): void
    {
        if (!$az->scope($perm)->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
    }

    public static function ymd(string $d): ?DateTimeImmutable
    {
        $x = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        return $x !== false && $x->format('Y-m-d') === $d ? $x : null;
    }

    private static function hm(string $t): ?int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:00)?$/', $t, $m) !== 1) {
            return null;
        }
        return (int) $m[1] * 60 + (int) $m[2];
    }

    // ---- rooms ---------------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> rooms of a company (optionally a site), active first */
    public function rooms(int $le, ?int $siteId = null, bool $activeOnly = false): array
    {
        $sql = 'SELECT r.*, s.code AS site_code FROM rm_rooms r LEFT JOIN sites s ON s.id = r.site_id WHERE r.legal_entity_id = ?';
        $p = [$le];
        if ($siteId !== null) {
            $sql .= ' AND (r.site_id = ? OR r.site_id IS NULL)';
            $p[] = $siteId;
        }
        if ($activeOnly) {
            $sql .= " AND r.status = 'active'";
        }
        return $this->app->db()->all($sql . " ORDER BY r.status = 'closed', s.code, r.code", $p);
    }

    /** @return array<string, mixed> */
    public function room(string $pid): array
    {
        return $this->app->db()->one('SELECT r.*, s.code AS site_code, l.code AS le_code FROM rm_rooms r LEFT JOIN sites s ON s.id = r.site_id JOIN legal_entities l ON l.id = r.legal_entity_id WHERE r.public_id = ?', [$pid])
            ?? throw HttpException::notFound();
    }

    /** @return array<string, mixed> a room the person may use */
    public function visibleRoom(Authorizer $az, string $pid): array
    {
        $r = $this->room($pid);
        if (!$az->scope('rooms.use')->coversLegalEntity((int) $r['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        $r['can_manage'] = $this->canManage($az, $r);
        $r['closures'] = $this->app->db()->all('SELECT * FROM rm_closures WHERE room_id = ? AND removed_at IS NULL AND ends_at >= ? ORDER BY starts_at', [$r['id'], $this->nowLocal()->format('Y-m-d H:i:s')]);
        return $r;
    }

    /** @return list<array<string, mixed>> sites of a company */
    public function sites(int $le): array
    {
        return $this->app->db()->all('SELECT id, public_id, code FROM sites WHERE legal_entity_id = ? ORDER BY code', [$le]);
    }

    /**
     * Adds or changes a room and its rules.
     *
     * @param array<string, mixed> $in code, name, site, capacity, equipment, location, open_from, open_to, open_days[], buffer_minutes, max_minutes, advance_days, checkin_required, release_minutes
     */
    public function saveRoom(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        $this->require($az, 'rooms.manage', $le);
        $db = $this->app->db();
        $e = [];
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,19}$/', $code) !== 1) {
            $e['code'] = 'rooms.error.code';
        } elseif ($db->scalar('SELECT id FROM rm_rooms WHERE legal_entity_id = ? AND code = ? AND public_id <> ?', [$le, $code, $pid ?? '']) !== null) {
            $e['code'] = 'rooms.error.code_taken';
        }
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            $e['name'] = 'rooms.error.name';
        }
        $site = null;
        $sitePid = (string) ($in['site'] ?? '');
        if ($sitePid !== '') {
            $site = $db->scalar('SELECT id FROM sites WHERE public_id = ? AND legal_entity_id = ?', [$sitePid, $le]);
            if ($site === null) {
                $e['site'] = 'rooms.error.site';
            }
        }
        $int = static function (string $k, int $min, int $max, bool $required = true) use ($in, &$e): ?int {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v === '' && !$required) {
                return null;
            }
            if (!ctype_digit($v) || (int) $v < $min || (int) $v > $max) {
                $e[$k] = 'rooms.error.number';
                return null;
            }
            return (int) $v;
        };
        $capacity = $int('capacity', 1, 500);
        $buffer = $int('buffer_minutes', 0, 120);
        $maxMin = $int('max_minutes', self::STEP, 24 * 60);
        $advance = $int('advance_days', 0, 366);
        $from = self::hm((string) ($in['open_from'] ?? ''));
        $to = self::hm((string) ($in['open_to'] ?? ''));
        if ($from === null || $to === null || $to <= $from || $from % self::STEP !== 0 || $to % self::STEP !== 0) {
            $e['open_to'] = 'rooms.error.hours_range';
        }
        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($in['open_days'] ?? [])), static fn (int $d): bool => $d >= 1 && $d <= 7)));
        sort($days);
        if ($days === []) {
            $e['open_days'] = 'rooms.error.days';
        }
        $checkin = !empty($in['checkin_required']);
        $release = $int('release_minutes', 5, 120, !$checkin ? false : true);
        if ($maxMin !== null && $maxMin % self::STEP !== 0) {
            $e['max_minutes'] = 'rooms.error.step';
        }
        $equip = trim((string) ($in['equipment'] ?? ''));
        $loc = trim((string) ($in['location'] ?? ''));
        if (mb_strlen($equip) > 500 || mb_strlen($loc) > 200) {
            $e['equipment'] = 'rooms.error.text';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $now = $this->stamp();
        $vals = [$site, $code, $name, $capacity, $equip === '' ? null : $equip, $loc === '' ? null : $loc, sprintf('%02d:%02d:00', intdiv((int) $from, 60), (int) $from % 60),
            sprintf('%02d:%02d:00', intdiv((int) $to, 60), (int) $to % 60), implode(',', $days), $buffer, $maxMin, $advance, $checkin ? 1 : 0, $checkin ? $release : null];
        if ($pid === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run(
                'INSERT INTO rm_rooms (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, site_id, code, name, capacity, equipment, location, open_from, open_to, open_days,
                    buffer_minutes, max_minutes, advance_days, checkin_required, release_minutes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $le, ...$vals]
            );
            $this->app->audit()->record('rooms.room_created', 'success', 'rm_room', $pid, $le, null, ['code' => $code]);
        } else {
            $r = $this->room($pid);
            if ((int) $r['legal_entity_id'] !== $le) {
                throw HttpException::notFound();
            }
            $db->run(
                'UPDATE rm_rooms SET site_id = ?, code = ?, name = ?, capacity = ?, equipment = ?, location = ?, open_from = ?, open_to = ?, open_days = ?, buffer_minutes = ?, max_minutes = ?,
                    advance_days = ?, checkin_required = ?, release_minutes = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
                [...$vals, $now, $az->userId, $r['id']]
            );
            $this->app->audit()->record('rooms.room_changed', 'success', 'rm_room', $pid, $le, null, ['code' => $code]); // bookings already made keep their times
        }
        return $pid;
    }

    /** Closes a room for bookings (existing future bookings stay — the admin decides about them) or opens it again. */
    public function setStatus(Authorizer $az, string $pid, bool $open, string $note): void
    {
        $r = $this->room($pid);
        $this->require($az, 'rooms.manage', (int) $r['legal_entity_id']);
        $note = trim($note);
        if (!$open && (mb_strlen($note) < 3 || mb_strlen($note) > 300)) {
            throw new ValidationException(['note' => 'rooms.error.reason']);
        }
        $this->app->db()->run(
            "UPDATE rm_rooms SET status = ?, closed_note = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?",
            [$open ? 'active' : 'closed', $open ? null : $note, $this->stamp(), $az->userId, $r['id']]
        );
        $this->app->audit()->record($open ? 'rooms.room_opened' : 'rooms.room_closed', 'success', 'rm_room', $pid, (int) $r['legal_entity_id'], $open ? null : $note);
    }

    /** A time the room cannot be booked (repair, event). Returns how many live bookings fall in it (the admin is told). */
    public function addClosure(Authorizer $az, string $pid, string $from, string $to, string $reason): int
    {
        $r = $this->room($pid);
        $this->require($az, 'rooms.manage', (int) $r['legal_entity_id']);
        $f = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $from) ?: DateTimeImmutable::createFromFormat('!Y-m-d H:i', $from);
        $t = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $to) ?: DateTimeImmutable::createFromFormat('!Y-m-d H:i', $to);
        $e = [];
        if ($f === false || $t === false || $t <= $f) {
            $e['closure_to'] = 'rooms.error.closure_range';
        }
        if (mb_strlen(trim($reason)) < 3 || mb_strlen(trim($reason)) > 300) {
            $e['closure_reason'] = 'rooms.error.reason';
        }
        if ($e !== [] || $f === false || $t === false) {
            throw new ValidationException($e);
        }
        $db = $this->app->db();
        $db->run('INSERT INTO rm_closures (room_id, starts_at, ends_at, reason, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$r['id'], $f->format('Y-m-d H:i:s'), $t->format('Y-m-d H:i:s'), trim($reason), $az->userId, $this->stamp()]);
        $this->app->audit()->record('rooms.closure_added', 'success', 'rm_room', $pid, (int) $r['legal_entity_id'], trim($reason));
        return (int) $db->scalar("SELECT COUNT(*) FROM rm_bookings WHERE room_id = ? AND status IN ('confirmed','checked_in') AND starts_at < ? AND ends_at > ?", [$r['id'], $t->format('Y-m-d H:i:s'), $f->format('Y-m-d H:i:s')]);
    }

    public function removeClosure(Authorizer $az, string $pid, int $closureId): void
    {
        $r = $this->room($pid);
        $this->require($az, 'rooms.manage', (int) $r['legal_entity_id']);
        $this->app->db()->run('UPDATE rm_closures SET removed_at = ? WHERE id = ? AND room_id = ? AND removed_at IS NULL', [$this->stamp(), $closureId, $r['id']]);
        $this->app->audit()->record('rooms.closure_removed', 'success', 'rm_room', $pid, (int) $r['legal_entity_id']);
    }

    // ---- booking -------------------------------------------------------------------------------------------------------------

    /**
     * Problems of one occurrence (field => key) — the rules of the room, then the clash check (live bookings and the
     * room's buffer on both sides, closures).
     *
     * @param array<string, mixed> $room
     * @return array<string, string>
     */
    private function check(array $room, DateTimeImmutable $start, DateTimeImmutable $end, int $people, ?int $exceptId = null): array
    {
        $e = [];
        $now = $this->nowLocal();
        if ($room['status'] !== 'active') {
            return ['room' => 'rooms.error.closed'];
        }
        if ($start < $now) {
            $e['start'] = 'rooms.error.past';
        }
        $mins = (int) (($end->getTimestamp() - $start->getTimestamp()) / 60);
        if ($mins <= 0 || $start->format('Y-m-d') !== $end->format('Y-m-d')) {
            $e['end'] = 'rooms.error.end';
            return $e;
        }
        if ($mins > (int) $room['max_minutes']) {
            $e['end'] = 'rooms.error.too_long';
        }
        if ($start->format('Y-m-d') > $now->modify('+' . (int) $room['advance_days'] . ' days')->format('Y-m-d')) {
            $e['date'] = 'rooms.error.too_far';
        }
        $days = array_map('intval', explode(',', (string) $room['open_days']));
        $s = $start->format('H:i:s');
        $t = $end->format('H:i:s');
        if (!in_array((int) $start->format('N'), $days, true) || $s < (string) $room['open_from'] || $t > (string) $room['open_to']) {
            $e['start'] = 'rooms.error.hours';
        }
        if ($people < 1 || $people > (int) $room['capacity']) {
            $e['attendee_count'] = 'rooms.error.capacity';
        }
        if ($e !== []) {
            return $e;
        }
        $db = $this->app->db();
        $a = $start->format('Y-m-d H:i:s');
        $b = $end->format('Y-m-d H:i:s');
        if ($db->scalar('SELECT id FROM rm_closures WHERE room_id = ? AND removed_at IS NULL AND starts_at < ? AND ends_at > ? LIMIT 1', [$room['id'], $b, $a]) !== null) {
            return ['start' => 'rooms.error.room_closed_then'];
        }
        $buf = (int) $room['buffer_minutes'];
        if (
            $db->scalar(
                "SELECT id FROM rm_bookings WHERE room_id = ? AND status IN ('confirmed','checked_in') AND id <> ? AND starts_at < ? AND ends_at > ? LIMIT 1",
                [$room['id'], $exceptId ?? 0, $end->modify('+' . $buf . ' minutes')->format('Y-m-d H:i:s'), $start->modify('-' . $buf . ' minutes')->format('Y-m-d H:i:s')]
            ) !== null
        ) {
            return ['start' => 'rooms.error.taken'];
        }
        return [];
    }

    /**
     * People to tell about a booking: employee codes, user names or e-mail addresses (comma / space / new line),
     * resolved to accounts that may use rooms in the company. Unknown entries are listed back.
     *
     * @return list<int>
     */
    private function attendees(string $text, int $le, int $self): array
    {
        $db = $this->app->db();
        $ids = [];
        $unknown = [];
        foreach (array_filter(array_map('trim', preg_split('/[\s,;]+/', $text) ?: [])) as $tok) {
            if (mb_strlen($tok) > 120) {
                $unknown[] = mb_substr($tok, 0, 20) . '…';
                continue;
            }
            $u = $db->scalar(
                "SELECT u.id FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.status = 'active' AND (u.username = ? OR u.email = ? OR e.employee_code = ?) LIMIT 1",
                [$tok, $tok, strtoupper($tok)]
            );
            if ($u === null) {
                $unknown[] = $tok;
                continue;
            }
            $az = new Authorizer($db, $this->app->clock, (int) $u);
            if (!$az->scope('rooms.use')->coversLegalEntity($le)) {
                $unknown[] = $tok;
                continue;
            }
            if ((int) $u !== $self) {
                $ids[(int) $u] = true;
            }
        }
        if ($unknown !== []) {
            throw new BookingProblem('attendees', 'rooms.error.attendee_unknown', array_values(array_unique($unknown)));
        }
        if (count($ids) > 50) {
            throw new ValidationException(['attendees' => 'rooms.error.attendees_many']);
        }
        return array_keys($ids);
    }

    /**
     * Books a room — once, or weekly (every 1–4 weeks) until a date. Without approval: written at once if free.
     *
     * @param array<string, mixed> $in date, start (HH:MM), end, title, attendee_count, attendees, repeat (none|weekly), every_weeks, until, skip_conflicts
     * @return array{first: string, booked: int, skipped: list<string>}
     */
    public function book(Authorizer $az, string $roomPid, array $in): array
    {
        $room = $this->visibleRoom($az, $roomPid);
        $le = (int) $room['legal_entity_id'];
        $e = [];
        $date = self::ymd((string) ($in['date'] ?? ''));
        $s = self::hm((string) ($in['start'] ?? ''));
        $t = self::hm((string) ($in['end'] ?? ''));
        if ($date === null) {
            $e['date'] = 'rooms.error.date';
        }
        if ($s === null || $s % self::STEP !== 0) {
            $e['start'] = 'rooms.error.step';
        }
        if ($t === null || $t % self::STEP !== 0) {
            $e['end'] = 'rooms.error.step';
        }
        $title = trim((string) ($in['title'] ?? ''));
        if (mb_strlen($title) < 2 || mb_strlen($title) > 200) {
            $e['title'] = 'rooms.error.title';
        }
        $people = ctype_digit(trim((string) ($in['attendee_count'] ?? ''))) ? (int) $in['attendee_count'] : 0;
        $weekly = ($in['repeat'] ?? 'none') === 'weekly';
        $every = (int) ($in['every_weeks'] ?? 1);
        $until = self::ymd((string) ($in['until'] ?? ''));
        if ($weekly && ($every < 1 || $every > 4)) {
            $e['every_weeks'] = 'rooms.error.every';
        }
        if ($weekly && ($until === null || $date === null || $until <= $date)) {
            $e['until'] = 'rooms.error.until';
        }
        if ($e !== [] || $date === null || $s === null || $t === null) {
            throw new ValidationException($e);
        }
        $start = $date->modify('+' . $s . ' minutes');
        $end = $date->modify('+' . $t . ' minutes');
        $first = $this->check($room, $start, $end, $people);
        if ($first !== []) {
            throw new ValidationException($first);
        }
        $invite = $this->attendees((string) ($in['attendees'] ?? ''), $le, $az->userId);
        $dates = [$date];
        if ($weekly && $until !== null) {
            for ($d = $date->modify('+' . (7 * $every) . ' days'); $d <= $until; $d = $d->modify('+' . (7 * $every) . ' days')) {
                $dates[] = $d;
                if (count($dates) > self::MAX_OCCURRENCES) {
                    throw new ValidationException(['until' => 'rooms.error.too_many']);
                }
            }
        }
        $db = $this->app->db();
        return $db->transactional(function () use ($db, $az, $room, $le, $dates, $s, $t, $title, $people, $invite, $weekly, $every, $until, $in): array {
            $room = ($db->one('SELECT * FROM rm_rooms WHERE id = ? FOR UPDATE', [$room['id']]) ?? []) + $room; // one booking of this room at a time
            $ok = [];
            $clash = [];
            foreach ($dates as $d) {
                $p = $this->check($room, $d->modify('+' . $s . ' minutes'), $d->modify('+' . $t . ' minutes'), $people);
                if ($p === []) {
                    $ok[] = $d;
                } else {
                    $clash[] = $d->format('Y-m-d');
                }
            }
            if ($ok === [] || ($clash !== [] && empty($in['skip_conflicts']))) {
                if (count($dates) === 1) {
                    throw new ValidationException(['start' => 'rooms.error.taken']);
                }
                throw new BookingProblem('repeat', 'rooms.error.series_clash', $clash);
            }
            $now = $this->stamp();
            $series = null;
            if ($weekly && count($ok) > 1 && $until !== null) {
                $db->run(
                    'INSERT INTO rm_series (public_id, created_at, created_by, updated_at, updated_by, room_id, booked_by, every_weeks, until_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [Ulid::generate($this->app->clock), $now, $az->userId, $now, $az->userId, $room['id'], $az->userId, $every, $until->format('Y-m-d')]
                );
                $series = (int) $db->pdo()->lastInsertId();
            }
            $firstPid = '';
            foreach ($ok as $d) {
                $pid = Ulid::generate($this->app->clock);
                $firstPid = $firstPid === '' ? $pid : $firstPid;
                $db->run(
                    'INSERT INTO rm_bookings (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, room_id, series_id, booked_by, title, attendee_count, attendees_json, starts_at, ends_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$pid, $now, $az->userId, $now, $az->userId, $le, $room['id'], $series, $az->userId, $title, $people, $invite === [] ? null : json_encode($invite),
                        $d->modify('+' . $s . ' minutes')->format('Y-m-d H:i:s'), $d->modify('+' . $t . ' minutes')->format('Y-m-d H:i:s')]
                );
                $this->event((int) $db->pdo()->lastInsertId(), 'booked', $az->userId, $series !== null ? 'series' : null);
            }
            $this->app->audit()->record('rooms.booked', 'success', 'rm_booking', $firstPid, $le, null, ['room' => (string) $room['code'], 'count' => count($ok), 'skipped' => count($clash)]);
            foreach ($invite as $u) {
                $this->app->notifier()->notify($u, 'rooms.invite', 'rooms.notify.invited', ['room' => (string) $room['name'], 'when' => $ok[0]->format('Y-m-d') . ' ' . sprintf('%02d:%02d', intdiv($s, 60), $s % 60),
                    'n' => (string) count($ok)], '/rooms/b/' . $firstPid, 'rm.inv:' . $firstPid . ':' . $u);
            }
            return ['first' => $firstPid, 'booked' => count($ok), 'skipped' => $clash];
        });
    }

    /** @return array<string, mixed> a booking the person may open (booker, invited, room admin) */
    public function booking(Authorizer $az, string $pid): array
    {
        $this->tick();
        $b = $this->app->db()->one(
            'SELECT b.*, r.public_id AS room_pid, r.code AS room_code, r.name AS room_name, r.legal_entity_id AS room_le, r.checkin_required, r.release_minutes, r.capacity, r.max_minutes,
                    COALESCE(u.display_name, u.username) AS booker, ser.public_id AS series_pid, ser.every_weeks, ser.until_date
               FROM rm_bookings b JOIN rm_rooms r ON r.id = b.room_id JOIN users u ON u.id = b.booked_by LEFT JOIN rm_series ser ON ser.id = b.series_id WHERE b.public_id = ?',
            [$pid]
        ) ?? throw HttpException::notFound();
        $invited = array_map('intval', (array) json_decode((string) ($b['attendees_json'] ?? '[]'), true));
        $b['mine'] = (int) $b['booked_by'] === $az->userId;
        $b['can_manage'] = $az->scope('rooms.manage')->coversLegalEntity((int) $b['room_le']);
        if (!$b['mine'] && !$b['can_manage'] && !in_array($az->userId, $invited, true)) {
            throw HttpException::notFound();
        }
        $db = $this->app->db();
        $b['invited'] = $invited === [] ? [] : $db->all('SELECT COALESCE(display_name, username) AS name FROM users WHERE id IN (' . implode(',', $invited) . ') ORDER BY display_name');
        $b['events'] = $db->all('SELECT e.event, e.note, e.created_at, COALESCE(u.display_name, u.username) AS actor FROM rm_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.booking_id = ? ORDER BY e.id', [$b['id']]);
        $now = $this->nowLocal();
        $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $b['starts_at']) ?: $now;
        $b['future'] = in_array($b['status'], self::LIVE, true) && $start > $now;
        $b['can_checkin'] = $b['mine'] && $b['status'] === 'confirmed' && (int) $b['checkin_required'] === 1
            && $now >= $start->modify('-' . self::CHECKIN_EARLY . ' minutes') && $now <= $start->modify('+' . (int) $b['release_minutes'] . ' minutes');
        $b['series_left'] = $b['series_id'] !== null ? (int) $db->scalar("SELECT COUNT(*) FROM rm_bookings WHERE series_id = ? AND status = 'confirmed' AND starts_at >= ?", [$b['series_id'], $b['starts_at']]) : 0;
        return $b;
    }

    /** Moves one booking to another time (the same room) — checked like a new booking; it leaves its series. */
    /** @param array<string, mixed> $in date, start, end */
    public function reschedule(Authorizer $az, string $pid, array $in): void
    {
        $b = $this->booking($az, $pid);
        if (!$b['mine'] || $b['status'] !== 'confirmed' || !$b['future']) {
            throw new BusinessException('rooms.error.cannot_change', [], 409);
        }
        $date = self::ymd((string) ($in['date'] ?? ''));
        $s = self::hm((string) ($in['start'] ?? ''));
        $t = self::hm((string) ($in['end'] ?? ''));
        if ($date === null || $s === null || $t === null || $s % self::STEP !== 0 || $t % self::STEP !== 0) {
            throw new ValidationException(['start' => 'rooms.error.step']);
        }
        $db = $this->app->db();
        $db->transactional(function () use ($db, $az, $b, $date, $s, $t): void {
            $room = $db->one('SELECT * FROM rm_rooms WHERE id = ? FOR UPDATE', [$b['room_id']]) ?? throw HttpException::notFound();
            $start = $date->modify('+' . $s . ' minutes');
            $end = $date->modify('+' . $t . ' minutes');
            $p = $this->check($room, $start, $end, (int) $b['attendee_count'], (int) $b['id']);
            if ($p !== []) {
                throw new ValidationException($p);
            }
            $db->run(
                'UPDATE rm_bookings SET starts_at = ?, ends_at = ?, series_id = NULL, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
                [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $this->stamp(), $az->userId, $b['id']]
            );
            $this->event((int) $b['id'], 'moved', $az->userId, substr((string) $b['starts_at'], 0, 16) . ' → ' . $start->format('Y-m-d H:i'));
            $this->app->audit()->record('rooms.moved', 'success', 'rm_booking', (string) $b['public_id'], (int) $b['legal_entity_id']);
            $this->tell($b, 'rooms.notify.moved', ['room' => (string) $b['room_name'], 'when' => $start->format('Y-m-d H:i')]);
        });
    }

    /**
     * Cancels a booking (or it and the rest of its series): the booker any time before it starts, a room admin with a
     * reason (the booker is told).
     */
    public function cancel(Authorizer $az, string $pid, string $reason, bool $restOfSeries): int
    {
        $b = $this->booking($az, $pid);
        $reason = trim($reason);
        if (!$b['future'] || $b['status'] !== 'confirmed') {
            throw new BusinessException('rooms.error.cannot_change', [], 409);
        }
        if (!$b['mine'] && !$b['can_manage']) {
            throw HttpException::forbidden();
        }
        if ((!$b['mine'] && mb_strlen($reason) < 3) || mb_strlen($reason) > 300) {
            throw new ValidationException(['reason' => 'rooms.error.reason']);
        }
        $db = $this->app->db();
        $ids = $restOfSeries && $b['series_id'] !== null
            ? array_map('intval', array_column($db->all("SELECT id FROM rm_bookings WHERE series_id = ? AND status = 'confirmed' AND starts_at >= ?", [$b['series_id'], $b['starts_at']]), 'id'))
            : [(int) $b['id']];
        $now = $this->stamp();
        foreach ($ids as $id) {
            $db->run(
                "UPDATE rm_bookings SET status = 'cancelled', cancelled_at = ?, cancelled_by = ?, cancel_reason = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = 'confirmed'",
                [$now, $az->userId, $reason === '' ? null : $reason, $now, $az->userId, $id]
            );
            $this->event($id, 'cancelled', $az->userId, $reason === '' ? null : $reason);
        }
        $this->app->audit()->record('rooms.cancelled', 'success', 'rm_booking', (string) $b['public_id'], (int) $b['legal_entity_id'], $reason === '' ? null : $reason, ['count' => count($ids), 'by_admin' => !$b['mine']]);
        $this->tell($b, 'rooms.notify.cancelled', ['room' => (string) $b['room_name'], 'when' => substr((string) $b['starts_at'], 0, 16), 'n' => (string) count($ids)], !$b['mine']);
        return count($ids);
    }

    /** The booker checks in (rooms that need it): from 15 minutes before the start until the release time. */
    public function checkIn(Authorizer $az, string $pid): void
    {
        $b = $this->booking($az, $pid);
        if (!$b['can_checkin']) {
            throw new BusinessException('rooms.error.checkin_window', [], 409);
        }
        $this->app->db()->run(
            "UPDATE rm_bookings SET status = 'checked_in', checked_in_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = 'confirmed'",
            [$this->stamp(), $this->stamp(), $az->userId, $b['id']]
        );
        $this->event((int) $b['id'], 'checked_in', $az->userId, null);
    }

    /**
     * Housekeeping (scheduled every 5 minutes and before screens): bookings of rooms that need a check-in and had none by
     * the release time become no-show (the room is free again, the booker is told); bookings that ended are completed.
     */
    public function tick(): int
    {
        $db = $this->app->db();
        $now = $this->nowLocal()->format('Y-m-d H:i:s');
        $n = 0;
        foreach (
            $db->all(
                "SELECT b.id, b.public_id, b.booked_by, b.starts_at, r.name AS room_name FROM rm_bookings b JOIN rm_rooms r ON r.id = b.room_id
                  WHERE b.status = 'confirmed' AND r.checkin_required = 1 AND DATE_ADD(b.starts_at, INTERVAL r.release_minutes MINUTE) < ? LIMIT 500",
                [$now]
            ) as $b
        ) {
            $db->run("UPDATE rm_bookings SET status = 'no_show', updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'confirmed'", [$this->stamp(), $b['id']]);
            $this->event((int) $b['id'], 'no_show', null, null);
            $this->app->notifier()->notify((int) $b['booked_by'], 'rooms.no_show', 'rooms.notify.no_show', ['room' => (string) $b['room_name'], 'when' => substr((string) $b['starts_at'], 0, 16)], '/rooms/b/' . $b['public_id'], 'rm.ns:' . $b['public_id']);
            $n++;
        }
        $n += $db->run("UPDATE rm_bookings SET status = 'completed', updated_at = ?, row_version = row_version + 1 WHERE status IN ('confirmed','checked_in') AND ends_at <= ?", [$this->stamp(), $now])->rowCount();
        return $n;
    }

    public static function tickJob(App $app): string
    {
        return 'rooms: ' . self::of($app)->tick();
    }

    // ---- views ---------------------------------------------------------------------------------------------------------------

    /**
     * A day of a company's rooms (optionally one site): the rooms, 15-minute slots from the earliest opening to the latest
     * closing, and per room the cells (a booking spans its slots). Busy slots of other people show no details.
     *
     * @return array{rooms: list<array<string, mixed>>, slots: list<string>, rows: array<int, list<array<string, mixed>>>}
     */
    public function day(Authorizer $az, int $le, ?int $siteId, string $date): array
    {
        $this->require($az, 'rooms.use', $le);
        $this->tick();
        $rooms = $this->rooms($le, $siteId);
        $manage = $az->scope('rooms.manage')->coversLegalEntity($le);
        $from = 24 * 60;
        $to = 0;
        foreach ($rooms as $r) {
            $from = min($from, (int) self::hm(substr((string) $r['open_from'], 0, 5)));
            $to = max($to, (int) self::hm(substr((string) $r['open_to'], 0, 5)));
        }
        if ($rooms === []) {
            return ['rooms' => [], 'slots' => [], 'rows' => []];
        }
        $slots = [];
        for ($m = $from; $m < $to; $m += self::STEP) {
            $slots[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        }
        $db = $this->app->db();
        $ids = implode(',', array_map(static fn (array $r): int => (int) $r['id'], $rooms));
        $bookings = $db->all(
            "SELECT b.id, b.public_id, b.room_id, b.title, b.booked_by, b.attendees_json, b.starts_at, b.ends_at, b.status, COALESCE(u.display_name, u.username) AS booker
               FROM rm_bookings b JOIN users u ON u.id = b.booked_by WHERE b.room_id IN ({$ids}) AND b.status IN ('confirmed','checked_in','completed') AND b.starts_at < ? AND b.ends_at > ? ORDER BY b.starts_at",
            [$date . ' 23:59:59', $date . ' 00:00:00']
        );
        $closures = $db->all("SELECT room_id, starts_at, ends_at, reason FROM rm_closures WHERE room_id IN ({$ids}) AND removed_at IS NULL AND starts_at < ? AND ends_at > ?", [$date . ' 23:59:59', $date . ' 00:00:00']);
        $rows = [];
        foreach ($rooms as $r) {
            $open = in_array((int) (self::ymd($date)?->format('N') ?? 0), array_map('intval', explode(',', (string) $r['open_days'])), true) && $r['status'] === 'active';
            $of = (int) self::hm(substr((string) $r['open_from'], 0, 5));
            $ot = (int) self::hm(substr((string) $r['open_to'], 0, 5));
            $cells = [];
            $i = 0;
            $n = count($slots);
            while ($i < $n) {
                $m = $from + $i * self::STEP;
                $hit = null;
                foreach ($bookings as $b) {
                    if ((int) $b['room_id'] === (int) $r['id'] && self::minuteOf((string) $b['starts_at'], $date) <= $m && self::minuteOf((string) $b['ends_at'], $date) > $m) {
                        $hit = $b;
                        break;
                    }
                }
                if ($hit !== null) {
                    $endM = min($to, self::minuteOf((string) $hit['ends_at'], $date));
                    $span = max(1, intdiv($endM - $m + self::STEP - 1, self::STEP));
                    $invited = array_map('intval', (array) json_decode((string) ($hit['attendees_json'] ?? '[]'), true));
                    $see = $manage || (int) $hit['booked_by'] === $az->userId || in_array($az->userId, $invited, true);
                    $cells[] = ['kind' => 'booked', 'span' => $span, 'pid' => $see ? (string) $hit['public_id'] : null, 'title' => $see ? (string) $hit['title'] : null,
                        'booker' => $see ? (string) $hit['booker'] : null, 'time' => substr((string) $hit['starts_at'], 11, 5) . '–' . substr((string) $hit['ends_at'], 11, 5), 'mine' => (int) $hit['booked_by'] === $az->userId];
                    $i += $span;
                    continue;
                }
                $closed = !$open || $m < $of || $m >= $ot;
                foreach ($closures as $c) {
                    if ((int) $c['room_id'] === (int) $r['id'] && self::minuteOf((string) $c['starts_at'], $date) <= $m && self::minuteOf((string) $c['ends_at'], $date) > $m) {
                        $closed = true;
                    }
                }
                $cells[] = ['kind' => $closed ? 'closed' : 'free', 'span' => 1, 'time' => $slots[$i]];
                $i++;
            }
            $rows[(int) $r['id']] = $cells;
        }
        return ['rooms' => $rooms, 'slots' => $slots, 'rows' => $rows];
    }

    /** minute of the day of a datetime on a given day (an earlier day → 0, a later day → 1440) */
    private static function minuteOf(string $dt, string $date): int
    {
        $d = substr($dt, 0, 10);
        if ($d !== $date) {
            return $d < $date ? 0 : 24 * 60;
        }
        return (int) substr($dt, 11, 2) * 60 + (int) substr($dt, 14, 2);
    }

    /** @return list<array<string, mixed>> my bookings and those I am invited to, from today */
    public function mine(Authorizer $az): array
    {
        $this->tick();
        $rows = $this->app->db()->all(
            "SELECT b.public_id, b.title, b.starts_at, b.ends_at, b.status, b.booked_by, b.series_id, r.code AS room_code, r.name AS room_name, l.code AS le_code
               FROM rm_bookings b JOIN rm_rooms r ON r.id = b.room_id JOIN legal_entities l ON l.id = b.legal_entity_id
              WHERE (b.booked_by = ? OR JSON_CONTAINS(COALESCE(b.attendees_json, '[]'), ?)) AND b.ends_at >= ? ORDER BY b.starts_at LIMIT 200",
            [$az->userId, (string) $az->userId, $this->today() . ' 00:00:00']
        );
        return array_map(static fn (array $x): array => $x + ['mine' => (int) $x['booked_by'] === $az->userId], $rows);
    }

    /**
     * Use of the rooms of a company in a month: bookings, hours booked, share of the opening hours, no-shows, cancellations.
     *
     * @return list<array<string, mixed>>
     */
    public function usage(Authorizer $az, int $le, string $month): array
    {
        $this->require($az, 'rooms.manage', $le);
        $first = self::ymd($month . '-01') ?? throw new ValidationException(['month' => 'rooms.error.date']);
        $last = $first->modify('last day of this month');
        $db = $this->app->db();
        $out = [];
        foreach ($this->rooms($le) as $r) {
            $agg = $db->one(
                "SELECT SUM(status IN ('confirmed','checked_in','completed')) AS used, SUM(CASE WHEN status IN ('confirmed','checked_in','completed') THEN TIMESTAMPDIFF(MINUTE, starts_at, ends_at) ELSE 0 END) AS mins,
                        SUM(status = 'no_show') AS no_show, SUM(status = 'cancelled') AS cancelled FROM rm_bookings WHERE room_id = ? AND starts_at BETWEEN ? AND ?",
                [$r['id'], $first->format('Y-m-d 00:00:00'), $last->format('Y-m-d 23:59:59')]
            ) ?? [];
            $days = array_map('intval', explode(',', (string) $r['open_days']));
            $openDays = 0;
            for ($d = $first; $d <= $last; $d = $d->modify('+1 day')) {
                $openDays += in_array((int) $d->format('N'), $days, true) ? 1 : 0;
            }
            $openMins = $openDays * ((int) self::hm(substr((string) $r['open_to'], 0, 5)) - (int) self::hm(substr((string) $r['open_from'], 0, 5)));
            $mins = (int) ($agg['mins'] ?? 0);
            $out[] = ['code' => (string) $r['code'], 'name' => (string) $r['name'], 'site' => (string) ($r['site_code'] ?? ''), 'used' => (int) ($agg['used'] ?? 0), 'hours' => round($mins / 60, 2),
                'share' => $openMins > 0 ? round($mins * 100 / $openMins, 1) : 0.0, 'no_show' => (int) ($agg['no_show'] ?? 0), 'cancelled' => (int) ($agg['cancelled'] ?? 0)];
        }
        return $out;
    }

    /** @return array{bytes: string, name: string} the month's room use as Excel (numbers only — no names) */
    public function export(Authorizer $az, int $le, string $month): array
    {
        $rows = $this->usage($az, $le, $month);
        $t = $this->app->translator();
        $code = (string) $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$le]);
        $bytes = Xlsx::write(['usage' => [
            'headers' => [$t->get('rooms.col.room'), $t->get('rooms.col.name'), $t->get('rooms.field.site'), $t->get('rooms.col.bookings'), $t->get('rooms.col.hours'), $t->get('rooms.col.share'), $t->get('rooms.col.no_show'), $t->get('rooms.col.cancelled')],
            'rows' => array_map(static fn (array $r): array => [$r['code'], $r['name'], $r['site'], (string) $r['used'], (string) $r['hours'], (string) $r['share'], (string) $r['no_show'], (string) $r['cancelled']], $rows),
            'numeric' => [3, 4, 5, 6, 7], 'widths' => [12, 30, 10, 12, 12, 14, 12, 12],
        ]], 'Blue OS room use');
        $this->app->audit()->record('rooms.report_exported', 'success', 'rm_report', $code . '-' . $month, $le, null, ['rows' => count($rows), 'sha256' => hash('sha256', $bytes)]);
        return ['bytes' => $bytes, 'name' => 'room-use-' . $code . '-' . $month . '.xlsx'];
    }

    // ---- helpers -------------------------------------------------------------------------------------------------------------

    private function event(int $bookingId, string $event, ?int $actor, ?string $note): void
    {
        $this->app->db()->run('INSERT INTO rm_events (booking_id, event, actor_id, note, created_at) VALUES (?, ?, ?, ?, ?)', [$bookingId, $event, $actor, $note === null ? null : mb_substr($note, 0, 300), $this->stamp()]);
    }

    /**
     * Tells the invited people (and the booker, when someone else made the change) about a change.
     *
     * @param array<string, mixed> $b
     * @param array<string, string> $params
     */
    private function tell(array $b, string $key, array $params, bool $alsoBooker = false): void
    {
        $to = array_map('intval', (array) json_decode((string) ($b['attendees_json'] ?? '[]'), true));
        if ($alsoBooker) {
            $to[] = (int) $b['booked_by'];
        }
        foreach (array_unique($to) as $u) {
            $this->app->notifier()->notify($u, 'rooms.change', $key, $params, '/rooms/b/' . $b['public_id']);
        }
    }
}

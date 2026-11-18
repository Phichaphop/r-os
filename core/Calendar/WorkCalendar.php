<?php

declare(strict_types=1);

namespace Blue\Core\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\Audit\AuditLogger;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Database\Connection;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * Work & service calendars (03 §6.14) — the one place every module asks "is this a working day for this site?".
 *
 * A calendar = a weekly pattern (Sun..Sat, 1 = working) + dated exceptions (holiday / extra working day / weekend).
 * A calendar may be based on another one (a site calendar on its company calendar, a shuttle service calendar on a
 * work calendar): its own exceptions win, then the base calendar's holidays, then its own weekly pattern.
 * Resolution for a site: site calendar → its company calendar → organization calendar. No calendar → "unknown"
 * (callers decide; nothing is guessed). No public holidays are seeded — they are entered or imported by the owner
 * (Country Pack: holiday calendars are versioned configuration, never hard-coded).
 */
final class WorkCalendar
{
    public const WEEKDAYS = 7;

    /** @var array<int, array<string, mixed>> */
    private array $cache = [];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
    ) {
    }

    // ---- questions modules ask ---------------------------------------------------------------------

    /** @return array<string, mixed>|null the calendar that applies (site → company → organization) */
    public function resolve(?int $siteId, ?int $legalEntityId, string $type = 'work'): ?array
    {
        if ($siteId !== null && $legalEntityId === null) {
            $legalEntityId = (int) ($this->db->scalar('SELECT legal_entity_id FROM sites WHERE id = ?', [$siteId]) ?? 0) ?: null;
        }
        $org = (int) ($this->db->scalar('SELECT id FROM organizations ORDER BY id LIMIT 1') ?? 0);
        foreach ([['site', $siteId], ['legal_entity', $legalEntityId], ['organization', $org]] as [$level, $id]) {
            if ($id === null || $id === 0) {
                continue;
            }
            $row = $this->db->one("SELECT * FROM calendars WHERE calendar_type = ? AND scope_level = ? AND scope_id = ? AND status = 'active' ORDER BY id LIMIT 1", [$type, $level, $id]);
            if ($row !== null) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Own exception → exceptions of the base calendar(s) → own weekly pattern.
     *
     * @param array<string, mixed> $calendar
     * @return array{working: bool, type: string, kind: ?string, name: ?string, source: string}
     */
    public function day(array $calendar, string $ymd): array
    {
        $cal = $calendar;
        for ($depth = 0; $cal !== null && $depth < 4; $depth++) {
            $x = $this->db->one('SELECT day_type, holiday_kind, name FROM calendar_days WHERE calendar_id = ? AND day_date = ?', [$cal['id'], $ymd]);
            if ($x !== null) {
                return ['working' => $x['day_type'] === 'working', 'type' => (string) $x['day_type'], 'kind' => $x['holiday_kind'], 'name' => $x['name'], 'source' => (string) $cal['code']];
            }
            $cal = $cal['base_calendar_id'] !== null ? $this->byId((int) $cal['base_calendar_id']) : null;
        }
        $dow = (int) (new DateTimeImmutable($ymd, new DateTimeZone('UTC')))->format('w');
        $working = ((string) $calendar['weekly_pattern'])[$dow] === '1';
        return ['working' => $working, 'type' => $working ? 'working' : 'weekend', 'kind' => null, 'name' => null, 'source' => 'pattern'];
    }

    /**
     * "Is this a working day?" for a site/company. working = null when no calendar is set up.
     *
     * @return array{working: ?bool, calendar: ?string, type: ?string, name: ?string}
     */
    public function isWorkingDay(?int $siteId, ?int $legalEntityId, string $ymd, string $type = 'work'): array
    {
        $cal = $this->resolve($siteId, $legalEntityId, $type);
        if ($cal === null) {
            return ['working' => null, 'calendar' => null, 'type' => null, 'name' => null];
        }
        $d = $this->day($cal, $ymd);
        return ['working' => $d['working'], 'calendar' => (string) $cal['code'], 'type' => $d['type'], 'name' => $d['name']];
    }

    /** Working days in [from, to] inclusive; null when no calendar applies. */
    public function workingDaysBetween(?int $siteId, ?int $legalEntityId, string $from, string $to): ?int
    {
        $cal = $this->resolve($siteId, $legalEntityId);
        if ($cal === null) {
            return null;
        }
        $n = 0;
        $d = new DateTimeImmutable($from, new DateTimeZone('UTC'));
        $end = new DateTimeImmutable($to, new DateTimeZone('UTC'));
        for ($i = 0; $d <= $end && $i < 3700; $i++, $d = $d->modify('+1 day')) {
            $n += $this->day($cal, $d->format('Y-m-d'))['working'] ? 1 : 0;
        }
        return $n;
    }

    /**
     * Adds N hours counted on working days only (DEC-56: approval SLAs pause on weekends and holidays; a working day
     * counts 24 hours — business hours come with shift schedules). Null when no calendar applies (caller keeps
     * plain calendar hours).
     */
    public function addWorkingHours(?int $legalEntityId, DateTimeImmutable $startUtc, int $hours, string $tz = 'Asia/Bangkok'): ?DateTimeImmutable
    {
        $cal = $this->resolve(null, $legalEntityId);
        if ($cal === null) {
            return null;
        }
        $zone = new DateTimeZone($tz);
        $t = $startUtc->setTimezone($zone);
        $left = $hours * 3600;
        for ($guard = 0; $left > 0 && $guard < 800; $guard++) {
            $dayEnd = $t->setTime(0, 0)->modify('+1 day');
            if (!$this->day($cal, $t->format('Y-m-d'))['working']) {
                $t = $dayEnd;
                continue;
            }
            $avail = $dayEnd->getTimestamp() - $t->getTimestamp();
            if ($left <= $avail) {
                $t = $t->modify('+' . $left . ' seconds');
                $left = 0;
            } else {
                $left -= $avail;
                $t = $dayEnd;
            }
        }
        return $t->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Month grid data: every day of the month with its resolved type.
     *
     * @param array<string, mixed> $calendar
     * @return list<array{date: string, working: bool, type: string, kind: ?string, name: ?string, source: string, own: bool}>
     */
    public function month(array $calendar, int $year, int $month): array
    {
        $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new DateTimeZone('UTC'));
        $out = [];
        for ($d = $first; (int) $d->format('n') === $month; $d = $d->modify('+1 day')) {
            $info = $this->day($calendar, $d->format('Y-m-d'));
            $out[] = ['date' => $d->format('Y-m-d')] + $info + ['own' => $info['source'] === (string) $calendar['code']];
        }
        return $out;
    }

    /**
     * The work calendar that applies to a signed-in person: their current employment (site → company) when the
     * employees module has one, otherwise the company picked in the top bar. via = employment|company|organization|none.
     *
     * @return array{calendar: ?array<string, mixed>, via: string, site_id: ?int, legal_entity_id: ?int}
     */
    public function forUser(int $userId, ?int $contextLegalEntityId, string $ymd): array
    {
        $site = null;
        $le = null;
        $via = 'company';
        try {
            $job = $this->db->one(
                'SELECT h.site_id, h.legal_entity_id FROM users u JOIN employment_history h ON h.employee_id = u.employee_id
                  WHERE u.id = ? AND h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?) ORDER BY h.effective_from DESC LIMIT 1',
                [$userId, $ymd, $ymd]
            );
        } catch (\Throwable) {
            $job = null; // employees module never installed
        }
        if ($job !== null) {
            $site = $job['site_id'] !== null ? (int) $job['site_id'] : null;
            $le = (int) $job['legal_entity_id'];
            $via = 'employment';
        } else {
            $le = $contextLegalEntityId;
        }
        $cal = $this->resolve($site, $le);
        if ($cal !== null && $cal['scope_level'] === 'organization' && $via === 'company') {
            $via = 'organization';
        }
        return ['calendar' => $cal, 'via' => $cal === null ? 'none' : $via, 'site_id' => $site, 'legal_entity_id' => $le];
    }

    /**
     * Holidays and special days of a year as the calendar sees them (own exceptions win over the base calendar's).
     *
     * @param array<string, mixed> $calendar
     * @return list<array{date: string, type: string, kind: ?string, name: ?string, source: string}>
     */
    public function yearExceptions(array $calendar, int $year): array
    {
        $out = [];
        $cal = $calendar;
        for ($depth = 0; $cal !== null && $depth < 4; $depth++) {
            foreach ($this->days((int) $cal['id'], $year) as $d) {
                $out[(string) $d['day_date']] ??= ['date' => (string) $d['day_date'], 'type' => (string) $d['day_type'], 'kind' => $d['holiday_kind'], 'name' => $d['name'], 'source' => (string) $cal['code']];
            }
            $cal = $cal['base_calendar_id'] !== null ? $this->byId((int) $cal['base_calendar_id']) : null;
        }
        ksort($out);
        return array_values($out);
    }

    // ---- admin -------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> calendars the user may see */
    public function list(Authorizer $az): array
    {
        $rows = $this->db->all(
            "SELECT c.*, b.code AS base_code, (SELECT COUNT(*) FROM calendar_days d WHERE d.calendar_id = c.id AND d.day_type = 'holiday') AS holidays
               FROM calendars c LEFT JOIN calendars b ON b.id = c.base_calendar_id WHERE c.status = 'active' ORDER BY c.calendar_type, c.scope_level, c.code"
        );
        return array_values(array_filter($rows, fn (array $c): bool => $this->allowed($az, 'core.calendar.view', $c) || $this->allowed($az, 'core.calendar.manage', $c)));
    }

    /** @return array<string, mixed> */
    public function get(Authorizer $az, string $pid, bool $manage = false): array
    {
        $c = $this->db->one('SELECT c.*, b.code AS base_code, b.public_id AS base_public_id FROM calendars c LEFT JOIN calendars b ON b.id = c.base_calendar_id WHERE c.public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $can = $this->allowed($az, 'core.calendar.manage', $c);
        if (!$can && ($manage || !$this->allowed($az, 'core.calendar.view', $c))) {
            throw $manage && $this->allowed($az, 'core.calendar.view', $c) ? HttpException::forbidden() : HttpException::notFound();
        }
        $c['can_manage'] = $can;
        return $c;
    }

    /** @param array{code: string, name: string, type: string, scope_level: string, scope_id: int, pattern: string, base: string} $in */
    public function create(Authorizer $az, array $in): string
    {
        $errors = $this->validate($in, true);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $row = ['scope_level' => $in['scope_level'], 'scope_id' => $in['scope_id']];
        if (!$this->allowed($az, 'core.calendar.manage', $row)) {
            throw HttpException::forbidden();
        }
        $base = $this->baseId($az, $in['base']);
        if ($in['type'] === 'work' && $this->db->scalar("SELECT id FROM calendars WHERE calendar_type = 'work' AND scope_level = ? AND scope_id = ? AND status = 'active'", [$in['scope_level'], $in['scope_id']]) !== null) {
            throw new ValidationException(['scope' => 'calendar.error.scope_taken']); // one work calendar per scope
        }
        $pid = Ulid::generate($this->clock);
        $now = $this->now();
        $this->db->run(
            'INSERT INTO calendars (public_id, created_at, created_by, updated_at, updated_by, code, name, calendar_type, scope_level, scope_id, base_calendar_id, weekly_pattern)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $now, $az->userId, $now, $az->userId, $in['code'], mb_substr(trim($in['name']), 0, 120), $in['type'], $in['scope_level'], $in['scope_id'], $base, $in['pattern']]
        );
        $this->audit->record('calendar.created', 'success', 'calendar', $pid, $this->leOf($row), null, ['code' => $in['code'], 'pattern' => $in['pattern']]);
        return $pid;
    }

    /** @param array{name: string, pattern: string, base: string, reason: string} $in */
    public function update(Authorizer $az, string $pid, array $in): void
    {
        $c = $this->get($az, $pid, true);
        $errors = [];
        if (trim($in['name']) === '') {
            $errors['name'] = 'calendar.error.name';
        }
        if (!preg_match('/^[01]{7}$/', $in['pattern'])) {
            $errors['pattern'] = 'calendar.error.pattern';
        }
        if ($in['pattern'] !== $c['weekly_pattern'] && trim($in['reason']) === '') {
            $errors['reason'] = 'iam.error.reason_required'; // changing the weekly pattern changes every past day too
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $base = $this->baseId($az, $in['base']);
        if ($base === (int) $c['id']) {
            throw new ValidationException(['base' => 'calendar.error.base']);
        }
        $this->db->run(
            'UPDATE calendars SET name = ?, weekly_pattern = ?, base_calendar_id = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [mb_substr(trim($in['name']), 0, 120), $in['pattern'], $base, $this->now(), $az->userId, $c['id']]
        );
        $this->audit->record('calendar.updated', 'success', 'calendar', $pid, $this->leOf($c), $in['reason'] !== '' ? $in['reason'] : null, ['pattern' => [$c['weekly_pattern'], $in['pattern']], 'name' => [$c['name'], $in['name']]]);
    }

    /** Sets (or replaces) the exception for one date. */
    public function setDay(Authorizer $az, string $calendarPid, string $ymd, string $type, ?string $kind, string $name, string $note, ?int $batchId = null): void
    {
        $c = $this->get($az, $calendarPid, true);
        $errors = self::dayErrors($ymd, $type, $kind, $name);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->putDay((int) $c['id'], $ymd, $type, $kind, $name, $note, $az->userId, $batchId);
        $this->audit->record('calendar.day_set', 'success', 'calendar', $calendarPid, $this->leOf($c), null, ['date' => $ymd, 'type' => $type, 'kind' => $kind, 'name' => $name]);
    }

    /** Used by the importer inside its own transaction (permission already checked for the calendar). */
    public function putDay(int $calendarId, string $ymd, string $type, ?string $kind, string $name, string $note, int $actorId, ?int $batchId = null): string
    {
        $now = $this->now();
        $exists = $this->db->scalar('SELECT id FROM calendar_days WHERE calendar_id = ? AND day_date = ?', [$calendarId, $ymd]) !== null;
        $this->db->run(
            'INSERT INTO calendar_days (calendar_id, day_date, day_type, holiday_kind, name, note, source, import_batch_id, created_at, created_by, updated_at, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE day_type = VALUES(day_type), holiday_kind = VALUES(holiday_kind), name = VALUES(name), note = VALUES(note), source = VALUES(source),
                import_batch_id = VALUES(import_batch_id), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [$calendarId, $ymd, $type, $type === 'holiday' ? $kind : null, $name !== '' ? mb_substr($name, 0, 120) : null, $note !== '' ? mb_substr($note, 0, 255) : null,
                $batchId !== null ? 'import' : 'manual', $batchId, $now, $actorId, $now, $actorId]
        );
        return $exists ? 'update' : 'create';
    }

    public function removeDay(Authorizer $az, string $calendarPid, string $ymd): void
    {
        $c = $this->get($az, $calendarPid, true);
        $old = $this->db->one('SELECT day_type, name FROM calendar_days WHERE calendar_id = ? AND day_date = ?', [$c['id'], $ymd]);
        if ($old === null) {
            throw HttpException::notFound();
        }
        $this->db->run('DELETE FROM calendar_days WHERE calendar_id = ? AND day_date = ?', [$c['id'], $ymd]);
        $this->audit->record('calendar.day_removed', 'success', 'calendar', $calendarPid, $this->leOf($c), null, ['date' => $ymd, 'was' => $old]);
    }

    /** @return list<array<string, mixed>> exceptions in a year (for the list and export) */
    public function days(int $calendarId, int $year): array
    {
        return $this->db->all('SELECT * FROM calendar_days WHERE calendar_id = ? AND day_date BETWEEN ? AND ? ORDER BY day_date', [$calendarId, "{$year}-01-01", "{$year}-12-31"]);
    }

    /** @return array<string, string> */
    public static function dayErrors(string $ymd, string $type, ?string $kind, string $name): array
    {
        $errors = [];
        if (DateTimeImmutable::createFromFormat('!Y-m-d', $ymd) === false || DateTimeImmutable::createFromFormat('!Y-m-d', $ymd)->format('Y-m-d') !== $ymd) {
            $errors['date'] = 'calendar.error.date';
        }
        if (!in_array($type, ['working', 'holiday', 'weekend'], true)) {
            $errors['type'] = 'calendar.error.type';
        }
        if ($type === 'holiday' && !in_array($kind, ['public', 'company', 'special'], true)) {
            $errors['kind'] = 'calendar.error.kind';
        }
        if ($type === 'holiday' && trim($name) === '') {
            $errors['name'] = 'calendar.error.holiday_name';
        }
        return $errors;
    }

    /** @param array<string, mixed> $c */
    public function allowed(Authorizer $az, string $perm, array $c): bool
    {
        $s = $az->scope($perm);
        if ($s->all) {
            return true;
        }
        return match ((string) $c['scope_level']) {
            'legal_entity' => in_array((int) $c['scope_id'], $s->legalEntityIds, true),
            'site' => in_array((int) $c['scope_id'], $s->siteIds, true)
                || in_array((int) ($this->db->scalar('SELECT legal_entity_id FROM sites WHERE id = ?', [$c['scope_id']]) ?? 0), $s->legalEntityIds, true),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $in
     * @return array<string, string>
     */
    private function validate(array $in, bool $new): array
    {
        $errors = [];
        if ($new && !preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/', (string) $in['code'])) {
            $errors['code'] = 'calendar.error.code';
        } elseif ($new && $this->db->scalar('SELECT id FROM calendars WHERE code = ?', [$in['code']]) !== null) {
            $errors['code'] = 'calendar.error.code_taken';
        }
        if (trim((string) $in['name']) === '') {
            $errors['name'] = 'calendar.error.name';
        }
        if (!in_array($in['type'], ['work', 'service'], true)) {
            $errors['type'] = 'calendar.error.type';
        }
        if (!in_array($in['scope_level'], ['organization', 'legal_entity', 'site'], true) || (int) $in['scope_id'] < 1) {
            $errors['scope'] = 'calendar.error.scope';
        }
        if (!preg_match('/^[01]{7}$/', (string) $in['pattern'])) {
            $errors['pattern'] = 'calendar.error.pattern';
        }
        if ($in['type'] === 'service' && $in['base'] === '') {
            $errors['base'] = 'calendar.error.service_base';
        }
        return $errors;
    }

    private function baseId(Authorizer $az, string $pid): ?int
    {
        if ($pid === '') {
            return null;
        }
        $b = $this->db->one("SELECT * FROM calendars WHERE public_id = ? AND calendar_type = 'work' AND status = 'active'", [$pid]);
        if ($b === null || !($this->allowed($az, 'core.calendar.view', $b) || $this->allowed($az, 'core.calendar.manage', $b))) {
            throw new ValidationException(['base' => 'calendar.error.base']);
        }
        return (int) $b['id'];
    }

    /** @return array<string, mixed>|null */
    private function byId(int $id): ?array
    {
        return $this->cache[$id] ??= ($this->db->one('SELECT * FROM calendars WHERE id = ?', [$id]) ?? []) ?: null;
    }

    /** @param array<string, mixed> $c */
    private function leOf(array $c): ?int
    {
        return match ((string) $c['scope_level']) {
            'legal_entity' => (int) $c['scope_id'],
            'site' => (int) ($this->db->scalar('SELECT legal_entity_id FROM sites WHERE id = ?', [$c['scope_id']]) ?? 0) ?: null,
            default => null,
        };
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}

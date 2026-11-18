<?php

declare(strict_types=1);

namespace Blue\Modules\Events;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\Audience;
use Blue\Modules\Employees\EmployeeService;

/**
 * M50 events (9D-2 · DEC-98).
 *
 * An organiser (events.manage) creates an event for a target group: kind, description, venue, start / end, registration
 * deadline, optional quota (with an optional waitlist), whether walk-ins are accepted, and optionally one choice to ask
 * (e.g. T-shirt size). Published events can be joined until the deadline; a full event puts people on the waitlist (or
 * refuses), and a cancellation moves the first waitlisted person up. At the check-in desk (events.checkin) staff type an
 * employee code, pick a name, scan the e-ID QR or tap an RFID card (the reader types its ID; an unknown card is paired to
 * the employee once — only a hash of the ID is kept). A second check-in changes nothing. People not registered come in
 * as walk-ins when the event allows it. Afterwards the report shows registered, came, walk-ins, no-shows, the attendance
 * rate and a breakdown by department and site, with a CSV export.
 *
 * Event statuses: draft → published (registration open → full / closed → in progress) → completed; cancelled.
 * Registration statuses: registered · waitlisted → checked_in / no-show (registered after the start) / cancelled.
 */
final class EventService
{
    public const QR = '#(?:/eid/v/)?([A-Za-z0-9_-]{22}\.(?:p|\d{1,12})\.[A-Za-z0-9_-]{16})$#';

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    private function emp(): EmployeeService
    {
        return EmployeeService::of($this->app);
    }

    public function nowLocal(): DateTimeImmutable
    {
        $n = $this->app->clock->now()->setTimezone(new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok')));
        return DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $n->format('Y-m-d H:i:s')) ?: $n;
    }

    public function now(): string
    {
        return $this->nowLocal()->format('Y-m-d H:i:s');
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

    public function can(Authorizer $az, string $perm, int $le): bool
    {
        return $az->scope($perm)->coversLegalEntity($le);
    }

    private function require(Authorizer $az, string $perm, int $le): void
    {
        if (!$this->can($az, $perm, $le)) {
            throw HttpException::forbidden();
        }
    }

    /** @return array<string, mixed> */
    private function row(string $pid): array
    {
        return $this->app->db()->one('SELECT * FROM ev_events WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /** @return array<string, mixed>|null my employee with today's job */
    private function me(Authorizer $az): ?array
    {
        $me = $this->emp()->forUser($az->userId);
        return $me === null || $me['status'] === 'former' || $me['job'] === null ? null : $me;
    }

    /**
     * @param array<string, mixed> $ev
     * @param array<string, mixed>|null $me
     */
    private function inAudience(array $ev, ?array $me): bool
    {
        return $me !== null && (int) $me['job']['legal_entity_id'] === (int) $ev['legal_entity_id'] && Audience::matches($this->app, (int) $ev['legal_entity_id'], Audience::decode((string) $ev['audience_json']), $me['job']);
    }

    /** @return list<string> */
    public static function choices(?string $options): array
    {
        return $options === null ? [] : array_values(array_filter(array_map('trim', explode("\n", $options)), static fn (string $x): bool => $x !== ''));
    }

    /** @param array<string, mixed> $ev */
    private function taken(array $ev): int
    {
        return (int) $this->app->db()->scalar("SELECT COUNT(*) FROM ev_regs WHERE event_id = ? AND walk_in = 0 AND status IN ('registered','checked_in')", [$ev['id']]);
    }

    /**
     * Where the event is now: draft · open · full · closed · in_progress · completed · cancelled.
     *
     * @param array<string, mixed> $ev
     */
    public function phase(array $ev): string
    {
        if (in_array($ev['status'], ['draft', 'cancelled', 'completed'], true)) {
            return (string) $ev['status'];
        }
        $now = $this->now();
        if ($now >= (string) $ev['ends_at']) {
            return 'completed';
        }
        if ($now >= (string) $ev['starts_at']) {
            return 'in_progress';
        }
        if ($now >= (string) $ev['reg_until']) {
            return 'closed';
        }
        return $ev['quota'] !== null && $this->taken($ev) >= (int) $ev['quota'] ? 'full' : 'open';
    }

    // ---- for employees ---------------------------------------------------------------------------------------------------------

    /**
     * Published upcoming / running events of my company that include me, with my registration.
     *
     * @return list<array<string, mixed>>
     */
    public function forMe(Authorizer $az): array
    {
        $me = $this->me($az);
        if ($me === null) {
            return [];
        }
        $out = [];
        foreach ($this->app->db()->all("SELECT * FROM ev_events WHERE legal_entity_id = ? AND status = 'published' AND ends_at > ? ORDER BY starts_at LIMIT 100", [$me['job']['legal_entity_id'], $this->now()]) as $ev) {
            $reg = $this->app->db()->one('SELECT * FROM ev_regs WHERE event_id = ? AND employee_id = ?', [$ev['id'], $me['id']]);
            if (!$this->inAudience($ev, $me) && $reg === null) {
                continue;
            }
            $ev['phase'] = $this->phase($ev);
            $ev['mine'] = $reg;
            $ev['taken'] = $this->taken($ev);
            $out[] = $ev;
        }
        return $out;
    }

    /** @return list<array<string, mixed>> events I joined that are over */
    public function myPast(Authorizer $az): array
    {
        $me = $this->me($az);
        if ($me === null) {
            return [];
        }
        return $this->app->db()->all("SELECT e.public_id, e.title, e.starts_at, r.status FROM ev_regs r JOIN ev_events e ON e.id = r.event_id WHERE r.employee_id = ? AND (e.ends_at <= ? OR e.status IN ('cancelled','completed')) ORDER BY e.starts_at DESC LIMIT 30", [$me['id'], $this->now()]);
    }

    /** @return array<string, mixed> */
    public function event(Authorizer $az, string $pid): array
    {
        $ev = $this->row($pid);
        $le = (int) $ev['legal_entity_id'];
        $manager = $this->can($az, 'events.manage', $le);
        $me = $this->me($az);
        $reg = $me === null ? null : $this->app->db()->one('SELECT * FROM ev_regs WHERE event_id = ? AND employee_id = ?', [$ev['id'], $me['id']]);
        if (!$manager && ($ev['status'] === 'draft' || (!$this->inAudience($ev, $me) && $reg === null))) {
            throw HttpException::notFound();
        }
        $ev['phase'] = $this->phase($ev);
        $ev['mine'] = $reg;
        $ev['taken'] = $this->taken($ev);
        $ev['waiting'] = (int) $this->app->db()->scalar("SELECT COUNT(*) FROM ev_regs WHERE event_id = ? AND status = 'waitlisted'", [$ev['id']]);
        $ev['position'] = $reg !== null && $reg['status'] === 'waitlisted' ? 1 + (int) $this->app->db()->scalar("SELECT COUNT(*) FROM ev_regs WHERE event_id = ? AND status = 'waitlisted' AND id < ?", [$ev['id'], $reg['id']]) : null;
        $ev['choices'] = self::choices($ev['options']);
        $ev['is_manager'] = $manager;
        $ev['can_checkin'] = $this->can($az, 'events.checkin', $le);
        $ev['can_join'] = $me !== null && $this->inAudience($ev, $me);
        return $ev;
    }

    /** Joins (or re-joins after cancelling) — the waitlist when full and the event keeps one. */
    public function register(Authorizer $az, string $pid, string $choice): string
    {
        $ev = $this->event($az, $pid);
        $me = $this->me($az);
        if (!$ev['can_join'] || $me === null) {
            throw new BusinessException('events.error.not_invited', [], 403);
        }
        if ($ev['status'] !== 'published' || $this->now() >= (string) $ev['reg_until']) {
            throw new BusinessException('events.error.registration_closed', [], 409);
        }
        if ($ev['mine'] !== null && $ev['mine']['status'] !== 'cancelled') {
            throw new BusinessException('events.error.already_registered', [], 409);
        }
        $choice = trim($choice);
        if ($ev['choices'] !== [] && !in_array($choice, $ev['choices'], true)) {
            throw new ValidationException(['choice' => 'events.error.choice']);
        }
        $db = $this->app->db();
        return $db->transactional(function () use ($db, $ev, $me, $az, $choice): string {
            $db->one('SELECT id FROM ev_events WHERE id = ? FOR UPDATE', [$ev['id']]);
            $full = $ev['quota'] !== null && $this->taken($ev) >= (int) $ev['quota'];
            if ($full && (int) $ev['waitlist'] === 0) {
                throw new BusinessException('events.error.full', [], 409);
            }
            $status = $full ? 'waitlisted' : 'registered';
            $vals = [$az->userId, $choice ?: null, $status, $this->stamp()];
            if ($ev['mine'] !== null) {
                $db->run('UPDATE ev_regs SET user_id = ?, choice = ?, status = ?, registered_at = ?, cancelled_at = NULL, walk_in = 0 WHERE id = ?', [...$vals, $ev['mine']['id']]);
            } else {
                $db->run('INSERT INTO ev_regs (user_id, choice, status, registered_at, event_id, employee_id) VALUES (?, ?, ?, ?, ?, ?)', [...$vals, $ev['id'], $me['id']]);
            }
            return $status;
        });
    }

    /** Cancels my place before the start; the first waitlisted person moves up and is told. */
    public function cancelMine(Authorizer $az, string $pid): void
    {
        $ev = $this->event($az, $pid);
        $reg = $ev['mine'];
        if ($reg === null || !in_array($reg['status'], ['registered', 'waitlisted'], true)) {
            throw new BusinessException('events.error.not_registered', [], 409);
        }
        if ($this->now() >= (string) $ev['starts_at']) {
            throw new BusinessException('events.error.started', [], 409);
        }
        $db = $this->app->db();
        $db->transactional(function () use ($db, $ev, $reg): void {
            $db->run("UPDATE ev_regs SET status = 'cancelled', cancelled_at = ? WHERE id = ?", [$this->stamp(), $reg['id']]);
            if ($reg['status'] === 'registered') {
                $this->promote($ev);
            }
        });
    }

    /** @param array<string, mixed> $ev */
    private function promote(array $ev): void
    {
        $db = $this->app->db();
        $next = $db->one("SELECT * FROM ev_regs WHERE event_id = ? AND status = 'waitlisted' ORDER BY id LIMIT 1", [$ev['id']]);
        if ($next === null || ($ev['quota'] !== null && $this->taken($ev) >= (int) $ev['quota'])) {
            return;
        }
        $db->run("UPDATE ev_regs SET status = 'registered' WHERE id = ?", [$next['id']]);
        if ($next['user_id'] !== null) {
            $this->app->notifier()->notify((int) $next['user_id'], 'events.event', 'events.notify.promoted', ['title' => (string) $ev['title']], '/events/e/' . $ev['public_id'], 'ev.up:' . $next['id']);
        }
    }

    // ---- organisers ------------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function manageList(Authorizer $az, int $le): array
    {
        $this->require($az, 'events.manage', $le);
        $out = [];
        foreach ($this->app->db()->all('SELECT * FROM ev_events WHERE legal_entity_id = ? ORDER BY starts_at DESC LIMIT 300', [$le]) as $ev) {
            $ev['phase'] = $this->phase($ev);
            $ev['taken'] = $this->taken($ev);
            $ev['came'] = (int) $this->app->db()->scalar("SELECT COUNT(*) FROM ev_regs WHERE event_id = ? AND status = 'checked_in'", [$ev['id']]);
            $out[] = $ev;
        }
        return $out;
    }

    /**
     * Creates or edits a draft.
     *
     * @param array<string, mixed> $in title, kind, description, venue, starts_at, ends_at, reg_until, quota, waitlist, walk_in, option_label, options, cost_note, audience
     */
    public function save(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        $this->require($az, 'events.manage', $le);
        $db = $this->app->db();
        if ($pid !== null) {
            $ev = $this->row($pid);
            if ((int) $ev['legal_entity_id'] !== $le) {
                throw HttpException::notFound();
            }
            if ($ev['status'] !== 'draft') {
                throw new BusinessException('events.error.not_draft', [], 409);
            }
        }
        $e = [];
        $v = [];
        foreach (['title' => [3, 160], 'venue' => [2, 200], 'kind' => [0, 60], 'description' => [0, 5000], 'option_label' => [0, 60], 'cost_note' => [0, 300]] as $k => [$min, $max]) {
            $v[$k] = trim((string) ($in[$k] ?? ''));
            if (mb_strlen($v[$k]) < $min || mb_strlen($v[$k]) > $max) {
                $e[$k] = $min > 0 ? 'events.error.' . $k : 'events.error.too_long';
            }
        }
        $dt = static fn (string $x): ?DateTimeImmutable => DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', trim($x)) ?: null;
        $a = $dt((string) ($in['starts_at'] ?? ''));
        $b = $dt((string) ($in['ends_at'] ?? ''));
        $r = $dt((string) ($in['reg_until'] ?? ''));
        if ($a === null || $b === null || $b <= $a) {
            $e['starts_at'] = 'events.error.window';
        } elseif ($a->format('Y-m-d H:i') <= $this->nowLocal()->format('Y-m-d H:i')) {
            $e['starts_at'] = 'events.error.past';
        } elseif ($a->modify('+14 days') < $b) {
            $e['starts_at'] = 'events.error.too_long_window';
        }
        if ($r === null || ($a !== null && $r > $a)) {
            $e['reg_until'] = 'events.error.reg_until';
        }
        $quota = trim((string) ($in['quota'] ?? ''));
        if ($quota !== '' && (preg_match('/^\d{1,5}$/', $quota) !== 1 || (int) $quota < 1)) {
            $e['quota'] = 'events.error.quota';
        }
        $waitlist = !empty($in['waitlist']) ? 1 : 0;
        if ($waitlist === 1 && $quota === '') {
            $e['quota'] = 'events.error.waitlist_quota';
        }
        $opts = array_values(array_unique(array_filter(array_map(static fn (string $x): string => mb_substr(trim($x), 0, 120), preg_split('/\R/', (string) ($in['options'] ?? '')) ?: []), static fn (string $x): bool => $x !== '')));
        if ($v['option_label'] !== '' && (count($opts) < 2 || count($opts) > 20)) {
            $e['options'] = 'events.error.options';
        }
        if ($v['option_label'] === '' && $opts !== []) {
            $e['option_label'] = 'events.error.option_label';
        }
        try {
            $aud = Audience::fromInput($this->app, $le, $in);
        } catch (ValidationException $x) {
            $e += $x->errors;
            $aud = Audience::empty();
        }
        if ($e !== [] || $a === null || $b === null || $r === null) {
            throw new ValidationException($e);
        }
        $vals = [$v['title'], $v['kind'] ?: null, $v['description'] ?: null, $v['venue'], $a->format('Y-m-d H:i:00'), $b->format('Y-m-d H:i:00'), $r->format('Y-m-d H:i:00'),
            $quota === '' ? null : (int) $quota, $waitlist, !empty($in['walk_in']) ? 1 : 0, Audience::encode($aud), $v['option_label'] ?: null, $opts === [] ? null : implode("\n", $opts), $v['cost_note'] ?: null];
        $now = $this->stamp();
        if ($pid === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run(
                'INSERT INTO ev_events (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, title, kind, description, venue, starts_at, ends_at, reg_until, quota, waitlist, walk_in, audience_json, option_label, options, cost_note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $le, ...$vals]
            );
        } else {
            $db->run('UPDATE ev_events SET title = ?, kind = ?, description = ?, venue = ?, starts_at = ?, ends_at = ?, reg_until = ?, quota = ?, waitlist = ?, walk_in = ?, audience_json = ?, option_label = ?, options = ?, cost_note = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE public_id = ?', [...$vals, $now, $az->userId, $pid]);
        }
        $this->app->audit()->record('events.saved', 'success', 'ev_event', $pid, $le);
        return $pid;
    }

    /** @return array<string, mixed> the event with the audience text and the people (organiser view) */
    public function manage(Authorizer $az, string $pid): array
    {
        $ev = $this->row($pid);
        $le = (int) $ev['legal_entity_id'];
        $this->require($az, 'events.manage', $le);
        $aud = Audience::decode((string) $ev['audience_json']);
        $ev['audience_text'] = Audience::describe($this->app, $aud);
        $ev['audience_in'] = Audience::toInput($this->app, $aud);
        $ev['phase'] = $this->phase($ev);
        $ev['taken'] = $this->taken($ev);
        $ev['choices'] = self::choices($ev['options']);
        $ev['people'] = $this->people((int) $ev['id']);
        return $ev;
    }

    /** @return list<array<string, mixed>> */
    private function people(int $eventId): array
    {
        return $this->app->db()->all(
            "SELECT r.*, p.employee_code, p.name_th, d.code AS dept_code, s.code AS site_code FROM ev_regs r JOIN employees p ON p.id = r.employee_id
               LEFT JOIN employment_history h ON h.employee_id = p.id AND h.effective_to IS NULL LEFT JOIN departments d ON d.id = h.department_id LEFT JOIN sites s ON s.id = h.site_id
              WHERE r.event_id = ? ORDER BY FIELD(r.status, 'checked_in', 'registered', 'waitlisted', 'cancelled'), r.id",
            [$eventId]
        );
    }

    /** draft → published (the audience is told) · cancel (reason; everyone registered or waiting is told). */
    public function transition(Authorizer $az, string $pid, string $to, string $reason = ''): void
    {
        $ev = $this->row($pid);
        $le = (int) $ev['legal_entity_id'];
        $this->require($az, 'events.manage', $le);
        $db = $this->app->db();
        if ($to === 'published') {
            if ($ev['status'] !== 'draft') {
                throw new BusinessException('events.error.not_draft', [], 409);
            }
            if ((string) $ev['reg_until'] <= $this->now()) {
                throw new BusinessException('events.error.reg_past', [], 409);
            }
            $db->run("UPDATE ev_events SET status = 'published', updated_at = ?, updated_by = ? WHERE id = ?", [$this->stamp(), $az->userId, $ev['id']]);
            foreach (Audience::members($this->app, $le, Audience::decode((string) $ev['audience_json']), $this->nowLocal()->format('Y-m-d')) as $u) {
                if ($u !== null) {
                    $this->app->notifier()->notify($u, 'events.event', 'events.notify.published', ['title' => (string) $ev['title'], 'until' => substr((string) $ev['reg_until'], 0, 16)], '/events/e/' . $pid, 'ev.pub:' . $pid . ':' . $u);
                }
            }
        } elseif ($to === 'cancelled') {
            if (!in_array($ev['status'], ['draft', 'published'], true) || $this->now() >= (string) $ev['ends_at']) {
                throw new BusinessException('events.error.cannot_cancel', [], 409);
            }
            $reason = trim($reason);
            if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
                throw new ValidationException(['reason' => 'events.error.reason']);
            }
            $db->run("UPDATE ev_events SET status = 'cancelled', cancel_reason = ?, updated_at = ?, updated_by = ? WHERE id = ?", [$reason, $this->stamp(), $az->userId, $ev['id']]);
            foreach ($db->all("SELECT user_id FROM ev_regs WHERE event_id = ? AND status IN ('registered','waitlisted') AND user_id IS NOT NULL", [$ev['id']]) as $r) {
                $this->app->notifier()->notify((int) $r['user_id'], 'events.event', 'events.notify.cancelled', ['title' => (string) $ev['title'], 'reason' => $reason], '/events/e/' . $pid, 'ev.cx:' . $pid . ':' . $r['user_id']);
            }
        } else {
            throw HttpException::notFound();
        }
        $this->app->audit()->record('events.' . $to, 'success', 'ev_event', $pid, $le, $reason !== '' ? $reason : null);
    }

    // ---- check-in desk ---------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> the event for the desk: counts and the registered people for the name list */
    public function desk(Authorizer $az, string $pid): array
    {
        $ev = $this->row($pid);
        $this->require($az, 'events.checkin', (int) $ev['legal_entity_id']);
        $ev['phase'] = $this->phase($ev);
        $ev['people'] = $this->people((int) $ev['id']);
        $ev['counts'] = $this->counts((int) $ev['id']);
        $ev['open'] = $this->deskOpen($ev);
        return $ev;
    }

    /** @param array<string, mixed> $ev */
    private function deskOpen(array $ev): bool
    {
        $now = $this->nowLocal();
        return $ev['status'] === 'published' && $now->format('Y-m-d H:i:s') < (string) $ev['ends_at'] && $now->modify('+2 hours')->format('Y-m-d H:i:s') >= (string) $ev['starts_at'];
    }

    /** @return array<string, int> */
    private function counts(int $eventId): array
    {
        $c = ['registered' => 0, 'waitlisted' => 0, 'cancelled' => 0, 'came' => 0, 'walk_in' => 0];
        foreach ($this->app->db()->all('SELECT status, walk_in, COUNT(*) AS n FROM ev_regs WHERE event_id = ? GROUP BY status, walk_in', [$eventId]) as $r) {
            $n = (int) $r['n'];
            if ($r['status'] === 'checked_in') {
                $c[(int) $r['walk_in'] === 1 ? 'walk_in' : 'came'] += $n;
            } else {
                $c[(string) $r['status']] += $n;
            }
        }
        $c['expected'] = $c['registered'] + $c['came'];
        return $c;
    }

    private function cardHash(int $le, string $card): string
    {
        return hash('sha256', $le . ':' . strtoupper(preg_replace('/\s+/', '', $card) ?? ''));
    }

    /**
     * Checks someone in from what the desk typed or scanned.
     *
     * @return array{result: string, name?: string, card?: string}
     *   ok · walk_in · already · no_walk_in · unknown · unknown_card · not_open · other_company · card_<eID result> · no_eid
     */
    public function checkIn(Authorizer $az, string $pid, string $input, string $pairCode = ''): array
    {
        $ev = $this->row($pid);
        $le = (int) $ev['legal_entity_id'];
        $this->require($az, 'events.checkin', $le);
        if (!$this->deskOpen($ev)) {
            return ['result' => 'not_open'];
        }
        $db = $this->app->db();
        $input = trim(mb_substr($input, 0, 200));
        $empId = null;
        $method = 'code';
        if ($input === '') {
            return ['result' => 'unknown'];
        }
        if (preg_match(self::QR, $input, $m) === 1) {
            $method = 'qr';
            if (!$this->app->modules()->isEnabled('eid')) {
                return ['result' => 'no_eid'];
            }
            try {
                $v = \Blue\Modules\Eid\CardService::of($this->app)->verify($az, $m[1]);
            } catch (HttpException) {
                return ['result' => 'no_eid'];
            }
            if ($v['result'] !== 'valid') {
                return ['result' => 'card_' . $v['result']];
            }
            $empId = (int) $v['card']['employee_id'];
        } elseif (preg_match('/^(\S{1,30}) — /u', $input, $m) === 1) {
            $method = 'name';
            $empId = $db->scalar('SELECT id FROM employees WHERE employee_code = ?', [$m[1]]);
        } elseif (($id = $db->scalar('SELECT id FROM employees WHERE employee_code = ?', [mb_substr($input, 0, 30)])) !== null) {
            $empId = $id;
        } elseif (preg_match('/^[0-9A-Fa-f:\s-]{4,40}$/', $input) === 1) {
            $method = 'card';
            $hash = $this->cardHash($le, $input);
            $empId = $db->scalar('SELECT employee_id FROM ev_cards WHERE legal_entity_id = ? AND card_hash = ?', [$le, $hash]);
            if ($empId === null && trim($pairCode) !== '') {
                $pair = $db->scalar('SELECT id FROM employees WHERE employee_code = ?', [mb_substr(trim($pairCode), 0, 30)]);
                if ($pair === null) {
                    return ['result' => 'unknown', 'card' => $input];
                }
                $db->run('INSERT INTO ev_cards (legal_entity_id, card_hash, employee_id, paired_by, paired_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE employee_id = VALUES(employee_id), paired_by = VALUES(paired_by), paired_at = VALUES(paired_at)', [$le, $hash, $pair, $az->userId, $this->stamp()]);
                $this->app->audit()->record('events.card_paired', 'success', 'employee', (string) $pair, $le);
                $empId = $pair;
            }
            if ($empId === null) {
                return ['result' => 'unknown_card', 'card' => $input];
            }
        }
        if ($empId === null) {
            return ['result' => 'unknown'];
        }
        $person = $this->emp()->byId((int) $empId, $this->nowLocal()->format('Y-m-d'));
        if ($person === null || $person['status'] === 'former' || $person['job'] === null || (int) $person['job']['legal_entity_id'] !== $le) {
            return ['result' => 'other_company'];
        }
        $name = (string) $person['name_th'];
        $reg = $db->one('SELECT * FROM ev_regs WHERE event_id = ? AND employee_id = ?', [$ev['id'], $empId]);
        if ($reg !== null && $reg['status'] === 'checked_in') {
            return ['result' => 'already', 'name' => $name];
        }
        $now = $this->stamp();
        if ($reg !== null && $reg['status'] === 'registered') {
            $db->run("UPDATE ev_regs SET status = 'checked_in', checked_in_at = ?, checked_in_by = ?, check_method = ? WHERE id = ? AND status = 'registered'", [$now, $az->userId, $method, $reg['id']]);
            return ['result' => 'ok', 'name' => $name];
        }
        // not registered, on the waitlist or cancelled → a walk-in, when the event takes them
        if ((int) $ev['walk_in'] === 0) {
            return ['result' => 'no_walk_in', 'name' => $name];
        }
        $user = $db->scalar("SELECT id FROM users WHERE employee_id = ? AND status = 'active' LIMIT 1", [$empId]);
        if ($reg !== null) {
            $db->run("UPDATE ev_regs SET status = 'checked_in', walk_in = 1, checked_in_at = ?, checked_in_by = ?, check_method = ? WHERE id = ?", [$now, $az->userId, $method, $reg['id']]);
        } else {
            $db->run("INSERT INTO ev_regs (event_id, employee_id, user_id, status, walk_in, registered_at, checked_in_at, checked_in_by, check_method) VALUES (?, ?, ?, 'checked_in', 1, ?, ?, ?, ?)", [$ev['id'], $empId, $user, $now, $now, $az->userId, $method]);
        }
        return ['result' => 'walk_in', 'name' => $name];
    }

    // ---- report -----------------------------------------------------------------------------------------------------------------

    /**
     * Registered (before the day), came, walk-ins, no-shows (registered after the start), cancelled, attendance rate,
     * by department and site, and the choices made.
     *
     * @return array<string, mixed>
     */
    public function report(Authorizer $az, string $pid): array
    {
        $ev = $this->manage($az, $pid);
        $started = $this->now() >= (string) $ev['starts_at'];
        $c = $this->counts((int) $ev['id']);
        $c['no_show'] = $started ? $c['registered'] : 0;
        $c['rate'] = $c['expected'] > 0 ? round($c['came'] * 100 / $c['expected'], 1) : null;
        $by = ['dept' => [], 'site' => []];
        $choices = [];
        foreach ($ev['people'] as $p) {
            if ($p['status'] === 'cancelled') {
                continue;
            }
            foreach (['dept' => 'dept_code', 'site' => 'site_code'] as $k => $col) {
                $key = (string) ($p[$col] ?? '—');
                $by[$k][$key] ??= ['registered' => 0, 'came' => 0, 'walk_in' => 0];
                if ((int) $p['walk_in'] === 1) {
                    $by[$k][$key]['walk_in']++;
                } elseif ($p['status'] !== 'waitlisted') {
                    $by[$k][$key]['registered']++;
                    if ($p['status'] === 'checked_in') {
                        $by[$k][$key]['came']++;
                    }
                }
            }
            if ($p['choice'] !== null && $p['status'] !== 'waitlisted') {
                $choices[(string) $p['choice']] = ($choices[(string) $p['choice']] ?? 0) + 1;
            }
        }
        ksort($by['dept']);
        ksort($by['site']);
        return $ev + ['counts' => $c, 'by' => $by, 'choice_counts' => $choices, 'started' => $started];
    }

    /** CSV of the people (code, name, department, site, choice, status, walk-in, how and when checked in) — audited. */
    public function exportCsv(Authorizer $az, string $pid): string
    {
        $rep = $this->report($az, $pid);
        $t = $this->app->translator();
        $fh = fopen('php://temp', 'w+');
        if ($fh === false) {
            throw new \RuntimeException('temp stream');
        }
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, [$t->get('events.csv.code'), $t->get('events.csv.name'), $t->get('events.csv.department'), $t->get('events.csv.site'), $t->get('events.csv.choice'), $t->get('events.csv.status'), $t->get('events.csv.walk_in'), $t->get('events.csv.method'), $t->get('events.csv.checked_in_at')], ',', '"', '');
        $safe = static fn (?string $v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) === 1 ? "'" . $v : (string) $v;
        foreach ($rep['people'] as $p) {
            $status = $p['status'] === 'registered' && $rep['started'] ? 'no_show' : (string) $p['status'];
            fputcsv($fh, [$safe((string) $p['employee_code']), $safe((string) $p['name_th']), (string) ($p['dept_code'] ?? ''), (string) ($p['site_code'] ?? ''), $safe($p['choice']), $t->get('events.reg_status.' . $status), (int) $p['walk_in'] === 1 ? '1' : '0', (string) ($p['check_method'] ?? ''), (string) ($p['checked_in_at'] ?? '')], ',', '"', '');
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);
        $this->app->audit()->record('events.exported', 'success', 'ev_event', $pid, (int) $rep['legal_entity_id'], null, ['rows' => count($rep['people'])]);
        return $csv;
    }

    /** Hourly: a reminder a day before (once); finished events → completed. */
    public function tick(): int
    {
        $db = $this->app->db();
        $now = $this->now();
        $soon = $this->nowLocal()->modify('+24 hours')->format('Y-m-d H:i:s');
        $n = 0;
        foreach ($db->all("SELECT * FROM ev_events WHERE status = 'published' AND reminded = 0 AND starts_at > ? AND starts_at <= ? LIMIT 100", [$now, $soon]) as $ev) {
            $db->run('UPDATE ev_events SET reminded = 1 WHERE id = ?', [$ev['id']]);
            foreach ($db->all("SELECT user_id FROM ev_regs WHERE event_id = ? AND status = 'registered' AND user_id IS NOT NULL", [$ev['id']]) as $r) {
                $this->app->notifier()->notify((int) $r['user_id'], 'events.event', 'events.notify.reminder', ['title' => (string) $ev['title'], 'at' => substr((string) $ev['starts_at'], 0, 16), 'venue' => (string) $ev['venue']], '/events/e/' . $ev['public_id'], 'ev.rm:' . $ev['public_id'] . ':' . $r['user_id']);
                $n++;
            }
        }
        $n += $db->run("UPDATE ev_events SET status = 'completed', updated_at = ? WHERE status = 'published' AND ends_at <= ?", [$this->stamp(), $now])->rowCount();
        return $n;
    }

    public static function tickJob(App $app): string
    {
        return 'events: ' . self::of($app)->tick();
    }
}

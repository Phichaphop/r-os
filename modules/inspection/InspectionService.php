<?php

declare(strict_types=1);

namespace Blue\Modules\Inspection;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Files\Xlsx;
use Blue\Core\Support\Qr\QrEncoder;
use Blue\Core\Support\Qr\QrRender;
use Blue\Core\Support\Ulid;
use Blue\Modules\Helpdesk\HelpdeskService;

/**
 * M18 inspections (9B-1 · DEC-91).
 *
 * The workplace admin writes checklists (a change is a new version that applies to rounds from its date) and sets up
 * inspection points: a place or piece of equipment with its checklist, how often it is checked (daily / weekly /
 * monthly from a start date), the one person who inspects it, and optionally the helpdesk category that receives
 * failures. Rounds are created on schedule (catching up at most 31 days) and become overdue after their day. The
 * inspector opens the round from the list or by scanning the QR at the point, answers every required item (pass /
 * fail / not applicable, or a number checked against its range), adds notes and photos, and submits. Each failed item
 * becomes a corrective action — a helpdesk ticket when the point has a category, otherwise an action the admin assigns.
 * The admin reviews (accept, or return with a reason → a new revision; earlier answers are kept) and closes the round
 * once every action is done. Statuses: scheduled → in_progress → submitted → reviewed → closed; overdue.
 */
final class InspectionService
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    private const OPEN = ['scheduled', 'in_progress', 'overdue'];

    private const CATCH_UP_DAYS = 31;

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    // ---- time and scope ------------------------------------------------------------------------------------------------------

    public function tz(): DateTimeZone
    {
        return new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }

    public function today(): string
    {
        return $this->app->clock->now()->setTimezone($this->tz())->format('Y-m-d');
    }

    private function nowLocal(): string
    {
        return $this->app->clock->now()->setTimezone($this->tz())->format('Y-m-d H:i:s');
    }

    private function stamp(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    private static function ymd(string $d): ?DateTimeImmutable
    {
        $x = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        return $x !== false && $x->format('Y-m-d') === $d ? $x : null;
    }

    /** @return list<int> */
    public function companies(Authorizer $az, string $perm): array
    {
        $s = $az->scope($perm);
        return array_values(array_filter(array_map('intval', array_column($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code'), 'id')), static fn (int $id): bool => $s->coversLegalEntity($id)));
    }

    private function require(Authorizer $az, int $le): void
    {
        if (!$az->scope('inspection.manage')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
    }

    // ---- checklists ----------------------------------------------------------------------------------------------------------

    /**
     * Items from text: one per line · a line starting with "?" is optional · "text | min | max" asks for a number that
     * passes inside the range.
     *
     * @return list<array{text: string, required: bool, kind: string, min: ?float, max: ?float}>
     */
    public static function parseItems(string $text): array
    {
        $items = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $required = !str_starts_with($line, '?');
            $line = ltrim($line, '? ');
            $parts = array_map('trim', explode('|', $line));
            $label = $parts[0];
            if (mb_strlen($label) < 2 || mb_strlen($label) > 200) {
                throw new ValidationException(['items' => 'inspection.error.item_text']);
            }
            if (count($parts) === 1) {
                $items[] = ['text' => $label, 'required' => $required, 'kind' => 'check', 'min' => null, 'max' => null];
                continue;
            }
            $min = $parts[1] ?? '';
            $max = $parts[2] ?? '';
            if (count($parts) !== 3 || ($min !== '' && !is_numeric($min)) || ($max !== '' && !is_numeric($max)) || ($min === '' && $max === '') || ($min !== '' && $max !== '' && (float) $min > (float) $max)) {
                throw new ValidationException(['items' => 'inspection.error.item_range']);
            }
            $items[] = ['text' => $label, 'required' => $required, 'kind' => 'number', 'min' => $min === '' ? null : (float) $min, 'max' => $max === '' ? null : (float) $max];
        }
        if ($items === [] || count($items) > 60) {
            throw new ValidationException(['items' => 'inspection.error.items']);
        }
        return $items;
    }

    /** @param array<string, mixed> $item */
    public static function itemLine(array $item): string
    {
        $s = ($item['required'] ? '' : '? ') . $item['text'];
        if ($item['kind'] === 'number') {
            $s .= ' | ' . ($item['min'] !== null ? rtrim(rtrim(sprintf('%.2F', $item['min']), '0'), '.') : '') . ' | ' . ($item['max'] !== null ? rtrim(rtrim(sprintf('%.2F', $item['max']), '0'), '.') : '');
        }
        return $s;
    }

    /** @return list<array<string, mixed>> checklists with their versions (newest first) */
    public function checklists(int $le): array
    {
        $db = $this->app->db();
        $rows = $db->all('SELECT * FROM in_checklists WHERE legal_entity_id = ? ORDER BY name', [$le]);
        foreach ($rows as &$c) {
            $c['versions'] = $db->all('SELECT id, version_no, effective_from, note, items_json FROM in_checklist_versions WHERE checklist_id = ? ORDER BY version_no DESC', [$c['id']]);
            $c['items'] = $c['versions'] !== [] ? (array) json_decode((string) $c['versions'][0]['items_json'], true) : [];
        }
        return $rows;
    }

    /**
     * A new checklist, or a new version of one — it applies to rounds due from its date (today or later); rounds already
     * created keep the version they started with.
     *
     * @param array<string, mixed> $in name, items, effective_from, note
     */
    public function saveChecklist(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        $this->require($az, $le);
        $db = $this->app->db();
        $e = [];
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            $e['name'] = 'inspection.error.name';
        } elseif ($db->scalar('SELECT id FROM in_checklists WHERE legal_entity_id = ? AND name = ? AND public_id <> ?', [$le, $name, $pid ?? '']) !== null) {
            $e['name'] = 'inspection.error.name_taken';
        }
        $from = self::ymd((string) ($in['effective_from'] ?? ''));
        if ($from === null || $from->format('Y-m-d') < $this->today()) {
            $e['effective_from'] = 'inspection.error.effective_from';
        }
        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($note) > 300) {
            $e['note'] = 'inspection.error.text';
        }
        try {
            $items = self::parseItems((string) ($in['items'] ?? ''));
        } catch (ValidationException $x) {
            $e += $x->errors;
            $items = [];
        }
        if ($e !== [] || $from === null) {
            throw new ValidationException($e);
        }
        $now = $this->stamp();
        return $db->transactional(function () use ($db, $az, $le, $pid, $name, $from, $note, $items, $now): string {
            if ($pid === null) {
                $pid = Ulid::generate($this->app->clock);
                $db->run('INSERT INTO in_checklists (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, name) VALUES (?, ?, ?, ?, ?, ?, ?)', [$pid, $now, $az->userId, $now, $az->userId, $le, $name]);
                $id = (int) $db->pdo()->lastInsertId();
                $no = 1;
            } else {
                $c = $db->one('SELECT * FROM in_checklists WHERE public_id = ? AND legal_entity_id = ? FOR UPDATE', [$pid, $le]) ?? throw HttpException::notFound();
                $id = (int) $c['id'];
                $db->run('UPDATE in_checklists SET name = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$name, $now, $az->userId, $id]);
                $no = (int) $db->scalar('SELECT MAX(version_no) FROM in_checklist_versions WHERE checklist_id = ?', [$id]) + 1;
            }
            $db->run(
                'INSERT INTO in_checklist_versions (checklist_id, version_no, items_json, effective_from, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$id, $no, json_encode($items, JSON_UNESCAPED_UNICODE), $from->format('Y-m-d'), $note === '' ? null : $note, $az->userId, $now]
            );
            $this->app->audit()->record('inspection.checklist_version', 'success', 'in_checklist', $pid, $le, null, ['version' => $no, 'items' => count($items), 'from' => $from->format('Y-m-d')]);
            return $pid;
        });
    }

    /** the version in force on a date (the first version when none had started yet) */
    private function versionFor(int $checklistId, string $date): int
    {
        $db = $this->app->db();
        $v = $db->scalar('SELECT id FROM in_checklist_versions WHERE checklist_id = ? AND effective_from <= ? ORDER BY effective_from DESC, version_no DESC LIMIT 1', [$checklistId, $date]);
        return (int) ($v ?? $db->scalar('SELECT id FROM in_checklist_versions WHERE checklist_id = ? ORDER BY version_no LIMIT 1', [$checklistId]));
    }

    // ---- points --------------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function points(int $le): array
    {
        return $this->app->db()->all(
            'SELECT p.*, s.code AS site_code, c.name AS checklist_name, COALESCE(u.display_name, u.username) AS inspector_name, u.username AS inspector_username
               FROM in_points p JOIN in_checklists c ON c.id = p.checklist_id JOIN users u ON u.id = p.inspector_id LEFT JOIN sites s ON s.id = p.site_id
              WHERE p.legal_entity_id = ? ORDER BY p.active DESC, p.code',
            [$le]
        );
    }

    /** @return array<string, mixed> */
    public function point(string $pid): array
    {
        return $this->app->db()->one(
            'SELECT p.*, s.code AS site_code, s.public_id AS site_pid, c.name AS checklist_name, c.public_id AS checklist_pid, u.username AS inspector_username, COALESCE(u.display_name, u.username) AS inspector_name
               FROM in_points p JOIN in_checklists c ON c.id = p.checklist_id JOIN users u ON u.id = p.inspector_id LEFT JOIN sites s ON s.id = p.site_id WHERE p.public_id = ?',
            [$pid]
        ) ?? throw HttpException::notFound();
    }

    /**
     * @param array<string, mixed> $in code, name, site, location, checklist, frequency, start_date, inspector (user name / employee code), ticket_category, ticket_priority, active
     */
    public function savePoint(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        $this->require($az, $le);
        $db = $this->app->db();
        $e = [];
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,29}$/', $code) !== 1) {
            $e['code'] = 'inspection.error.code';
        } elseif ($db->scalar('SELECT id FROM in_points WHERE legal_entity_id = ? AND code = ? AND public_id <> ?', [$le, $code, $pid ?? '']) !== null) {
            $e['code'] = 'inspection.error.code_taken';
        }
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            $e['name'] = 'inspection.error.name';
        }
        $loc = trim((string) ($in['location'] ?? ''));
        if (mb_strlen($loc) > 200) {
            $e['location'] = 'inspection.error.text';
        }
        $site = null;
        if ((string) ($in['site'] ?? '') !== '') {
            $site = $db->scalar('SELECT id FROM sites WHERE public_id = ? AND legal_entity_id = ?', [(string) $in['site'], $le]);
            if ($site === null) {
                $e['site'] = 'inspection.error.site';
            }
        }
        $checklist = $db->scalar('SELECT id FROM in_checklists WHERE public_id = ? AND legal_entity_id = ?', [(string) ($in['checklist'] ?? ''), $le]);
        if ($checklist === null) {
            $e['checklist'] = 'inspection.error.checklist';
        }
        $freq = (string) ($in['frequency'] ?? '');
        if (!in_array($freq, self::FREQUENCIES, true)) {
            $e['frequency'] = 'inspection.error.frequency';
        }
        $start = self::ymd((string) ($in['start_date'] ?? ''));
        if ($start === null) {
            $e['start_date'] = 'inspection.error.date';
        }
        $who = trim((string) ($in['inspector'] ?? ''));
        $inspector = $who === '' ? null : $db->scalar(
            "SELECT u.id FROM users u LEFT JOIN employees x ON x.id = u.employee_id WHERE u.status = 'active' AND (u.username = ? OR x.employee_code = ?) LIMIT 1",
            [$who, strtoupper($who)]
        );
        if ($inspector === null || !(new Authorizer($db, $this->app->clock, (int) $inspector))->scope('inspection.use')->coversLegalEntity($le)) {
            $e['inspector'] = 'inspection.error.inspector';
        }
        $cat = trim((string) ($in['ticket_category'] ?? ''));
        if ($cat !== '' && $db->scalar('SELECT id FROM hd_categories WHERE public_id = ? AND legal_entity_id = ? AND active = 1', [$cat, $le]) === null) {
            $e['ticket_category'] = 'inspection.error.ticket_category';
        }
        $prio = (string) ($in['ticket_priority'] ?? 'normal');
        if ($cat !== '' && !in_array($prio, HelpdeskService::PRIORITIES, true)) {
            $e['ticket_priority'] = 'inspection.error.priority';
        }
        if ($e !== [] || $start === null) {
            throw new ValidationException($e);
        }
        $now = $this->stamp();
        $vals = [$site, $code, $name, $loc === '' ? null : $loc, (int) $checklist, $freq, $start->format('Y-m-d'), (int) $inspector, $cat === '' ? null : $cat, $cat === '' ? null : $prio];
        if ($pid === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run(
                'INSERT INTO in_points (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, site_id, code, name, location, checklist_id, frequency, start_date, inspector_id, ticket_category_pid, ticket_priority, qr_token)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $le, ...$vals, ...[bin2hex(random_bytes(16))]]
            );
            $this->app->audit()->record('inspection.point_added', 'success', 'in_point', $pid, $le, null, ['code' => $code]);
        } else {
            $p = $this->point($pid);
            if ((int) $p['legal_entity_id'] !== $le) {
                throw HttpException::notFound();
            }
            $db->run(
                'UPDATE in_points SET site_id = ?, code = ?, name = ?, location = ?, checklist_id = ?, frequency = ?, start_date = ?, inspector_id = ?, ticket_category_pid = ?, ticket_priority = ?, active = ?,
                    updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
                [...$vals, !empty($in['active']) ? 1 : 0, $now, $az->userId, $p['id']]
            );
            // rounds not started yet follow the new inspector
            $db->run("UPDATE in_rounds SET inspector_id = ? WHERE point_id = ? AND status IN ('scheduled','overdue') AND started_at IS NULL", [(int) $inspector, $p['id']]);
            $this->app->audit()->record('inspection.point_changed', 'success', 'in_point', $pid, $le, null, ['code' => $code]);
        }
        $this->schedulePoint((int) $db->scalar('SELECT id FROM in_points WHERE public_id = ?', [$pid]));
        return $pid;
    }

    /** a new QR (the printed label of the old one stops working) */
    public function newQr(Authorizer $az, string $pid): void
    {
        $p = $this->point($pid);
        $this->require($az, (int) $p['legal_entity_id']);
        $this->app->db()->run('UPDATE in_points SET qr_token = ?, updated_at = ?, updated_by = ? WHERE id = ?', [bin2hex(random_bytes(16)), $this->stamp(), $az->userId, $p['id']]);
        $this->app->audit()->record('inspection.qr_renewed', 'success', 'in_point', $pid, (int) $p['legal_entity_id']);
    }

    /** @param array<string, mixed> $p */
    public function qrSvg(array $p, string $origin): string
    {
        $url = rtrim($origin, '/') . '/inspection/q/' . $p['qr_token'];
        return QrRender::svg(QrEncoder::encode($url, 'M')['modules'], 6, null, (string) $p['code']);
    }

    // ---- schedule ------------------------------------------------------------------------------------------------------------

    /**
     * The due dates of a point between two dates (inclusive).
     *
     * @return list<string>
     */
    public static function dueDates(string $frequency, string $start, string $from, string $to): array
    {
        $s = self::ymd($start);
        $f = self::ymd($from);
        $t = self::ymd($to);
        if ($s === null || $f === null || $t === null || $t < $s) {
            return [];
        }
        $out = [];
        if ($frequency === 'monthly') {
            $day = (int) $s->format('j');
            for ($m = $s->modify('first day of this month'); $m <= $t && count($out) < 400; $m = $m->modify('first day of next month')) {
                $d = $m->modify('+' . (min($day, (int) $m->format('t')) - 1) . ' days');
                if ($d >= $s && $d >= $f && $d <= $t) {
                    $out[] = $d->format('Y-m-d');
                }
            }
            return $out;
        }
        $step = $frequency === 'weekly' ? 7 : 1;
        $d = $s;
        if ($f > $s) {
            $skip = intdiv((int) $s->diff($f)->days, $step);
            $d = $s->modify('+' . ($skip * $step) . ' days');
        }
        for (; $d <= $t && count($out) < 400; $d = $d->modify('+' . $step . ' days')) {
            if ($d >= $f) {
                $out[] = $d->format('Y-m-d');
            }
        }
        return $out;
    }

    /** Creates the rounds of a point due up to today (catching up at most 31 days) and the next one ahead. */
    private function schedulePoint(int $pointId): int
    {
        $db = $this->app->db();
        $p = $db->one('SELECT * FROM in_points WHERE id = ? AND active = 1', [$pointId]);
        if ($p === null) {
            return 0;
        }
        $today = self::ymd($this->today()) ?? throw new \LogicException('today');
        $from = $today->modify('-' . self::CATCH_UP_DAYS . ' days')->format('Y-m-d');
        $ahead = $today->modify($p['frequency'] === 'monthly' ? '+31 days' : ($p['frequency'] === 'weekly' ? '+7 days' : '+1 day'))->format('Y-m-d');
        $dates = self::dueDates((string) $p['frequency'], (string) $p['start_date'], max($from, (string) $p['start_date']), $ahead);
        $future = array_values(array_filter($dates, fn (string $d): bool => $d > $this->today()));
        $dates = array_merge(array_filter($dates, fn (string $d): bool => $d <= $this->today()), array_slice($future, 0, 1));
        $n = 0;
        $now = $this->stamp();
        foreach ($dates as $d) {
            $n += $db->run(
                'INSERT IGNORE INTO in_rounds (public_id, created_at, updated_at, legal_entity_id, point_id, due_date, version_id, inspector_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [Ulid::generate($this->app->clock), $now, $now, $p['legal_entity_id'], $p['id'], $d, $this->versionFor((int) $p['checklist_id'], $d), $p['inspector_id'], $d < $this->today() ? 'overdue' : 'scheduled']
            )->rowCount();
        }
        return $n;
    }

    /**
     * Housekeeping (hourly and before the lists): new rounds, rounds past their day become overdue (the inspector is
     * told once), corrective actions follow their helpdesk ticket.
     */
    public function tick(): int
    {
        $db = $this->app->db();
        $n = 0;
        foreach (array_column($db->all('SELECT id FROM in_points WHERE active = 1'), 'id') as $id) {
            $n += $this->schedulePoint((int) $id);
        }
        foreach ($db->all("SELECT r.id, r.public_id, r.inspector_id, r.due_date, p.name FROM in_rounds r JOIN in_points p ON p.id = r.point_id WHERE r.status IN ('scheduled','in_progress') AND r.due_date < ? LIMIT 1000", [$this->today()]) as $r) {
            $db->run("UPDATE in_rounds SET status = 'overdue', updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->stamp(), $r['id']]);
            $this->event((int) $r['id'], 'overdue', null, null);
            $this->app->notifier()->notify((int) $r['inspector_id'], 'inspection.overdue', 'inspection.notify.overdue', ['point' => (string) $r['name'], 'date' => (string) $r['due_date']], '/inspection/r/' . $r['public_id'], 'in.od:' . $r['public_id']);
            $n++;
        }
        foreach ($db->all("SELECT r.id, r.public_id, r.inspector_id, p.name FROM in_rounds r JOIN in_points p ON p.id = r.point_id WHERE r.status = 'scheduled' AND r.due_date = ? LIMIT 1000", [$this->today()]) as $r) {
            $this->app->notifier()->notify((int) $r['inspector_id'], 'inspection.due', 'inspection.notify.due', ['point' => (string) $r['name']], '/inspection/r/' . $r['public_id'], 'in.due:' . $r['public_id']);
        }
        $n += $this->syncActions();
        return $n;
    }

    public static function tickJob(App $app): string
    {
        return 'inspection: ' . self::of($app)->tick();
    }

    /** corrective actions linked to a helpdesk ticket are done when the ticket is resolved or closed */
    private function syncActions(?int $roundId = null): int
    {
        $db = $this->app->db();
        $n = 0;
        $sql = "SELECT a.id, a.round_id FROM in_actions a JOIN hd_tickets t ON t.public_id = a.ticket_pid WHERE a.status = 'open' AND t.status IN ('resolved','closed')";
        foreach ($db->all($sql . ($roundId !== null ? ' AND a.round_id = ' . $roundId : '') . ' LIMIT 1000') as $a) {
            $db->run("UPDATE in_actions SET status = 'done', done_note = 'ticket', done_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'open'", [$this->nowLocal(), $this->stamp(), $a['id']]);
            $n++;
        }
        return $n;
    }

    // ---- rounds --------------------------------------------------------------------------------------------------------------

    /**
     * My rounds to do: overdue, today, the next ones, and those returned to me.
     *
     * @return list<array<string, mixed>>
     */
    public function mine(Authorizer $az): array
    {
        $this->tick();
        return $this->app->db()->all(
            "SELECT r.public_id, r.due_date, r.status, r.revision, p.code, p.name, p.location, s.code AS site_code FROM in_rounds r JOIN in_points p ON p.id = r.point_id LEFT JOIN sites s ON s.id = p.site_id
              WHERE r.inspector_id = ? AND r.status IN ('scheduled','in_progress','overdue') ORDER BY r.status = 'overdue' DESC, r.due_date, p.code LIMIT 300",
            [$az->userId]
        );
    }

    /** @return array<string, mixed> a round the person may open — its inspector or an inspection admin */
    public function round(Authorizer $az, string $pid): array
    {
        $db = $this->app->db();
        $r = $db->one(
            'SELECT r.*, p.public_id AS point_pid, p.code, p.name, p.location, p.ticket_category_pid, p.ticket_priority, p.site_id, s.code AS site_code, v.items_json, v.version_no, c.name AS checklist_name,
                    COALESCE(u.display_name, u.username) AS inspector_name
               FROM in_rounds r JOIN in_points p ON p.id = r.point_id JOIN in_checklist_versions v ON v.id = r.version_id JOIN in_checklists c ON c.id = v.checklist_id JOIN users u ON u.id = r.inspector_id
               LEFT JOIN sites s ON s.id = p.site_id WHERE r.public_id = ?',
            [$pid]
        ) ?? throw HttpException::notFound();
        $r['manage'] = $az->scope('inspection.manage')->coversLegalEntity((int) $r['legal_entity_id']);
        $r['mine'] = (int) $r['inspector_id'] === $az->userId;
        if (!$r['mine'] && !$r['manage']) {
            throw HttpException::notFound();
        }
        $this->syncActions((int) $r['id']);
        $r['items'] = (array) json_decode((string) $r['items_json'], true);
        $results = [];
        foreach ($db->all('SELECT * FROM in_results WHERE round_id = ? AND revision = ? ORDER BY item_no', [$r['id'], $r['revision']]) as $x) {
            $results[(int) $x['item_no']] = $x;
        }
        $r['results'] = $results;
        $r['earlier'] = (int) $r['revision'] > 1 ? $db->all('SELECT revision, item_no, result, value, note FROM in_results WHERE round_id = ? AND revision < ? ORDER BY revision, item_no', [$r['id'], $r['revision']]) : [];
        $r['actions'] = $db->all(
            'SELECT a.*, COALESCE(u.display_name, u.username) AS assignee_name, t.ref AS ticket_ref, t.status AS ticket_status FROM in_actions a LEFT JOIN users u ON u.id = a.assignee_id
               LEFT JOIN hd_tickets t ON t.public_id = a.ticket_pid WHERE a.round_id = ? ORDER BY a.item_no',
            [$r['id']]
        );
        $r['events'] = $db->all('SELECT e.event, e.note, e.created_at, COALESCE(u.display_name, u.username) AS actor FROM in_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.round_id = ? ORDER BY e.id', [$r['id']]);
        $st = (string) $r['status'];
        $open = array_filter($r['actions'], static fn (array $a): bool => $a['status'] === 'open');
        $r['can_fill'] = $r['mine'] && in_array($st, self::OPEN, true) && (string) $r['due_date'] <= $this->today();
        $r['early'] = $r['mine'] && in_array($st, self::OPEN, true) && (string) $r['due_date'] > $this->today();
        $r['can_review'] = $r['manage'] && $st === 'submitted';
        $r['can_close'] = $r['manage'] && $st === 'reviewed' && $open === [];
        $r['open_actions'] = count($open);
        return $r;
    }

    /** the round to do now at a scanned point (the earliest one due up to today), or the point's next round */
    public function byQr(Authorizer $az, string $token): string
    {
        $this->tick();
        $db = $this->app->db();
        $p = $db->one('SELECT * FROM in_points WHERE qr_token = ? AND active = 1', [$token]) ?? throw HttpException::notFound();
        if ((int) $p['inspector_id'] !== $az->userId && !$az->scope('inspection.manage')->coversLegalEntity((int) $p['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        $pid = $db->scalar("SELECT public_id FROM in_rounds WHERE point_id = ? AND status IN ('scheduled','in_progress','overdue') AND due_date <= ? ORDER BY due_date LIMIT 1", [$p['id'], $this->today()])
            ?? $db->scalar('SELECT public_id FROM in_rounds WHERE point_id = ? ORDER BY due_date DESC LIMIT 1', [$p['id']]);
        if ($pid === null) {
            throw new BusinessException('inspection.error.no_round', [], 409);
        }
        return (string) $pid;
    }

    /**
     * Saves the answers (draft) or submits them. Each item: result_N (pass / fail / na) or value_N for a number item
     * (pass inside its range), note_N (needed when failed) and a photo file photo_N (a new one replaces the old).
     *
     * @param array<string, mixed> $in
     * @param array<int, UploadedFile> $photos item number → file
     */
    public function save(Authorizer $az, string $pid, array $in, array $photos, bool $submit): void
    {
        $r = $this->round($az, $pid);
        if (!$r['can_fill']) {
            throw new BusinessException('inspection.error.cannot', [], 409);
        }
        $e = [];
        $rows = [];
        foreach ($r['items'] as $i => $item) {
            $n = $i + 1;
            $note = trim((string) ($in['note_' . $n] ?? ''));
            if (mb_strlen($note) > 500) {
                $e['note_' . $n] = 'inspection.error.text';
            }
            $res = null;
            $value = null;
            if ($item['kind'] === 'number') {
                $raw = trim((string) ($in['value_' . $n] ?? ''));
                if (!empty($in['na_' . $n])) {
                    $res = 'na';
                } elseif ($raw !== '') {
                    if (!is_numeric($raw) || abs((float) $raw) > 9999999999) {
                        $e['value_' . $n] = 'inspection.error.number';
                    } else {
                        $value = round((float) $raw, 2);
                        $res = ($item['min'] === null || $value >= (float) $item['min']) && ($item['max'] === null || $value <= (float) $item['max']) ? 'pass' : 'fail';
                    }
                }
            } else {
                $v = (string) ($in['result_' . $n] ?? '');
                $res = in_array($v, ['pass', 'fail', 'na'], true) ? $v : null;
            }
            if ($submit && $item['required'] && ($res === null || $res === 'na')) {
                $e[($item['kind'] === 'number' ? 'value_' : 'result_') . $n] = 'inspection.error.required';
            }
            if ($submit && $res === null) {
                $res = 'na'; // optional item left empty
            }
            if ($res === 'fail' && $note === '' && $submit) {
                $e['note_' . $n] = 'inspection.error.fail_note';
            }
            $rows[$n] = ['result' => $res, 'value' => $value, 'note' => $note];
        }
        foreach ($photos as $n => $f) {
            if (!isset($rows[$n]) || !$f->ok()) {
                $e['photo_' . $n] = $f->ok() ? 'inspection.error.photo' : $f->errorKey();
            }
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $db = $this->app->db();
        $le = (int) $r['legal_entity_id'];
        $db->transactional(function () use ($db, $az, $r, $rows, $photos, $submit, $le, $pid): void {
            $cur = $db->one('SELECT status, revision FROM in_rounds WHERE id = ? FOR UPDATE', [$r['id']]) ?? [];
            if (!in_array($cur['status'] ?? '', self::OPEN, true) || (int) $cur['revision'] !== (int) $r['revision']) {
                throw new BusinessException('inspection.error.cannot', [], 409);
            }
            foreach ($rows as $n => $x) {
                $doc = $r['results'][$n]['document_pid'] ?? null;
                $fname = $r['results'][$n]['filename'] ?? null;
                if (isset($photos[$n])) {
                    try {
                        $stored = $this->app->documents()->storeGenerated($az->userId, 'inspection.photo', $photos[$n]->bytes(), $photos[$n]->safeName(), ['title' => 'Inspection photo', 'legal_entity_id' => $le], 'upload', true);
                    } catch (ValidationException $x2) {
                        throw new ValidationException(['photo_' . $n => (string) (array_values($x2->errors)[0] ?? 'inspection.error.photo')]);
                    }
                    $doc = $stored['document'];
                    $fname = mb_substr($photos[$n]->safeName(), 0, 200);
                }
                if ($x['result'] === null && $doc === null && $x['note'] === '') {
                    $db->run('DELETE FROM in_results WHERE round_id = ? AND revision = ? AND item_no = ?', [$r['id'], $r['revision'], $n]);
                    continue;
                }
                $db->run(
                    'INSERT INTO in_results (round_id, revision, item_no, result, value, note, document_pid, filename) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE result = VALUES(result), value = VALUES(value), note = VALUES(note), document_pid = VALUES(document_pid), filename = VALUES(filename)',
                    [$r['id'], $r['revision'], $n, $x['result'] ?? 'na', $x['value'], $x['note'] === '' ? null : $x['note'], $doc, $fname]
                );
            }
            if (!$submit) {
                $db->run("UPDATE in_rounds SET status = IF(status = 'overdue', 'overdue', 'in_progress'), started_at = COALESCE(started_at, ?), updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->nowLocal(), $this->stamp(), $r['id']]);
                $this->event((int) $r['id'], 'saved', $az->userId, null);
                return;
            }
            $fails = array_keys(array_filter($rows, static fn (array $x): bool => $x['result'] === 'fail'));
            $late = $this->today() > (string) $r['due_date'];
            $db->run(
                "UPDATE in_rounds SET status = 'submitted', result = ?, fail_count = ?, late = ?, started_at = COALESCE(started_at, ?), submitted_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?",
                [$fails === [] ? 'pass' : 'fail', count($fails), $late ? 1 : 0, $this->nowLocal(), $this->nowLocal(), $this->stamp(), $r['id']]
            );
            $this->event((int) $r['id'], 'submitted', $az->userId, $fails === [] ? null : count($fails) . ' ✗');
            foreach ($fails as $n) {
                if ($db->scalar('SELECT id FROM in_actions WHERE round_id = ? AND item_no = ?', [$r['id'], $n]) !== null) {
                    continue; // already raised in an earlier revision
                }
                $item = $r['items'][$n - 1];
                $desc = $item['text'] . ($rows[$n]['value'] !== null ? ' = ' . $rows[$n]['value'] : '') . ' — ' . $rows[$n]['note'];
                $ticket = null;
                if ($r['ticket_category_pid'] !== null) {
                    $ticket = HelpdeskService::of($this->app)->openFromInspection(
                        (int) $r['inspector_id'],
                        $le,
                        $r['site_id'] !== null ? (int) $r['site_id'] : null,
                        (string) $r['ticket_category_pid'],
                        (string) ($r['ticket_priority'] ?? 'normal'),
                        $r['code'] . ' · ' . $item['text'],
                        $this->app->translator()->get('inspection.ticket_text', ['point' => $r['code'] . ' ' . $r['name'], 'date' => (string) $r['due_date'], 'item' => $desc]),
                        trim(($r['site_code'] ?? '') . ' ' . ($r['location'] ?? $r['name'])),
                        'inspection:' . $pid . ':' . $n
                    );
                }
                $now = $this->stamp();
                $db->run(
                    'INSERT INTO in_actions (public_id, created_at, created_by, updated_at, updated_by, round_id, item_no, description, ticket_pid) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [Ulid::generate($this->app->clock), $now, $az->userId, $now, $az->userId, $r['id'], $n, mb_substr($desc, 0, 500), $ticket]
                );
            }
            $this->app->audit()->record('inspection.submitted', 'success', 'in_round', $pid, $le, null, ['fails' => count($fails), 'late' => $late, 'revision' => (int) $r['revision']]);
            foreach ($this->admins($le) as $u) {
                if ($u !== $az->userId) {
                    $this->app->notifier()->notify($u, 'inspection.review', $fails === [] ? 'inspection.notify.submitted' : 'inspection.notify.submitted_fail', ['point' => (string) $r['name'], 'n' => (string) count($fails)], '/inspection/r/' . $pid, 'in.sub:' . $pid . ':' . $r['revision'] . ':' . $u);
                }
            }
        });
    }

    /** The admin accepts the results (closing at once when nothing is left open) or returns them with a reason (a new revision). */
    public function review(Authorizer $az, string $pid, bool $accept, string $note): void
    {
        $r = $this->round($az, $pid);
        if (!$r['can_review']) {
            throw new BusinessException('inspection.error.cannot', [], 409);
        }
        $note = trim($note);
        if ((!$accept && mb_strlen($note) < 3) || mb_strlen($note) > 500) {
            throw new ValidationException(['review_note' => 'inspection.error.reason']);
        }
        $db = $this->app->db();
        if ($accept) {
            $close = $r['open_actions'] === 0;
            $db->run(
                'UPDATE in_rounds SET status = ?, reviewed_by = ?, reviewed_at = ?, closed_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = ?',
                [$close ? 'closed' : 'reviewed', $az->userId, $this->nowLocal(), $close ? $this->nowLocal() : null, $this->stamp(), $r['id'], 'submitted']
            );
            $this->event((int) $r['id'], $close ? 'reviewed_closed' : 'reviewed', $az->userId, $note === '' ? null : $note);
        } else {
            $db->run(
                "UPDATE in_rounds SET status = 'in_progress', revision = revision + 1, result = NULL, fail_count = 0, submitted_at = NULL, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'submitted'",
                [$this->stamp(), $r['id']]
            );
            // the new revision starts from the answers given (they stay in the earlier revision too)
            $db->run(
                'INSERT INTO in_results (round_id, revision, item_no, result, value, note, document_pid, filename) SELECT round_id, revision + 1, item_no, result, value, note, document_pid, filename FROM in_results WHERE round_id = ? AND revision = ?',
                [$r['id'], $r['revision']]
            );
            $this->event((int) $r['id'], 'returned', $az->userId, $note);
            $this->app->notifier()->notify((int) $r['inspector_id'], 'inspection.due', 'inspection.notify.returned', ['point' => (string) $r['name']], '/inspection/r/' . $pid);
        }
        $this->app->audit()->record($accept ? 'inspection.reviewed' : 'inspection.returned', 'success', 'in_round', $pid, (int) $r['legal_entity_id'], $accept ? null : $note);
    }

    public function close(Authorizer $az, string $pid): void
    {
        $r = $this->round($az, $pid);
        if (!$r['can_close']) {
            throw new BusinessException('inspection.error.close', [], 409);
        }
        $this->app->db()->run("UPDATE in_rounds SET status = 'closed', closed_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'reviewed'", [$this->nowLocal(), $this->stamp(), $r['id']]);
        $this->event((int) $r['id'], 'closed', $az->userId, null);
        $this->app->audit()->record('inspection.closed', 'success', 'in_round', $pid, (int) $r['legal_entity_id']);
    }

    /**
     * A corrective action without a ticket: the admin assigns it (person, due date); the person (or the admin) marks it done.
     *
     * @param array<string, mixed> $in assignee (user name / employee code), due_date
     */
    public function assignAction(Authorizer $az, string $actionPid, array $in): void
    {
        $db = $this->app->db();
        $a = $db->one('SELECT a.*, r.legal_entity_id, r.public_id AS round_pid FROM in_actions a JOIN in_rounds r ON r.id = a.round_id WHERE a.public_id = ?', [$actionPid]) ?? throw HttpException::notFound();
        $this->require($az, (int) $a['legal_entity_id']);
        if ($a['status'] !== 'open' || $a['ticket_pid'] !== null) {
            throw new BusinessException('inspection.error.cannot', [], 409);
        }
        $who = trim((string) ($in['assignee'] ?? ''));
        $u = $db->scalar("SELECT u.id FROM users u LEFT JOIN employees x ON x.id = u.employee_id WHERE u.status = 'active' AND (u.username = ? OR x.employee_code = ?) LIMIT 1", [$who, strtoupper($who)]);
        $e = [];
        if ($u === null || !(new Authorizer($db, $this->app->clock, (int) $u))->scope('inspection.use')->coversLegalEntity((int) $a['legal_entity_id'])) {
            $e['assignee'] = 'inspection.error.inspector';
        }
        $due = self::ymd((string) ($in['due_date'] ?? ''));
        if ($due === null || $due->format('Y-m-d') < $this->today()) {
            $e['due_date'] = 'inspection.error.due';
        }
        if ($e !== [] || $due === null) {
            throw new ValidationException($e);
        }
        $db->run('UPDATE in_actions SET assignee_id = ?, due_date = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [(int) $u, $due->format('Y-m-d'), $this->stamp(), $az->userId, $a['id']]);
        $this->event((int) $a['round_id'], 'action_assigned', $az->userId, '#' . $a['item_no'] . ' · ' . $due->format('Y-m-d'));
        $this->app->notifier()->notify((int) $u, 'inspection.action', 'inspection.notify.action', ['due' => $due->format('Y-m-d')], '/inspection/actions');
    }

    public function doneAction(Authorizer $az, string $actionPid, string $note): string
    {
        $db = $this->app->db();
        $a = $db->one('SELECT a.*, r.legal_entity_id, r.public_id AS round_pid FROM in_actions a JOIN in_rounds r ON r.id = a.round_id WHERE a.public_id = ?', [$actionPid]) ?? throw HttpException::notFound();
        $manage = $az->scope('inspection.manage')->coversLegalEntity((int) $a['legal_entity_id']);
        if (!$manage && ($a['assignee_id'] === null || (int) $a['assignee_id'] !== $az->userId)) {
            throw HttpException::notFound();
        }
        if ($a['status'] !== 'open' || $a['ticket_pid'] !== null) {
            throw new BusinessException('inspection.error.cannot', [], 409);
        }
        $note = trim($note);
        if (mb_strlen($note) < 3 || mb_strlen($note) > 500) {
            throw new ValidationException(['done_note' => 'inspection.error.reason']);
        }
        $db->run("UPDATE in_actions SET status = 'done', done_note = ?, done_at = ?, done_by = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'open'", [$note, $this->nowLocal(), $az->userId, $this->stamp(), $a['id']]);
        $this->event((int) $a['round_id'], 'action_done', $az->userId, '#' . $a['item_no'] . ' — ' . $note);
        $this->app->audit()->record('inspection.action_done', 'success', 'in_round', (string) $a['round_pid'], (int) $a['legal_entity_id']);
        return (string) $a['round_pid'];
    }

    /** @return list<array<string, mixed>> open corrective actions given to me */
    public function myActions(Authorizer $az): array
    {
        return $this->app->db()->all(
            "SELECT a.public_id, a.item_no, a.description, a.due_date, r.public_id AS round_pid, r.due_date AS round_date, p.code, p.name FROM in_actions a JOIN in_rounds r ON r.id = a.round_id JOIN in_points p ON p.id = r.point_id
              WHERE a.assignee_id = ? AND a.status = 'open' AND a.ticket_pid IS NULL ORDER BY a.due_date",
            [$az->userId]
        );
    }

    /** @return array{bytes: string, mime: string, filename: string} a photo of a round the person may open */
    public function photo(Authorizer $az, string $pid, int $resultId): array
    {
        $r = $this->round($az, $pid);
        $x = $this->app->db()->one('SELECT * FROM in_results WHERE id = ? AND round_id = ? AND document_pid IS NOT NULL', [$resultId, $r['id']]) ?? throw HttpException::notFound();
        $doc = $this->app->documents()->moduleRead((string) $x['document_pid'], 'inspection', $az->userId, 'inspection photo');
        return ['bytes' => $doc['bytes'], 'mime' => $doc['mime'], 'filename' => (string) $x['filename']];
    }

    /**
     * The admin's board: rounds submitted (to review), reviewed with actions open, overdue, and the points.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function board(Authorizer $az, int $le): array
    {
        $this->require($az, $le);
        $this->tick();
        $db = $this->app->db();
        $sql = 'SELECT r.public_id, r.due_date, r.status, r.result, r.fail_count, r.late, r.submitted_at, p.code, p.name, COALESCE(u.display_name, u.username) AS inspector_name,
                       (SELECT COUNT(*) FROM in_actions a WHERE a.round_id = r.id AND a.status = \'open\') AS open_actions
                  FROM in_rounds r JOIN in_points p ON p.id = r.point_id JOIN users u ON u.id = r.inspector_id WHERE r.legal_entity_id = ? AND ';
        return [
            'submitted' => $db->all($sql . "r.status = 'submitted' ORDER BY r.submitted_at", [$le]),
            'reviewed' => $db->all($sql . "r.status = 'reviewed' ORDER BY r.due_date", [$le]),
            'overdue' => $db->all($sql . "r.status = 'overdue' ORDER BY r.due_date", [$le]),
            'today' => $db->all($sql . "r.status IN ('scheduled','in_progress') AND r.due_date = ? ORDER BY p.code", [$le, $this->today()]),
        ];
    }

    /**
     * A month by point: rounds due, done on time, done late, missed (still overdue), failed items, actions still open.
     *
     * @return list<array<string, mixed>>
     */
    public function report(Authorizer $az, int $le, string $month): array
    {
        $this->require($az, $le);
        $first = self::ymd($month . '-01') ?? throw new ValidationException(['month' => 'inspection.error.date']);
        $last = $first->modify('last day of this month')->format('Y-m-d');
        return $this->app->db()->all(
            "SELECT p.code, p.name, COUNT(r.id) AS due, SUM(r.status IN ('submitted','reviewed','closed') AND r.late = 0) AS on_time, SUM(r.status IN ('submitted','reviewed','closed') AND r.late = 1) AS late,
                    SUM(r.status = 'overdue') AS missed, COALESCE(SUM(r.fail_count), 0) AS fails,
                    (SELECT COUNT(*) FROM in_actions a JOIN in_rounds r2 ON r2.id = a.round_id WHERE r2.point_id = p.id AND a.status = 'open' AND r2.due_date BETWEEN ? AND ?) AS open_actions
               FROM in_points p LEFT JOIN in_rounds r ON r.point_id = p.id AND r.due_date BETWEEN ? AND ? WHERE p.legal_entity_id = ? GROUP BY p.id ORDER BY p.code",
            [$first->format('Y-m-d'), $last, $first->format('Y-m-d'), $last, $le]
        );
    }

    /** @return array{bytes: string, name: string} */
    public function export(Authorizer $az, int $le, string $month): array
    {
        $rows = $this->report($az, $le, $month);
        $t = $this->app->translator();
        $code = (string) $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$le]);
        $bytes = Xlsx::write(['inspections' => [
            'headers' => [$t->get('inspection.field.code'), $t->get('inspection.field.name'), $t->get('inspection.col.due'), $t->get('inspection.col.on_time'), $t->get('inspection.col.late'),
                $t->get('inspection.col.missed'), $t->get('inspection.col.fails'), $t->get('inspection.col.open_actions')],
            'rows' => array_map(static fn (array $x): array => [(string) $x['code'], (string) $x['name'], (string) (int) $x['due'], (string) (int) $x['on_time'], (string) (int) $x['late'], (string) (int) $x['missed'],
                (string) (int) $x['fails'], (string) (int) $x['open_actions']], $rows),
            'numeric' => [2, 3, 4, 5, 6, 7], 'widths' => [14, 30, 10, 10, 10, 10, 10, 12],
        ]], 'Blue OS inspections');
        $this->app->audit()->record('inspection.report_exported', 'success', 'in_report', $code . '-' . $month, $le, null, ['sha256' => hash('sha256', $bytes)]);
        return ['bytes' => $bytes, 'name' => 'inspections-' . $code . '-' . $month . '.xlsx'];
    }

    // ---- helpers -------------------------------------------------------------------------------------------------------------

    private function event(int $roundId, string $event, ?int $actor, ?string $note): void
    {
        $this->app->db()->run('INSERT INTO in_events (round_id, event, actor_id, note, created_at) VALUES (?, ?, ?, ?, ?)', [$roundId, $event, $actor, $note === null ? null : mb_substr($note, 0, 500), $this->stamp()]);
    }

    /** @return list<int> */
    private function admins(int $le): array
    {
        $out = [];
        foreach ($this->app->db()->all("SELECT DISTINCT a.user_id FROM user_role_assignments a JOIN users u ON u.id = a.user_id WHERE a.status = 'active' AND u.status = 'active'") as $row) {
            if ((new Authorizer($this->app->db(), $this->app->clock, (int) $row['user_id']))->scope('inspection.manage')->coversLegalEntity($le)) {
                $out[] = (int) $row['user_id'];
            }
        }
        return $out;
    }
}

<?php

declare(strict_types=1);

namespace Blue\Modules\Helpdesk;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Files\Xlsx;
use Blue\Core\Support\Ulid;

/**
 * M15 helpdesk (9B-1 · DEC-91).
 *
 * Someone reports a problem in a work category (with place, equipment, urgency, files). The ticket goes to the leads of
 * that category; a lead gives it to a member of the category (the requester never picks the technician) with a due
 * date. Requester and technician talk in the ticket; technicians also keep internal work notes. The technician resolves;
 * the requester confirms with a rating (1–5) or reopens within the company's window. A requester who has a closed ticket
 * without a rating cannot open a new one until rating it. Statuses: open → assigned → in_progress ⇄ waiting_user /
 * waiting_parts → resolved → closed; cancelled.
 *
 * SLA: hours per category and priority (no defaults), counted on working days of the company calendar (DEC-56);
 * a breach is flagged once and escalated to the category leads. Unanswered resolved tickets close after the company's
 * auto-close days (no default — never otherwise). Tickets are seen by the requester, the members of the category and
 * the helpdesk admins; work notes and their files are hidden from the requester.
 */
final class HelpdeskService
{
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const OPEN = ['open', 'assigned', 'in_progress', 'waiting_user', 'waiting_parts'];

    private const MAX_FILES = 5;

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

    public function nowLocal(): DateTimeImmutable
    {
        $n = $this->app->clock->now()->setTimezone($this->tz());
        return DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $n->format('Y-m-d H:i:s')) ?: $n;
    }

    private function now(): string
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

    private function require(Authorizer $az, string $perm, int $le): void
    {
        if (!$az->scope($perm)->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
    }

    /** a deadline N hours (working days of the company calendar; plain hours without a calendar) from now */
    private function due(int $le, ?int $hours, ?DateTimeImmutable $fromUtc = null): ?string
    {
        if ($hours === null) {
            return null;
        }
        $from = $fromUtc ?? $this->app->clock->now();
        $t = $this->app->calendars()->addWorkingHours($le, $from, $hours, $this->tz()->getName()) ?? $from->modify('+' . $hours . ' hours');
        return $t->setTimezone($this->tz())->format('Y-m-d H:i:s');
    }

    // ---- categories, team, policy -------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function categories(int $le, bool $activeOnly = false): array
    {
        $rows = $this->app->db()->all('SELECT * FROM hd_categories WHERE legal_entity_id = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY active DESC, name', [$le]);
        foreach ($rows as &$c) {
            $c['members'] = $this->app->db()->all(
                'SELECT m.user_id, m.role, COALESCE(u.display_name, u.username) AS name, u.username FROM hd_members m JOIN users u ON u.id = m.user_id WHERE m.category_id = ? ORDER BY m.role, name',
                [$c['id']]
            );
        }
        return $rows;
    }

    /** @return array<string, mixed> */
    public function category(string $pid): array
    {
        return $this->app->db()->one('SELECT * FROM hd_categories WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /**
     * A work category and its SLA hours per priority (blank = no target), counted on working days.
     *
     * @param array<string, mixed> $in name, description, response_hours, hours_low, hours_normal, hours_high, hours_urgent, active
     */
    public function saveCategory(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        $this->require($az, 'helpdesk.manage', $le);
        $db = $this->app->db();
        $e = [];
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $e['name'] = 'helpdesk.error.name';
        } elseif ($db->scalar('SELECT id FROM hd_categories WHERE legal_entity_id = ? AND name = ? AND public_id <> ?', [$le, $name, $pid ?? '']) !== null) {
            $e['name'] = 'helpdesk.error.name_taken';
        }
        $desc = trim((string) ($in['description'] ?? ''));
        if (mb_strlen($desc) > 500) {
            $e['description'] = 'helpdesk.error.text';
        }
        $hours = [];
        foreach (['response_hours', 'hours_low', 'hours_normal', 'hours_high', 'hours_urgent'] as $k) {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v !== '' && (!ctype_digit($v) || (int) $v < 1 || (int) $v > 2000)) {
                $e[$k] = 'helpdesk.error.hours';
            }
            $hours[] = $v === '' ? null : (int) $v;
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $now = $this->stamp();
        if ($pid === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run(
                'INSERT INTO hd_categories (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, name, description, response_hours, hours_low, hours_normal, hours_high, hours_urgent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $le, $name, $desc === '' ? null : $desc, ...$hours]
            );
            $this->app->audit()->record('helpdesk.category_added', 'success', 'hd_category', $pid, $le, null, ['name' => $name]);
            return $pid;
        }
        $c = $this->category($pid);
        if ((int) $c['legal_entity_id'] !== $le) {
            throw HttpException::notFound();
        }
        $db->run(
            'UPDATE hd_categories SET name = ?, description = ?, response_hours = ?, hours_low = ?, hours_normal = ?, hours_high = ?, hours_urgent = ?, active = ?, updated_at = ?, updated_by = ?,
                row_version = row_version + 1 WHERE id = ?',
            [$name, $desc === '' ? null : $desc, ...$hours, !empty($in['active']) ? 1 : 0, $now, $az->userId, $c['id']]
        );
        // tickets already open keep their deadlines
        $this->app->audit()->record('helpdesk.category_changed', 'success', 'hd_category', $pid, $le, null, ['name' => $name, 'hours' => $hours]);
        return $pid;
    }

    /** Adds a lead or an agent to a category (a user name or employee code of someone who may use the helpdesk there). */
    public function addMember(Authorizer $az, string $categoryPid, string $who, string $role): void
    {
        $c = $this->category($categoryPid);
        $le = (int) $c['legal_entity_id'];
        $this->require($az, 'helpdesk.manage', $le);
        if (!in_array($role, ['lead', 'agent'], true)) {
            throw new ValidationException(['role' => 'helpdesk.error.role']);
        }
        $db = $this->app->db();
        $u = $db->scalar(
            "SELECT u.id FROM users u LEFT JOIN employees x ON x.id = u.employee_id WHERE u.status = 'active' AND (u.username = ? OR x.employee_code = ?) LIMIT 1",
            [trim($who), strtoupper(trim($who))]
        );
        if ($u === null || !(new Authorizer($db, $this->app->clock, (int) $u))->scope('helpdesk.use')->coversLegalEntity($le)) {
            throw new ValidationException(['member' => 'helpdesk.error.member']);
        }
        $db->run('INSERT INTO hd_members (category_id, user_id, role, created_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role)', [$c['id'], (int) $u, $role, $this->stamp()]);
        $this->app->audit()->record('helpdesk.member_set', 'success', 'hd_category', $categoryPid, $le, null, ['user' => (int) $u, 'role' => $role]);
    }

    public function removeMember(Authorizer $az, string $categoryPid, int $userId): void
    {
        $c = $this->category($categoryPid);
        $this->require($az, 'helpdesk.manage', (int) $c['legal_entity_id']);
        $this->app->db()->run('DELETE FROM hd_members WHERE category_id = ? AND user_id = ?', [$c['id'], $userId]);
        $this->app->audit()->record('helpdesk.member_removed', 'success', 'hd_category', $categoryPid, (int) $c['legal_entity_id'], null, ['user' => $userId]);
    }

    /** @return array{reopen_days: ?int, auto_close_days: ?int} */
    public function policy(int $le): array
    {
        $p = $this->app->db()->one('SELECT * FROM hd_policies WHERE legal_entity_id = ?', [$le]);
        return ['reopen_days' => $p !== null && $p['reopen_days'] !== null ? (int) $p['reopen_days'] : null, 'auto_close_days' => $p !== null && $p['auto_close_days'] !== null ? (int) $p['auto_close_days'] : null];
    }

    /** @param array<string, mixed> $in reopen_days, auto_close_days (blank = no limit / never) */
    public function savePolicy(Authorizer $az, int $le, array $in): void
    {
        $this->require($az, 'helpdesk.manage', $le);
        $vals = [];
        foreach (['reopen_days', 'auto_close_days'] as $k) {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v !== '' && (!ctype_digit($v) || (int) $v < 1 || (int) $v > 365)) {
                throw new ValidationException([$k => 'helpdesk.error.days']);
            }
            $vals[] = $v === '' ? null : (int) $v;
        }
        $this->app->db()->run(
            'INSERT INTO hd_policies (legal_entity_id, reopen_days, auto_close_days, updated_at, updated_by) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE reopen_days = VALUES(reopen_days), auto_close_days = VALUES(auto_close_days), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [$le, ...$vals, $this->stamp(), $az->userId]
        );
        $this->app->audit()->record('helpdesk.policy_changed', 'success', 'hd_policy', (string) $le, $le, null, ['reopen_days' => $vals[0], 'auto_close_days' => $vals[1]]);
    }

    /** lead / agent of a category, or null */
    public function roleIn(int $userId, int $categoryId): ?string
    {
        $r = $this->app->db()->scalar('SELECT role FROM hd_members WHERE category_id = ? AND user_id = ?', [$categoryId, $userId]);
        return $r === null ? null : (string) $r;
    }

    /** @return list<int> */
    private function leads(int $categoryId): array
    {
        return array_map('intval', array_column($this->app->db()->all("SELECT m.user_id FROM hd_members m JOIN users u ON u.id = m.user_id WHERE m.category_id = ? AND m.role = 'lead' AND u.status = 'active'", [$categoryId]), 'user_id'));
    }

    // ---- tickets -------------------------------------------------------------------------------------------------------------

    /**
     * A closed ticket of mine still waiting for my rating (it blocks a new ticket), or null.
     *
     * @return array<string, mixed>|null
     */
    public function unrated(int $userId): ?array
    {
        return $this->app->db()->one("SELECT public_id, ref FROM hd_tickets WHERE requester_id = ? AND status = 'closed' AND rating IS NULL AND source = 'user' ORDER BY closed_at LIMIT 1", [$userId]);
    }

    private function nextRef(int $le): string
    {
        $prefix = 'HD' . $this->nowLocal()->format('ym') . '-';
        $last = (string) ($this->app->db()->scalar('SELECT MAX(ref) FROM hd_tickets WHERE legal_entity_id = ? AND ref LIKE ?', [$le, $prefix . '%']) ?? '');
        return $prefix . str_pad((string) ((int) substr($last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Opens a ticket. Refused while a closed ticket of mine waits for its rating.
     *
     * @param array<string, mixed> $in category, priority, title, description, location, asset, site
     * @param list<UploadedFile> $files
     */
    public function open(Authorizer $az, int $le, array $in, array $files = []): string
    {
        $this->require($az, 'helpdesk.use', $le);
        $u = $this->unrated($az->userId);
        if ($u !== null) {
            throw new BusinessException('helpdesk.error.rate_first', ['ref' => (string) $u['ref']], 409);
        }
        $db = $this->app->db();
        $e = [];
        $cat = $db->one('SELECT * FROM hd_categories WHERE public_id = ? AND legal_entity_id = ? AND active = 1', [(string) ($in['category'] ?? ''), $le]);
        if ($cat === null) {
            $e['category'] = 'helpdesk.error.category';
        }
        $priority = (string) ($in['priority'] ?? '');
        if (!in_array($priority, self::PRIORITIES, true)) {
            $e['priority'] = 'helpdesk.error.priority';
        }
        $title = trim((string) ($in['title'] ?? ''));
        if (mb_strlen($title) < 3 || mb_strlen($title) > 200) {
            $e['title'] = 'helpdesk.error.title';
        }
        $desc = trim((string) ($in['description'] ?? ''));
        if (mb_strlen($desc) < 3 || mb_strlen($desc) > 5000) {
            $e['description'] = 'helpdesk.error.description';
        }
        $loc = trim((string) ($in['location'] ?? ''));
        $asset = trim((string) ($in['asset'] ?? ''));
        if (mb_strlen($loc) > 200) {
            $e['location'] = 'helpdesk.error.text';
        }
        if (mb_strlen($asset) > 200) {
            $e['asset'] = 'helpdesk.error.text';
        }
        $site = null;
        if ((string) ($in['site'] ?? '') !== '') {
            $site = $db->scalar('SELECT id FROM sites WHERE public_id = ? AND legal_entity_id = ?', [(string) $in['site'], $le]);
            if ($site === null) {
                $e['site'] = 'helpdesk.error.site';
            }
        }
        if (count($files) > self::MAX_FILES) {
            $e['files'] = 'helpdesk.error.too_many_files';
        }
        if ($e !== [] || $cat === null) {
            throw new ValidationException($e);
        }
        return $this->insert($az->userId, $le, $site === null ? null : (int) $site, $cat, $priority, $title, $desc, $loc, $asset, 'user', null, $files);
    }

    /**
     * A ticket raised by another module (an inspection item that failed) — no rating gate for the inspector.
     */
    public function openFromInspection(int $requesterId, int $le, ?int $siteId, string $categoryPid, string $priority, string $title, string $desc, string $location, string $sourceRef): string
    {
        $cat = $this->app->db()->one('SELECT * FROM hd_categories WHERE public_id = ? AND legal_entity_id = ?', [$categoryPid, $le]) ?? throw new BusinessException('helpdesk.error.category', [], 409);
        return $this->insert($requesterId, $le, $siteId, $cat, in_array($priority, self::PRIORITIES, true) ? $priority : 'normal', mb_substr($title, 0, 200), $desc, mb_substr($location, 0, 200), '', 'inspection', $sourceRef, []);
    }

    /**
     * @param array<string, mixed> $cat
     * @param list<UploadedFile> $files
     */
    private function insert(int $userId, int $le, ?int $site, array $cat, string $priority, string $title, string $desc, string $loc, string $asset, string $source, ?string $sourceRef, array $files): string
    {
        $db = $this->app->db();
        $pid = $db->transactional(function () use ($db, $userId, $le, $site, $cat, $priority, $title, $desc, $loc, $asset, $source, $sourceRef, $files): string {
            $pid = Ulid::generate($this->app->clock);
            $now = $this->stamp();
            $db->run(
                'INSERT INTO hd_tickets (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, site_id, ref, category_id, requester_id, title, description, location, asset, priority, opened_at,
                    response_due, resolve_due, source, source_ref) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $userId, $now, $userId, $le, $site, $this->nextRef($le), $cat['id'], $userId, $title, $desc, $loc === '' ? null : $loc, $asset === '' ? null : $asset, $priority, $this->now(),
                    $this->due($le, $cat['response_hours'] !== null ? (int) $cat['response_hours'] : null), $this->due($le, $cat['hours_' . $priority] !== null ? (int) $cat['hours_' . $priority] : null), $source, $sourceRef]
            );
            $id = (int) $db->pdo()->lastInsertId();
            $this->addFiles($id, null, $le, $files, $userId, false);
            $this->event($id, 'opened', $userId, null);
            return $pid;
        });
        $t = $db->one('SELECT id, ref, category_id FROM hd_tickets WHERE public_id = ?', [$pid]) ?? [];
        $this->app->audit()->record('helpdesk.opened', 'success', 'hd_ticket', $pid, $le, null, ['category' => (int) $cat['id'], 'priority' => $priority, 'source' => $source, 'files' => count($files)]);
        foreach ($this->leads((int) $cat['id']) as $u) {
            $this->app->notifier()->notify($u, 'helpdesk.new', 'helpdesk.notify.new', ['ref' => (string) $t['ref'], 'category' => (string) $cat['name']], '/helpdesk/t/' . $pid, 'hd.new:' . $pid . ':' . $u);
        }
        return $pid;
    }

    /**
     * @param list<UploadedFile> $files
     */
    private function addFiles(int $ticketId, ?int $messageId, int $le, array $files, int $actorId, bool $internal): void
    {
        $db = $this->app->db();
        $have = (int) $db->scalar('SELECT COUNT(*) FROM hd_files WHERE ticket_id = ?', [$ticketId]);
        foreach ($files as $f) {
            if (!$f->ok()) {
                throw new ValidationException(['files' => $f->errorKey()]);
            }
            if ($have >= 30) {
                throw new ValidationException(['files' => 'helpdesk.error.too_many_files']);
            }
            $bytes = $f->bytes();
            try {
                $stored = $this->app->documents()->storeGenerated($actorId, 'helpdesk.attachment', $bytes, $f->safeName(), ['title' => 'Helpdesk attachment', 'legal_entity_id' => $le], 'upload', true);
            } catch (ValidationException $e) {
                throw new ValidationException(['files' => (string) (array_values($e->errors)[0] ?? 'helpdesk.error.file')]);
            }
            $db->run(
                'INSERT INTO hd_files (ticket_id, message_id, internal, document_pid, sha256, filename, added_by, added_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$ticketId, $messageId, $internal ? 1 : 0, $stored['document'], hash('sha256', $bytes), mb_substr($f->safeName(), 0, 200), $actorId, $this->stamp()]
            );
            $have++;
        }
    }

    /** @return array<string, mixed> a ticket the person may open — requester, members of its category, helpdesk admins */
    public function ticket(Authorizer $az, string $pid): array
    {
        $db = $this->app->db();
        $t = $db->one(
            'SELECT t.*, c.name AS category_name, c.public_id AS category_pid, l.code AS le_code, s.code AS site_code, COALESCE(r.display_name, r.username) AS requester_name,
                    COALESCE(a.display_name, a.username) AS assignee_name
               FROM hd_tickets t JOIN hd_categories c ON c.id = t.category_id JOIN legal_entities l ON l.id = t.legal_entity_id JOIN users r ON r.id = t.requester_id
               LEFT JOIN users a ON a.id = t.assignee_id LEFT JOIN sites s ON s.id = t.site_id WHERE t.public_id = ?',
            [$pid]
        ) ?? throw HttpException::notFound();
        $le = (int) $t['legal_entity_id'];
        $t['mine'] = (int) $t['requester_id'] === $az->userId;
        $t['manage'] = $az->scope('helpdesk.manage')->coversLegalEntity($le);
        $t['role'] = $this->roleIn($az->userId, (int) $t['category_id']);
        $t['staff'] = $t['manage'] || $t['role'] !== null;
        if (!$t['mine'] && !$t['staff']) {
            throw HttpException::notFound();
        }
        $lead = $t['manage'] || $t['role'] === 'lead';
        $worker = $lead || ($t['assignee_id'] !== null && (int) $t['assignee_id'] === $az->userId);
        $st = (string) $t['status'];
        $open = in_array($st, self::OPEN, true);
        $policy = $this->policy($le);
        $t['policy'] = $policy;
        $t['messages'] = $db->all(
            'SELECT m.id, m.kind, m.body, m.created_at, m.author_id, COALESCE(u.display_name, u.username) AS author FROM hd_messages m JOIN users u ON u.id = m.author_id WHERE m.ticket_id = ?'
            . ($t['staff'] ? '' : " AND m.kind = 'message'") . ' ORDER BY m.id',
            [$t['id']]
        );
        $t['files'] = $db->all('SELECT id, message_id, internal, filename, added_at FROM hd_files WHERE ticket_id = ?' . ($t['staff'] ? '' : ' AND internal = 0') . ' ORDER BY id', [$t['id']]);
        $t['events'] = $db->all('SELECT e.event, e.note, e.created_at, COALESCE(u.display_name, u.username) AS actor FROM hd_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.ticket_id = ? ORDER BY e.id', [$t['id']]);
        $t['team'] = $lead ? $db->all('SELECT m.user_id, m.role, COALESCE(u.display_name, u.username) AS name FROM hd_members m JOIN users u ON u.id = m.user_id WHERE m.category_id = ? ORDER BY m.role, name', [$t['category_id']]) : [];
        $t['can_assign'] = $lead && $open;
        $t['can_work'] = $worker && $open;
        $t['can_message'] = ($t['mine'] || $t['staff']) && !in_array($st, ['closed', 'cancelled'], true);
        $t['can_confirm'] = $t['mine'] && $st === 'resolved';
        $t['can_reopen'] = $t['mine'] && $st === 'resolved'
            && ($policy['reopen_days'] === null || $t['resolved_at'] === null || $this->nowLocal() <= (DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $t['resolved_at']) ?: $this->nowLocal())->modify('+' . $policy['reopen_days'] . ' days'));
        $t['can_rate'] = $t['mine'] && $st === 'closed' && $t['rating'] === null;
        $t['can_cancel'] = ($t['mine'] && in_array($st, ['open', 'assigned'], true)) || ($lead && $open);
        $t['lead'] = $lead;
        return $t;
    }

    /**
     * A lead gives the ticket to a member of the category and sets its due date (a change of person needs a reason).
     *
     * @param array<string, mixed> $in assignee (user id), due (Y-m-dTH:i, optional), reason
     */
    public function assign(Authorizer $az, string $pid, array $in): void
    {
        $t = $this->ticket($az, $pid);
        if (!$t['can_assign']) {
            throw new BusinessException('helpdesk.error.cannot', [], 409);
        }
        $to = (int) ($in['assignee'] ?? 0);
        $e = [];
        if ($this->roleIn($to, (int) $t['category_id']) === null) {
            $e['assignee'] = 'helpdesk.error.assignee';
        }
        $due = null;
        $dueIn = trim((string) ($in['due'] ?? ''));
        if ($dueIn !== '') {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $dueIn);
            if ($d === false || $d < $this->nowLocal()) {
                $e['due'] = 'helpdesk.error.due';
            } else {
                $due = $d->format('Y-m-d H:i:s');
            }
        }
        $reason = trim((string) ($in['reason'] ?? ''));
        $change = $t['assignee_id'] !== null && (int) $t['assignee_id'] !== $to;
        if (($change && mb_strlen($reason) < 3) || mb_strlen($reason) > 300) {
            $e['reason'] = 'helpdesk.error.reason';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $st = $t['status'] === 'open' ? 'assigned' : (string) $t['status'];
        $this->app->db()->run(
            'UPDATE hd_tickets SET assignee_id = ?, due_at = ?, status = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$to, $due, $st, $this->stamp(), $az->userId, $t['id']]
        );
        $name = (string) $this->app->db()->scalar('SELECT COALESCE(display_name, username) FROM users WHERE id = ?', [$to]);
        $this->event((int) $t['id'], $change ? 'reassigned' : 'assigned', $az->userId, $name . ($due !== null ? ' · ' . substr($due, 0, 16) : '') . ($reason !== '' ? ' — ' . $reason : ''));
        $this->app->audit()->record($change ? 'helpdesk.reassigned' : 'helpdesk.assigned', 'success', 'hd_ticket', $pid, (int) $t['legal_entity_id'], $reason === '' ? null : $reason, ['assignee' => $to]);
        $this->app->notifier()->notify($to, 'helpdesk.assigned', 'helpdesk.notify.assigned', ['ref' => (string) $t['ref'], 'title' => (string) $t['title']], '/helpdesk/t/' . $pid);
        if ($t['assignee_id'] === null) {
            $this->app->notifier()->notify((int) $t['requester_id'], 'helpdesk.update', 'helpdesk.notify.taken', ['ref' => (string) $t['ref']], '/helpdesk/t/' . $pid);
        }
    }

    /** A lead changes the urgency (reason required; the resolve deadline is counted again from the opening). */
    public function setPriority(Authorizer $az, string $pid, string $priority, string $reason): void
    {
        $t = $this->ticket($az, $pid);
        if (!$t['can_assign']) {
            throw new BusinessException('helpdesk.error.cannot', [], 409);
        }
        $reason = trim($reason);
        if (!in_array($priority, self::PRIORITIES, true)) {
            throw new ValidationException(['priority' => 'helpdesk.error.priority']);
        }
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 300) {
            throw new ValidationException(['priority_reason' => 'helpdesk.error.reason']);
        }
        $cat = $this->app->db()->one('SELECT * FROM hd_categories WHERE id = ?', [$t['category_id']]) ?? [];
        $opened = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $t['opened_at'], $this->tz());
        $due = $this->due((int) $t['legal_entity_id'], $cat['hours_' . $priority] !== null ? (int) $cat['hours_' . $priority] : null, $opened !== false ? $opened->setTimezone(new DateTimeZone('UTC')) : null);
        $this->app->db()->run('UPDATE hd_tickets SET priority = ?, resolve_due = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$priority, $due, $this->stamp(), $az->userId, $t['id']]);
        $this->event((int) $t['id'], 'priority', $az->userId, $t['priority'] . ' → ' . $priority . ' — ' . $reason);
        $this->app->audit()->record('helpdesk.priority_changed', 'success', 'hd_ticket', $pid, (int) $t['legal_entity_id'], $reason, ['from' => $t['priority'], 'to' => $priority]);
    }

    /** A lead moves the ticket to another category of the company (reason required; it goes back to that category's leads). */
    public function transfer(Authorizer $az, string $pid, string $categoryPid, string $reason): void
    {
        $t = $this->ticket($az, $pid);
        if (!$t['can_assign']) {
            throw new BusinessException('helpdesk.error.cannot', [], 409);
        }
        $reason = trim($reason);
        $cat = $this->app->db()->one('SELECT * FROM hd_categories WHERE public_id = ? AND legal_entity_id = ? AND active = 1 AND id <> ?', [$categoryPid, $t['legal_entity_id'], $t['category_id']]);
        if ($cat === null) {
            throw new ValidationException(['category' => 'helpdesk.error.category']);
        }
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 300) {
            throw new ValidationException(['transfer_reason' => 'helpdesk.error.reason']);
        }
        $opened = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $t['opened_at'], $this->tz());
        $from = $opened !== false ? $opened->setTimezone(new DateTimeZone('UTC')) : null;
        $le = (int) $t['legal_entity_id'];
        $this->app->db()->run(
            "UPDATE hd_tickets SET category_id = ?, assignee_id = NULL, due_at = NULL, status = 'open', response_due = ?, resolve_due = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?",
            [$cat['id'], $this->due($le, $cat['response_hours'] !== null ? (int) $cat['response_hours'] : null, $from), $this->due($le, $cat['hours_' . $t['priority']] !== null ? (int) $cat['hours_' . $t['priority']] : null, $from),
                $this->stamp(), $az->userId, $t['id']]
        );
        $this->event((int) $t['id'], 'transferred', $az->userId, $t['category_name'] . ' → ' . $cat['name'] . ' — ' . $reason);
        $this->app->audit()->record('helpdesk.transferred', 'success', 'hd_ticket', $pid, $le, $reason, ['to' => (int) $cat['id']]);
        foreach ($this->leads((int) $cat['id']) as $u) {
            $this->app->notifier()->notify($u, 'helpdesk.new', 'helpdesk.notify.new', ['ref' => (string) $t['ref'], 'category' => (string) $cat['name']], '/helpdesk/t/' . $pid);
        }
    }

    /** The technician marks progress: in_progress, waiting_user, waiting_parts. */
    public function setStatus(Authorizer $az, string $pid, string $status, string $note): void
    {
        $t = $this->ticket($az, $pid);
        if (!$t['can_work'] || $t['assignee_id'] === null) {
            throw new BusinessException('helpdesk.error.cannot', [], 409);
        }
        if (!in_array($status, ['in_progress', 'waiting_user', 'waiting_parts'], true)) {
            throw new ValidationException(['status' => 'helpdesk.error.status']);
        }
        $note = trim($note);
        if (mb_strlen($note) > 300) {
            throw new ValidationException(['status_note' => 'helpdesk.error.text']);
        }
        $this->app->db()->run(
            'UPDATE hd_tickets SET status = ?, first_response_at = COALESCE(first_response_at, ?), updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$status, $this->now(), $this->stamp(), $az->userId, $t['id']]
        );
        $this->event((int) $t['id'], 'status_' . $status, $az->userId, $note === '' ? null : $note);
        if ($status === 'waiting_user') {
            $this->app->notifier()->notify((int) $t['requester_id'], 'helpdesk.update', 'helpdesk.notify.waiting_user', ['ref' => (string) $t['ref']], '/helpdesk/t/' . $pid);
        }
    }

    /**
     * A message in the ticket (requester or staff), or an internal work note (staff only). A reply of the requester
     * to a ticket waiting for them puts it back in progress; the first staff message counts as the first response.
     *
     * @param list<UploadedFile> $files
     */
    public function message(Authorizer $az, string $pid, string $body, array $files, bool $internal): void
    {
        $t = $this->ticket($az, $pid);
        if (!$t['can_message'] || ($internal && !$t['staff'])) {
            throw new BusinessException('helpdesk.error.cannot', [], 409);
        }
        $body = trim($body);
        if (mb_strlen($body) < 1 || mb_strlen($body) > 5000) {
            throw new ValidationException(['body' => 'helpdesk.error.message']);
        }
        if (count($files) > self::MAX_FILES) {
            throw new ValidationException(['files' => 'helpdesk.error.too_many_files']);
        }
        $db = $this->app->db();
        $staffSide = $t['staff'] && !$t['mine'];
        $db->transactional(function () use ($db, $az, $t, $body, $files, $internal, $staffSide): void {
            $db->run('INSERT INTO hd_messages (ticket_id, author_id, kind, body, created_at) VALUES (?, ?, ?, ?, ?)', [$t['id'], $az->userId, $internal ? 'note' : 'message', $body, $this->stamp()]);
            $this->addFiles((int) $t['id'], (int) $db->pdo()->lastInsertId(), (int) $t['legal_entity_id'], $files, $az->userId, $internal);
            if ($staffSide && !$internal) {
                $db->run('UPDATE hd_tickets SET first_response_at = COALESCE(first_response_at, ?), updated_at = ? WHERE id = ?', [$this->now(), $this->stamp(), $t['id']]);
            }
            if ($t['mine'] && $t['status'] === 'waiting_user') {
                $db->run("UPDATE hd_tickets SET status = 'in_progress', updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->stamp(), $t['id']]);
                $this->event((int) $t['id'], 'status_in_progress', $az->userId, null);
            }
        });
        if ($internal) {
            return;
        }
        $to = $t['mine'] ? ($t['assignee_id'] !== null ? [(int) $t['assignee_id']] : $this->leads((int) $t['category_id'])) : [(int) $t['requester_id']];
        foreach (array_diff($to, [$az->userId]) as $u) {
            $this->app->notifier()->notify($u, 'helpdesk.message', 'helpdesk.notify.message', ['ref' => (string) $t['ref']], '/helpdesk/t/' . $pid);
        }
    }

    /** The technician (or a lead) resolves with what was done; the requester is asked to confirm and rate. */
    public function resolve(Authorizer $az, string $pid, string $resolution): void
    {
        $t = $this->ticket($az, $pid);
        if (!$t['can_work'] || $t['assignee_id'] === null) {
            throw new BusinessException('helpdesk.error.cannot', [], 409);
        }
        $resolution = trim($resolution);
        if (mb_strlen($resolution) < 3 || mb_strlen($resolution) > 2000) {
            throw new ValidationException(['resolution' => 'helpdesk.error.resolution']);
        }
        $n = $this->app->db()->run(
            "UPDATE hd_tickets SET status = 'resolved', resolution = ?, resolved_at = ?, first_response_at = COALESCE(first_response_at, ?), updated_at = ?, updated_by = ?, row_version = row_version + 1
              WHERE id = ? AND status IN ('open','assigned','in_progress','waiting_user','waiting_parts')",
            [$resolution, $this->now(), $this->now(), $this->stamp(), $az->userId, $t['id']]
        )->rowCount();
        if ($n !== 1) {
            throw new BusinessException('helpdesk.error.cannot', [], 409);
        }
        $this->event((int) $t['id'], 'resolved', $az->userId, null);
        $this->app->audit()->record('helpdesk.resolved', 'success', 'hd_ticket', $pid, (int) $t['legal_entity_id']);
        $this->app->notifier()->notify((int) $t['requester_id'], 'helpdesk.resolved', 'helpdesk.notify.resolved', ['ref' => (string) $t['ref']], '/helpdesk/t/' . $pid, 'hd.res:' . $pid . ':' . ((int) $t['reopen_count']));
    }

    private static function rating(string $rating, string $note): int
    {
        if (!ctype_digit($rating) || (int) $rating < 1 || (int) $rating > 5) {
            throw new ValidationException(['rating' => 'helpdesk.error.rating']);
        }
        if (mb_strlen(trim($note)) > 500) {
            throw new ValidationException(['rating_note' => 'helpdesk.error.text']);
        }
        return (int) $rating;
    }

    /** The requester accepts the fix: closed with the rating. */
    public function confirm(Authorizer $az, string $pid, string $rating, string $note): void
    {
        $t = $this->ticket($az, $pid);
        if (!$t['can_confirm']) {
            throw new BusinessException('helpdesk.error.cannot', [], 409);
        }
        $r = self::rating($rating, $note);
        $this->app->db()->run(
            "UPDATE hd_tickets SET status = 'closed', close_kind = 'confirmed', closed_at = ?, rating = ?, rating_note = ?, rated_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = 'resolved'",
            [$this->now(), $r, trim($note) === '' ? null : trim($note), $this->now(), $this->stamp(), $az->userId, $t['id']]
        );
        $this->event((int) $t['id'], 'closed', $az->userId, (string) $r . '/5');
        $this->app->audit()->record('helpdesk.closed', 'success', 'hd_ticket', $pid, (int) $t['legal_entity_id'], null, ['rating' => $r]);
    }

    /** A closed ticket without a rating (closed by the system) is rated — this lifts the block on new tickets. */
    public function rate(Authorizer $az, string $pid, string $rating, string $note): void
    {
        $t = $this->ticket($az, $pid);
        if (!$t['can_rate']) {
            throw new BusinessException('helpdesk.error.cannot', [], 409);
        }
        $r = self::rating($rating, $note);
        $this->app->db()->run('UPDATE hd_tickets SET rating = ?, rating_note = ?, rated_at = ?, updated_at = ? WHERE id = ? AND rating IS NULL', [$r, trim($note) === '' ? null : trim($note), $this->now(), $this->stamp(), $t['id']]);
        $this->event((int) $t['id'], 'rated', $az->userId, (string) $r . '/5');
    }

    /** The requester says the problem is still there (within the company's reopen window). */
    public function reopen(Authorizer $az, string $pid, string $reason): void
    {
        $t = $this->ticket($az, $pid);
        if (!$t['can_reopen']) {
            throw new BusinessException('helpdesk.error.reopen_window', [], 409);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new ValidationException(['reopen_reason' => 'helpdesk.error.reason']);
        }
        $this->app->db()->run(
            "UPDATE hd_tickets SET status = ?, resolved_at = NULL, reopen_count = reopen_count + 1, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = 'resolved'",
            [$t['assignee_id'] !== null ? 'in_progress' : 'open', $this->stamp(), $az->userId, $t['id']]
        );
        $this->event((int) $t['id'], 'reopened', $az->userId, $reason);
        $this->app->audit()->record('helpdesk.reopened', 'success', 'hd_ticket', $pid, (int) $t['legal_entity_id']);
        foreach ($t['assignee_id'] !== null ? [(int) $t['assignee_id']] : $this->leads((int) $t['category_id']) as $u) {
            $this->app->notifier()->notify($u, 'helpdesk.message', 'helpdesk.notify.reopened', ['ref' => (string) $t['ref']], '/helpdesk/t/' . $pid);
        }
    }

    /** Cancel: the requester before work starts, a lead with a reason. No rating is asked. */
    public function cancel(Authorizer $az, string $pid, string $reason): void
    {
        $t = $this->ticket($az, $pid);
        if (!$t['can_cancel']) {
            throw new BusinessException('helpdesk.error.cannot', [], 409);
        }
        $reason = trim($reason);
        if ((!$t['mine'] && mb_strlen($reason) < 3) || mb_strlen($reason) > 300) {
            throw new ValidationException(['cancel_reason' => 'helpdesk.error.reason']);
        }
        $this->app->db()->run(
            "UPDATE hd_tickets SET status = 'cancelled', close_kind = 'cancelled', closed_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?",
            [$this->now(), $this->stamp(), $az->userId, $t['id']]
        );
        $this->event((int) $t['id'], 'cancelled', $az->userId, $reason === '' ? null : $reason);
        $this->app->audit()->record('helpdesk.cancelled', 'success', 'hd_ticket', $pid, (int) $t['legal_entity_id'], $reason === '' ? null : $reason, ['by_requester' => $t['mine']]);
        if (!$t['mine']) {
            $this->app->notifier()->notify((int) $t['requester_id'], 'helpdesk.update', 'helpdesk.notify.cancelled', ['ref' => (string) $t['ref']], '/helpdesk/t/' . $pid);
        }
    }

    /** @return array{bytes: string, mime: string, filename: string} an attachment the person may see */
    public function file(Authorizer $az, string $pid, int $fileId): array
    {
        $t = $this->ticket($az, $pid);
        $f = $this->app->db()->one('SELECT * FROM hd_files WHERE id = ? AND ticket_id = ?', [$fileId, $t['id']]) ?? throw HttpException::notFound();
        if ((int) $f['internal'] === 1 && !$t['staff']) {
            throw HttpException::notFound();
        }
        $doc = $this->app->documents()->moduleRead((string) $f['document_pid'], 'helpdesk', $az->userId, $t['mine'] ? 'own helpdesk ticket' : 'helpdesk ticket');
        return ['bytes' => $doc['bytes'], 'mime' => $doc['mime'], 'filename' => (string) $f['filename']];
    }

    /**
     * Housekeeping (every 15 minutes and before the queue): resolved tickets nobody answered close after the company's
     * auto-close days (still waiting for a rating); unresolved tickets past their resolve deadline are flagged once and
     * escalated to the category leads.
     */
    public function tick(): int
    {
        $db = $this->app->db();
        $n = 0;
        foreach ($db->all('SELECT legal_entity_id, auto_close_days FROM hd_policies WHERE auto_close_days IS NOT NULL') as $p) {
            $cut = $this->nowLocal()->modify('-' . (int) $p['auto_close_days'] . ' days')->format('Y-m-d H:i:s');
            foreach ($db->all("SELECT id, public_id, ref, requester_id FROM hd_tickets WHERE legal_entity_id = ? AND status = 'resolved' AND resolved_at < ? LIMIT 500", [$p['legal_entity_id'], $cut]) as $t) {
                $db->run("UPDATE hd_tickets SET status = 'closed', close_kind = 'auto', closed_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'resolved'", [$this->now(), $this->stamp(), $t['id']]);
                $this->event((int) $t['id'], 'auto_closed', null, null);
                $this->app->notifier()->notify((int) $t['requester_id'], 'helpdesk.resolved', 'helpdesk.notify.auto_closed', ['ref' => (string) $t['ref']], '/helpdesk/t/' . $t['public_id'], 'hd.ac:' . $t['public_id']);
                $n++;
            }
        }
        foreach (
            $db->all(
                "SELECT id, public_id, ref, category_id, assignee_id, legal_entity_id FROM hd_tickets WHERE status IN ('open','assigned','in_progress','waiting_user','waiting_parts')
                    AND sla_breached = 0 AND resolve_due IS NOT NULL AND resolve_due < ? LIMIT 500",
                [$this->now()]
            ) as $t
        ) {
            $db->run('UPDATE hd_tickets SET sla_breached = 1, updated_at = ? WHERE id = ?', [$this->stamp(), $t['id']]);
            $this->event((int) $t['id'], 'sla_breached', null, null);
            $to = $this->leads((int) $t['category_id']);
            if ($t['assignee_id'] !== null) {
                $to[] = (int) $t['assignee_id'];
            }
            foreach (array_unique($to) as $u) {
                $this->app->notifier()->notify($u, 'helpdesk.sla', 'helpdesk.notify.breached', ['ref' => (string) $t['ref']], '/helpdesk/t/' . $t['public_id'], 'hd.sla:' . $t['public_id'] . ':' . $u);
            }
            $n++;
        }
        return $n;
    }

    public static function tickJob(App $app): string
    {
        return 'helpdesk: ' . self::of($app)->tick();
    }

    // ---- lists and report ----------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function mine(Authorizer $az): array
    {
        return $this->app->db()->all(
            'SELECT t.public_id, t.ref, t.title, t.status, t.priority, t.opened_at, t.rating, t.close_kind, c.name AS category_name FROM hd_tickets t JOIN hd_categories c ON c.id = t.category_id
              WHERE t.requester_id = ? ORDER BY t.id DESC LIMIT 200',
            [$az->userId]
        );
    }

    /**
     * The work queue of a member / admin: assigned to me, waiting for a person in categories I lead, the rest open in my
     * categories (admins: the whole company), and recently resolved.
     *
     * @return array{mine: list<array<string, mixed>>, unassigned: list<array<string, mixed>>, team: list<array<string, mixed>>, resolved: list<array<string, mixed>>, member: bool}
     */
    public function queue(Authorizer $az): array
    {
        $this->tick();
        $db = $this->app->db();
        $cats = array_map('intval', array_column($db->all('SELECT category_id FROM hd_members WHERE user_id = ?', [$az->userId]), 'category_id'));
        foreach ($this->companies($az, 'helpdesk.manage') as $le) {
            $cats = array_merge($cats, array_map('intval', array_column($db->all('SELECT id FROM hd_categories WHERE legal_entity_id = ?', [$le]), 'id')));
        }
        $cats = array_values(array_unique($cats));
        $empty = ['mine' => [], 'unassigned' => [], 'team' => [], 'resolved' => [], 'member' => false];
        if ($cats === []) {
            return $empty;
        }
        $in = implode(',', $cats);
        $sql = "SELECT t.public_id, t.ref, t.title, t.status, t.priority, t.opened_at, t.due_at, t.resolve_due, t.sla_breached, c.name AS category_name, COALESCE(a.display_name, a.username) AS assignee_name,
                       COALESCE(r.display_name, r.username) AS requester_name
                  FROM hd_tickets t JOIN hd_categories c ON c.id = t.category_id JOIN users r ON r.id = t.requester_id LEFT JOIN users a ON a.id = t.assignee_id WHERE t.category_id IN ({$in}) AND ";
        $order = " ORDER BY FIELD(t.priority, 'urgent', 'high', 'normal', 'low'), COALESCE(t.resolve_due, '9999-12-31'), t.id";
        return [
            'mine' => $db->all($sql . "t.assignee_id = ? AND t.status IN ('assigned','in_progress','waiting_user','waiting_parts')" . $order, [$az->userId]),
            'unassigned' => $db->all($sql . "t.status = 'open'" . $order),
            'team' => $db->all($sql . "(t.assignee_id IS NULL OR t.assignee_id <> ?) AND t.status IN ('assigned','in_progress','waiting_user','waiting_parts')" . $order, [$az->userId]),
            'resolved' => $db->all($sql . "t.status = 'resolved' ORDER BY t.resolved_at DESC LIMIT 50"),
            'member' => true,
        ];
    }

    /**
     * A month (by opening): per category and per technician — tickets, resolved, average hours to resolve, resolved
     * within SLA, average rating, reopened.
     *
     * @return array{categories: list<array<string, mixed>>, agents: list<array<string, mixed>>}
     */
    public function report(Authorizer $az, int $le, string $month): array
    {
        $this->require($az, 'helpdesk.manage', $le);
        $first = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01') ?: throw new ValidationException(['month' => 'helpdesk.error.month']);
        $a = $first->format('Y-m-d 00:00:00');
        $z = $first->modify('last day of this month')->format('Y-m-d 23:59:59');
        $agg = "COUNT(t.id) AS tickets, SUM(t.resolved_at IS NOT NULL OR t.close_kind IN ('confirmed','auto')) AS resolved,
                ROUND(AVG(CASE WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.opened_at, t.resolved_at) END) / 60, 1) AS avg_hours,
                SUM(t.resolve_due IS NOT NULL AND t.resolved_at IS NOT NULL AND t.resolved_at <= t.resolve_due) AS within_sla, SUM(t.resolve_due IS NOT NULL AND t.resolved_at IS NOT NULL) AS with_sla,
                ROUND(AVG(t.rating), 2) AS avg_rating, SUM(t.reopen_count > 0) AS reopened";
        $db = $this->app->db();
        return [
            'categories' => $db->all("SELECT c.name, {$agg} FROM hd_categories c LEFT JOIN hd_tickets t ON t.category_id = c.id AND t.status <> 'cancelled' AND t.opened_at BETWEEN ? AND ? WHERE c.legal_entity_id = ? GROUP BY c.id ORDER BY c.name", [$a, $z, $le]),
            'agents' => $db->all(
                "SELECT COALESCE(u.display_name, u.username) AS name, {$agg} FROM hd_tickets t JOIN users u ON u.id = t.assignee_id
                  WHERE t.legal_entity_id = ? AND t.status <> 'cancelled' AND t.opened_at BETWEEN ? AND ? GROUP BY t.assignee_id ORDER BY name",
                [$le, $a, $z]
            ),
        ];
    }

    /** @return array{bytes: string, name: string} */
    public function export(Authorizer $az, int $le, string $month): array
    {
        $r = $this->report($az, $le, $month);
        $t = $this->app->translator();
        $h = [$t->get('helpdesk.col.tickets'), $t->get('helpdesk.col.resolved'), $t->get('helpdesk.col.avg_hours'), $t->get('helpdesk.col.within_sla'), $t->get('helpdesk.col.avg_rating'), $t->get('helpdesk.col.reopened')];
        $row = static fn (array $x): array => [(string) $x['name'], (string) (int) $x['tickets'], (string) (int) $x['resolved'], (string) ($x['avg_hours'] ?? ''), (int) $x['with_sla'] > 0 ? $x['within_sla'] . '/' . $x['with_sla'] : '',
            (string) ($x['avg_rating'] ?? ''), (string) (int) $x['reopened']];
        $code = (string) $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$le]);
        $bytes = Xlsx::write([
            'categories' => ['headers' => [$t->get('helpdesk.field.category'), ...$h], 'rows' => array_map($row, $r['categories']), 'numeric' => [1, 2, 3, 5, 6], 'widths' => [28, 10, 10, 14, 14, 12, 10]],
            'technicians' => ['headers' => [$t->get('helpdesk.col.assignee'), ...$h], 'rows' => array_map($row, $r['agents']), 'numeric' => [1, 2, 3, 5, 6], 'widths' => [28, 10, 10, 14, 14, 12, 10]],
        ], 'Blue OS helpdesk');
        $this->app->audit()->record('helpdesk.report_exported', 'success', 'hd_report', $code . '-' . $month, $le, null, ['sha256' => hash('sha256', $bytes)]);
        return ['bytes' => $bytes, 'name' => 'helpdesk-' . $code . '-' . $month . '.xlsx'];
    }

    private function event(int $ticketId, string $event, ?int $actor, ?string $note): void
    {
        $this->app->db()->run('INSERT INTO hd_events (ticket_id, event, actor_id, note, created_at) VALUES (?, ?, ?, ?, ?)', [$ticketId, $event, $actor, $note === null ? null : mb_substr($note, 0, 500), $this->stamp()]);
    }
}

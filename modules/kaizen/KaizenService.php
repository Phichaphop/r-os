<?php

declare(strict_types=1);

namespace Blue\Modules\Kaizen;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Support\FrozenClock;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\EmployeeService;

/**
 * M41 suggestions / Kaizen (9D-1 · DEC-97).
 *
 * An employee sends an idea (problem, proposal, expected benefit, optional photo) under their name or anonymously. An
 * anonymous idea keeps no submitter: only the SHA-256 of a tracking code shown once, a submission time rounded to the day,
 * no photo (the file service records who uploaded) and no audit event that names the sender. A manager (kaizen.manage)
 * screens it and names an owner; the owner (or a manager) scores it against the company's published criteria (weights; no
 * defaults); a manager accepts or rejects it with a reason; the owner records the plan and the result; a manager who is not
 * the owner verifies the benefit before it counts as done (or closes it without implementation). A reward is proposed by a
 * manager and decided by a holder of kaizen.reward who neither proposed it nor sent the idea. Ideas past screening (not
 * rejected or closed) are on the company board, where colleagues vote once each.
 *
 * Statuses: submitted → screening → evaluation → accepted → implementing → verified; rejected · closed.
 */
final class KaizenService
{
    public const BOARD = ['screening', 'evaluation', 'accepted', 'implementing', 'verified'];

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

    public function tz(): DateTimeZone
    {
        return new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }

    public function today(): string
    {
        return $this->app->clock->now()->setTimezone($this->tz())->format('Y-m-d');
    }

    private function day(string $ymd): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('!Y-m-d', $ymd) ?: throw new \RuntimeException('date');
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

    /**
     * The company of the person signed in (their employment today) — ideas and votes belong to it.
     *
     * @return int|null legal entity id
     */
    public function myCompany(Authorizer $az): ?int
    {
        $me = $this->emp()->forUser($az->userId);
        if ($me === null || $me['status'] === 'former' || $me['job'] === null) {
            return null;
        }
        return (int) $me['job']['legal_entity_id'];
    }

    private function userByName(string $who): ?int
    {
        $who = trim($who);
        if ($who === '') {
            return null;
        }
        $id = $this->app->db()->scalar("SELECT u.id FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.status = 'active' AND (u.username = ? OR e.employee_code = ?) LIMIT 1", [$who, $who]);
        return $id === null ? null : (int) $id;
    }

    /** @return list<int> */
    public function staff(int $le, string $perm): array
    {
        $out = [];
        foreach ($this->app->db()->all("SELECT DISTINCT a.user_id FROM user_role_assignments a JOIN users u ON u.id = a.user_id JOIN role_permissions p ON p.role_id = a.role_id WHERE a.status = 'active' AND u.status = 'active' AND p.permission_code = ?", [$perm]) as $r) {
            $az = new Authorizer($this->app->db(), $this->app->clock, (int) $r['user_id']);
            if ($az->scope($perm)->coversLegalEntity($le)) {
                $out[] = (int) $r['user_id'];
            }
        }
        return $out;
    }

    private function event(int $idea, string $event, ?int $actor, ?string $note = null, bool $internal = false, ?string $at = null): void
    {
        $this->app->db()->run('INSERT INTO kz_events (idea_id, event, actor_id, note, internal, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$idea, $event, $actor, $note === null ? null : mb_substr($note, 0, 1000), $internal ? 1 : 0, $at ?? $this->stamp()]);
    }

    // ---- criteria ------------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function criteria(int $le, bool $activeOnly = false): array
    {
        return $this->app->db()->all('SELECT * FROM kz_criteria WHERE legal_entity_id = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY active DESC, name', [$le]);
    }

    /** @param array<string, mixed> $in name, weight, active */
    public function saveCriterion(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        $this->require($az, 'kaizen.manage', $le);
        $db = $this->app->db();
        $e = [];
        $name = trim((string) ($in['name'] ?? ''));
        $weight = trim((string) ($in['weight'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $e['name'] = 'kaizen.error.name';
        } elseif ($db->scalar('SELECT id FROM kz_criteria WHERE legal_entity_id = ? AND name = ? AND public_id <> ?', [$le, $name, $pid ?? '']) !== null) {
            $e['name'] = 'kaizen.error.name_taken';
        }
        if (preg_match('/^\d{1,3}$/', $weight) !== 1 || (int) $weight < 1 || (int) $weight > 100) {
            $e['weight'] = 'kaizen.error.weight';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $active = $pid === null || !empty($in['active']) ? 1 : 0;
        $now = $this->stamp();
        if ($pid === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run('INSERT INTO kz_criteria (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, name, weight) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$pid, $now, $az->userId, $now, $az->userId, $le, $name, (int) $weight]);
        } else {
            $n = $db->run('UPDATE kz_criteria SET name = ?, weight = ?, active = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE public_id = ? AND legal_entity_id = ?', [$name, (int) $weight, $active, $now, $az->userId, $pid, $le])->rowCount();
            if ($n === 0 && $db->scalar('SELECT id FROM kz_criteria WHERE public_id = ? AND legal_entity_id = ?', [$pid, $le]) === null) {
                throw HttpException::notFound();
            }
        }
        $this->app->audit()->record('kaizen.criteria_changed', 'success', 'kz_criteria', $pid, $le, null, ['name' => $name, 'weight' => (int) $weight, 'active' => $active]);
        return $pid;
    }

    // ---- sending and tracking ------------------------------------------------------------------------------------------------

    private function nextRef(int $le): string
    {
        $prefix = 'KZ' . $this->app->clock->now()->setTimezone($this->tz())->format('ym') . '-';
        $last = (string) ($this->app->db()->scalar('SELECT MAX(ref) FROM kz_ideas WHERE legal_entity_id = ? AND ref LIKE ?', [$le, $prefix . '%']) ?? '');
        return $prefix . str_pad((string) ((int) substr($last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Sends an idea.
     *
     * @param array<string, mixed> $in title, problem, proposal, benefit, anonymous
     * @return array{pid: string, ref: string, code: ?string} code = the tracking code of an anonymous idea (shown once)
     */
    public function submit(Authorizer $az, array $in, ?UploadedFile $photo): array
    {
        $le = $this->myCompany($az);
        if ($le === null) {
            throw new BusinessException('kaizen.error.no_employee', [], 403);
        }
        $this->require($az, 'kaizen.submit', $le);
        $e = [];
        $title = trim((string) ($in['title'] ?? ''));
        $problem = trim((string) ($in['problem'] ?? ''));
        $proposal = trim((string) ($in['proposal'] ?? ''));
        $benefit = trim((string) ($in['benefit'] ?? ''));
        $anon = !empty($in['anonymous']);
        if (mb_strlen($title) < 5 || mb_strlen($title) > 160) {
            $e['title'] = 'kaizen.error.title';
        }
        if (mb_strlen($problem) < 10 || mb_strlen($problem) > 3000) {
            $e['problem'] = 'kaizen.error.long_text';
        }
        if (mb_strlen($proposal) < 10 || mb_strlen($proposal) > 3000) {
            $e['proposal'] = 'kaizen.error.long_text';
        }
        if (mb_strlen($benefit) > 3000) {
            $e['benefit'] = 'kaizen.error.too_long';
        }
        if ($anon && $photo !== null) {
            $e['photo'] = 'kaizen.error.anonymous_photo';
        } elseif ($photo !== null && !$photo->ok()) {
            $e['photo'] = $photo->errorKey();
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $photoPid = null;
        if ($photo !== null) {
            try {
                $photoPid = (string) $this->app->documents()->storeGenerated($az->userId, 'kaizen.photo', $photo->bytes(), $photo->safeName(), ['title' => 'Kaizen photo', 'legal_entity_id' => $le], 'upload', true)['document'];
            } catch (ValidationException $x) {
                throw new ValidationException(['photo' => (string) (array_values($x->errors)[0] ?? 'kaizen.error.file')]);
            }
        }
        $db = $this->app->db();
        $code = null;
        $hash = null;
        if ($anon) {
            $code = strtoupper(substr(Ulid::generate($this->app->clock), 10, 4) . '-' . bin2hex(random_bytes(3)));
            $hash = hash('sha256', $code);
        }
        // an anonymous idea is stamped with the day only, so its time cannot be matched to a sign-in or a request log line
        $at = $anon ? $this->app->clock->now()->format('Y-m-d') . ' 00:00:00.000000' : $this->stamp();
        // 0.44.0: the public id of an anonymous record carries the day only too (a ULID encodes its creation time to the millisecond)
        $pid = $anon ? Ulid::generate(new FrozenClock(substr($at, 0, 10) . 'T00:00:00Z')) : Ulid::generate($this->app->clock);
        $ref = $db->transactional(function () use ($db, $le, $pid, $at, $title, $problem, $proposal, $benefit, $anon, $hash, $photoPid, $az): string {
            $ref = $this->nextRef($le);
            $by = $anon ? null : $az->userId;
            $db->run(
                'INSERT INTO kz_ideas (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, ref, title, problem, proposal, benefit, anonymous, submitter_id, track_hash, photo_pid)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $at, $by, $at, $by, $le, $ref, $title, $problem, $proposal, $benefit ?: null, $anon ? 1 : 0, $by, $hash, $photoPid]
            );
            $this->event((int) $db->pdo()->lastInsertId(), 'submitted', $by, null, false, $at);
            return $ref;
        });
        if (!$anon) {
            $this->app->audit()->record('kaizen.idea_submitted', 'success', 'kz_idea', $pid, $le);
        }
        // managers hear about new ideas in the daily digest (tick), never at the moment of sending
        return ['pid' => $pid, 'ref' => $ref, 'code' => $code];
    }

    /** @return array<string, mixed>|null the idea of an anonymous tracking code (public view) */
    public function track(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '' || strlen($code) > 40) {
            return null;
        }
        $i = $this->app->db()->one('SELECT * FROM kz_ideas WHERE track_hash = ?', [hash('sha256', $code)]);
        if ($i === null) {
            return null;
        }
        return $this->decorate($i, false, null);
    }

    // ---- reading -------------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> the ideas I sent under my name */
    public function mine(Authorizer $az): array
    {
        return $this->app->db()->all('SELECT i.public_id, i.ref, i.title, i.status, i.created_at, (SELECT COUNT(*) FROM kz_votes v WHERE v.idea_id = i.id) AS votes FROM kz_ideas i WHERE i.submitter_id = ? ORDER BY i.id DESC LIMIT 200', [$az->userId]);
    }

    /**
     * The company board: ideas past screening, with votes and whether I voted.
     *
     * @param array<string, string> $f status, sort (votes|recent), q
     * @return list<array<string, mixed>>
     */
    public function board(Authorizer $az, int $le, array $f = []): array
    {
        $where = 'i.legal_entity_id = ? AND i.status IN (' . implode(',', array_fill(0, count(self::BOARD), '?')) . ')';
        $params = [$le, ...self::BOARD];
        if (in_array($f['status'] ?? '', self::BOARD, true)) {
            $where .= ' AND i.status = ?';
            $params[] = $f['status'];
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $where .= ' AND (i.title LIKE ? OR i.ref LIKE ?)';
            $like = '%' . addcslashes(mb_substr($q, 0, 60), '%_\\') . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $order = ($f['sort'] ?? '') === 'recent' ? 'i.id DESC' : 'votes DESC, i.id DESC';
        return $this->app->db()->all(
            "SELECT i.public_id, i.ref, i.title, i.status, i.anonymous, i.created_at, u.display_name AS submitter_name,
                    (SELECT COUNT(*) FROM kz_votes v WHERE v.idea_id = i.id) AS votes,
                    (SELECT COUNT(*) FROM kz_votes v WHERE v.idea_id = i.id AND v.user_id = ?) AS voted
               FROM kz_ideas i LEFT JOIN users u ON u.id = i.submitter_id WHERE {$where} ORDER BY {$order} LIMIT 300",
            [$az->userId, ...$params]
        );
    }

    /** @return array<string, mixed> */
    private function row(string $pid): array
    {
        return $this->app->db()->one('SELECT * FROM kz_ideas WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /**
     * One idea as this person may see it: a manager sees everything (scores, internal notes); the owner sees the work; the
     * sender (named) sees their own; colleagues of the company see board ideas.
     *
     * @return array<string, mixed>
     */
    public function idea(Authorizer $az, string $pid): array
    {
        $i = $this->row($pid);
        $le = (int) $i['legal_entity_id'];
        $manager = $this->can($az, 'kaizen.manage', $le);
        $owner = $i['owner_id'] !== null && (int) $i['owner_id'] === $az->userId;
        $sender = $i['submitter_id'] !== null && (int) $i['submitter_id'] === $az->userId;
        $colleague = in_array($i['status'], self::BOARD, true) && $this->myCompany($az) === $le && $this->can($az, 'kaizen.submit', $le);
        if (!$manager && !$owner && !$sender && !$colleague) {
            throw HttpException::notFound();
        }
        $out = $this->decorate($i, $manager || $owner, $az->userId);
        $out += ['is_manager' => $manager, 'is_owner' => $owner, 'is_sender' => $sender,
            'can_reward' => $this->can($az, 'kaizen.reward', $le) && $i['reward_status'] === 'proposed' && (int) $i['reward_by'] !== $az->userId && !$sender,
            'can_vote' => in_array($i['status'], self::BOARD, true) && !$sender && $this->myCompany($az) === $le,
            'criteria' => $manager || $owner ? $this->criteria($le, true) : []];
        return $out;
    }

    /**
     * @param array<string, mixed> $i
     * @return array<string, mixed>
     */
    private function decorate(array $i, bool $inside, ?int $viewer): array
    {
        $db = $this->app->db();
        $i['submitter_name'] = $i['submitter_id'] === null ? null : $db->scalar('SELECT display_name FROM users WHERE id = ?', [$i['submitter_id']]);
        $i['owner_name'] = $i['owner_id'] === null ? null : $db->scalar('SELECT display_name FROM users WHERE id = ?', [$i['owner_id']]);
        $i['votes'] = (int) $db->scalar('SELECT COUNT(*) FROM kz_votes WHERE idea_id = ?', [$i['id']]);
        $i['voted'] = $viewer !== null && $db->scalar('SELECT 1 FROM kz_votes WHERE idea_id = ? AND user_id = ?', [$i['id'], $viewer]) !== null;
        $i['events'] = $db->all('SELECT e.event, e.note, e.internal, e.created_at, u.display_name AS actor FROM kz_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.idea_id = ?' . ($inside ? '' : ' AND e.internal = 0') . ' ORDER BY e.id', [$i['id']]);
        $i['scores'] = $inside ? $db->all('SELECT c.name, s.score, s.weight FROM kz_scores s JOIN kz_criteria c ON c.id = s.criterion_id WHERE s.idea_id = ? ORDER BY c.name', [$i['id']]) : [];
        if (!$inside) {
            unset($i['score']);
        }
        unset($i['track_hash']);
        return $i;
    }

    /** @return array{bytes: string, mime: string, filename: string} */
    public function photo(Authorizer $az, string $pid): array
    {
        $i = $this->idea($az, $pid);
        if ($i['photo_pid'] === null) {
            throw HttpException::notFound();
        }
        $doc = $this->app->documents()->moduleRead((string) $i['photo_pid'], 'kaizen', $az->userId, 'kaizen photo');
        return ['bytes' => $doc['bytes'], 'mime' => $doc['mime'], 'filename' => 'kaizen-' . $i['ref']];
    }

    /** Votes for a board idea, or takes the vote back. */
    public function vote(Authorizer $az, string $pid): bool
    {
        $i = $this->idea($az, $pid);
        if (!$i['can_vote']) {
            throw new BusinessException('kaizen.error.cannot_vote', [], 409);
        }
        $db = $this->app->db();
        if ($i['voted']) {
            $db->run('DELETE FROM kz_votes WHERE idea_id = ? AND user_id = ?', [$i['id'], $az->userId]);
            return false;
        }
        $db->run('INSERT IGNORE INTO kz_votes (idea_id, user_id) VALUES (?, ?)', [$i['id'], $az->userId]);
        return true;
    }

    // ---- handling --------------------------------------------------------------------------------------------------------------

    /**
     * A step on an idea.
     *
     * @param string $action screen | score | accept | reject | start | result | verify | close | reward | reward_ok | reward_no | note
     * @param array<string, mixed> $in owner, note, scores[criterion pid], plan, due_date, result, reward, internal
     */
    public function act(Authorizer $az, string $pid, string $action, array $in): void
    {
        $i = $this->idea($az, $pid);
        $le = (int) $i['legal_entity_id'];
        $id = (int) $i['id'];
        $db = $this->app->db();
        $note = trim((string) ($in['note'] ?? ''));
        $manager = (bool) $i['is_manager'];
        $owner = (bool) $i['is_owner'];
        $st = (string) $i['status'];
        $need = static function (bool $ok, string $key = 'kaizen.error.not_now'): void {
            if (!$ok) {
                throw new BusinessException($key, [], 409);
            }
        };
        $reason = static function (string $note, string $field = 'note'): void {
            if (mb_strlen($note) < 5) {
                throw new ValidationException([$field => 'kaizen.error.reason']);
            }
        };
        $set = function (string $sql, array $params) use ($db, $id, $az): void {
            $db->run('UPDATE kz_ideas SET ' . $sql . ', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [...$params, $this->stamp(), $az->userId, $id]);
        };
        switch ($action) {
            case 'screen':
                $need($manager, 'kaizen.error.manager_only');
                $need(in_array($st, ['submitted', 'screening'], true));
                $who = $this->userByName((string) ($in['owner'] ?? ''));
                if ($who === null) {
                    throw new ValidationException(['owner' => 'kaizen.error.person']);
                }
                $set("status = 'screening', owner_id = ?", [$who]);
                $this->event($id, 'screened', $az->userId, $note ?: null);
                if ($who !== $az->userId) {
                    $this->app->notifier()->notify($who, 'kaizen.idea', 'kaizen.notify.owner', ['ref' => (string) $i['ref']], '/kaizen/i/' . $pid, 'kz.own:' . $pid . ':' . $who);
                }
                break;
            case 'score':
                $need($manager || $owner, 'kaizen.error.manager_only');
                $need(in_array($st, ['screening', 'evaluation'], true));
                $crit = $i['criteria'];
                $need($crit !== [], 'kaizen.error.no_criteria');
                $e = [];
                $rows = [];
                foreach ($crit as $c) {
                    $v = (string) (((array) ($in['scores'] ?? []))[(string) $c['public_id']] ?? '');
                    if (preg_match('/^[1-5]$/', $v) !== 1) {
                        $e['score_' . $c['public_id']] = 'kaizen.error.score';
                        continue;
                    }
                    $rows[] = [(int) $c['id'], (int) $v, (int) $c['weight']];
                }
                if ($e !== []) {
                    throw new ValidationException($e);
                }
                $sum = array_sum(array_map(static fn (array $r): int => $r[1] * $r[2], $rows));
                $w = array_sum(array_column($rows, 2));
                $score = round($sum / $w, 2);
                $db->transactional(function () use ($db, $id, $rows, $set, $score, $az, $note): void {
                    $db->run('DELETE FROM kz_scores WHERE idea_id = ?', [$id]);
                    foreach ($rows as [$cid, $s, $wt]) {
                        $db->run('INSERT INTO kz_scores (idea_id, criterion_id, score, weight) VALUES (?, ?, ?, ?)', [$id, $cid, $s, $wt]);
                    }
                    $set("status = 'evaluation', score = ?", [$score]);
                    $this->event($id, 'scored', $az->userId, number_format($score, 2) . ($note !== '' ? ' — ' . $note : ''), true);
                });
                break;
            case 'accept':
                $need($manager, 'kaizen.error.manager_only');
                $need($st === 'evaluation', 'kaizen.error.score_first');
                $set("status = 'accepted', decision_note = ?", [$note ?: null]);
                $this->event($id, 'accepted', $az->userId, $note ?: null);
                $this->tell($i, 'kaizen.notify.accepted');
                break;
            case 'reject':
                $need($manager, 'kaizen.error.manager_only');
                $need(in_array($st, ['submitted', 'screening', 'evaluation'], true));
                $reason($note);
                $set("status = 'rejected', decision_note = ?", [$note]);
                $this->event($id, 'rejected', $az->userId, $note);
                $this->tell($i, 'kaizen.notify.rejected');
                break;
            case 'start':
                $need($manager || $owner, 'kaizen.error.manager_only');
                $need($st === 'accepted');
                $plan = trim((string) ($in['plan'] ?? ''));
                $due = trim((string) ($in['due_date'] ?? ''));
                $e = [];
                if (mb_strlen($plan) < 5 || mb_strlen($plan) > 1000) {
                    $e['plan'] = 'kaizen.error.plan';
                }
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) !== 1 || DateTimeImmutable::createFromFormat('!Y-m-d', $due) === false || $due < $this->today()) {
                    $e['due_date'] = 'kaizen.error.due';
                }
                if ($e !== []) {
                    throw new ValidationException($e);
                }
                $set("status = 'implementing', plan = ?, due_date = ?", [$plan, $due]);
                $this->event($id, 'started', $az->userId, $plan . ' → ' . $due);
                $this->tell($i, 'kaizen.notify.started');
                break;
            case 'result':
                $need($manager || $owner, 'kaizen.error.manager_only');
                $need($st === 'implementing');
                $result = trim((string) ($in['result'] ?? ''));
                if (mb_strlen($result) < 10 || mb_strlen($result) > 1000) {
                    throw new ValidationException(['result' => 'kaizen.error.result']);
                }
                $set('result_note = ?', [$result]);
                $this->event($id, 'result', $az->userId, $result);
                if ($i['owner_id'] !== null) {
                    foreach ($this->staff($le, 'kaizen.manage') as $u) {
                        if ($u !== (int) $i['owner_id']) {
                            $this->app->notifier()->notify($u, 'kaizen.idea', 'kaizen.notify.verify', ['ref' => (string) $i['ref']], '/kaizen/i/' . $pid, 'kz.ver:' . $pid . ':' . $u);
                        }
                    }
                }
                break;
            case 'verify':
                $need($manager, 'kaizen.error.manager_only');
                $need($st === 'implementing' && $i['result_note'] !== null, 'kaizen.error.result_first');
                $need(!$owner, 'kaizen.error.owner_verifies');
                $set("status = 'verified'", []);
                $this->event($id, 'verified', $az->userId, $note ?: null);
                $this->tell($i, 'kaizen.notify.verified');
                break;
            case 'close':
                $need($manager, 'kaizen.error.manager_only');
                $need(in_array($st, ['accepted', 'implementing'], true));
                $reason($note);
                $set("status = 'closed', decision_note = ?", [$note]);
                $this->event($id, 'closed', $az->userId, $note);
                $this->tell($i, 'kaizen.notify.closed');
                break;
            case 'reward':
                $need($manager, 'kaizen.error.manager_only');
                $need($st === 'verified' && in_array($i['reward_status'], ['none', 'rejected'], true));
                $need((int) $i['anonymous'] === 0, 'kaizen.error.anonymous_reward');
                $reward = trim((string) ($in['reward'] ?? ''));
                if (mb_strlen($reward) < 3 || mb_strlen($reward) > 300) {
                    throw new ValidationException(['reward' => 'kaizen.error.reward']);
                }
                $set("reward = ?, reward_status = 'proposed', reward_by = ?, reward_decided_by = NULL", [$reward, $az->userId]);
                $this->event($id, 'reward_proposed', $az->userId, $reward, true);
                foreach ($this->staff($le, 'kaizen.reward') as $u) {
                    if ($u !== $az->userId && $u !== (int) $i['submitter_id']) {
                        $this->app->notifier()->notify($u, 'kaizen.idea', 'kaizen.notify.reward_decide', ['ref' => (string) $i['ref']], '/kaizen/i/' . $pid, 'kz.rw:' . $pid . ':' . $u . ':' . $this->stamp());
                    }
                }
                break;
            case 'reward_ok':
            case 'reward_no':
                $need((bool) $i['can_reward'], 'kaizen.error.reward_self');
                if ($action === 'reward_no') {
                    $reason($note);
                }
                $set('reward_status = ?, reward_decided_by = ?', [$action === 'reward_ok' ? 'approved' : 'rejected', $az->userId]);
                $this->event($id, $action === 'reward_ok' ? 'reward_approved' : 'reward_rejected', $az->userId, $note ?: null, $action === 'reward_no');
                if ($action === 'reward_ok') {
                    $this->tell($i, 'kaizen.notify.rewarded');
                }
                break;
            case 'note':
                $need($manager || $owner, 'kaizen.error.manager_only');
                $reason($note);
                $this->event($id, 'note', $az->userId, $note, !empty($in['internal']));
                if (empty($in['internal'])) {
                    $this->tell($i, 'kaizen.notify.update');
                }
                break;
            default:
                throw HttpException::notFound();
        }
        $this->app->audit()->record('kaizen.idea_' . $action, 'success', 'kz_idea', $pid, $le);
    }

    /** @param array<string, mixed> $i */
    private function tell(array $i, string $key): void
    {
        if ($i['submitter_id'] !== null) {
            $this->app->notifier()->notify((int) $i['submitter_id'], 'kaizen.idea', $key, ['ref' => (string) $i['ref']], '/kaizen/i/' . $i['public_id'], 'kz:' . $key . ':' . $i['public_id']);
        }
    }

    // ---- management list and report ------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $f status, q
     * @return list<array<string, mixed>>
     */
    public function adminList(Authorizer $az, int $le, array $f = []): array
    {
        $this->require($az, 'kaizen.manage', $le);
        $where = 'i.legal_entity_id = ?';
        $params = [$le];
        $st = (string) ($f['status'] ?? '');
        if ($st === 'open') {
            $where .= " AND i.status IN ('submitted','screening','evaluation','accepted','implementing')";
        } elseif ($st !== '' && $st !== 'all') {
            $where .= ' AND i.status = ?';
            $params[] = $st;
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $where .= ' AND (i.title LIKE ? OR i.ref LIKE ?)';
            $like = '%' . addcslashes(mb_substr($q, 0, 60), '%_\\') . '%';
            $params[] = $like;
            $params[] = $like;
        }
        return $this->app->db()->all(
            "SELECT i.public_id, i.ref, i.title, i.status, i.anonymous, i.score, i.due_date, i.reward_status, i.created_at, u.display_name AS submitter_name, o.display_name AS owner_name,
                    (SELECT COUNT(*) FROM kz_votes v WHERE v.idea_id = i.id) AS votes
               FROM kz_ideas i LEFT JOIN users u ON u.id = i.submitter_id LEFT JOIN users o ON o.id = i.owner_id
              WHERE {$where} ORDER BY FIELD(i.status, 'submitted','screening','evaluation','accepted','implementing','verified','rejected','closed'), i.id DESC LIMIT 500",
            $params
        );
    }

    /**
     * Trend for the last 12 months (sent, accepted, verified per month) and totals.
     *
     * @return array<string, mixed>
     */
    public function report(Authorizer $az, int $le): array
    {
        $this->require($az, 'kaizen.manage', $le);
        $db = $this->app->db();
        $first = $this->day($this->today())->modify('first day of this month')->modify('-11 months');
        $months = [];
        for ($k = 0; $k < 12; $k++) {
            $months[$first->modify('+' . $k . ' months')->format('Y-m')] = ['sent' => 0, 'accepted' => 0, 'verified' => 0];
        }
        foreach ($db->all("SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, COUNT(*) AS n FROM kz_ideas WHERE legal_entity_id = ? AND created_at >= ? GROUP BY m", [$le, $first->format('Y-m-d')]) as $r) {
            if (isset($months[$r['m']])) {
                $months[$r['m']]['sent'] = (int) $r['n'];
            }
        }
        foreach ($db->all("SELECT DATE_FORMAT(e.created_at, '%Y-%m') AS m, e.event, COUNT(*) AS n FROM kz_events e JOIN kz_ideas i ON i.id = e.idea_id WHERE i.legal_entity_id = ? AND e.event IN ('accepted','verified') AND e.created_at >= ? GROUP BY m, e.event", [$le, $first->format('Y-m-d')]) as $r) {
            if (isset($months[$r['m']])) {
                $months[$r['m']][(string) $r['event']] = (int) $r['n'];
            }
        }
        $status = [];
        foreach ($db->all('SELECT status, COUNT(*) AS n FROM kz_ideas WHERE legal_entity_id = ? GROUP BY status', [$le]) as $r) {
            $status[(string) $r['status']] = (int) $r['n'];
        }
        $total = array_sum($status);
        $decided = ($status['rejected'] ?? 0) + ($status['accepted'] ?? 0) + ($status['implementing'] ?? 0) + ($status['verified'] ?? 0) + ($status['closed'] ?? 0);
        $taken = $decided - ($status['rejected'] ?? 0);
        return ['months' => $months, 'status' => $status, 'total' => $total,
            'accept_rate' => $decided === 0 ? null : round($taken * 100 / $decided, 1),
            'avg_score' => $db->scalar('SELECT ROUND(AVG(score), 2) FROM kz_ideas WHERE legal_entity_id = ? AND score IS NOT NULL', [$le]),
            'top' => $db->all("SELECT i.public_id, i.ref, i.title, i.status, (SELECT COUNT(*) FROM kz_votes v WHERE v.idea_id = i.id) AS votes FROM kz_ideas i WHERE i.legal_entity_id = ? AND i.status IN ('screening','evaluation','accepted','implementing','verified') ORDER BY votes DESC, i.id DESC LIMIT 5", [$le])];
    }

    /**
     * Implementing ideas past their due date → the owner is reminded once · ideas waiting for screening → each manager of
     * the company gets one digest a day (no per-idea message, so the moment an anonymous idea arrived is not recorded).
     */
    public function tick(): int
    {
        $n = 0;
        $day = $this->today();
        foreach ($this->app->db()->all("SELECT legal_entity_id, COUNT(*) AS c FROM kz_ideas WHERE status = 'submitted' GROUP BY legal_entity_id") as $w) {
            foreach ($this->staff((int) $w['legal_entity_id'], 'kaizen.manage') as $u) {
                $this->app->notifier()->notify($u, 'kaizen.idea', 'kaizen.notify.waiting', ['n' => (string) $w['c']], '/kaizen/admin', 'kz.wait:' . $day . ':' . $w['legal_entity_id'] . ':' . $u);
                $n++;
            }
        }
        foreach ($this->app->db()->all("SELECT public_id, ref, owner_id FROM kz_ideas WHERE status = 'implementing' AND due_date < ? AND owner_id IS NOT NULL LIMIT 500", [$this->today()]) as $i) {
            $this->app->notifier()->notify((int) $i['owner_id'], 'kaizen.idea', 'kaizen.notify.overdue', ['ref' => (string) $i['ref']], '/kaizen/i/' . $i['public_id'], 'kz.due:' . $i['public_id']);
            $n++;
        }
        return $n;
    }
}

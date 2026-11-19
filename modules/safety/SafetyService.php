<?php

declare(strict_types=1);

namespace Blue\Modules\Safety;

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
use Blue\Modules\Employees\Audience;
use Blue\Modules\Employees\EmployeeService;

/**
 * M39 safety incidents and near misses (9E-1 · DEC-100).
 *
 * Anyone reports an accident, damage, a near miss or a hazard (where, when, what happened, what was done at once, who
 * was involved, photos) — from the phone, and anonymously when the company turns that on. Levels 3–4 alert the safety
 * team and the people the company lists for that level at once; other reports reach the team as they come (anonymous
 * low-level ones once a day, so the time of sending is not given away). The safety team triages (confirms kind and level,
 * links a repeat of an earlier incident), assigns an investigator, or closes it as not an incident / a duplicate. The
 * investigator records facts, root cause, the temporary control and the likelihood × impact, takes witness statements
 * (need-to-know) and sets corrective / preventive actions with owners and due dates; owners finish them with a note and
 * evidence. When all actions are done the incident waits for verification by someone of the safety team who is not the
 * investigator; effective → closed (the reporter is told the outcome), not effective → back to actions. A closed incident
 * can be reopened with a reason. Medical details are a separate record only safety.medical sees. Letters or calls to
 * authorities, insurers or customers are logged — the system does not know legal deadlines or official forms.
 */
final class SafetyService
{
    public const KINDS = ['injury', 'damage', 'near_miss', 'hazard'];
    public const LEVELS = [1, 2, 3, 4];
    public const URGENT_LEVEL = 3;
    private const MAX_FILES = 5;

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    private function tz(): DateTimeZone
    {
        return new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }

    public function today(): string
    {
        return $this->app->clock->now()->setTimezone($this->tz())->format('Y-m-d');
    }

    private function nowLocal(): DateTimeImmutable
    {
        return $this->app->clock->now()->setTimezone($this->tz());
    }

    private function stamp(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    private function addDays(string $date, int $days): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->tz());
        return $d === false ? $date : $d->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    public function emp(): EmployeeService
    {
        return EmployeeService::of($this->app);
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

    /** @return array<string, mixed>|null my employee with today's job */
    public function me(Authorizer $az): ?array
    {
        $me = $this->emp()->forUser($az->userId);
        return $me === null || $me['status'] === 'former' || $me['job'] === null ? null : $me;
    }

    /** The company a person reports to: their employer, else the first company whose safety they look after. */
    public function myCompany(Authorizer $az): ?int
    {
        $me = $this->me($az);
        if ($me !== null && $this->can($az, 'safety.report', (int) $me['job']['legal_entity_id'])) {
            return (int) $me['job']['legal_entity_id'];
        }
        return $this->companies($az, 'safety.manage')[0] ?? null;
    }

    /** @return list<int> active users holding $perm for company $le */
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

    private function event(int $incident, string $event, ?int $actor, ?string $note = null, ?string $at = null): void
    {
        $this->app->db()->run('INSERT INTO sf_events (incident_id, event, actor_id, note, created_at) VALUES (?, ?, ?, ?, ?)', [$incident, $event, $actor, $note === null ? null : mb_substr($note, 0, 1000), $at ?? $this->stamp()]);
    }

    private function nextRef(int $le): string
    {
        $prefix = 'SF' . $this->nowLocal()->format('ym') . '-';
        $last = (string) ($this->app->db()->scalar('SELECT MAX(ref) FROM sf_incidents WHERE legal_entity_id = ? AND ref LIKE ?', [$le, $prefix . '%']) ?? '');
        return $prefix . str_pad((string) ((int) substr($last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }

    // ---- settings ------------------------------------------------------------------------------------------------------------

    /** @return array{notes: array<int, string>, escalate: array<int, list<int>>, anonymous_on: bool, escalate_names: array<int, string>} */
    public function settings(int $le): array
    {
        $r = $this->app->db()->one('SELECT * FROM sf_settings WHERE legal_entity_id = ?', [$le]);
        $notes = json_decode((string) ($r['level_notes'] ?? '{}'), true);
        $esc = json_decode((string) ($r['escalate_json'] ?? '{}'), true);
        $out = ['notes' => [], 'escalate' => [], 'anonymous_on' => (int) ($r['anonymous_on'] ?? 0) === 1, 'escalate_names' => []];
        foreach (self::LEVELS as $l) {
            $out['notes'][$l] = is_array($notes) ? (string) ($notes[(string) $l] ?? '') : '';
            $ids = is_array($esc) && is_array($esc[(string) $l] ?? null) ? array_values(array_map('intval', $esc[(string) $l])) : [];
            $out['escalate'][$l] = $ids;
            $out['escalate_names'][$l] = $ids === [] ? '' : implode(', ', array_column($this->app->db()->all('SELECT username FROM users WHERE id IN (' . implode(',', $ids) . ') ORDER BY username'), 'username'));
        }
        // 0.45.0: a published safety policy gives the meaning of each level (name — meaning)
        $pol = SafetyPolicy::of($this->app)->published($le);
        if ($pol !== null) {
            foreach (self::LEVELS as $l) {
                $lv = (array) ($pol['levels'][$l] ?? $pol['levels'][(string) $l] ?? []);
                if (($lv['name'] ?? '') !== '') {
                    $out['notes'][$l] = $lv['name'] . ' — ' . ($lv['meaning'] ?? '');
                }
            }
        }
        return $out;
    }

    /**
     * What each level means for this company (no defaults), who is alerted at once for levels 3–4, anonymous reports on/off.
     *
     * @param array<string, mixed> $in note_1..note_4, escalate_3, escalate_4 (user names), anonymous_on
     */
    public function saveSettings(Authorizer $az, int $le, array $in): void
    {
        if (!$this->can($az, 'safety.manage', $le)) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        $notes = [];
        $esc = [];
        $e = [];
        foreach (self::LEVELS as $l) {
            $n = trim((string) ($in['note_' . $l] ?? ''));
            if (mb_strlen($n) > 300) {
                $e['note_' . $l] = 'safety.error.too_long';
            }
            $notes[(string) $l] = $n;
        }
        foreach ([3, 4] as $l) {
            $ids = [];
            foreach (preg_split('/[\s,]+/', trim((string) ($in['escalate_' . $l] ?? ''))) ?: [] as $name) {
                if ($name === '') {
                    continue;
                }
                $id = $db->scalar("SELECT id FROM users WHERE username = ? AND status = 'active'", [$name]);
                if ($id === null) {
                    $e['escalate_' . $l] = 'safety.error.unknown_user';
                    continue;
                }
                $ids[(int) $id] = (int) $id;
            }
            if (count($ids) > 20) {
                $e['escalate_' . $l] = 'safety.error.too_many';
            }
            $esc[(string) $l] = array_values($ids);
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $db->run(
            'INSERT INTO sf_settings (legal_entity_id, level_notes, escalate_json, anonymous_on, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE level_notes = VALUES(level_notes), escalate_json = VALUES(escalate_json), anonymous_on = VALUES(anonymous_on), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [$le, json_encode($notes, JSON_UNESCAPED_UNICODE), json_encode($esc), !empty($in['anonymous_on']) ? 1 : 0, $this->stamp(), $az->userId]
        );
        $this->app->audit()->record('safety.settings_saved', 'success', 'legal_entity', (string) $le, $le);
    }

    // ---- reporting -----------------------------------------------------------------------------------------------------------

    /**
     * Report an incident.
     *
     * @param array<string, mixed> $in kind, severity, occurred_at (Y-m-dTH:i), site, location, description, immediate_action, people, anonymous
     * @param list<UploadedFile> $files
     * @return array{pid: string, ref: string, code: ?string} code = tracking code of an anonymous report (shown once)
     */
    public function report(Authorizer $az, array $in, array $files = []): array
    {
        $le = $this->myCompany($az);
        if ($le === null || !$this->can($az, 'safety.report', $le)) {
            throw new BusinessException('safety.error.no_company', [], 403);
        }
        $set = $this->settings($le);
        $db = $this->app->db();
        $kind = (string) ($in['kind'] ?? '');
        $sev = (int) ($in['severity'] ?? 0);
        $loc = trim((string) ($in['location'] ?? ''));
        $desc = trim(str_replace("\r\n", "\n", (string) ($in['description'] ?? '')));
        $imm = trim(str_replace("\r\n", "\n", (string) ($in['immediate_action'] ?? '')));
        $people = trim(str_replace("\r\n", "\n", (string) ($in['people'] ?? '')));
        $anon = !empty($in['anonymous']);
        $e = [];
        if (!in_array($kind, self::KINDS, true)) {
            $e['kind'] = 'safety.error.choice';
        }
        if (!in_array($sev, self::LEVELS, true)) {
            $e['severity'] = 'safety.error.choice';
        }
        $when = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) ($in['occurred_at'] ?? ''), $this->tz());
        if ($when === false) {
            $e['occurred_at'] = 'safety.error.when';
        } elseif ($when > $this->nowLocal()->modify('+10 minutes')) {
            $e['occurred_at'] = 'safety.error.future';
        } elseif ($when < $this->nowLocal()->modify('-1 year')) {
            $e['occurred_at'] = 'safety.error.too_old';
        }
        $site = null;
        if ((string) ($in['site'] ?? '') !== '') {
            $site = $db->scalar('SELECT id FROM sites WHERE public_id = ? AND legal_entity_id = ?', [(string) $in['site'], $le]);
            if ($site === null) {
                $e['site'] = 'safety.error.choice';
            }
        }
        if (mb_strlen($loc) < 3 || mb_strlen($loc) > 200) {
            $e['location'] = 'safety.error.location';
        }
        if (mb_strlen($desc) < 10 || mb_strlen($desc) > 5000) {
            $e['description'] = 'safety.error.description';
        }
        if (mb_strlen($imm) > 3000) {
            $e['immediate_action'] = 'safety.error.too_long';
        }
        if (mb_strlen($people) > 2000) {
            $e['people'] = 'safety.error.too_long';
        }
        if ($anon && !$set['anonymous_on']) {
            $e['anonymous'] = 'safety.error.anonymous_off';
        }
        if ($anon && $files !== []) {
            $e['files'] = 'safety.error.anonymous_files';
        } elseif (count($files) > self::MAX_FILES) {
            $e['files'] = 'safety.error.too_many_files';
        } else {
            foreach ($files as $f) {
                if (!$f->ok()) {
                    $e['files'] = $f->errorKey();
                }
            }
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $stored = $this->storeFiles($az, $le, $files, 'files');
        $code = null;
        $hash = null;
        if ($anon) {
            $code = strtoupper(substr(Ulid::generate($this->app->clock), 10, 4) . '-' . bin2hex(random_bytes(3)));
            $hash = hash('sha256', $code);
        }
        // an anonymous report is stamped with the day only, so its time cannot be matched to a sign-in or a request log line
        $at = $anon ? $this->app->clock->now()->format('Y-m-d') . ' 00:00:00.000000' : $this->stamp();
        $by = $anon ? null : $az->userId;
        // 0.44.0: the public id of an anonymous record carries the day only too (a ULID encodes its creation time to the millisecond)
        $pid = $anon ? Ulid::generate(new FrozenClock(substr($at, 0, 10) . 'T00:00:00Z')) : Ulid::generate($this->app->clock);
        $ref = $db->transactional(function () use ($db, $le, $pid, $at, $by, $site, $kind, $sev, $when, $loc, $desc, $imm, $people, $anon, $hash, $stored, $az): string {
            $db->one('SELECT id FROM legal_entities WHERE id = ? FOR UPDATE', [$le]);
            $ref = $this->nextRef($le);
            $db->run(
                'INSERT INTO sf_incidents (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, ref, site_id, kind, severity, occurred_at, location, description, immediate_action, people,
                    anonymous, reporter_id, track_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $at, $by, $at, $by, $le, $ref, $site, $kind, $sev, $when === false ? null : $when->format('Y-m-d H:i:s'), $loc, $desc, $imm ?: null, $people ?: null, $anon ? 1 : 0, $by, $hash]
            );
            $id = (int) $db->pdo()->lastInsertId();
            foreach ($stored as [$doc, $name, $mime]) {
                $db->run("INSERT INTO sf_files (incident_id, stage, document_pid, filename, mime, uploaded_by, uploaded_at) VALUES (?, 'report', ?, ?, ?, ?, ?)", [$id, $doc, $name, $mime, $az->userId, $at]);
            }
            $this->event($id, 'reported', $by, null, $at);
            return $ref;
        });
        if (!$anon) {
            $this->app->audit()->record('safety.reported', 'success', 'sf_incident', $pid, $le, null, ['severity' => $sev]);
        }
        // levels 3–4 alert at once, even when anonymous (safety comes first — the form says so); others: named reports now,
        // anonymous ones in the daily digest
        if ($sev >= self::URGENT_LEVEL || !$anon) {
            $this->alert($le, $pid, $ref, $kind, $sev, $loc, $by);
            $db->run('UPDATE sf_incidents SET digested = 1 WHERE public_id = ?', [$pid]);
        }
        return ['pid' => $pid, 'ref' => $ref, 'code' => $code];
    }

    /**
     * People the policy chain names for a level: the reporter's line manager, HR (safety.medical) and executives (role EXE).
     * @param list<string> $who
     * @return list<int>
     */
    public function chainPeople(int $le, array $who, ?int $reporter): array
    {
        $to = [];
        if (in_array('manager', $who, true) && $reporter !== null) {
            $emp = $this->emp()->forUser($reporter);
            if ($emp !== null) {
                $to = array_merge($to, $this->emp()->managerUserIds((int) $emp['id'], $this->today()));
            }
        }
        if (in_array('hr', $who, true)) {
            $to = array_merge($to, $this->staff($le, 'safety.medical'));
        }
        if (in_array('exec', $who, true)) {
            foreach ($this->staff($le, 'safety.approve') as $u) {
                if ($this->app->db()->scalar("SELECT 1 FROM user_role_assignments a JOIN roles r ON r.id = a.role_id WHERE a.user_id = ? AND a.status = 'active' AND r.code = 'EXE' LIMIT 1", [$u]) !== null) {
                    $to[] = $u;
                }
            }
        }
        return array_values(array_unique(array_map('intval', $to)));
    }

    /**
     * Tell the safety team about a new report — and, for urgent levels, the company's named people; with a published policy
     * also the people its chain names for that level (0.45.0).
     */
    private function alert(int $le, string $pid, string $ref, string $kind, int $sev, string $loc, ?int $reporter): int
    {
        $to = $this->staff($le, 'safety.manage');
        if ($sev >= self::URGENT_LEVEL) {
            $to = array_merge($to, $this->settings($le)['escalate'][$sev] ?? []);
        }
        $pol = SafetyPolicy::of($this->app)->published($le);
        $chain = $pol === null ? null : (array) ($pol['chain'][$sev] ?? $pol['chain'][(string) $sev] ?? []);
        if ($chain !== null) {
            $to = array_merge($to, $this->chainPeople($le, array_values(array_filter(SafetyPolicy::WHO, static fn (string $w): bool => !empty($chain[$w]))), $reporter));
        }
        $this->app->db()->run('UPDATE sf_incidents SET alerted_at = COALESCE(alerted_at, ?) WHERE public_id = ?', [$this->stamp(), $pid]);
        $n = 0;
        $kindLabel = $this->app->translator()->get('safety.kind.' . $kind);
        foreach (array_unique($to) as $u) {
            if ($u === $reporter) {
                continue;
            }
            $key = $sev >= self::URGENT_LEVEL ? 'safety.notify.urgent' : 'safety.notify.reported';
            $this->app->notifier()->notify((int) $u, 'safety.incident', $key, ['ref' => $ref, 'kind' => $kindLabel, 'level' => (string) $sev, 'where' => mb_substr($loc, 0, 80)], '/safety/i/' . $pid, 'sf.new:' . $pid . ':' . $u);
            $n++;
        }
        return $n;
    }

    /**
     * @param list<UploadedFile> $files
     * @return list<array{0: string, 1: string, 2: string}> document id, file name, mime
     */
    private function storeFiles(Authorizer $az, int $le, array $files, string $field): array
    {
        $out = [];
        foreach ($files as $f) {
            try {
                $d = $this->app->documents()->storeGenerated($az->userId, 'safety.evidence', $f->bytes(), $f->safeName(), ['title' => 'Safety evidence', 'legal_entity_id' => $le], 'upload', true);
            } catch (ValidationException $x) {
                throw new ValidationException([$field => (string) (array_values($x->errors)[0] ?? 'safety.error.file')]);
            }
            $ext = strtolower(pathinfo($f->safeName(), PATHINFO_EXTENSION));
            $out[] = [(string) $d['document'], mb_substr($f->safeName(), 0, 200), ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][$ext] ?? 'application/pdf'];
        }
        return $out;
    }

    /** @return array<string, mixed>|null the public view of an anonymous report (reference, status, outcome) */
    public function track(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if (preg_match('/^[0-9A-Z]{4}-[0-9A-F]{6}$/', $code) !== 1) {
            return null;
        }
        $i = $this->app->db()->one('SELECT ref, kind, status, closed_reason, outcome, created_at FROM sf_incidents WHERE track_hash = ?', [hash('sha256', $code)]);
        return $i;
    }

    // ---- access --------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function row(string $pid): array
    {
        return $this->app->db()->one('SELECT * FROM sf_incidents WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /**
     * What this person may do with the incident.
     *
     * @param array<string, mixed> $i
     * @return array{manage: bool, view: bool, medical: bool, investigator: bool, owner: bool, reporter: bool, any: bool, full: bool}
     */
    public function roles(Authorizer $az, array $i): array
    {
        $le = (int) $i['legal_entity_id'];
        $r = [
            'manage' => $this->can($az, 'safety.manage', $le),
            'view' => $this->can($az, 'safety.view', $le),
            'medical' => $this->can($az, 'safety.medical', $le),
            'investigator' => $i['investigator_id'] !== null && (int) $i['investigator_id'] === $az->userId,
            'owner' => $this->app->db()->scalar('SELECT id FROM sf_actions WHERE incident_id = ? AND owner_id = ? LIMIT 1', [$i['id'], $az->userId]) !== null,
            'reporter' => $i['reporter_id'] !== null && (int) $i['reporter_id'] === $az->userId,
        ];
        $r['full'] = $r['manage'] || $r['view'] || $r['investigator']; // need-to-know: people, witnesses, investigation
        $r['any'] = $r['full'] || $r['medical'] || $r['owner'] || $r['reporter'];
        return $r;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array{manage: bool, view: bool, medical: bool, investigator: bool, owner: bool, reporter: bool, any: bool, full: bool}}
     */
    private function guarded(Authorizer $az, string $pid): array
    {
        $i = $this->row($pid);
        $r = $this->roles($az, $i);
        if (!$r['any']) {
            throw HttpException::notFound();
        }
        return [$i, $r];
    }

    /** @return array<string, mixed> the incident as this person may see it */
    public function incident(Authorizer $az, string $pid): array
    {
        [$i, $r] = $this->guarded($az, $pid);
        $db = $this->app->db();
        $id = (int) $i['id'];
        unset($i['track_hash']);
        $i['roles'] = $r;
        $i['site'] = $i['site_id'] === null ? null : (Audience::options($this->app, (int) $i['legal_entity_id'])['sites'][(string) $db->scalar('SELECT public_id FROM sites WHERE id = ?', [$i['site_id']])] ?? null);
        $i['reporter'] = $i['reporter_id'] === null ? null : $db->scalar('SELECT username FROM users WHERE id = ?', [$i['reporter_id']]);
        $i['investigator'] = $i['investigator_id'] === null ? null : $db->scalar('SELECT username FROM users WHERE id = ?', [$i['investigator_id']]);
        $i['related'] = $i['related_id'] === null ? null : $db->one('SELECT public_id, ref, kind, status FROM sf_incidents WHERE id = ?', [$i['related_id']]);
        $i['repeats'] = $r['full'] ? $db->all('SELECT public_id, ref, status FROM sf_incidents WHERE related_id = ? ORDER BY id', [$id]) : [];
        $i['actions'] = $db->all('SELECT a.*, u.username AS owner FROM sf_actions a JOIN users u ON u.id = a.owner_id WHERE a.incident_id = ? ' . ($r['full'] ? '' : 'AND a.owner_id = ? ') . 'ORDER BY a.id', $r['full'] ? [$id] : [$id, $az->userId]);
        $files = $db->all('SELECT * FROM sf_files WHERE incident_id = ? ORDER BY id', [$id]);
        $mine = array_column($i['actions'], 'id');
        $i['files'] = array_values(array_filter($files, static fn (array $f): bool => $r['full'] || ($f['stage'] === 'report' && $r['reporter']) || ($f['action_id'] !== null && in_array($f['action_id'], $mine, false))));
        $i['witnesses'] = $r['full'] ? $db->all('SELECT * FROM sf_witnesses WHERE incident_id = ? ORDER BY id', [$id]) : [];
        $i['medical'] = $r['medical'] ? $db->all('SELECT * FROM sf_medical WHERE incident_id = ? ORDER BY id', [$id]) : [];
        $i['has_medical'] = (int) $db->scalar('SELECT COUNT(*) FROM sf_medical WHERE incident_id = ?', [$id]) > 0;
        $i['comms'] = $r['manage'] || $r['view'] ? $db->all('SELECT * FROM sf_comms WHERE incident_id = ? ORDER BY sent_on, id', [$id]) : [];
        $i['events'] = $r['full'] ? $db->all('SELECT e.*, u.username FROM sf_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.incident_id = ? ORDER BY e.id', [$id]) : [];
        if (!$r['full']) {
            // reporters and action owners do not see who else was involved
            $i['people'] = null;
            $i['facts'] = null;
            $i['temp_control'] = null;
            $i['likelihood'] = null;
            $i['impact'] = null;
            if (!$r['owner']) {
                $i['root_cause'] = null;
            }
        }
        $i['level_note'] = $this->settings((int) $i['legal_entity_id'])['notes'][(int) $i['severity']] ?? '';
        $pol = SafetyPolicy::of($this->app)->published((int) $i['legal_entity_id']);
        $i['policy'] = $pol;
        $i['band'] = $r['full'] ? SafetyPolicy::band($pol, $i['likelihood'] === null ? null : (int) $i['likelihood'], $i['impact'] === null ? null : (int) $i['impact']) : null;
        $i['today'] = $this->today();
        return $i;
    }

    /** @return array{bytes: string, mime: string, filename: string} */
    public function file(Authorizer $az, string $pid, int $fileId): array
    {
        $i = $this->incident($az, $pid);
        foreach ($i['files'] as $f) {
            if ((int) $f['id'] === $fileId) {
                $doc = $this->app->documents()->moduleRead((string) $f['document_pid'], 'safety', $az->userId, 'safety evidence');
                return ['bytes' => $doc['bytes'], 'mime' => (string) $f['mime'], 'filename' => (string) $f['filename']];
            }
        }
        throw HttpException::notFound();
    }

    /** @return array<string, mixed> */
    private function managed(Authorizer $az, string $pid): array
    {
        $i = $this->row($pid);
        if (!$this->can($az, 'safety.manage', (int) $i['legal_entity_id'])) {
            throw HttpException::forbidden();
        }
        return $i;
    }

    /** @return array<string, mixed> the incident, for its investigator or the safety team */
    private function investigated(Authorizer $az, string $pid): array
    {
        $i = $this->row($pid);
        $r = $this->roles($az, $i);
        if (!$r['manage'] && !$r['investigator']) {
            throw HttpException::forbidden();
        }
        return $i;
    }

    /**
     * @param array<string, mixed> $i
     * @param list<string> $from
     */
    private function requireStatus(array $i, array $from): void
    {
        if (!in_array($i['status'], $from, true)) {
            throw new BusinessException('safety.error.wrong_status', [], 409);
        }
    }

    /** A user found by user name or employee code who belongs to (or looks after) the company. */
    private function userOf(int $le, string $who): ?int
    {
        $who = trim((string) preg_replace('/\s+—.*$/u', '', trim($who)));
        if ($who === '') {
            return null;
        }
        $db = $this->app->db();
        $id = $db->scalar("SELECT id FROM users WHERE username = ? AND status = 'active'", [$who]);
        if ($id === null) {
            $id = $db->scalar("SELECT u.id FROM users u JOIN employees e ON e.id = u.employee_id WHERE e.employee_code = ? AND u.status = 'active' LIMIT 1", [mb_substr($who, 0, 30)]);
        }
        if ($id === null) {
            return null;
        }
        $az = new Authorizer($db, $this->app->clock, (int) $id);
        $me = $this->me($az);
        $ok = ($me !== null && (int) $me['job']['legal_entity_id'] === $le) || $this->can($az, 'safety.manage', $le);
        return $ok ? (int) $id : null;
    }

    // ---- triage and investigation ----------------------------------------------------------------------------------------------

    /**
     * Triage: confirm kind and level, link a repeat of an earlier incident (by reference).
     *
     * @param array<string, mixed> $in kind, severity, related (reference)
     */
    public function triage(Authorizer $az, string $pid, array $in): void
    {
        $i = $this->managed($az, $pid);
        $this->requireStatus($i, ['reported', 'triage']);
        $db = $this->app->db();
        $kind = (string) ($in['kind'] ?? '');
        $sev = (int) ($in['severity'] ?? 0);
        $rel = trim((string) ($in['related'] ?? ''));
        $e = [];
        if (!in_array($kind, self::KINDS, true)) {
            $e['kind'] = 'safety.error.choice';
        }
        if (!in_array($sev, self::LEVELS, true)) {
            $e['severity'] = 'safety.error.choice';
        }
        $relId = null;
        if ($rel !== '') {
            $relId = $db->scalar('SELECT id FROM sf_incidents WHERE legal_entity_id = ? AND ref = ? AND id <> ?', [$i['legal_entity_id'], strtoupper($rel), $i['id']]);
            if ($relId === null) {
                $e['related'] = 'safety.error.unknown_ref';
            }
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $now = $this->stamp();
        $db->run(
            "UPDATE sf_incidents SET status = 'triage', kind = ?, severity = ?, related_id = ?, acknowledged_at = COALESCE(acknowledged_at, ?), acknowledged_by = COALESCE(acknowledged_by, ?), updated_at = ?, updated_by = ? WHERE id = ?",
            [$kind, $sev, $relId, $now, $az->userId, $now, $az->userId, $i['id']]
        );
        $changed = $kind !== $i['kind'] || $sev !== (int) $i['severity'] ? $i['kind'] . '/' . $i['severity'] . ' → ' . $kind . '/' . $sev : null;
        $this->event((int) $i['id'], 'triaged', $az->userId, $changed);
        $this->app->audit()->record('safety.triaged', 'success', 'sf_incident', $pid, (int) $i['legal_entity_id'], $changed);
        if ($sev >= self::URGENT_LEVEL && (int) $i['severity'] < self::URGENT_LEVEL) {
            $this->alert((int) $i['legal_entity_id'], $pid, (string) $i['ref'], $kind, $sev, (string) $i['location'], $az->userId); // raised to urgent: alert now
        }
    }

    /** Assign (or change) the investigator: someone of the company or of the safety team. */
    public function assign(Authorizer $az, string $pid, string $who): void
    {
        $i = $this->managed($az, $pid);
        $this->requireStatus($i, ['triage', 'investigating', 'reopened']);
        $uid = $this->userOf((int) $i['legal_entity_id'], $who);
        if ($uid === null) {
            throw new ValidationException(['who' => 'safety.error.unknown_person']);
        }
        $this->app->db()->run("UPDATE sf_incidents SET status = IF(status = 'triage' OR status = 'reopened', 'investigating', status), investigator_id = ?, updated_at = ?, updated_by = ? WHERE id = ?", [$uid, $this->stamp(), $az->userId, $i['id']]);
        $this->event((int) $i['id'], 'assigned', $az->userId, (string) $this->app->db()->scalar('SELECT username FROM users WHERE id = ?', [$uid]));
        $this->app->audit()->record('safety.assigned', 'success', 'sf_incident', $pid, (int) $i['legal_entity_id']);
        if ($uid !== $az->userId) {
            $this->app->notifier()->notify($uid, 'safety.incident', 'safety.notify.assigned', ['ref' => (string) $i['ref']], '/safety/i/' . $pid, 'sf.as:' . $pid . ':' . $uid . ':' . $i['reopen_count']);
        }
    }

    /** Close at triage: not an incident, or a duplicate of another report. */
    public function reject(Authorizer $az, string $pid, string $reason, string $note): void
    {
        $i = $this->managed($az, $pid);
        $this->requireStatus($i, ['reported', 'triage']);
        $note = trim($note);
        $e = [];
        if (!in_array($reason, ['not_incident', 'duplicate'], true)) {
            $e['reason'] = 'safety.error.choice';
        }
        if (mb_strlen($note) < 5 || mb_strlen($note) > 1000) {
            $e['note'] = 'safety.error.note';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $now = $this->stamp();
        $this->app->db()->run(
            "UPDATE sf_incidents SET status = 'closed', closed_reason = ?, outcome = ?, closed_at = ?, acknowledged_at = COALESCE(acknowledged_at, ?), acknowledged_by = COALESCE(acknowledged_by, ?), updated_at = ?, updated_by = ? WHERE id = ?",
            [$reason, $note, $now, $now, $az->userId, $now, $az->userId, $i['id']]
        );
        $this->event((int) $i['id'], 'closed_' . $reason, $az->userId, $note);
        $this->app->audit()->record('safety.closed', 'success', 'sf_incident', $pid, (int) $i['legal_entity_id'], $reason);
        $this->tellReporter($i, 'safety.notify.closed');
    }

    /** @param array<string, mixed> $i */
    private function tellReporter(array $i, string $key): void
    {
        if ($i['reporter_id'] !== null) {
            $this->app->notifier()->notify((int) $i['reporter_id'], 'safety.incident', $key, ['ref' => (string) $i['ref']], '/safety/i/' . $i['public_id'], 'sf.rp:' . $i['public_id'] . ':' . $key . ':' . $i['reopen_count']);
        }
    }

    /**
     * Investigation record: facts, root cause, temporary control, likelihood and impact (1–5), files.
     *
     * @param array<string, mixed> $in
     * @param list<UploadedFile> $files
     */
    public function investigate(Authorizer $az, string $pid, array $in, array $files = []): void
    {
        $i = $this->investigated($az, $pid);
        $this->requireStatus($i, ['investigating', 'action_assigned']);
        $v = [];
        $e = [];
        foreach (['facts' => 5000, 'root_cause' => 3000, 'temp_control' => 3000] as $k => $max) {
            $v[$k] = trim(str_replace("\r\n", "\n", (string) ($in[$k] ?? '')));
            if (mb_strlen($v[$k]) > $max) {
                $e[$k] = 'safety.error.too_long';
            }
        }
        foreach (['likelihood', 'impact'] as $k) {
            $x = trim((string) ($in[$k] ?? ''));
            if ($x !== '' && (preg_match('/^[1-5]$/', $x) !== 1)) {
                $e[$k] = 'safety.error.scale';
            }
            $v[$k] = $x === '' ? null : (int) $x;
        }
        if (count($files) > self::MAX_FILES) {
            $e['files'] = 'safety.error.too_many_files';
        }
        foreach ($files as $f) {
            if (!$f->ok()) {
                $e['files'] = $f->errorKey();
            }
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $stored = $this->storeFiles($az, (int) $i['legal_entity_id'], $files, 'files');
        $db = $this->app->db();
        $now = $this->stamp();
        $db->transactional(function () use ($db, $i, $v, $stored, $az, $now): void {
            $db->run(
                'UPDATE sf_incidents SET facts = ?, root_cause = ?, temp_control = ?, likelihood = ?, impact = ?, updated_at = ?, updated_by = ? WHERE id = ?',
                [$v['facts'] ?: null, $v['root_cause'] ?: null, $v['temp_control'] ?: null, $v['likelihood'], $v['impact'], $now, $az->userId, $i['id']]
            );
            foreach ($stored as [$doc, $name, $mime]) {
                $db->run("INSERT INTO sf_files (incident_id, stage, document_pid, filename, mime, uploaded_by, uploaded_at) VALUES (?, 'investigation', ?, ?, ?, ?, ?)", [$i['id'], $doc, $name, $mime, $az->userId, $now]);
            }
        });
        $this->event((int) $i['id'], 'investigation_saved', $az->userId);
        $this->app->audit()->record('safety.investigation_saved', 'success', 'sf_incident', $pid, (int) $i['legal_entity_id']);
    }

    /** A witness statement (need-to-know: the investigator and the safety team). */
    public function addWitness(Authorizer $az, string $pid, string $who, string $statement): void
    {
        $i = $this->investigated($az, $pid);
        $this->requireStatus($i, ['investigating', 'action_assigned', 'verification']);
        [$empId, $name] = $this->person($who);
        $statement = trim(str_replace("\r\n", "\n", $statement));
        $e = [];
        if ($name === '') {
            $e['who'] = 'safety.error.name';
        }
        if (mb_strlen($statement) < 10 || mb_strlen($statement) > 5000) {
            $e['statement'] = 'safety.error.statement';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $this->app->db()->run('INSERT INTO sf_witnesses (incident_id, employee_id, name, statement, taken_by, taken_at) VALUES (?, ?, ?, ?, ?, ?)', [$i['id'], $empId, $name, $statement, $az->userId, $this->stamp()]);
        $this->event((int) $i['id'], 'witness_added', $az->userId);
        $this->app->audit()->record('safety.witness_added', 'success', 'sf_incident', $pid, (int) $i['legal_entity_id']);
    }

    /** @return array{0: ?int, 1: string} employee id (from a code) and the name to keep */
    private function person(string $who): array
    {
        $who = trim($who);
        $code = trim((string) preg_replace('/\s+—.*$/u', '', $who));
        $id = $code === '' ? null : $this->app->db()->scalar('SELECT id FROM employees WHERE employee_code = ?', [mb_substr($code, 0, 30)]);
        if ($id !== null) {
            $e = $this->emp()->byId((int) $id);
            return [(int) $id, $e === null ? $code : $this->emp()->displayName($e)];
        }
        return [null, mb_strlen($who) >= 2 && mb_strlen($who) <= 160 ? $who : ''];
    }

    /**
     * Medical details of an injured person (safety.medical only — not the investigator, not the safety team by default).
     *
     * @param array<string, mixed> $in who, injury, body_part, treatment, days_off, hospital
     */
    public function addMedical(Authorizer $az, string $pid, array $in): void
    {
        $i = $this->row($pid);
        if (!$this->can($az, 'safety.medical', (int) $i['legal_entity_id'])) {
            throw HttpException::forbidden();
        }
        [$empId, $name] = $this->person((string) ($in['who'] ?? ''));
        $injury = trim((string) ($in['injury'] ?? ''));
        $part = trim((string) ($in['body_part'] ?? ''));
        $treat = trim((string) ($in['treatment'] ?? ''));
        $days = trim((string) ($in['days_off'] ?? ''));
        $e = [];
        if ($name === '') {
            $e['who'] = 'safety.error.name';
        }
        if (mb_strlen($injury) < 3 || mb_strlen($injury) > 2000) {
            $e['injury'] = 'safety.error.injury';
        }
        if (mb_strlen($part) > 160) {
            $e['body_part'] = 'safety.error.too_long';
        }
        if (mb_strlen($treat) > 2000) {
            $e['treatment'] = 'safety.error.too_long';
        }
        if ($days !== '' && (preg_match('/^\d{1,3}$/', $days) !== 1)) {
            $e['days_off'] = 'safety.error.days';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $this->app->db()->run(
            'INSERT INTO sf_medical (incident_id, employee_id, name, injury, body_part, treatment, days_off, hospital, recorded_by, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$i['id'], $empId, $name, $injury, $part ?: null, $treat ?: null, $days === '' ? null : (int) $days, !empty($in['hospital']) ? 1 : 0, $az->userId, $this->stamp()]
        );
        $this->event((int) $i['id'], 'medical_added', $az->userId);
        $this->app->audit()->record('safety.medical_added', 'success', 'sf_incident', $pid, (int) $i['legal_entity_id']);
    }

    /**
     * Communication with authorities, insurers or customers (a record only — the system does not know legal deadlines).
     *
     * @param array<string, mixed> $in sent_on, recipient, channel, reference, note
     */
    public function addComm(Authorizer $az, string $pid, array $in): void
    {
        $i = $this->managed($az, $pid);
        $sent = trim((string) ($in['sent_on'] ?? ''));
        $to = trim((string) ($in['recipient'] ?? ''));
        $ch = trim((string) ($in['channel'] ?? ''));
        $ref = trim((string) ($in['reference'] ?? ''));
        $note = trim((string) ($in['note'] ?? ''));
        $e = [];
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $sent, $this->tz());
        if ($d === false || $d->format('Y-m-d') !== $sent || $sent > $this->today()) {
            $e['sent_on'] = 'safety.error.date';
        }
        if (mb_strlen($to) < 2 || mb_strlen($to) > 200) {
            $e['recipient'] = 'safety.error.recipient';
        }
        if (mb_strlen($ch) > 60 || mb_strlen($ref) > 120 || mb_strlen($note) > 2000) {
            $e['note'] = 'safety.error.too_long';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $this->app->db()->run(
            'INSERT INTO sf_comms (incident_id, sent_on, recipient, channel, reference, note, recorded_by, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$i['id'], $sent, $to, $ch ?: null, $ref ?: null, $note ?: null, $az->userId, $this->stamp()]
        );
        $this->event((int) $i['id'], 'comm_logged', $az->userId, $to);
        $this->app->audit()->record('safety.comm_logged', 'success', 'sf_incident', $pid, (int) $i['legal_entity_id']);
    }

    // ---- actions and verification --------------------------------------------------------------------------------------------

    /**
     * A corrective / preventive action with an owner and a due date. Needs the facts and the root cause first.
     *
     * @param array<string, mixed> $in kind, description, owner, due_on, external_ref
     */
    public function addAction(Authorizer $az, string $pid, array $in): string
    {
        $i = $this->investigated($az, $pid);
        $this->requireStatus($i, ['investigating', 'action_assigned']);
        if (mb_strlen((string) ($i['facts'] ?? '')) < 10 || mb_strlen((string) ($i['root_cause'] ?? '')) < 5) {
            throw new BusinessException('safety.error.investigate_first', [], 409);
        }
        $kind = (string) ($in['kind'] ?? '');
        $desc = trim((string) ($in['description'] ?? ''));
        $due = trim((string) ($in['due_on'] ?? ''));
        $ext = trim((string) ($in['external_ref'] ?? ''));
        $e = [];
        if (!in_array($kind, ['corrective', 'preventive'], true)) {
            $e['kind'] = 'safety.error.choice';
        }
        if (mb_strlen($desc) < 5 || mb_strlen($desc) > 2000) {
            $e['description'] = 'safety.error.action';
        }
        $owner = $this->userOf((int) $i['legal_entity_id'], (string) ($in['owner'] ?? ''));
        if ($owner === null) {
            $e['owner'] = 'safety.error.unknown_person';
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $due, $this->tz());
        if ($d === false || $d->format('Y-m-d') !== $due || $due < $this->today() || $due > $this->addDays($this->today(), 365)) {
            $e['due_on'] = 'safety.error.due';
        }
        if (mb_strlen($ext) > 60) {
            $e['external_ref'] = 'safety.error.too_long';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $db = $this->app->db();
        $apid = Ulid::generate($this->app->clock);
        $now = $this->stamp();
        $db->run('INSERT INTO sf_actions (public_id, incident_id, kind, description, owner_id, due_on, external_ref, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$apid, $i['id'], $kind, $desc, $owner, $due, $ext ?: null, $az->userId, $now]);
        $db->run("UPDATE sf_incidents SET status = 'action_assigned', updated_at = ?, updated_by = ? WHERE id = ?", [$now, $az->userId, $i['id']]);
        $this->event((int) $i['id'], 'action_added', $az->userId, mb_substr($desc, 0, 200));
        $this->app->audit()->record('safety.action_added', 'success', 'sf_incident', $pid, (int) $i['legal_entity_id']);
        if ($owner !== $az->userId) {
            $this->app->notifier()->notify((int) $owner, 'safety.action', 'safety.notify.action', ['ref' => (string) $i['ref'], 'due' => $due], '/safety/i/' . $pid, 'sf.ac:' . $apid);
        }
        return $apid;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} action and its incident */
    private function action(string $apid): array
    {
        $a = $this->app->db()->one('SELECT * FROM sf_actions WHERE public_id = ?', [$apid]) ?? throw HttpException::notFound();
        $i = $this->app->db()->one('SELECT * FROM sf_incidents WHERE id = ?', [$a['incident_id']]) ?? throw HttpException::notFound();
        return [$a, $i];
    }

    /**
     * The owner (or the safety team) finishes an action with a note and evidence. When no action is left open, the
     * incident waits for verification and the safety team is told.
     */
    public function completeAction(Authorizer $az, string $apid, string $note, ?UploadedFile $file): void
    {
        [$a, $i] = $this->action($apid);
        $manage = $this->can($az, 'safety.manage', (int) $i['legal_entity_id']);
        if ((int) $a['owner_id'] !== $az->userId && !$manage) {
            throw HttpException::forbidden();
        }
        if ($a['status'] !== 'open' || $i['status'] !== 'action_assigned') {
            throw new BusinessException('safety.error.wrong_status', [], 409);
        }
        $note = trim($note);
        $e = [];
        if (mb_strlen($note) < 5 || mb_strlen($note) > 2000) {
            $e['note'] = 'safety.error.note';
        }
        if ($file !== null && !$file->ok()) {
            $e['file'] = $file->errorKey();
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $stored = $file === null ? [] : $this->storeFiles($az, (int) $i['legal_entity_id'], [$file], 'file');
        $db = $this->app->db();
        $now = $this->stamp();
        $left = $db->transactional(function () use ($db, $a, $i, $note, $stored, $az, $now): int {
            $db->one('SELECT id FROM sf_incidents WHERE id = ? FOR UPDATE', [$i['id']]);
            $n = $db->run("UPDATE sf_actions SET status = 'done', done_note = ?, done_at = ? WHERE id = ? AND status = 'open'", [$note, $now, $a['id']])->rowCount();
            if ($n === 0) {
                throw new BusinessException('safety.error.wrong_status', [], 409);
            }
            foreach ($stored as [$doc, $name, $mime]) {
                $db->run("INSERT INTO sf_files (incident_id, action_id, stage, document_pid, filename, mime, uploaded_by, uploaded_at) VALUES (?, ?, 'action', ?, ?, ?, ?, ?)", [$i['id'], $a['id'], $doc, $name, $mime, $az->userId, $now]);
            }
            $left = (int) $db->scalar("SELECT COUNT(*) FROM sf_actions WHERE incident_id = ? AND status = 'open'", [$i['id']]);
            if ($left === 0) {
                $db->run("UPDATE sf_incidents SET status = 'verification', updated_at = ? WHERE id = ?", [$now, $i['id']]);
            }
            return $left;
        });
        $this->event((int) $i['id'], 'action_done', $az->userId, mb_substr((string) $a['description'], 0, 200));
        $this->app->audit()->record('safety.action_done', 'success', 'sf_incident', (string) $i['public_id'], (int) $i['legal_entity_id']);
        if ($left === 0) {
            foreach ($this->staff((int) $i['legal_entity_id'], 'safety.manage') as $u) {
                if ($i['investigator_id'] === null || $u !== (int) $i['investigator_id']) {
                    $this->app->notifier()->notify($u, 'safety.incident', 'safety.notify.verify', ['ref' => (string) $i['ref']], '/safety/i/' . $i['public_id'], 'sf.vf:' . $i['public_id'] . ':' . $u . ':' . $now);
                }
            }
        }
    }

    /** Cancel an action that is no longer needed (reason). */
    public function cancelAction(Authorizer $az, string $apid, string $reason): void
    {
        [$a, $i] = $this->action($apid);
        $r = $this->roles($az, $i);
        if (!$r['manage'] && !$r['investigator']) {
            throw HttpException::forbidden();
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new ValidationException(['reason' => 'safety.error.note']);
        }
        if ($a['status'] !== 'open') {
            throw new BusinessException('safety.error.wrong_status', [], 409);
        }
        $db = $this->app->db();
        $now = $this->stamp();
        $db->run("UPDATE sf_actions SET status = 'cancelled', cancel_reason = ?, done_at = ? WHERE id = ?", [$reason, $now, $a['id']]);
        $open = (int) $db->scalar("SELECT COUNT(*) FROM sf_actions WHERE incident_id = ? AND status = 'open'", [$i['id']]);
        $done = (int) $db->scalar("SELECT COUNT(*) FROM sf_actions WHERE incident_id = ? AND status = 'done'", [$i['id']]);
        if ($open === 0 && $i['status'] === 'action_assigned') {
            $db->run('UPDATE sf_incidents SET status = ?, updated_at = ? WHERE id = ?', [$done > 0 ? 'verification' : 'investigating', $now, $i['id']]);
        }
        $this->event((int) $i['id'], 'action_cancelled', $az->userId, $reason);
        $this->app->audit()->record('safety.action_cancelled', 'success', 'sf_incident', (string) $i['public_id'], (int) $i['legal_entity_id'], $reason);
    }

    /**
     * Verification by the safety team, not the investigator: effective → closed with the outcome the reporter sees;
     * not effective → back to actions (add a new one).
     */
    public function verify(Authorizer $az, string $pid, bool $effective, string $note): void
    {
        $i = $this->managed($az, $pid);
        $this->requireStatus($i, ['verification']);
        if ($i['investigator_id'] !== null && (int) $i['investigator_id'] === $az->userId) {
            throw new BusinessException('safety.error.not_investigator', [], 403);
        }
        $note = trim(str_replace("\r\n", "\n", $note));
        if (mb_strlen($note) < 10 || mb_strlen($note) > 3000) {
            throw new ValidationException(['note' => 'safety.error.outcome']);
        }
        $now = $this->stamp();
        if ($effective) {
            $this->app->db()->run("UPDATE sf_incidents SET status = 'closed', closed_reason = 'done', outcome = ?, closed_at = ?, verified_by = ?, updated_at = ?, updated_by = ? WHERE id = ?", [$note, $now, $az->userId, $now, $az->userId, $i['id']]);
            $this->event((int) $i['id'], 'verified', $az->userId, $note);
            $this->app->audit()->record('safety.verified', 'success', 'sf_incident', $pid, (int) $i['legal_entity_id']);
            $this->tellReporter($i, 'safety.notify.closed');
            return;
        }
        $this->app->db()->run("UPDATE sf_incidents SET status = 'action_assigned', updated_at = ?, updated_by = ? WHERE id = ?", [$now, $az->userId, $i['id']]);
        $this->event((int) $i['id'], 'not_effective', $az->userId, $note);
        $this->app->audit()->record('safety.not_effective', 'success', 'sf_incident', $pid, (int) $i['legal_entity_id'], $note);
        if ($i['investigator_id'] !== null) {
            $this->app->notifier()->notify((int) $i['investigator_id'], 'safety.incident', 'safety.notify.not_effective', ['ref' => (string) $i['ref']], '/safety/i/' . $pid, 'sf.ne:' . $pid . ':' . $now);
        }
    }

    /** Reopen a closed incident (e.g. it happened again, the fix did not hold). */
    public function reopen(Authorizer $az, string $pid, string $reason): void
    {
        $i = $this->managed($az, $pid);
        $this->requireStatus($i, ['closed']);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) {
            throw new ValidationException(['reason' => 'safety.error.note']);
        }
        $this->app->db()->run("UPDATE sf_incidents SET status = 'reopened', closed_reason = NULL, closed_at = NULL, verified_by = NULL, reopen_count = reopen_count + 1, updated_at = ?, updated_by = ? WHERE id = ?", [$this->stamp(), $az->userId, $i['id']]);
        $this->event((int) $i['id'], 'reopened', $az->userId, $reason);
        $this->app->audit()->record('safety.reopened', 'success', 'sf_incident', $pid, (int) $i['legal_entity_id'], $reason);
    }

    // ---- lists and figures ---------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $f status (open|closed|<status>), kind, severity, site, q
     * @return list<array<string, mixed>>
     */
    public function manageList(Authorizer $az, int $le, array $f): array
    {
        if (!$this->can($az, 'safety.manage', $le) && !$this->can($az, 'safety.view', $le)) {
            throw HttpException::forbidden();
        }
        $where = ['i.legal_entity_id = ?'];
        $args = [$le];
        $st = $f['status'] ?? 'open';
        if ($st === 'open' || $st === '') {
            $where[] = "i.status <> 'closed'";
        } elseif ($st !== 'all') {
            $where[] = 'i.status = ?';
            $args[] = $st;
        }
        if (in_array($f['kind'] ?? '', self::KINDS, true)) {
            $where[] = 'i.kind = ?';
            $args[] = $f['kind'];
        }
        if (in_array((int) ($f['severity'] ?? 0), self::LEVELS, true)) {
            $where[] = 'i.severity = ?';
            $args[] = (int) $f['severity'];
        }
        if (($f['site'] ?? '') !== '') {
            $where[] = 'i.site_id = (SELECT id FROM sites WHERE public_id = ?)';
            $args[] = $f['site'];
        }
        if (($f['q'] ?? '') !== '') {
            $where[] = "CONCAT_WS(' ', i.ref, i.location, i.description) LIKE ?";
            $args[] = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
        }
        return $this->app->db()->all(
            "SELECT i.id, i.public_id, i.ref, i.kind, i.severity, i.status, i.occurred_at, i.location, i.anonymous, i.closed_reason, u.username AS investigator,
                    (SELECT COUNT(*) FROM sf_actions a WHERE a.incident_id = i.id AND a.status = 'open') AS open_actions,
                    (SELECT COUNT(*) FROM sf_actions a WHERE a.incident_id = i.id AND a.status = 'open' AND a.due_on < ?) AS late_actions
             FROM sf_incidents i LEFT JOIN users u ON u.id = i.investigator_id WHERE " . implode(' AND ', $where) . ' ORDER BY i.status = \'closed\', i.severity DESC, i.occurred_at DESC LIMIT 500',
            array_merge([$this->today()], $args)
        );
    }

    /**
     * My reports, incidents I investigate and actions I own.
     *
     * @return array{reports: list<array<string, mixed>>, investigating: list<array<string, mixed>>, actions: list<array<string, mixed>>}
     */
    public function mine(Authorizer $az): array
    {
        $db = $this->app->db();
        return [
            'reports' => $db->all('SELECT public_id, ref, kind, severity, status, occurred_at, location, closed_reason FROM sf_incidents WHERE reporter_id = ? ORDER BY id DESC LIMIT 50', [$az->userId]),
            'investigating' => $db->all("SELECT public_id, ref, kind, severity, status, occurred_at, location FROM sf_incidents WHERE investigator_id = ? AND status <> 'closed' ORDER BY id DESC", [$az->userId]),
            'actions' => $db->all("SELECT a.public_id, a.description, a.due_on, a.status, i.public_id AS incident_pid, i.ref FROM sf_actions a JOIN sf_incidents i ON i.id = a.incident_id WHERE a.owner_id = ? AND a.status = 'open' ORDER BY a.due_on", [$az->userId]),
        ];
    }

    /**
     * Days since the last injury (confirmed or not yet triaged; closed as "not an incident" / duplicate does not count) —
     * for the company and each site. null = none on record.
     *
     * @return array{company: ?int, last: ?string, sites: list<array{label: string, days: ?int}>}
     */
    public function board(int $le): array
    {
        $db = $this->app->db();
        $today = DateTimeImmutable::createFromFormat('!Y-m-d', $this->today(), $this->tz());
        $since = function (?string $at) use ($today): ?int {
            if ($at === null || $today === false) {
                return null;
            }
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($at, 0, 10), $this->tz());
            return $d === false ? null : max(0, (int) $d->diff($today)->days);
        };
        $base = "kind = 'injury' AND (closed_reason IS NULL OR closed_reason = 'done')";
        $last = $db->scalar("SELECT MAX(occurred_at) FROM sf_incidents WHERE legal_entity_id = ? AND {$base}", [$le]);
        $sites = [];
        foreach (Audience::options($this->app, $le)['sites'] as $pid => $label) {
            $at = $db->scalar("SELECT MAX(i.occurred_at) FROM sf_incidents i JOIN sites s ON s.id = i.site_id WHERE s.public_id = ? AND i.legal_entity_id = ? AND i.{$base}", [$pid, $le]);
            $sites[] = ['label' => $label, 'days' => $since($at === null ? null : (string) $at)];
        }
        return ['company' => $since($last === null ? null : (string) $last), 'last' => $last === null ? null : (string) $last, 'sites' => $sites];
    }

    /**
     * Figures for the last 12 months: by month and kind, by level, by site, open by status, actions overdue, days to close.
     *
     * @return array<string, mixed>
     */
    public function stats(Authorizer $az, int $le): array
    {
        if (!$this->can($az, 'safety.manage', $le) && !$this->can($az, 'safety.view', $le)) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        $first = $this->nowLocal()->modify('first day of this month')->modify('-11 months')->format('Y-m-01');
        $valid = "(closed_reason IS NULL OR closed_reason = 'done')";
        $months = [];
        for ($k = 0; $k < 12; $k++) {
            $m = DateTimeImmutable::createFromFormat('!Y-m-d', $first, $this->tz());
            $key = $m === false ? '' : $m->modify('+' . $k . ' months')->format('Y-m');
            $months[$key] = array_fill_keys(self::KINDS, 0);
        }
        foreach ($db->all("SELECT DATE_FORMAT(occurred_at, '%Y-%m') AS m, kind, COUNT(*) AS c FROM sf_incidents WHERE legal_entity_id = ? AND occurred_at >= ? AND {$valid} GROUP BY m, kind", [$le, $first]) as $r) {
            if (isset($months[(string) $r['m']])) {
                $months[(string) $r['m']][(string) $r['kind']] = (int) $r['c'];
            }
        }
        $levels = array_fill_keys(self::LEVELS, 0);
        foreach ($db->all("SELECT severity, COUNT(*) AS c FROM sf_incidents WHERE legal_entity_id = ? AND occurred_at >= ? AND {$valid} GROUP BY severity", [$le, $first]) as $r) {
            $levels[(int) $r['severity']] = (int) $r['c'];
        }
        $siteNames = Audience::options($this->app, $le)['sites'];
        $bySite = [];
        foreach ($db->all("SELECT s.public_id, COUNT(*) AS c FROM sf_incidents i LEFT JOIN sites s ON s.id = i.site_id WHERE i.legal_entity_id = ? AND i.occurred_at >= ? AND (i.closed_reason IS NULL OR i.closed_reason = 'done') GROUP BY s.public_id ORDER BY c DESC", [$le, $first]) as $r) {
            $bySite[] = ['label' => $r['public_id'] === null ? null : ($siteNames[(string) $r['public_id']] ?? (string) $r['public_id']), 'count' => (int) $r['c']];
        }
        $open = [];
        foreach ($db->all("SELECT status, COUNT(*) AS c FROM sf_incidents WHERE legal_entity_id = ? AND status <> 'closed' GROUP BY status", [$le]) as $r) {
            $open[(string) $r['status']] = (int) $r['c'];
        }
        $closed = $db->all("SELECT created_at, closed_at FROM sf_incidents WHERE legal_entity_id = ? AND closed_reason = 'done' AND closed_at >= ?", [$le, $first]);
        $daysToClose = null;
        if ($closed !== []) {
            $sum = 0;
            foreach ($closed as $c) {
                $a = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', (string) $c['created_at'], new DateTimeZone('UTC'));
                $b = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', (string) $c['closed_at'], new DateTimeZone('UTC'));
                $sum += $a === false || $b === false ? 0 : (int) $a->diff($b)->days;
            }
            $daysToClose = round($sum / count($closed), 1);
        }
        return ['months' => $months, 'levels' => $levels, 'sites' => $bySite, 'open' => $open, 'days_to_close' => $daysToClose, 'closed' => count($closed),
            'late_actions' => (int) $db->scalar("SELECT COUNT(*) FROM sf_actions a JOIN sf_incidents i ON i.id = a.incident_id WHERE i.legal_entity_id = ? AND a.status = 'open' AND a.due_on < ?", [$le, $this->today()]),
            'board' => $this->board($le)];
    }

    /** Hourly: next-day digest of anonymous low-level reports; action reminder the day before; one overdue notice. */
    public function tick(): int
    {
        $db = $this->app->db();
        $today = $this->today();
        $n = 0;
        // anonymous low-level reports of earlier days only (stamped with the day), so the alert never follows the sending
        $midnight = $this->app->clock->now()->format('Y-m-d') . ' 00:00:00';
        foreach ($db->all('SELECT legal_entity_id, COUNT(*) AS c FROM sf_incidents WHERE digested = 0 AND created_at < ? GROUP BY legal_entity_id', [$midnight]) as $w) {
            $le = (int) $w['legal_entity_id'];
            $db->run('UPDATE sf_incidents SET digested = 1, alerted_at = COALESCE(alerted_at, ?) WHERE legal_entity_id = ? AND digested = 0 AND created_at < ?', [$this->stamp(), $le, $midnight]);
            foreach ($this->staff($le, 'safety.manage') as $u) {
                $this->app->notifier()->notify($u, 'safety.incident', 'safety.notify.digest', ['n' => (string) $w['c']], '/safety/manage', 'sf.dg:' . $today . ':' . $le . ':' . $u);
                $n++;
            }
        }
        // 0.45.0: escalation — a report nobody has triaged within the policy's minutes for its level goes to the next people once
        $now = $this->app->clock->now();
        foreach (
            $db->all("SELECT id, public_id, ref, legal_entity_id, severity, kind, location, reporter_id, alerted_at FROM sf_incidents
            WHERE status = 'reported' AND acknowledged_at IS NULL AND escalated = 0 AND alerted_at IS NOT NULL LIMIT 500") as $i
        ) {
            $pol = SafetyPolicy::of($this->app)->published((int) $i['legal_entity_id']);
            $chain = $pol === null ? [] : (array) ($pol['chain'][(int) $i['severity']] ?? $pol['chain'][(string) $i['severity']] ?? []);
            $after = $chain['after_min'] ?? null;
            if ($after === null) {
                continue;
            }
            $since = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', (string) $i['alerted_at'], new DateTimeZone('UTC'));
            if ($since === false || $since->modify('+' . (int) $after . ' minutes') > $now) {
                continue;
            }
            $db->run('UPDATE sf_incidents SET escalated = 1 WHERE id = ?', [$i['id']]);
            $to = array_merge($this->staff((int) $i['legal_entity_id'], 'safety.manage'), $this->chainPeople((int) $i['legal_entity_id'], array_values((array) ($chain['then'] ?? [])), $i['reporter_id'] === null ? null : (int) $i['reporter_id']));
            $kindLabel = $this->app->translator()->get('safety.kind.' . $i['kind']);
            foreach (array_unique($to) as $u) {
                if ($i['reporter_id'] !== null && (int) $i['reporter_id'] === $u) {
                    continue;
                }
                $this->app->notifier()->notify($u, 'safety.incident', 'safety.notify.escalated', ['ref' => (string) $i['ref'], 'kind' => $kindLabel, 'level' => (string) $i['severity'], 'min' => (string) $after], '/safety/i/' . $i['public_id'], 'sf.es:' . $i['public_id'] . ':' . $u);
                $n++;
            }
            $this->event((int) $i['id'], 'escalated', null, (string) $after);
        }
        foreach ($db->all("SELECT a.*, i.ref, i.public_id AS ipid FROM sf_actions a JOIN sf_incidents i ON i.id = a.incident_id WHERE a.status = 'open' AND a.reminded = 0 AND a.due_on = ? LIMIT 500", [$this->addDays($today, 1)]) as $a) {
            $db->run('UPDATE sf_actions SET reminded = 1 WHERE id = ?', [$a['id']]);
            $this->app->notifier()->notify((int) $a['owner_id'], 'safety.action', 'safety.notify.due', ['ref' => (string) $a['ref'], 'due' => (string) $a['due_on']], '/safety/i/' . $a['ipid'], 'sf.du:' . $a['public_id']);
            $n++;
        }
        foreach ($db->all("SELECT a.*, i.ref, i.public_id AS ipid, i.investigator_id FROM sf_actions a JOIN sf_incidents i ON i.id = a.incident_id WHERE a.status = 'open' AND a.overdue_notified = 0 AND a.due_on < ? LIMIT 500", [$today]) as $a) {
            $db->run('UPDATE sf_actions SET overdue_notified = 1 WHERE id = ?', [$a['id']]);
            foreach (array_unique(array_filter([(int) $a['owner_id'], $a['investigator_id'] === null ? 0 : (int) $a['investigator_id']])) as $u) {
                $this->app->notifier()->notify($u, 'safety.action', 'safety.notify.overdue', ['ref' => (string) $a['ref'], 'due' => (string) $a['due_on']], '/safety/i/' . $a['ipid'], 'sf.od:' . $a['public_id'] . ':' . $u);
                $n++;
            }
        }
        return $n;
    }

    public static function tickJob(App $app): string
    {
        return 'safety: ' . self::of($app)->tick();
    }
}

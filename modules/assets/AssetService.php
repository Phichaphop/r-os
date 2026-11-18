<?php

declare(strict_types=1);

namespace Blue\Modules\Assets;

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

/**
 * M42 asset register (9C-1 · DEC-94).
 *
 * Assets have a permanent code (never the name), optional serial (unique in the company), purchase facts, a site and
 * location, a cost centre and at most one custodian at a time. Every change goes to the append-only history with
 * actor, time, reason and before/after values. Custody: the supply/asset admin assigns with the condition out; the
 * custodian acknowledges in the app (no drawn signature is stored); the admin records the return with the condition
 * in. Disposal (sell/scrap/donate/write-off) needs a reason and the approval of an assets.dispose holder (never the
 * requester); the register only records the decision — no depreciation or accounting entry is made here.
 *
 * Statuses: available ⇄ assigned · maintenance · lost · disposal_pending → disposed.
 */
final class AssetService
{
    public const CLASSES = ['fixed', 'it', 'tool', 'machine', 'facility', 'vehicle', 'low_value'];

    public const CONDITIONS = ['good', 'fair', 'poor', 'broken'];

    public const FILE_KINDS = ['purchase', 'warranty', 'photo', 'handover', 'repair', 'disposal', 'other'];

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

    public function today(): string
    {
        return $this->nowLocal()->format('Y-m-d');
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

    /** @return list<array<string, mixed>> */
    public function sites(int $le): array
    {
        return $this->app->db()->all("SELECT id, public_id, code FROM sites WHERE legal_entity_id = ? AND status = 'active' ORDER BY code", [$le]);
    }

    /** @return list<array<string, mixed>> */
    public function departments(int $le): array
    {
        return $this->app->db()->all("SELECT id, public_id, code FROM departments WHERE legal_entity_id = ? AND status = 'active' ORDER BY code", [$le]);
    }

    // ---- types ---------------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function types(int $le, bool $activeOnly = false): array
    {
        return $this->app->db()->all(
            "SELECT t.*, (SELECT COUNT(*) FROM as_assets a WHERE a.type_id = t.id AND a.status <> 'disposed') AS asset_count FROM as_types t WHERE t.legal_entity_id = ?"
                . ($activeOnly ? ' AND t.active = 1' : '') . ' ORDER BY t.active DESC, t.name',
            [$le]
        );
    }

    /** @param array<string, mixed> $in name, asset_class, code_prefix, active */
    public function saveType(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        $this->require($az, 'assets.manage', $le);
        $db = $this->app->db();
        $e = [];
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $e['type_name'] = 'assets.error.name';
        } elseif ($db->scalar('SELECT id FROM as_types WHERE legal_entity_id = ? AND name = ? AND public_id <> ?', [$le, $name, $pid ?? '']) !== null) {
            $e['type_name'] = 'assets.error.name_taken';
        }
        $class = (string) ($in['asset_class'] ?? '');
        if (!in_array($class, self::CLASSES, true)) {
            $e['asset_class'] = 'assets.error.choice';
        }
        $prefix = strtoupper(trim((string) ($in['code_prefix'] ?? '')));
        if (!preg_match('/^[A-Z][A-Z0-9-]{0,9}$/', $prefix)) {
            $e['code_prefix'] = 'assets.error.prefix';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $now = $this->stamp();
        if ($pid === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run(
                'INSERT INTO as_types (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, name, asset_class, code_prefix) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $le, $name, $class, $prefix]
            );
            $this->app->audit()->record('assets.type_added', 'success', 'as_type', $pid, $le, null, ['name' => $name, 'class' => $class]);
            return $pid;
        }
        $t = $db->one('SELECT id FROM as_types WHERE public_id = ? AND legal_entity_id = ?', [$pid, $le]) ?? throw HttpException::notFound();
        $db->run('UPDATE as_types SET name = ?, asset_class = ?, code_prefix = ?, active = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$name, $class, $prefix, !empty($in['active']) ? 1 : 0, $now, $az->userId, $t['id']]);
        $this->app->audit()->record('assets.type_changed', 'success', 'as_type', $pid, $le, null, ['name' => $name, 'class' => $class]);
        return $pid;
    }

    // ---- assets --------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function row(string $pid): array
    {
        return $this->app->db()->one('SELECT * FROM as_assets WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /**
     * An asset the person may open: register viewers of the company, or its current custodian.
     *
     * @return array<string, mixed>
     */
    public function asset(Authorizer $az, string $pid): array
    {
        $db = $this->app->db();
        $a = $db->one(
            'SELECT a.*, t.name AS type_name, t.asset_class, s.code AS site_code, s.public_id AS site_pid, d.code AS dept_code, d.public_id AS dept_pid,
                    COALESCE(u.display_name, u.username) AS custodian_name, u.username AS custodian_username
               FROM as_assets a JOIN as_types t ON t.id = a.type_id JOIN sites s ON s.id = a.site_id LEFT JOIN departments d ON d.id = a.department_id LEFT JOIN users u ON u.id = a.custodian_id
              WHERE a.public_id = ?',
            [$pid]
        ) ?? throw HttpException::notFound();
        $le = (int) $a['legal_entity_id'];
        $a['can_view'] = $az->scope('assets.view')->coversLegalEntity($le);
        $a['can_manage'] = $az->scope('assets.manage')->coversLegalEntity($le);
        $a['mine'] = $a['custodian_id'] !== null && (int) $a['custodian_id'] === $az->userId;
        if (!$a['can_view'] && !$a['mine']) {
            throw HttpException::notFound();
        }
        $a['custody'] = $db->one('SELECT * FROM as_custody WHERE asset_id = ? AND returned_at IS NULL ORDER BY id DESC LIMIT 1', [$a['id']]);
        $a['custody_history'] = $a['can_view'] ? $db->all(
            'SELECT c.*, COALESCE(u.display_name, u.username) AS user_name FROM as_custody c JOIN users u ON u.id = c.user_id WHERE c.asset_id = ? ORDER BY c.id DESC',
            [$a['id']]
        ) : [];
        $a['moves'] = $a['can_view'] ? $db->all(
            'SELECT m.*, COALESCE(u.display_name, u.username) AS actor_name FROM as_moves m LEFT JOIN users u ON u.id = m.actor_id WHERE m.asset_id = ? ORDER BY m.id DESC LIMIT 200',
            [$a['id']]
        ) : [];
        $a['files'] = $a['can_view'] ? $db->all('SELECT * FROM as_files WHERE asset_id = ? ORDER BY id DESC', [$a['id']]) : [];
        $a['disposal'] = $db->one("SELECT * FROM as_disposals WHERE asset_id = ? ORDER BY id DESC LIMIT 1", [$a['id']]);
        return $a;
    }

    /**
     * The register of a company with filters.
     *
     * @param array<string, string> $f q, site, type, status, custodian (user id), department
     * @return list<array<string, mixed>>
     */
    public function listing(Authorizer $az, int $le, array $f = [], int $limit = 500): array
    {
        $this->require($az, 'assets.view', $le);
        $where = ['a.legal_entity_id = ?'];
        $p = [$le];
        if (($f['q'] ?? '') !== '') {
            $where[] = '(a.code LIKE ? OR a.name LIKE ? OR a.serial_no LIKE ?)';
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            array_push($p, $like, $like, $like);
        }
        foreach (['site' => 's.public_id', 'type' => 't.public_id', 'department' => 'd.public_id'] as $k => $col) {
            if (($f[$k] ?? '') !== '') {
                $where[] = $col . ' = ?';
                $p[] = (string) $f[$k];
            }
        }
        if (($f['status'] ?? '') !== '') {
            $where[] = 'a.status = ?';
            $p[] = (string) $f['status'];
        } elseif (empty($f['all'])) {
            $where[] = "a.status <> 'disposed'";
        }
        if (($f['custodian'] ?? '') !== '') {
            $where[] = 'a.custodian_id = ?';
            $p[] = (int) $f['custodian'];
        }
        return $this->app->db()->all(
            'SELECT a.*, t.name AS type_name, t.asset_class, s.code AS site_code, d.code AS dept_code, COALESCE(u.display_name, u.username) AS custodian_name,
                    (SELECT c.acknowledged_at IS NULL FROM as_custody c WHERE c.asset_id = a.id AND c.returned_at IS NULL ORDER BY c.id DESC LIMIT 1) AS unacknowledged
               FROM as_assets a JOIN as_types t ON t.id = a.type_id JOIN sites s ON s.id = a.site_id LEFT JOIN departments d ON d.id = a.department_id LEFT JOIN users u ON u.id = a.custodian_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY a.code LIMIT ' . max(1, min(5000, $limit)),
            $p
        );
    }

    /** next free code for a type: prefix-0001 … */
    private function nextCode(int $le, string $prefix): string
    {
        $last = (string) ($this->app->db()->scalar('SELECT MAX(code) FROM as_assets WHERE legal_entity_id = ? AND code REGEXP ?', [$le, '^' . preg_quote($prefix, '/') . '-[0-9]{4,}$']) ?? '');
        return $prefix . '-' . str_pad((string) ((int) substr($last, strlen($prefix) + 1) + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Validates the master fields of an asset.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed> column => value
     */
    private function fields(int $le, array $in, ?int $id): array
    {
        $db = $this->app->db();
        $e = [];
        $type = $db->one('SELECT id, code_prefix FROM as_types WHERE public_id = ? AND legal_entity_id = ?', [(string) ($in['type'] ?? ''), $le]);
        if ($type === null) {
            $e['type'] = 'assets.error.type';
        }
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if ($code !== '' && !preg_match('/^[A-Z0-9][A-Z0-9._-]{0,39}$/', $code)) {
            $e['code'] = 'assets.error.code';
        } elseif ($code !== '' && $db->scalar('SELECT id FROM as_assets WHERE legal_entity_id = ? AND code = ? AND id <> ?', [$le, $code, $id ?? 0]) !== null) {
            $e['code'] = 'assets.error.code_taken';
        } elseif ($code === '' && $id !== null) {
            $e['code'] = 'assets.error.code';
        }
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            $e['name'] = 'assets.error.name';
        }
        $serial = trim((string) ($in['serial_no'] ?? ''));
        if (mb_strlen($serial) > 80) {
            $e['serial_no'] = 'assets.error.text';
        } elseif ($serial !== '' && $db->scalar('SELECT id FROM as_assets WHERE legal_entity_id = ? AND serial_no = ? AND id <> ?', [$le, $serial, $id ?? 0]) !== null) {
            $e['serial_no'] = 'assets.error.serial_taken';
        }
        $text = [];
        foreach (['manufacturer' => 80, 'model' => 80, 'supplier' => 160, 'purchase_ref' => 80, 'location' => 160, 'notes' => 1000] as $k => $max) {
            $text[$k] = trim((string) ($in[$k] ?? ''));
            if (mb_strlen($text[$k]) > $max) {
                $e[$k] = 'assets.error.text';
            }
        }
        $dates = [];
        foreach (['acquired_on', 'warranty_until'] as $k) {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v !== '' && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1 || DateTimeImmutable::createFromFormat('!Y-m-d', $v) === false)) {
                $e[$k] = 'assets.error.date';
            }
            $dates[$k] = $v === '' ? null : $v;
        }
        if ($dates['acquired_on'] !== null && $dates['acquired_on'] > $this->today()) {
            $e['acquired_on'] = 'assets.error.future';
        }
        $cost = str_replace(',', '', trim((string) ($in['cost'] ?? '')));
        if ($cost !== '' && (preg_match('/^\d{1,12}(\.\d{1,2})?$/', $cost) !== 1)) {
            $e['cost'] = 'assets.error.amount';
        }
        $site = $db->scalar("SELECT id FROM sites WHERE public_id = ? AND legal_entity_id = ? AND status = 'active'", [(string) ($in['site'] ?? ''), $le]);
        if ($site === null) {
            $e['site'] = 'assets.error.site';
        }
        $dept = null;
        if (($in['department'] ?? '') !== '') {
            $dept = $db->scalar("SELECT id FROM departments WHERE public_id = ? AND legal_entity_id = ?", [(string) $in['department'], $le]);
            if ($dept === null) {
                $e['department'] = 'assets.error.department';
            }
        }
        if ($e !== [] || $type === null) {
            throw new ValidationException($e);
        }
        return [
            'type_id' => (int) $type['id'], 'code' => $code !== '' ? $code : $this->nextCode($le, (string) $type['code_prefix']), 'name' => $name, 'serial_no' => $serial === '' ? null : $serial,
            'manufacturer' => $text['manufacturer'] ?: null, 'model' => $text['model'] ?: null, 'acquired_on' => $dates['acquired_on'], 'cost' => $cost === '' ? null : number_format((float) $cost, 2, '.', ''),
            'supplier' => $text['supplier'] ?: null, 'purchase_ref' => $text['purchase_ref'] ?: null, 'warranty_until' => $dates['warranty_until'], 'site_id' => (int) $site,
            'location' => $text['location'] ?: null, 'department_id' => $dept === null ? null : (int) $dept, 'notes' => $text['notes'] ?: null,
        ];
    }

    /**
     * Registers an asset (blank code = next code of the type's prefix).
     *
     * @param array<string, mixed> $in
     */
    public function create(Authorizer $az, int $le, array $in): string
    {
        $this->require($az, 'assets.manage', $le);
        $v = $this->fields($le, $in, null);
        $condition = (string) ($in['condition'] ?? 'good');
        if (!in_array($condition, self::CONDITIONS, true)) {
            throw new ValidationException(['condition' => 'assets.error.choice']);
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->stamp();
        $db = $this->app->db();
        $db->transactional(function () use ($db, $pid, $now, $az, $le, $v, $condition): void {
            $db->run(
                'INSERT INTO as_assets (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, ' . implode(', ', array_keys($v)) . ', asset_condition, qr_token)
                 VALUES (?, ?, ?, ?, ?, ?, ' . implode(', ', array_fill(0, count($v), '?')) . ', ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $le, ...array_values($v), $condition, bin2hex(random_bytes(16))]
            );
            $this->move((int) $db->pdo()->lastInsertId(), 'registered', null, $this->snapshot($v + ['asset_condition' => $condition]), null, $az->userId);
        });
        $this->app->audit()->record('assets.registered', 'success', 'as_asset', $pid, $le, null, ['code' => $v['code'], 'type' => $v['type_id'], 'site' => $v['site_id']]);
        return $pid;
    }

    /**
     * Changes master fields; the history keeps before/after of what changed (a reason is required for code, site, cost
     * centre or cost changes).
     *
     * @param array<string, mixed> $in
     */
    public function update(Authorizer $az, string $pid, array $in): void
    {
        $a = $this->row($pid);
        $le = (int) $a['legal_entity_id'];
        $this->require($az, 'assets.manage', $le);
        if ($a['status'] === 'disposed') {
            throw new BusinessException('assets.error.disposed', [], 409);
        }
        $v = $this->fields($le, $in, (int) $a['id']);
        $before = [];
        $after = [];
        foreach ($v as $k => $val) {
            $old = $a[$k] === null ? null : (string) $a[$k];
            $new = $val === null ? null : (string) $val;
            if ($old !== $new) {
                $before[$k] = $old;
                $after[$k] = $new;
            }
        }
        if ($after === []) {
            return;
        }
        $reason = trim((string) ($in['reason'] ?? ''));
        if (array_intersect(array_keys($after), ['code', 'site_id', 'department_id', 'cost']) !== [] && mb_strlen($reason) < 3) {
            throw new ValidationException(['reason' => 'assets.error.reason_change']);
        }
        $db = $this->app->db();
        $db->transactional(function () use ($db, $a, $after, $before, $reason, $az): void {
            $db->run('UPDATE as_assets SET ' . implode(', ', array_map(static fn (string $k): string => $k . ' = ?', array_keys($after))) . ', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [...array_values($after), $this->stamp(), $az->userId, $a['id']]);
            $kind = isset($after['site_id']) || isset($after['location']) ? 'transferred' : 'changed';
            $this->move((int) $a['id'], $kind, $this->snapshot($before), $this->snapshot($after), $reason === '' ? null : $reason, $az->userId);
        });
        $this->app->audit()->record('assets.changed', 'success', 'as_asset', $pid, $le, null, ['fields' => array_keys($after)]);
    }

    /**
     * Human-readable values for the history (site/department/type codes instead of ids).
     *
     * @param array<string, mixed> $v
     */
    private function snapshot(array $v): string
    {
        $db = $this->app->db();
        $out = [];
        foreach ($v as $k => $val) {
            if ($val !== null && $k === 'site_id') {
                $out['site'] = (string) $db->scalar('SELECT code FROM sites WHERE id = ?', [$val]);
            } elseif ($val !== null && $k === 'department_id') {
                $out['department'] = (string) $db->scalar('SELECT code FROM departments WHERE id = ?', [$val]);
            } elseif ($val !== null && $k === 'type_id') {
                $out['type'] = (string) $db->scalar('SELECT name FROM as_types WHERE id = ?', [$val]);
            } elseif ($val !== null && in_array($k, ['custodian_id', 'user_id'], true)) {
                $out['custodian'] = (string) $db->scalar('SELECT COALESCE(display_name, username) FROM users WHERE id = ?', [$val]);
            } else {
                $out[preg_replace('/_id$/', '', $k)] = $val;
            }
        }
        return (string) json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    private function move(int $assetId, string $kind, ?string $before, ?string $after, ?string $reason, ?int $actor): void
    {
        $this->app->db()->run(
            'INSERT INTO as_moves (asset_id, kind, before_json, after_json, reason, actor_id, at_local, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$assetId, $kind, $before, $after, $reason === null ? null : mb_substr($reason, 0, 500), $actor, $this->now(), $this->stamp()]
        );
    }

    // ---- custody -------------------------------------------------------------------------------------------------------------

    /** finds an active user of the company by user name or employee code */
    private function person(int $le, string $who): ?int
    {
        $who = trim($who);
        if ($who === '') {
            return null;
        }
        $u = $this->app->db()->one(
            "SELECT u.id FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.status = 'active' AND (u.username = ? OR e.employee_code = ?) LIMIT 1",
            [$who, $who]
        );
        if ($u === null) {
            return null;
        }
        $az = new Authorizer($this->app->db(), $this->app->clock, (int) $u['id']);
        return $az->scope('assets.use')->coversLegalEntity($le) ? (int) $u['id'] : null;
    }

    /** Gives an available asset to one person (one custodian at a time); they acknowledge it in the app. */
    public function assign(Authorizer $az, string $pid, string $who, string $condition, string $note): void
    {
        $a = $this->row($pid);
        $le = (int) $a['legal_entity_id'];
        $this->require($az, 'assets.manage', $le);
        $user = $this->person($le, $who);
        $e = [];
        if ($user === null) {
            $e['who'] = 'assets.error.person';
        }
        if (!in_array($condition, self::CONDITIONS, true)) {
            $e['condition_out'] = 'assets.error.choice';
        }
        if ($e !== [] || $user === null) {
            throw new ValidationException($e);
        }
        $db = $this->app->db();
        $db->transactional(function () use ($db, $a, $user, $condition, $note, $az): void {
            $x = $db->one('SELECT * FROM as_assets WHERE id = ? FOR UPDATE', [$a['id']]);
            if ($x === null || $x['status'] !== 'available' || $db->scalar('SELECT id FROM as_custody WHERE asset_id = ? AND returned_at IS NULL', [$a['id']]) !== null) {
                throw new BusinessException('assets.error.not_available', [], 409);
            }
            $db->run(
                'INSERT INTO as_custody (asset_id, user_id, assigned_at, assigned_by, condition_out, note) VALUES (?, ?, ?, ?, ?, ?)',
                [$a['id'], $user, $this->now(), $az->userId, $condition, trim($note) === '' ? null : mb_substr(trim($note), 0, 500)]
            );
            $db->run("UPDATE as_assets SET status = 'assigned', custodian_id = ?, asset_condition = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$user, $condition, $this->stamp(), $az->userId, $a['id']]);
            $this->move((int) $a['id'], 'assigned', null, $this->snapshot(['custodian_id' => $user, 'asset_condition' => $condition]), trim($note) === '' ? null : $note, $az->userId);
        });
        $this->app->notifier()->notify($user, 'assets.assigned', 'assets.notify.assigned', ['code' => (string) $a['code'], 'name' => (string) $a['name']], '/assets/a/' . $pid, 'as.as:' . $pid . ':' . $this->stamp());
        $this->app->audit()->record('assets.assigned', 'success', 'as_asset', $pid, $le, null, ['user' => $user]);
    }

    /** The custodian confirms receipt (in the app — no drawn signature is kept). */
    public function acknowledge(Authorizer $az, string $pid): void
    {
        $a = $this->row($pid);
        $db = $this->app->db();
        $c = $db->one('SELECT * FROM as_custody WHERE asset_id = ? AND returned_at IS NULL ORDER BY id DESC LIMIT 1', [$a['id']]);
        if ($c === null || (int) $c['user_id'] !== $az->userId) {
            throw HttpException::notFound();
        }
        if ($c['acknowledged_at'] !== null) {
            return;
        }
        $db->run('UPDATE as_custody SET acknowledged_at = ? WHERE id = ? AND acknowledged_at IS NULL', [$this->now(), $c['id']]);
        $this->move((int) $a['id'], 'acknowledged', null, null, null, $az->userId);
    }

    /** Records the return of an asset (condition in; "broken" sends it to maintenance). */
    public function returnAsset(Authorizer $az, string $pid, string $condition, string $note): void
    {
        $a = $this->row($pid);
        $le = (int) $a['legal_entity_id'];
        $this->require($az, 'assets.manage', $le);
        if (!in_array($condition, self::CONDITIONS, true)) {
            throw new ValidationException(['condition_in' => 'assets.error.choice']);
        }
        $db = $this->app->db();
        $user = $db->transactional(function () use ($db, $a, $condition, $note, $az): int {
            $c = $db->one('SELECT * FROM as_custody WHERE asset_id = ? AND returned_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE', [$a['id']]);
            if ($c === null) {
                throw new BusinessException('assets.error.not_assigned', [], 409);
            }
            $db->run('UPDATE as_custody SET returned_at = ?, returned_by = ?, condition_in = ? WHERE id = ?', [$this->now(), $az->userId, $condition, $c['id']]);
            $status = $condition === 'broken' ? 'maintenance' : 'available';
            $db->run('UPDATE as_assets SET status = ?, custodian_id = NULL, asset_condition = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$status, $condition, $this->stamp(), $az->userId, $a['id']]);
            $this->move((int) $a['id'], 'returned', $this->snapshot(['custodian_id' => (int) $c['user_id']]), $this->snapshot(['asset_condition' => $condition, 'status' => $status]), trim($note) === '' ? null : $note, $az->userId);
            return (int) $c['user_id'];
        });
        $this->app->notifier()->notify($user, 'assets.assigned', 'assets.notify.returned', ['code' => (string) $a['code'], 'name' => (string) $a['name']], '/assets/mine', 'as.rt:' . $pid . ':' . $this->stamp());
        $this->app->audit()->record('assets.returned', 'success', 'as_asset', $pid, $le, null, ['condition' => $condition]);
    }

    /**
     * Status changes outside custody: maintenance ⇄ available, lost (reason required); never from or to disposed here.
     */
    public function setStatus(Authorizer $az, string $pid, string $status, string $reason): void
    {
        $a = $this->row($pid);
        $le = (int) $a['legal_entity_id'];
        $this->require($az, 'assets.manage', $le);
        $allowed = ['available' => ['maintenance', 'lost'], 'maintenance' => ['available', 'lost'], 'lost' => ['available'], 'assigned' => ['lost']];
        if (!in_array($status, $allowed[$a['status']] ?? [], true)) {
            throw new BusinessException('assets.error.status', [], 409);
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new ValidationException(['status_reason' => 'assets.error.reason']);
        }
        $db = $this->app->db();
        $db->transactional(function () use ($db, $a, $status, $reason, $az): void {
            $x = $db->one('SELECT status FROM as_assets WHERE id = ? FOR UPDATE', [$a['id']]);
            if ($x === null || $x['status'] !== $a['status']) {
                throw new BusinessException('assets.error.changed_meanwhile', [], 409);
            }
            $clearCustody = $a['status'] === 'assigned';
            if ($clearCustody) { // an assigned asset reported lost: the custody ends, the history keeps who had it
                $db->run('UPDATE as_custody SET returned_at = ?, returned_by = ?, condition_in = NULL, note = CONCAT(COALESCE(note, \'\'), ?) WHERE asset_id = ? AND returned_at IS NULL', [$this->now(), $az->userId, ' [lost]', $a['id']]);
            }
            $db->run('UPDATE as_assets SET status = ?' . ($clearCustody ? ', custodian_id = NULL' : '') . ', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$status, $this->stamp(), $az->userId, $a['id']]);
            $this->move((int) $a['id'], 'status', $this->snapshot(['status' => $a['status']]), $this->snapshot(['status' => $status]), trim($reason), $az->userId);
        });
        $this->app->audit()->record('assets.status_changed', 'success', 'as_asset', $pid, $le, null, ['from' => $a['status'], 'to' => $status]);
    }

    /** @return list<array<string, mixed>> what I hold now (with acknowledgement state) and what I returned */
    public function mine(Authorizer $az): array
    {
        return $this->app->db()->all(
            'SELECT a.public_id, a.code, a.name, a.serial_no, a.status, s.code AS site_code, a.location, c.assigned_at, c.acknowledged_at, c.returned_at, c.condition_out, c.condition_in
               FROM as_custody c JOIN as_assets a ON a.id = c.asset_id JOIN sites s ON s.id = a.site_id WHERE c.user_id = ? ORDER BY c.returned_at IS NULL DESC, c.id DESC LIMIT 200',
            [$az->userId]
        );
    }

    // ---- files ----------------------------------------------------------------------------------------------------------------

    public function addFile(Authorizer $az, string $pid, string $kind, ?UploadedFile $f): void
    {
        $a = $this->row($pid);
        $le = (int) $a['legal_entity_id'];
        $this->require($az, 'assets.manage', $le);
        if (!in_array($kind, self::FILE_KINDS, true)) {
            throw new ValidationException(['file_kind' => 'assets.error.choice']);
        }
        if ($f === null || !$f->ok()) {
            throw new ValidationException(['file' => $f?->errorKey() ?? 'assets.error.file']);
        }
        $bytes = $f->bytes();
        try {
            $stored = $this->app->documents()->storeGenerated($az->userId, 'assets.file', $bytes, $f->safeName(), ['title' => 'Asset ' . $a['code'] . ' (' . $kind . ')', 'legal_entity_id' => $le], 'upload', true);
        } catch (ValidationException $e) {
            throw new ValidationException(['file' => (string) (array_values($e->errors)[0] ?? 'assets.error.file')]);
        }
        $this->app->db()->run(
            'INSERT INTO as_files (asset_id, kind, document_pid, sha256, filename, added_by, added_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$a['id'], $kind, $stored['document'], hash('sha256', $bytes), mb_substr($f->safeName(), 0, 200), $az->userId, $this->stamp()]
        );
        $this->move((int) $a['id'], 'file_added', null, (string) json_encode(['kind' => $kind, 'file' => mb_substr($f->safeName(), 0, 200)], JSON_UNESCAPED_UNICODE), null, $az->userId);
    }

    /** @return array{bytes: string, mime: string, filename: string} */
    public function file(Authorizer $az, string $pid, int $fileId): array
    {
        $a = $this->asset($az, $pid);
        if (!$a['can_view']) {
            throw HttpException::notFound();
        }
        $f = $this->app->db()->one('SELECT * FROM as_files WHERE id = ? AND asset_id = ?', [$fileId, $a['id']]) ?? throw HttpException::notFound();
        $doc = $this->app->documents()->moduleRead((string) $f['document_pid'], 'assets', $az->userId, 'asset record');
        return ['bytes' => $doc['bytes'], 'mime' => $doc['mime'], 'filename' => (string) $f['filename']];
    }

    // ---- disposal ------------------------------------------------------------------------------------------------------------

    /** Asks to dispose of an asset that nobody holds; an assets.dispose holder (never the requester) decides. */
    public function requestDisposal(Authorizer $az, string $pid, string $method, string $reason, string $proceeds): string
    {
        $a = $this->row($pid);
        $le = (int) $a['legal_entity_id'];
        $this->require($az, 'assets.manage', $le);
        $e = [];
        if (!in_array($method, ['sell', 'scrap', 'donate', 'write_off'], true)) {
            $e['method'] = 'assets.error.choice';
        }
        if (mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 1000) {
            $e['disposal_reason'] = 'assets.error.reason';
        }
        $proceeds = str_replace(',', '', trim($proceeds));
        if ($proceeds !== '' && preg_match('/^\d{1,12}(\.\d{1,2})?$/', $proceeds) !== 1) {
            $e['proceeds'] = 'assets.error.amount';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $db = $this->app->db();
        $dpid = Ulid::generate($this->app->clock);
        $db->transactional(function () use ($db, $a, $az, $method, $reason, $proceeds, $dpid, $le): void {
            $x = $db->one('SELECT status FROM as_assets WHERE id = ? FOR UPDATE', [$a['id']]);
            if ($x === null || !in_array($x['status'], ['available', 'maintenance', 'lost'], true)) {
                throw new BusinessException('assets.error.dispose_status', [], 409);
            }
            $now = $this->stamp();
            $db->run(
                'INSERT INTO as_disposals (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, asset_id, method, reason, proceeds, prior_status, requested_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$dpid, $now, $az->userId, $now, $az->userId, $le, $a['id'], $method, trim($reason), $proceeds === '' ? null : $proceeds, $x['status'], $az->userId]
            );
            $db->run("UPDATE as_assets SET status = 'disposal_pending', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$now, $az->userId, $a['id']]);
            $this->move((int) $a['id'], 'disposal_requested', $this->snapshot(['status' => $x['status']]), (string) json_encode(['method' => $method, 'proceeds' => $proceeds === '' ? null : $proceeds], JSON_UNESCAPED_UNICODE), trim($reason), $az->userId);
        });
        $title = $this->app->translator()->get('assets.wf_title', ['code' => (string) $a['code'], 'name' => (string) $a['name']]);
        $inst = $this->app->workflow()->start('assets.disposal', $dpid, $title, $le, $az->userId, (int) $a['site_id'], $a['department_id'] === null ? null : (int) $a['department_id']);
        $db->run('UPDATE as_disposals SET workflow_id = ? WHERE public_id = ?', [$inst, $dpid]);
        $this->app->audit()->record('assets.disposal_requested', 'success', 'as_asset', $pid, $le, null, ['method' => $method]);
        return $dpid;
    }

    public function onDisposalApproved(string $dpid, int $approverId): void
    {
        $db = $this->app->db();
        $db->transactional(function () use ($db, $dpid, $approverId): void {
            $d = $db->one('SELECT * FROM as_disposals WHERE public_id = ? FOR UPDATE', [$dpid]);
            if ($d === null || $d['status'] !== 'pending') {
                return;
            }
            $db->run("UPDATE as_disposals SET status = 'approved', decided_at = ?, updated_at = ? WHERE id = ?", [$this->now(), $this->stamp(), $d['id']]);
            $db->run("UPDATE as_assets SET status = 'disposed', disposed_on = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'disposal_pending'", [$this->today(), $this->stamp(), $d['asset_id']]);
            $this->move((int) $d['asset_id'], 'disposed', null, (string) json_encode(['method' => $d['method']], JSON_UNESCAPED_UNICODE), null, $approverId);
        });
    }

    /** @param 'rejected'|'returned'|'cancelled' $outcome */
    public function onDisposalClosed(string $dpid, string $outcome): void
    {
        $db = $this->app->db();
        $db->transactional(function () use ($db, $dpid, $outcome): void {
            $d = $db->one('SELECT * FROM as_disposals WHERE public_id = ? FOR UPDATE', [$dpid]);
            if ($d === null || $d['status'] !== 'pending') {
                return;
            }
            $db->run('UPDATE as_disposals SET status = ?, decided_at = ?, updated_at = ? WHERE id = ?', [$outcome === 'cancelled' ? 'cancelled' : 'rejected', $this->now(), $this->stamp(), $d['id']]);
            $db->run("UPDATE as_assets SET status = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'disposal_pending'", [$d['prior_status'], $this->stamp(), $d['asset_id']]);
            $this->move((int) $d['asset_id'], 'disposal_' . ($outcome === 'cancelled' ? 'cancelled' : 'rejected'), null, $this->snapshot(['status' => $d['prior_status']]), null, null);
        });
    }

    /** @return array<string, mixed>|null */
    public function disposal(string $dpid): ?array
    {
        return $this->app->db()->one(
            'SELECT d.*, a.public_id AS asset_pid, a.code, a.name, a.cost, a.acquired_on, s.code AS site_code, t.name AS type_name FROM as_disposals d JOIN as_assets a ON a.id = d.asset_id
               JOIN sites s ON s.id = a.site_id JOIN as_types t ON t.id = a.type_id WHERE d.public_id = ?',
            [$dpid]
        );
    }

    // ---- QR ------------------------------------------------------------------------------------------------------------------

    /** the asset behind a scanned tag, when the person may open it */
    public function byQr(Authorizer $az, string $token): string
    {
        $pid = $this->app->db()->scalar('SELECT public_id FROM as_assets WHERE qr_token = ?', [$token]);
        if ($pid === null) {
            throw HttpException::notFound();
        }
        $this->asset($az, (string) $pid);
        return (string) $pid;
    }

    /** @param array<string, mixed> $a */
    public function qrSvg(array $a, string $origin, int $scale = 6): string
    {
        return QrRender::svg(QrEncoder::encode(rtrim($origin, '/') . '/assets/q/' . $a['qr_token'], 'M')['modules'], $scale, null, (string) $a['code']);
    }

    public function newQr(Authorizer $az, string $pid): void
    {
        $a = $this->row($pid);
        $this->require($az, 'assets.manage', (int) $a['legal_entity_id']);
        $this->app->db()->run('UPDATE as_assets SET qr_token = ?, updated_at = ? WHERE id = ?', [bin2hex(random_bytes(16)), $this->stamp(), $a['id']]);
        $this->move((int) $a['id'], 'tag_reissued', null, null, null, $az->userId);
    }

    // ---- leavers and housekeeping ---------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> assets still held by people who left (former employee or inactive account) */
    public function leavers(int $le): array
    {
        return $this->app->db()->all(
            "SELECT a.public_id, a.code, a.name, s.code AS site_code, COALESCE(u.display_name, u.username) AS custodian_name, u.username, e.employee_code, e.termination_date, u.status AS user_status, c.assigned_at
               FROM as_custody c JOIN as_assets a ON a.id = c.asset_id JOIN sites s ON s.id = a.site_id JOIN users u ON u.id = c.user_id LEFT JOIN employees e ON e.id = u.employee_id
              WHERE c.returned_at IS NULL AND a.legal_entity_id = ? AND (u.status <> 'active' OR e.status = 'former') ORDER BY e.termination_date, a.code",
            [$le]
        );
    }

    /** Daily: tells the asset admins once about each asset still held by someone who left. */
    public function tick(): int
    {
        $db = $this->app->db();
        $n = 0;
        foreach (
            $db->all(
                "SELECT c.id, a.public_id, a.code, a.name, a.legal_entity_id, COALESCE(u.display_name, u.username) AS custodian_name FROM as_custody c JOIN as_assets a ON a.id = c.asset_id
                   JOIN users u ON u.id = c.user_id LEFT JOIN employees e ON e.id = u.employee_id
                  WHERE c.returned_at IS NULL AND c.leaver_notified = 0 AND (u.status <> 'active' OR e.status = 'former') LIMIT 500"
            ) as $c
        ) {
            $db->run('UPDATE as_custody SET leaver_notified = 1 WHERE id = ?', [$c['id']]);
            foreach ($this->staff((int) $c['legal_entity_id'], 'assets.manage') as $u) {
                $this->app->notifier()->notify($u, 'assets.leaver', 'assets.notify.leaver', ['code' => (string) $c['code'], 'name' => (string) $c['name'], 'who' => (string) $c['custodian_name']], '/assets/a/' . $c['public_id'], 'as.lv:' . $c['id'] . ':' . $u);
            }
            $n++;
        }
        return $n;
    }

    public static function tickJob(App $app): string
    {
        return 'assets: ' . self::of($app)->tick();
    }

    /** @return list<int> */
    private function staff(int $le, string $perm): array
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

    // ---- report ----------------------------------------------------------------------------------------------------------------

    /**
     * Counts and recorded cost by site / type / department / status, people holding assets, leavers. Cost is the recorded
     * purchase cost — no depreciation (the company's accounting rules are not set here).
     *
     * @return array<string, mixed>
     */
    public function report(Authorizer $az, int $le): array
    {
        $this->require($az, 'assets.view', $le);
        $db = $this->app->db();
        $group = static fn (string $label, string $join): string => "SELECT {$label} AS label, COUNT(*) AS n, COALESCE(SUM(a.cost), 0) AS cost, SUM(a.cost IS NULL) AS no_cost
               FROM as_assets a {$join} WHERE a.legal_entity_id = ? AND a.status <> 'disposed' GROUP BY label ORDER BY label";
        return [
            'by_site' => $db->all($group('s.code', 'JOIN sites s ON s.id = a.site_id'), [$le]),
            'by_type' => $db->all($group('t.name', 'JOIN as_types t ON t.id = a.type_id'), [$le]),
            'by_department' => $db->all($group("COALESCE(d.code, '—')", 'LEFT JOIN departments d ON d.id = a.department_id'), [$le]),
            'by_status' => $db->all($group('a.status', ''), [$le]),
            'holders' => $db->all(
                "SELECT COALESCE(u.display_name, u.username) AS label, u.username, COUNT(*) AS n, SUM(c.acknowledged_at IS NULL) AS unacknowledged FROM as_custody c JOIN as_assets a ON a.id = c.asset_id JOIN users u ON u.id = c.user_id
                  WHERE c.returned_at IS NULL AND a.legal_entity_id = ? GROUP BY u.id ORDER BY label",
                [$le]
            ),
            'leavers' => $this->leavers($le),
            'warranty' => $db->all(
                "SELECT a.public_id, a.code, a.name, a.warranty_until FROM as_assets a WHERE a.legal_entity_id = ? AND a.status <> 'disposed' AND a.warranty_until BETWEEN ? AND ? ORDER BY a.warranty_until",
                [$le, $this->today(), $this->nowLocal()->modify('+90 days')->format('Y-m-d')]
            ),
        ];
    }

    /** @return array{bytes: string, name: string} the register (not disposed) as Excel */
    public function export(Authorizer $az, int $le): array
    {
        $rows = $this->listing($az, $le, ['all' => '1'], 5000);
        $t = $this->app->translator();
        $h = ['code', 'name', 'type', 'class', 'serial_no', 'manufacturer', 'model', 'acquired_on', 'cost', 'supplier', 'purchase_ref', 'warranty_until', 'site', 'location', 'department', 'custodian', 'status', 'condition'];
        $bytes = Xlsx::write(['assets' => [
            'headers' => array_map(static fn (string $k): string => $t->get('assets.field.' . $k), $h),
            'rows' => array_map(static fn (array $a): array => [
                (string) $a['code'], (string) $a['name'], (string) $a['type_name'], $t->get('assets.class.' . $a['asset_class']), (string) ($a['serial_no'] ?? ''), (string) ($a['manufacturer'] ?? ''), (string) ($a['model'] ?? ''),
                (string) ($a['acquired_on'] ?? ''), (string) ($a['cost'] ?? ''), (string) ($a['supplier'] ?? ''), (string) ($a['purchase_ref'] ?? ''), (string) ($a['warranty_until'] ?? ''), (string) $a['site_code'],
                (string) ($a['location'] ?? ''), (string) ($a['dept_code'] ?? ''), (string) ($a['custodian_name'] ?? ''), $t->get('assets.status.' . $a['status']), $t->get('assets.condition.' . $a['asset_condition']),
            ], $rows),
            'numeric' => [8], 'widths' => [14, 32, 18, 14, 18, 14, 14, 12, 12, 24, 14, 12, 8, 20, 10, 24, 14, 10],
        ]], 'Blue OS asset register');
        $code = (string) $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$le]);
        $this->app->audit()->record('assets.exported', 'success', 'as_register', $code, $le, null, ['rows' => count($rows), 'sha256' => hash('sha256', $bytes)]);
        return ['bytes' => $bytes, 'name' => 'assets-' . $code . '-' . $this->today() . '.xlsx'];
    }
}

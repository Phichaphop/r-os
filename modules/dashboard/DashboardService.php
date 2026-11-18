<?php

declare(strict_types=1);

namespace Blue\Modules\Dashboard;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Kpi\MeasureCatalog;
use Blue\Core\Kpi\MeasureQuery;
use Blue\Core\Org\Translations;
use Blue\Core\Support\Ulid;
use Throwable;

/**
 * M46 Executive dashboard (phase 5E-5, DEC-80).
 *
 *  dictionary  each company defines its KPIs: a measure offered by a module (the module owns the formula, shown as the
 *              definition), names, purpose, owner, refresh interval, which way is better and two optional thresholds.
 *              Only one live KPI per measure and per name — the same name never means two formulas. Changing a live KPI
 *              makes a new version with a reason; values remember the version they were computed with.
 *  values      computed from the source module on demand and kept with their time: pending (never computed) → fresh,
 *              stale (older than the refresh interval), partial (a filter the measure cannot apply — company total
 *              shown), error (kept with the last good value). A job refreshes the company figures and alerts owners.
 *  access      dashboard.view: whole companies, or only the sites / departments of the viewer's scope — a measure that
 *              cannot be narrowed to them is not shown ("restricted"). Links to the source screen only with the source
 *              permission. Export and snapshots (kept as evidence for a decision) are audited.
 */
final class DashboardService
{
    public const REFRESH = [60, 360, 1440];

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    public function today(): string
    {
        return $this->app->clock->now()->setTimezone(new \DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok')))->format('Y-m-d');
    }

    /** @return array<string, array<string, mixed>> */
    public function catalog(): array
    {
        return MeasureCatalog::all($this->app);
    }

    // ---- access -----------------------------------------------------------------------------------------------------------

    /** @return list<int> companies the person may see on the dashboard (whole or in part) */
    public function companies(Authorizer $az, string $perm = 'dashboard.view'): array
    {
        $s = $az->scope($perm);
        if ($s->isEmpty()) {
            return [];
        }
        $out = [];
        foreach ($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code') as $le) {
            $id = (int) $le['id'];
            if ($s->coversLegalEntity($id) || $this->partOf($az, $id, $perm) !== ['sites' => [], 'departments' => []]) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /** @return array{sites: list<int>, departments: list<int>} sites / departments of the company in the person's scope */
    private function partOf(Authorizer $az, int $le, string $perm = 'dashboard.view'): array
    {
        $s = $az->scope($perm);
        $db = $this->app->db();
        $pick = static fn (string $table, array $ids): array => $ids === [] ? [] : array_map('intval', array_column($db->all(
            "SELECT id FROM {$table} WHERE legal_entity_id = ? AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')',
            [$le, ...$ids]
        ), 'id'));
        return ['sites' => $pick('sites', $s->siteIds), 'departments' => $pick('departments', $s->departmentIds)];
    }

    /**
     * The filter actually applied: requested sites / departments of the company, narrowed to the person's scope.
     *
     * @param list<string> $sitePids
     * @param list<string> $deptPids
     * @return array{le: int, sites: list<int>, departments: list<int>, whole: bool, asked: bool}
     */
    public function filter(Authorizer $az, int $le, array $sitePids, array $deptPids): array
    {
        if (!in_array($le, $this->companies($az), true)) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        $ids = static fn (string $table, array $pids): array => $pids === [] ? [] : array_map('intval', array_column($db->all(
            "SELECT id FROM {$table} WHERE legal_entity_id = ? AND public_id IN (" . implode(',', array_fill(0, count($pids), '?')) . ')',
            [$le, ...array_values($pids)]
        ), 'id'));
        $sites = $ids('sites', $sitePids);
        $depts = $this->withChildren($ids('departments', $deptPids));
        $asked = $sites !== [] || $depts !== [];
        if ($az->scope('dashboard.view')->coversLegalEntity($le)) {
            return ['le' => $le, 'sites' => $sites, 'departments' => $depts, 'whole' => true, 'asked' => $asked];
        }
        $part = $this->partOf($az, $le);
        $narrow = static fn (array $want, array $allowed): array => $allowed === [] ? [] : ($want === [] ? $allowed : array_values(array_intersect($want, $allowed)));
        $s = $narrow($sites, $part['sites']);
        $d = $narrow($depts, $part['departments']);
        if ($s === [] && $d === []) { // asked only for things outside the scope: fall back to the whole scope
            [$s, $d] = [$part['sites'], $part['departments']];
        }
        return ['le' => $le, 'sites' => $s, 'departments' => $d, 'whole' => false, 'asked' => $asked];
    }

    /**
     * A unit and the units below it (a division covers its departments and sections — DEC-69).
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private function withChildren(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $children = [];
        foreach ($this->app->db()->all('SELECT id, parent_id FROM departments WHERE parent_id IS NOT NULL') as $d) {
            $children[(int) $d['parent_id']][] = (int) $d['id'];
        }
        $out = $ids;
        for ($i = 0; $i < count($out); $i++) {
            foreach ($children[$out[$i]] ?? [] as $c) {
                if (!in_array($c, $out, true)) {
                    $out[] = $c;
                }
            }
        }
        sort($out);
        return $out;
    }

    /** @return array{sites: array<string, string>, departments: array<string, string>} options the person may pick */
    public function filterOptions(Authorizer $az, int $le): array
    {
        $db = $this->app->db();
        $whole = $az->scope('dashboard.view')->coversLegalEntity($le);
        $part = $whole ? null : $this->partOf($az, $le);
        $tr = new Translations($db);
        $loc = $this->app->translator()->locale();
        $opts = static function (string $table, string $type, ?array $only) use ($db, $le, $tr, $loc): array {
            $rows = $db->all("SELECT id, public_id, code FROM {$table} WHERE legal_entity_id = ? AND status = 'active' ORDER BY code", [$le]);
            $names = $tr->many($type, array_map(static fn (array $r): int => (int) $r['id'], $rows));
            $out = [];
            foreach ($rows as $r) {
                if ($only === null || in_array((int) $r['id'], $only, true)) {
                    $n = Translations::pick($names[(int) $r['id']] ?? [], $loc);
                    $out[(string) $r['public_id']] = $r['code'] . ($n !== '' ? ' · ' . $n : '');
                }
            }
            return $out;
        };
        return ['sites' => $opts('sites', 'site', $part['sites'] ?? null), 'departments' => $opts('departments', 'department', $part['departments'] ?? null)];
    }

    // ---- dictionary ---------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function kpis(int $le, bool $activeOnly = false): array
    {
        return $this->app->db()->all(
            'SELECT k.*, u.display_name AS owner_name, u.username AS owner_username FROM dash_kpis k LEFT JOIN users u ON u.id = k.owner_user_id
              WHERE k.legal_entity_id = ?' . ($activeOnly ? " AND k.status = 'active'" : '') . " ORDER BY k.status = 'retired', k.sort_order, k.code",
            [$le]
        );
    }

    /** @return array<string, mixed> */
    public function kpi(Authorizer $az, string $pid, bool $manage = false): array
    {
        $k = $this->app->db()->one('SELECT k.*, u.display_name AS owner_name, u.username AS owner_username FROM dash_kpis k LEFT JOIN users u ON u.id = k.owner_user_id WHERE k.public_id = ?', [$pid]);
        if ($k === null || !in_array((int) $k['legal_entity_id'], $this->companies($az, $manage ? 'dashboard.manage' : 'dashboard.view'), true)) {
            throw HttpException::notFound();
        }
        if ($manage && !$az->scope('dashboard.manage')->coversLegalEntity((int) $k['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        return $k;
    }

    /** @return list<array<string, mixed>> */
    public function versions(int $kpiId): array
    {
        return $this->app->db()->all('SELECT v.*, u.display_name AS actor FROM dash_kpi_versions v LEFT JOIN users u ON u.id = v.changed_by WHERE v.kpi_id = ? ORDER BY v.version DESC', [$kpiId]);
    }

    /** @param array<string, string> $in code, measure, name_th, name_en, purpose, direction, warn_at, alert_at, refresh_minutes, owner, status, sort_order, reason */
    public function saveKpi(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        if (!$az->scope('dashboard.manage')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        $cur = $pid !== null ? $db->one('SELECT * FROM dash_kpis WHERE public_id = ? AND legal_entity_id = ?', [$pid, $le]) : null;
        if ($pid !== null && $cur === null) {
            throw HttpException::notFound();
        }
        $errors = [];
        $code = strtoupper(trim($in['code'] ?? ''));
        if (preg_match('/^[A-Z][A-Z0-9_-]{1,29}$/', $code) !== 1) {
            $errors['code'] = 'dashboard.error.code';
        } elseif ($db->scalar('SELECT id FROM dash_kpis WHERE legal_entity_id = ? AND code = ? AND id <> ?', [$le, $code, (int) ($cur['id'] ?? 0)]) !== null) {
            $errors['code'] = 'dashboard.error.code_taken';
        }
        $measure = trim($in['measure'] ?? '');
        $catalog = $this->catalog();
        if (!isset($catalog[$measure])) {
            $errors['measure'] = 'dashboard.error.measure';
        }
        $status = in_array($in['status'] ?? '', ['draft', 'active', 'retired'], true) ? (string) $in['status'] : 'draft';
        $live = $status !== 'retired';
        if (!isset($errors['measure']) && $live && $db->scalar("SELECT id FROM dash_kpis WHERE legal_entity_id = ? AND measure = ? AND status <> 'retired' AND id <> ?", [$le, $measure, (int) ($cur['id'] ?? 0)]) !== null) {
            $errors['measure'] = 'dashboard.error.measure_taken';
        }
        $names = [];
        foreach (['name_th', 'name_en'] as $k) {
            $names[$k] = mb_substr(trim($in[$k] ?? ''), 0, 120);
            if (mb_strlen($names[$k]) < 2) {
                $errors[$k] = 'dashboard.error.name';
            } elseif ($live && $db->scalar("SELECT id FROM dash_kpis WHERE legal_entity_id = ? AND {$k} = ? AND status <> 'retired' AND id <> ?", [$le, $names[$k], (int) ($cur['id'] ?? 0)]) !== null) {
                $errors[$k] = 'dashboard.error.name_taken';
            }
        }
        $num = [];
        foreach (['warn_at', 'alert_at'] as $k) {
            $v = str_replace(',', '', trim($in[$k] ?? ''));
            if ($v !== '' && preg_match('/^-?\d{1,10}(\.\d{1,2})?$/', $v) !== 1) {
                $errors[$k] = 'dashboard.error.number';
            }
            $num[$k] = $v === '' ? null : $v;
        }
        $direction = in_array($in['direction'] ?? '', ['up', 'down', 'none'], true) ? (string) $in['direction'] : 'none';
        if (($num['warn_at'] !== null || $num['alert_at'] !== null) && $direction === 'none') {
            $errors['direction'] = 'dashboard.error.direction';
        }
        if (
            !isset($errors['warn_at'], $errors['alert_at']) && $num['warn_at'] !== null && $num['alert_at'] !== null && $direction !== 'none'
            && ($direction === 'down' ? (float) $num['alert_at'] < (float) $num['warn_at'] : (float) $num['alert_at'] > (float) $num['warn_at'])
        ) {
            $errors['alert_at'] = 'dashboard.error.thresholds';
        }
        $refresh = (int) ($in['refresh_minutes'] ?? 60);
        if (!in_array($refresh, self::REFRESH, true)) {
            $errors['refresh_minutes'] = 'form.invalid_choice';
        }
        $owner = null;
        $ou = trim($in['owner'] ?? '');
        if ($ou !== '') {
            $owner = $db->scalar("SELECT id FROM users WHERE username = ? AND status = 'active'", [$ou]);
            if ($owner === null) {
                $errors['owner'] = 'dashboard.error.owner';
            }
        }
        if ($status === 'active' && $owner === null && !isset($errors['owner'])) {
            $errors['owner'] = 'dashboard.error.owner_required';
        }
        $purpose = mb_substr(trim($in['purpose'] ?? ''), 0, 500);
        $reason = mb_substr(trim($in['reason'] ?? ''), 0, 300);
        $def = ['code' => $code, 'measure' => $measure, 'name_th' => $names['name_th'], 'name_en' => $names['name_en'], 'purpose' => $purpose !== '' ? $purpose : null, 'direction' => $direction,
            'warn_at' => $num['warn_at'], 'alert_at' => $num['alert_at'], 'refresh_minutes' => $refresh, 'owner_user_id' => $owner !== null ? (int) $owner : null, 'status' => $status];
        $changed = $cur !== null && $this->defChanged($cur, $def);
        if ($changed && $cur['status'] !== 'draft' && mb_strlen($reason) < 5) {
            $errors['reason'] = 'dashboard.error.reason';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $now = $this->now();
        $sort = max(0, min(999, (int) ($in['sort_order'] ?? 100)));
        if ($cur === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run('INSERT INTO dash_kpis (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, code, measure, name_th, name_en, purpose, direction, warn_at, alert_at, refresh_minutes, owner_user_id, status, version, sort_order)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)', [$pid, $now, $az->userId, $now, $az->userId, $le, ...array_values($def), $sort]);
            $id = (int) $db->scalar('SELECT id FROM dash_kpis WHERE public_id = ?', [$pid]);
            $this->version($id, 1, $def, $reason !== '' ? $reason : null, $az->userId);
            $this->app->audit()->record('dashboard.kpi_created', 'success', 'dash_kpi', $pid, $le, null, ['code' => $code, 'measure' => $measure]);
            return $pid;
        }
        $version = (int) $cur['version'] + ($changed && $cur['status'] !== 'draft' ? 1 : 0);
        $db->run('UPDATE dash_kpis SET code = ?, measure = ?, name_th = ?, name_en = ?, purpose = ?, direction = ?, warn_at = ?, alert_at = ?, refresh_minutes = ?, owner_user_id = ?, status = ?,
                  version = ?, sort_order = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [...array_values($def), $version, $sort, $now, $az->userId, $cur['id']]);
        if ($changed) {
            $db->run('DELETE FROM dash_kpi_versions WHERE kpi_id = ? AND version = ?', [$cur['id'], $version]); // a draft keeps one version, rewritten
            $this->version((int) $cur['id'], $version, $def, $reason !== '' ? $reason : null, $az->userId);
        }
        $this->app->audit()->record('dashboard.kpi_saved', 'success', 'dash_kpi', (string) $pid, $le, $reason !== '' ? $reason : null, ['code' => $code, 'version' => $version, 'status' => $status]);
        return (string) $pid;
    }

    /**
     * @param array<string, mixed> $cur
     * @param array<string, mixed> $def
     */
    private function defChanged(array $cur, array $def): bool
    {
        foreach ($def as $k => $v) {
            $a = $cur[$k] ?? null;
            if (in_array($k, ['warn_at', 'alert_at'], true)) {
                if (($a === null) !== ($v === null) || ($a !== null && (float) $a !== (float) $v)) {
                    return true;
                }
            } elseif ((string) ($a ?? '') !== (string) ($v ?? '')) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $def */
    private function version(int $kpiId, int $version, array $def, ?string $reason, int $actor): void
    {
        $catalog = $this->catalog();
        $def['formula'] = isset($catalog[$def['measure']]) ? $this->app->translator()->get((string) $catalog[$def['measure']]['formula']) : null;
        $this->app->db()->run(
            'INSERT INTO dash_kpi_versions (kpi_id, version, definition_json, reason, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$kpiId, $version, (string) json_encode($def, JSON_UNESCAPED_UNICODE), $reason, $actor, $this->now()]
        );
    }

    // ---- values -------------------------------------------------------------------------------------------------------------

    /** @return array{0: string, 1: string} first day and last day (or today) of a month "YYYY-MM" */
    public function period(string $month): array
    {
        $today = $this->today();
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1 || $month > substr($today, 0, 7)) {
            $month = substr($today, 0, 7);
        }
        $from = $month . '-01';
        $end = self::day($from)->modify('last day of this month')->format('Y-m-d');
        return [$from, min($end, $today)];
    }

    private static function day(string $ymd): \DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new \DateTimeZone('UTC'));
        if ($d === false) {
            throw new \InvalidArgumentException('date');
        }
        return $d;
    }

    public static function prevMonth(string $month, int $n = 1): string
    {
        return self::day($month . '-01')->modify('-' . $n . ' month')->format('Y-m');
    }

    /**
     * One KPI value for a filter and period (cached; computed when missing, outdated version, or forced).
     *
     * @param array<string, mixed> $k
     * @param array{le: int, sites: list<int>, departments: list<int>, whole: bool, asked: bool} $f
     * @return array{value: float|null, parts: array<string, float|int>, status: string, note: string|null, computed_at: string|null}
     */
    public function value(array $k, array $f, string $from, string $to, bool $force = false): array
    {
        $m = $this->catalog()[(string) $k['measure']] ?? null;
        if ($m === null) {
            return ['value' => null, 'parts' => [], 'status' => 'error', 'note' => 'dashboard.note.no_measure', 'computed_at' => null];
        }
        $dims = (array) $m['dims'];
        $needs = ($f['sites'] !== [] && !in_array('site', $dims, true)) || ($f['departments'] !== [] && !in_array('department', $dims, true));
        if ($needs && !$f['whole']) {
            return ['value' => null, 'parts' => [], 'status' => 'restricted', 'note' => 'dashboard.note.restricted', 'computed_at' => null];
        }
        $sites = in_array('site', $dims, true) ? $f['sites'] : [];
        $depts = in_array('department', $dims, true) ? $f['departments'] : [];
        $key = sha1((string) json_encode([$f['le'], $sites, $depts, $needs]));
        $db = $this->app->db();
        $row = $db->one('SELECT * FROM dash_values WHERE kpi_id = ? AND filter_key = ? AND period_from = ?', [$k['id'], $key, $from]);
        if ($row !== null && !$force && (int) $row['version'] === (int) $k['version'] && (string) $row['period_to'] === $to) {
            return $this->shape($row, (int) $k['refresh_minutes']);
        }
        $status = $needs ? 'partial' : 'fresh';
        $note = $needs ? 'dashboard.note.filter_ignored' : null;
        $value = null;
        $parts = [];
        try {
            $provider = $m['provider'];
            $res = $provider::compute($this->app, (string) $k['measure'], new MeasureQuery($f['le'], $from, $to, $sites, $depts));
            $value = $res->value;
            $parts = $res->parts;
        } catch (Throwable $e) {
            $this->app->logger->error('dashboard.compute_failed', ['kpi' => (string) $k['code'], 'error' => $e->getMessage()]);
            $status = 'error';
            $note = 'dashboard.note.error';
            $value = $row !== null && $row['value'] !== null ? (float) $row['value'] : null; // keep the last good value
            $parts = $row !== null ? (array) json_decode((string) ($row['parts_json'] ?? '[]'), true) : [];
        }
        $now = $this->now();
        $db->run(
            'INSERT INTO dash_values (kpi_id, version, filter_key, period_from, period_to, value, parts_json, status, note, computed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE version = VALUES(version), period_to = VALUES(period_to), value = VALUES(value), parts_json = VALUES(parts_json), status = VALUES(status), note = VALUES(note),
                  computed_at = IF(VALUES(status) = \'error\', computed_at, VALUES(computed_at))',
            [$k['id'], $k['version'], $key, $from, $to, $value, $parts !== [] ? json_encode($parts) : null, $status, $note, $now]
        );
        return $this->shape((array) $db->one('SELECT * FROM dash_values WHERE kpi_id = ? AND filter_key = ? AND period_from = ?', [$k['id'], $key, $from]), (int) $k['refresh_minutes']);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{value: float|null, parts: array<string, float|int>, status: string, note: string|null, computed_at: string|null}
     */
    private function shape(array $row, int $refresh): array
    {
        $status = (string) $row['status'];
        $age = $this->app->clock->now()->getTimestamp() - (\DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', (string) $row['computed_at'], new \DateTimeZone('UTC')) ?: $this->app->clock->now())->getTimestamp();
        if ($status === 'fresh' && $age > $refresh * 60) {
            $status = 'stale';
        }
        return ['value' => $row['value'] !== null ? (float) $row['value'] : null, 'parts' => (array) json_decode((string) ($row['parts_json'] ?? '[]'), true), 'status' => $status,
            'note' => $row['note'] !== null ? (string) $row['note'] : null, 'computed_at' => (string) $row['computed_at']];
    }

    /** @param array<string, mixed> $k */
    public function level(array $k, ?float $v): ?string
    {
        if ($v === null || $k['direction'] === 'none') {
            return null;
        }
        $down = $k['direction'] === 'down';
        foreach (['alert' => $k['alert_at'], 'warn' => $k['warn_at']] as $level => $t) {
            if ($t !== null && ($down ? $v >= (float) $t : $v <= (float) $t)) {
                return $level;
            }
        }
        return null;
    }

    /**
     * Cards: the month, the month before, and up to 6 months of trend for each live KPI.
     *
     * @param array{le: int, sites: list<int>, departments: list<int>, whole: bool, asked: bool} $f
     * @return list<array<string, mixed>>
     */
    public function board(Authorizer $az, array $f, string $month, bool $force = false): array
    {
        $catalog = $this->catalog();
        [$from, $to] = $this->period($month);
        $month = substr($from, 0, 7);
        $current = $month === substr($this->today(), 0, 7);
        $cards = [];
        foreach ($this->kpis($f['le'], true) as $k) {
            $m = $catalog[(string) $k['measure']] ?? null;
            $point = (bool) ($m['point'] ?? false);
            $card = ['k' => $k, 'm' => $m, 'point' => $point, 'month' => $month, 'from' => $from, 'to' => $to, 'prev' => null, 'trend' => [],
                'drill' => $m !== null && $m['drill'] !== null && $az->can((string) $m['drill'][1]) ? (string) $m['drill'][0] : null];
            if ($point && !$current) {
                $card['v'] = ['value' => null, 'parts' => [], 'status' => 'now_only', 'note' => 'dashboard.note.now_only', 'computed_at' => null];
            } else {
                $card['v'] = $this->value($k, $f, $point ? $this->today() : $from, $point ? $this->today() : $to, $force);
            }
            if (!$point && $m !== null && $card['v']['status'] !== 'restricted') {
                for ($i = 5; $i >= 0; $i--) {
                    $mm = self::prevMonth($month, $i);
                    [$a, $b] = $this->period($mm);
                    $val = $i === 0 ? $card['v'] : $this->value($k, $f, $a, $b);
                    $card['trend'][] = ['month' => $mm, 'value' => $val['value']];
                    if ($i === 1) {
                        $card['prev'] = $val['value'];
                    }
                }
            }
            $card['level'] = $this->level($k, $card['v']['value']);
            $cards[] = $card;
        }
        return $cards;
    }

    /** Job: refresh company figures of live KPIs for the current month and tell owners when a threshold is crossed. */
    public static function refreshJob(App $app): int
    {
        $svc = self::of($app);
        $db = $app->db();
        $today = $svc->today();
        [$from, $to] = $svc->period(substr($today, 0, 7));
        $n = 0;
        foreach ($db->all("SELECT * FROM dash_kpis WHERE status = 'active'") as $k) {
            $m = $svc->catalog()[(string) $k['measure']] ?? null;
            if ($m === null) {
                continue;
            }
            $point = (bool) ($m['point'] ?? false);
            $f = ['le' => (int) $k['legal_entity_id'], 'sites' => [], 'departments' => [], 'whole' => true, 'asked' => false];
            $cur = $svc->value($k, $f, $point ? $today : $from, $point ? $today : $to);
            $v = in_array($cur['status'], ['stale'], true) ? $svc->value($k, $f, $point ? $today : $from, $point ? $today : $to, true) : $cur;
            $n++;
            $level = $svc->level($k, $v['value']);
            if ($level === null || $k['owner_user_id'] === null) {
                continue;
            }
            $st = $db->run('INSERT IGNORE INTO dash_alerts (kpi_id, period_from, level, value, notified_at) VALUES (?, ?, ?, ?, ?)', [$k['id'], $from, $level, $v['value'], $svc->now()]);
            if ($st->rowCount() > 0) {
                $app->notifier()->notify(
                    (int) $k['owner_user_id'],
                    'dashboard.alert',
                    'dashboard.notify.' . $level,
                    ['kpi' => (string) $k['name_th'], 'value' => (string) round((float) $v['value'], 2)],
                    '/dashboard?le=' . rawurlencode((string) $db->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$k['legal_entity_id']])),
                    'dash.alert:' . $k['id'] . ':' . $from . ':' . $level
                );
            }
        }
        return $n;
    }

    // ---- export + snapshots ---------------------------------------------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $cards
     * @return list<array<string, mixed>> what is kept / exported: one line per KPI
     */
    public function lines(array $cards): array
    {
        $t = $this->app->translator();
        $out = [];
        foreach ($cards as $c) {
            $out[] = ['code' => (string) $c['k']['code'], 'name' => (string) $c['k']['name_th'], 'version' => (int) $c['k']['version'], 'measure' => (string) $c['k']['measure'],
                'formula' => $c['m'] !== null ? $t->get((string) $c['m']['formula']) : '', 'unit' => (string) ($c['m']['unit'] ?? ''), 'from' => $c['point'] ? $this->today() : (string) $c['from'],
                'to' => $c['point'] ? $this->today() : (string) $c['to'], 'value' => $c['v']['value'], 'status' => (string) $c['v']['status'], 'computed_at' => $c['v']['computed_at'],
                'parts' => $c['v']['parts']];
        }
        return $out;
    }

    /**
     * @param array{le: int, sites: list<int>, departments: list<int>, whole: bool, asked: bool} $f
     * @param list<array<string, mixed>> $cards
     */
    public function snapshot(Authorizer $az, array $f, string $month, array $cards, string $note): string
    {
        $note = mb_substr(trim($note), 0, 500);
        if (mb_strlen($note) < 5) {
            throw new ValidationException(['note' => 'dashboard.error.note']);
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $this->app->db()->run('INSERT INTO dash_snapshots (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, period, filter_json, values_json, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $pid, $now, $az->userId, $now, $az->userId, $f['le'], $month, (string) json_encode(['sites' => $f['sites'], 'departments' => $f['departments'], 'whole' => $f['whole']]),
            (string) json_encode($this->lines($cards), JSON_UNESCAPED_UNICODE), $note,
        ]);
        $this->app->audit()->record('dashboard.snapshot', 'success', 'dash_snapshot', $pid, $f['le'], $note, ['kpis' => count($cards)]);
        return $pid;
    }

    /** @return list<array<string, mixed>> */
    public function snapshots(Authorizer $az, int $le): array
    {
        $rows = $this->app->db()->all('SELECT s.*, u.display_name AS actor FROM dash_snapshots s LEFT JOIN users u ON u.id = s.created_by WHERE s.legal_entity_id = ? ORDER BY s.id DESC LIMIT 100', [$le]);
        return $az->scope('dashboard.view')->coversLegalEntity($le) ? $rows : array_values(array_filter($rows, static fn (array $r): bool => (int) $r['created_by'] === $az->userId));
    }

    /** @return array<string, mixed> */
    public function snapshotRow(Authorizer $az, string $pid): array
    {
        $s = $this->app->db()->one('SELECT s.*, u.display_name AS actor FROM dash_snapshots s LEFT JOIN users u ON u.id = s.created_by WHERE s.public_id = ?', [$pid]);
        if (
            $s === null || !in_array((int) $s['legal_entity_id'], $this->companies($az), true)
            || (!$az->scope('dashboard.view')->coversLegalEntity((int) $s['legal_entity_id']) && (int) $s['created_by'] !== $az->userId)
        ) {
            throw HttpException::notFound();
        }
        $this->app->audit()->record('dashboard.snapshot_viewed', 'success', 'dash_snapshot', $pid, (int) $s['legal_entity_id']);
        return $s;
    }
}

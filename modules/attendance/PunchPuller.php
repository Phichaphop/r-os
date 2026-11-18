<?php

declare(strict_types=1);

namespace Blue\Modules\Attendance;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Integrations\IntegrationAuth;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\EmployeeService;

/**
 * Scheduled pull from the Punch API of the Time Collector (DEC-30/33, P0-02 §9, P0-03 §12).
 *
 *   integration "hip.time_collector" must be ACTIVE with an endpoint and at least one key (Integrations screen);
 *   GET {endpoint}/v1/punches?after={cursor}&limit=500, signed with HMAC (newest key first, then the previous one
 *   during a rotation) → every item validated → INSERT IGNORE into att_raw_punches (unique source + event id)
 *   → cursor saved only up to the last page that worked → days of the affected employees recalculated.
 * Runs every 4 hours (adjustable on the collector page) and on "pull now". One run at a time (DB lock).
 * Items that carry anything that looks like biometric data are refused whole (DEC-25).
 */
final class PunchPuller
{
    public const CODE = 'hip.time_collector';
    public const PAGE = 500;
    public const MAX_PAGES = 20;
    public const MAX_BYTES = 4 * 1024 * 1024;
    private const LOCK = 'blue_att_pull';
    private const BIOMETRIC = ['template', 'fingerprint', 'finger_template', 'face_image', 'photo', 'image', 'picture', 'citizen_id', 'id_card', 'national_id'];

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    /** Scheduled job entry point. */
    public static function job(App $app): int
    {
        if (!$app->db()->tableExists('att_pull_runs')) {
            return 0;
        }
        $r = self::of($app)->run('schedule', null);
        return (int) ($r['inserted'] ?? 0);
    }

    /**
     * "Pull now" (attendance.collector).
     *
     * @return array<string, mixed>
     */
    public function pullNow(Authorizer $az): array
    {
        if ($az->scope('attendance.collector')->isEmpty()) {
            throw HttpException::forbidden();
        }
        $r = $this->run('manual', $az->userId);
        if ($r === null) {
            throw new BusinessException('attendance.error.collector_not_ready', [], 409);
        }
        return $r;
    }

    /**
     * Is the collector usable? Returns the integration row or a reason key.
     *
     * @return array{ok: bool, reason: ?string, integration: ?array<string, mixed>}
     */
    public function readiness(): array
    {
        $i = $this->app->integrations()->byCode(self::CODE);
        if ($i === null) {
            return ['ok' => false, 'reason' => 'attendance.collector.not_registered', 'integration' => null];
        }
        if ($i['status'] !== 'active') {
            return ['ok' => false, 'reason' => 'attendance.collector.not_active', 'integration' => $i];
        }
        $url = (string) ($i['endpoint'] ?? '');
        if ($url === '' || !self::urlAllowed($url, $this->app->config->isProduction())) {
            return ['ok' => false, 'reason' => $url === '' ? 'attendance.collector.no_endpoint' : 'attendance.collector.https_required', 'integration' => $i];
        }
        if ($this->app->integrations()->outboundCredentials((int) $i['id']) === []) {
            return ['ok' => false, 'reason' => 'attendance.collector.no_key', 'integration' => $i];
        }
        return ['ok' => true, 'reason' => null, 'integration' => $i];
    }

    /** HTTPS always — plain HTTP only to this computer or the office network outside production (simulation). */
    public static function urlAllowed(string $url, bool $production): bool
    {
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host']) || isset($p['user']) || isset($p['query']) || isset($p['fragment'])) {
            return false;
        }
        if ($p['scheme'] === 'https') {
            return true;
        }
        if ($p['scheme'] !== 'http' || $production) {
            return false;
        }
        $h = strtolower((string) $p['host']);
        return $h === 'localhost' || $h === '127.0.0.1' || $h === '::1' || $h === '[::1]'
            || (filter_var($h, FILTER_VALIDATE_IP) !== false && filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false);
    }

    /**
     * One pull run. Null when the collector is not set up (nothing recorded — a quiet skip for the scheduler).
     *
     * @return array<string, mixed>|null the att_pull_runs row
     */
    public function run(string $trigger, ?int $actorId): ?array
    {
        $ready = $this->readiness();
        if (!$ready['ok'] || $ready['integration'] === null) {
            return null;
        }
        $i = $ready['integration'];
        $db = $this->app->db();
        if (!$db->acquireLock(self::LOCK, 0)) {
            throw new BusinessException('attendance.error.pull_running', [], 409);
        }
        try {
            return $this->pull($i, $trigger, $actorId);
        } finally {
            $db->releaseLock(self::LOCK);
        }
    }

    /**
     * @param array<string, mixed> $i integration row
     * @return array<string, mixed>
     */
    private function pull(array $i, string $trigger, ?int $actorId): array
    {
        $db = $this->app->db();
        $reg = $this->app->integrations();
        $runPid = Ulid::generate($this->app->clock);
        $corr = Ulid::generate($this->app->clock);
        $cursor = (string) ($db->scalar("SELECT cursor_to FROM att_pull_runs WHERE cursor_to IS NOT NULL ORDER BY id DESC LIMIT 1") ?? '');
        $db->run(
            'INSERT INTO att_pull_runs (public_id, started_at, `trigger`, triggered_by, cursor_from, status, correlation_id) VALUES (?, ?, ?, ?, ?, \'running\', ?)',
            [$runPid, $this->now(), $trigger, $actorId, $cursor !== '' ? $cursor : null, $corr]
        );
        $runId = (int) $db->pdo()->lastInsertId();
        $c = ['pages' => 0, 'fetched' => 0, 'inserted' => 0, 'duplicates' => 0, 'invalid' => 0, 'unmapped' => 0];
        $status = 'success';
        $err = null;
        $http = null;
        $summary = null;
        $affected = [];
        $base = rtrim((string) $i['endpoint'], '/');
        $timeout = max(5, min(120, (int) ($i['timeout_seconds'] ?? 30)));
        $keys = $reg->outboundCredentials((int) $i['id']);
        $more = true;
        while ($more && $c['pages'] < self::MAX_PAGES) {
            $pathQuery = (string) parse_url($base, PHP_URL_PATH) . '/v1/punches?' . http_build_query(['after' => $cursor, 'limit' => self::PAGE]);
            [$res, $keyId] = $this->call($i, $base, $pathQuery, $keys, $timeout, $corr);
            $http = $res['status'];
            if ($res['status'] !== 200) {
                $err = $res['status'] === 0 ? (string) $res['error'] : 'http_' . $res['status'];
                $summary = self::errorText($res);
                $status = $c['pages'] > 0 ? 'partial' : 'failed';
                break;
            }
            $data = json_decode($res['body'], true);
            if (!is_array($data) || !is_array($data['items'] ?? null) || !is_string($data['next_cursor'] ?? null) || preg_match('/^[A-Za-z0-9_.~-]{0,200}$/', (string) $data['next_cursor']) !== 1) {
                $err = 'bad_response';
                $summary = 'Answer is not the Punch API v1 format';
                $status = $c['pages'] > 0 ? 'partial' : 'failed';
                break;
            }
            $c['pages']++;
            foreach ($data['items'] as $item) {
                $c['fetched']++;
                $r = $this->store(is_array($item) ? $item : [], $runId);
                $c[$r['result']]++;
                if ($r['result'] === 'inserted') {
                    if ($r['employee_id'] === null) {
                        $c['unmapped']++;
                    } else {
                        $affected[$r['employee_id']][] = $r['date'];
                    }
                }
            }
            $cursor = (string) $data['next_cursor'];
            $db->run('UPDATE att_pull_runs SET cursor_to = ? WHERE id = ?', [$cursor !== '' ? $cursor : null, $runId]); // progress kept page by page
            $more = ($data['has_more'] ?? false) === true;
            $reg->log((int) $i['id'], 'outbound', ['operation' => 'GET /v1/punches', 'status' => 'success', 'correlation_id' => $corr, 'key_id' => $keyId, 'http_status' => 200,
                'duration_ms' => $res['ms'], 'records' => count($data['items']), 'reconciliation_ref' => $runPid]);
        }
        if ($status !== 'failed') {
            $this->health($i, $base, $keys, $timeout, $corr);
        }
        $att = AttendanceService::of($this->app);
        foreach ($affected as $emp => $dates) {
            sort($dates);
            $from = EmployeeService::ymd($dates[0])->modify('-1 day')->format('Y-m-d');
            $att->recalc((int) $emp, $from, end($dates) ?: $dates[0]);
        }
        $db->run(
            'UPDATE att_pull_runs SET finished_at = ?, pages = ?, fetched = ?, inserted = ?, duplicates = ?, invalid = ?, unmapped = ?, status = ?, http_status = ?, error_code = ?, error_summary = ? WHERE id = ?',
            [$this->now(), $c['pages'], $c['fetched'], $c['inserted'], $c['duplicates'], $c['invalid'], $c['unmapped'], $status, $http, $err, $summary !== null ? mb_substr($summary, 0, 300) : null, $runId]
        );
        $this->app->audit()->record('attendance.pulled', $status === 'failed' ? 'failure' : 'success', 'att_pull_run', $runPid, null, null, $c + ['status' => $status, 'error' => $err, 'trigger' => $trigger], $actorId);
        return (array) $db->one('SELECT * FROM att_pull_runs WHERE id = ?', [$runId]);
    }

    /**
     * Signed GET; on 401 with the newest key, tries the previous active key once (key rotation).
     *
     * @param array<string, mixed> $i
     * @param list<array<string, mixed>> $keys
     * @return array{0: array{status: int, body: string, error: ?string, ms: int}, 1: ?string}
     */
    private function call(array $i, string $base, string $pathQuery, array $keys, int $timeout, string $corr): array
    {
        $origin = (string) preg_replace('#^(https?://[^/]+).*$#i', '$1', $base);
        $res = ['status' => 0, 'body' => '', 'error' => 'no_key', 'ms' => 0];
        $used = null;
        foreach (array_slice($keys, 0, 2) as $n => $k) {
            $headers = IntegrationAuth::signHeaders((string) $k['key_id'], (string) $k['secret'], 'GET', $pathQuery, $this->app->clock->now()->getTimestamp()) + ['X-Correlation-Id' => $corr];
            $res = $this->app->http()->get($origin . $pathQuery, $headers, $timeout, self::MAX_BYTES);
            $used = (string) $k['key_id'];
            if ($res['status'] === 200) {
                $this->app->integrations()->touch((int) $k['id']);
                break;
            }
            $this->app->integrations()->log((int) $i['id'], 'outbound', ['operation' => 'GET ' . (string) parse_url($pathQuery, PHP_URL_PATH), 'status' => 'failed', 'correlation_id' => $corr,
                'key_id' => $used, 'http_status' => $res['status'] ?: null, 'duration_ms' => $res['ms'], 'attempt' => $n + 1, 'error_code' => $res['error'] ?? ('http_' . $res['status']),
                'error_detail' => self::errorText($res)]);
            if ($res['status'] !== 401) {
                break;
            }
        }
        return [$res, $used];
    }

    /**
     * @param array<string, mixed> $i
     * @param list<array<string, mixed>> $keys
     */
    private function health(array $i, string $base, array $keys, int $timeout, string $corr): void
    {
        $path = (string) parse_url($base, PHP_URL_PATH) . '/v1/health';
        [$res] = $this->call($i, $base, $path, $keys, $timeout, $corr);
        if ($res['status'] !== 200) {
            return;
        }
        $data = json_decode($res['body'], true);
        $dev = DeviceService::of($this->app);
        foreach ((array) ($data['devices'] ?? []) as $d) {
            if (!is_array($d) || !is_string($d['device_id'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $d['device_id']) !== 1) {
                continue;
            }
            $dev->ensureDevice($d['device_id'], null, null);
            $seen = is_string($d['last_seen_at'] ?? null) ? self::utc($d['last_seen_at']) : null;
            $dev->touch($d['device_id'], $seen?->format('Y-m-d H:i:s.u'), isset($d['clock_drift_seconds']) && is_int($d['clock_drift_seconds']) ? $d['clock_drift_seconds'] : null);
        }
    }

    /**
     * Validates and stores one Punch API item.
     *
     * @param array<string, mixed> $item
     * @return array{result: 'inserted'|'duplicates'|'invalid', employee_id: ?int, date: string}
     */
    private function store(array $item, int $runId): array
    {
        $bad = ['result' => 'invalid', 'employee_id' => null, 'date' => ''];
        foreach (array_keys($item) as $k) {
            foreach (self::BIOMETRIC as $b) {
                if (str_contains(strtolower((string) $k), $b)) {
                    return $bad; // never store an item that carries (or might carry) biometric/ID data
                }
            }
        }
        $event = $item['event_id'] ?? null;
        $device = $item['device_id'] ?? null;
        $code = $item['device_user_code'] ?? null;
        $at = is_string($item['occurred_at'] ?? null) ? self::utc($item['occurred_at']) : null;
        if (
            !is_string($event) || preg_match('/^[A-Za-z0-9_.:-]{3,80}$/', $event) !== 1 || !is_string($device) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $device) !== 1
            || !is_string($code) || preg_match('/^[A-Za-z0-9_-]{1,32}$/', $code) !== 1 || $at === null
        ) {
            return $bad;
        }
        $now = $this->app->clock->now();
        if ($at > $now->modify('+1 day') || $at < $now->modify('-3 years')) {
            return $bad;
        }
        $verify = in_array($item['verify_method'] ?? '', ['finger', 'face', 'card', 'password'], true) ? (string) $item['verify_method'] : 'unknown';
        $tz = is_string($item['device_timezone'] ?? null) && preg_match('#^[A-Za-z_]+(/[A-Za-z0-9_+-]+){0,2}$#', $item['device_timezone']) === 1 ? $item['device_timezone'] : null;
        $local = is_string($item['device_local_time'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $item['device_local_time']) === 1 ? str_replace('T', ' ', $item['device_local_time']) : null;
        $collected = is_string($item['collected_at'] ?? null) ? self::utc($item['collected_at']) : null;
        $serial = is_string($item['device_serial'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $item['device_serial']) === 1 ? $item['device_serial'] : null;
        $deviceId = DeviceService::of($this->app)->ensureDevice($device, $serial, $tz);
        $n = $this->app->db()->run(
            "INSERT IGNORE INTO att_raw_punches (source, source_event_id, device_id, device_code, device_user_code, occurred_at_utc, device_local_time, device_timezone, verify_method,
                pull_run_id, collected_at, received_at) VALUES ('api', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$event, $deviceId, $device, $code, $at->format('Y-m-d H:i:s'), $local, $tz, $verify, $runId, $collected?->format('Y-m-d H:i:s'), $this->now()]
        )->rowCount();
        if ($n !== 1) {
            return ['result' => 'duplicates', 'employee_id' => null, 'date' => ''];
        }
        $date = $at->setTimezone(new DateTimeZone($tz ?? $this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok')))->format('Y-m-d');
        return ['result' => 'inserted', 'employee_id' => DeviceService::of($this->app)->employeeFor($code, $deviceId, $date), 'date' => $date];
    }

    private static function utc(string $iso): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/', $iso) !== 1) {
            return null;
        }
        $t = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', substr($iso, 0, 19), new DateTimeZone('UTC'));
        return $t === false ? null : $t;
    }

    /** @param array{status: int, body: string, error: ?string, ms: int} $res */
    private static function errorText(array $res): string
    {
        if ($res['status'] === 0) {
            return 'No answer from the Collector (' . ($res['error'] ?? 'network') . ')';
        }
        $d = json_decode($res['body'], true);
        $code = is_array($d) && is_string($d['error'] ?? null) ? preg_replace('/[^a-z0-9_]/', '', $d['error']) : '';
        return 'HTTP ' . $res['status'] . ($code !== '' ? ' · ' . $code : '');
    }

    /** Interval of the scheduled pull in hours (DEC-30: 4 by default, adjustable). */
    public function intervalHours(): int
    {
        return (int) round(((int) ($this->app->db()->scalar("SELECT interval_minutes FROM scheduled_jobs WHERE job_type = 'attendance.pull'") ?? 240)) / 60);
    }

    public function setInterval(Authorizer $az, int $hours): void
    {
        if ($az->scope('attendance.collector')->isEmpty()) {
            throw HttpException::forbidden();
        }
        if ($hours < 1 || $hours > 24) {
            throw new ValidationException(['hours' => 'attendance.error.hours']);
        }
        $before = $this->intervalHours();
        $this->app->scheduler()->syncSchedules();
        $this->app->db()->run("UPDATE scheduled_jobs SET interval_minutes = ?, updated_at = ? WHERE job_type = 'attendance.pull'", [$hours * 60, $this->now()]);
        $this->app->audit()->record('attendance.pull_interval', 'success', 'scheduled_job', 'attendance.pull', null, null, ['before_hours' => $before, 'after_hours' => $hours]);
    }

    /** @return list<array<string, mixed>> latest runs */
    public function runs(int $limit = 20): array
    {
        return $this->app->db()->all('SELECT r.*, u.display_name AS actor_name FROM att_pull_runs r LEFT JOIN users u ON u.id = r.triggered_by ORDER BY r.id DESC LIMIT ' . max(1, $limit));
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }
}

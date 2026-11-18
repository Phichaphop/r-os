<?php

declare(strict_types=1);

namespace Blue\Core\Integrations;

use DateTimeImmutable;
use Blue\Core\Audit\AuditLogger;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Database\Connection;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Crypto;
use Blue\Core\Support\Ulid;

/**
 * Integration registry (03 §6.30.15, 06 §7A "Enterprise Workflow & Integration"): every connection to another system is
 * written down — owner, direction, authentication, scopes, data classification, schema version, limits, retry,
 * idempotency, monitoring, incident contact, cost and privacy review — with its credentials and a call log.
 *
 * Credentials: the secret is shown ONCE when created (key id + secret), stored encrypted with a keyed hash for checking,
 * and can be rotated (the old one keeps working until revoked) and revoked. The log never contains payloads or secrets.
 */
final class IntegrationRegistry
{
    public const DIRECTIONS = ['inbound', 'outbound', 'file_exchange'];
    public const AUTH = ['none', 'api_key', 'hmac', 'basic', 'oauth', 'manual'];
    public const STATUSES = ['planned', 'active', 'suspended', 'retired'];
    public const PRIVACY = ['not_required', 'pending', 'approved', 'rejected'];

    /**
     * Integrations known from the decisions so far (seeded once, editable): code => [name, direction, type, auth, class, status, endpoint, notes].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: ?string, 7: string}>
     */
    public const SEED = [
        'blue.scheduler' => ['ตัวเรียกงานตามเวลาจากภายนอก (external scheduler)', 'inbound', 'scheduler', 'api_key', 'I', 'planned', '/m2m/scheduler/run',
            'M48 ข้อ 2 / DEC-30: เรียกงานตามรอบ (สำรองข้อมูล ส่ง event ฯลฯ) จาก cron ภายนอกหรือ cron ของเซิร์ฟเวอร์ — ต้องมี credential ที่หมุนเวียนได้'],
        'hip.time_collector' => ['Time Collector + Punch API (เครื่องสแกน HIP)', 'outbound', 'attendance', 'hmac', 'R', 'planned', null,
            'DEC-30/33: Blue OS ดึง punch จาก Collector ทุก 4 ชม. (ปรับได้) ด้วย HMAC + nonce — ยังรอข้อมูลโปรแกรม HIP (BT-02); สร้างจริงในเฟส 4'],
        'bank.payment_file' => ['ไฟล์โอนเงินธนาคาร (อัปโหลดด้วยคน)', 'file_exchange', 'bank', 'manual', 'R', 'active', null,
            'DEC-26/31: ไฟล์ BLUE_STANDARD_XLSX_v1 ดาวน์โหลดแล้วอัปโหลดเข้าระบบธนาคารเอง · นำเข้าผลการโอนกลับมา reconcile · ไม่มี API ธนาคารใน rollout 1'],
        'mail.provider' => ['ผู้ให้บริการส่งอีเมล', 'outbound', 'email', 'api_key', 'C', 'planned', null,
            'DEC-48: ผู้ให้บริการอีเมลภายนอก — ต้องผ่าน vendor/privacy review (SP-04) ก่อนเปิดใช้'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly Crypto $crypto,
    ) {
    }

    public static function seed(Connection $db, Clock $clock): int
    {
        $n = 0;
        foreach (self::SEED as $code => [$name, $dir, $type, $auth, $class, $status, $endpoint, $notes]) {
            if ($db->scalar('SELECT id FROM integrations WHERE code = ?', [$code]) !== null) {
                continue;
            }
            $now = $clock->now()->format('Y-m-d H:i:s.u');
            $db->run(
                "INSERT INTO integrations (public_id, created_at, updated_at, code, name, direction, system_type, auth_method, data_classification, status, endpoint, notes,
                    privacy_review, is_system, retry_policy, idempotency, rate_limit_per_min)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)",
                [Ulid::generate($clock), $now, $now, $code, $name, $dir, $type, $auth, $class, $status, $endpoint, $notes,
                    $class === 'R' || $class === 'C' ? 'pending' : 'not_required',
                    $dir === 'outbound' ? 'back-off 1, 2, 4 … 60 min, 8 attempts → dead letter' : null,
                    $code === 'blue.scheduler' ? 'scheduler lock: one run at a time' : ($code === 'hip.time_collector' ? 'event_id unique + cursor' : null),
                    $code === 'blue.scheduler' ? 12 : null]
            );
            $n++;
        }
        return $n;
    }

    // ---- registry -------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> with health counters */
    public function list(Authorizer $az): array
    {
        $this->require($az, 'core.integration.view');
        $since = $this->clock->now()->modify('-24 hours')->format('Y-m-d H:i:s.u');
        return $this->db->all(
            "SELECT i.*, u.display_name AS owner_name,
                    (SELECT COUNT(*) FROM integration_credentials c WHERE c.integration_id = i.id AND c.status = 'active') AS active_keys,
                    (SELECT MAX(occurred_at) FROM integration_logs l WHERE l.integration_id = i.id AND l.status = 'success') AS last_success,
                    (SELECT COUNT(*) FROM integration_logs l WHERE l.integration_id = i.id AND l.status IN ('failed','rejected','dead') AND l.occurred_at >= ?) AS failures_24h
               FROM integrations i LEFT JOIN users u ON u.id = i.owner_user_id ORDER BY i.status = 'retired', i.code",
            [$since]
        );
    }

    /** @return array<string, mixed> */
    public function get(Authorizer $az, string $pid): array
    {
        $this->require($az, 'core.integration.view');
        $i = $this->db->one('SELECT i.*, u.display_name AS owner_name FROM integrations i LEFT JOIN users u ON u.id = i.owner_user_id WHERE i.public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $i['credentials'] = $this->db->all(
            'SELECT c.public_id, c.key_id, c.last4, c.status, c.valid_from, c.expires_at, c.last_used_at, c.revoked_at, c.revoke_reason, c.created_at, u.display_name AS created_name
               FROM integration_credentials c LEFT JOIN users u ON u.id = c.created_by WHERE c.integration_id = ? ORDER BY c.id DESC',
            [$i['id']]
        );
        return $i;
    }

    /** @return array<string, mixed>|null */
    public function byCode(string $code): ?array
    {
        return $this->db->one('SELECT * FROM integrations WHERE code = ?', [$code]);
    }

    /**
     * @param array<string, string> $in
     */
    public function save(Authorizer $az, ?string $pid, array $in): string
    {
        $this->require($az, 'core.integration.manage');
        $errors = [];
        $code = trim($in['code'] ?? '');
        if ($pid === null && !preg_match('/^[a-z][a-z0-9_]{1,29}(\.[a-z0-9_]{1,29}){1,2}$/', $code)) {
            $errors['code'] = 'integration.error.code';
        } elseif ($pid === null && $this->byCode($code) !== null) {
            $errors['code'] = 'integration.error.code_taken';
        }
        if (trim($in['name'] ?? '') === '') {
            $errors['name'] = 'form.required';
        }
        foreach (['direction' => self::DIRECTIONS, 'auth_method' => self::AUTH, 'status' => self::STATUSES, 'privacy_review' => self::PRIVACY, 'data_classification' => ['P', 'I', 'C', 'R']] as $f => $allowed) {
            if (!in_array($in[$f] ?? '', $allowed, true)) {
                $errors[$f] = 'integration.error.choice';
            }
        }
        foreach (['rate_limit_per_min' => 100000, 'timeout_seconds' => 3600] as $f => $max) {
            $v = trim($in[$f] ?? '');
            if ($v !== '' && (!ctype_digit($v) || (int) $v < 1 || (int) $v > $max)) {
                $errors[$f] = 'integration.error.number';
            }
        }
        $endpoint = trim($in['endpoint'] ?? '');
        if ($endpoint !== '' && (preg_match('#[?&](key|token|secret|password|sig)=#i', $endpoint) || preg_match('#//[^/]*:[^/]*@#', $endpoint))) {
            $errors['endpoint'] = 'integration.error.secret_in_url'; // secrets never in the registry text
        }
        if (($in['status'] ?? '') === 'active' && in_array($in['data_classification'] ?? '', ['C', 'R'], true) && !in_array($in['privacy_review'] ?? '', ['approved', 'not_required'], true)) {
            $errors['status'] = 'integration.error.privacy_first';
        }
        $owner = trim($in['owner'] ?? '');
        $ownerId = null;
        if ($owner !== '') {
            $ownerId = $this->db->scalar("SELECT id FROM users WHERE username = ? AND status = 'active'", [$owner]);
            if ($ownerId === null) {
                $errors['owner'] = 'integration.error.owner';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $fields = [
            'name' => mb_substr(trim($in['name']), 0, 200), 'direction' => $in['direction'], 'system_type' => mb_substr(trim($in['system_type'] ?? 'other') ?: 'other', 0, 40),
            'owner_user_id' => $ownerId, 'auth_method' => $in['auth_method'], 'scopes' => self::opt($in['scopes'] ?? '', 300), 'data_classification' => $in['data_classification'],
            'schema_version' => self::opt($in['schema_version'] ?? '', 20), 'endpoint' => self::opt($endpoint, 255),
            'rate_limit_per_min' => ($in['rate_limit_per_min'] ?? '') !== '' ? (int) $in['rate_limit_per_min'] : null,
            'timeout_seconds' => ($in['timeout_seconds'] ?? '') !== '' ? (int) $in['timeout_seconds'] : null,
            'retry_policy' => self::opt($in['retry_policy'] ?? '', 200), 'idempotency' => self::opt($in['idempotency'] ?? '', 200), 'monitoring' => self::opt($in['monitoring'] ?? '', 200),
            'incident_contact' => self::opt($in['incident_contact'] ?? '', 200), 'cost_note' => self::opt($in['cost_note'] ?? '', 200),
            'privacy_review' => $in['privacy_review'], 'privacy_ref' => self::opt($in['privacy_ref'] ?? '', 200), 'status' => $in['status'], 'notes' => self::opt($in['notes'] ?? '', 1000),
        ];
        $now = $this->now();
        if ($pid === null) {
            $pid = Ulid::generate($this->clock);
            $cols = array_keys($fields);
            $this->db->run(
                'INSERT INTO integrations (public_id, created_at, created_by, updated_at, updated_by, code, `' . implode('`,`', $cols) . '`) VALUES (?, ?, ?, ?, ?, ?, ' . implode(',', array_fill(0, count($cols), '?')) . ')',
                array_merge([$pid, $now, $az->userId, $now, $az->userId, $code], array_values($fields))
            );
            $this->audit->record('integration.created', 'success', 'integration', $pid, null, null, ['code' => $code]);
            return $pid;
        }
        $row = $this->db->one('SELECT * FROM integrations WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $set = implode(', ', array_map(static fn (string $c): string => '`' . $c . '` = ?', array_keys($fields)));
        $this->db->run('UPDATE integrations SET ' . $set . ', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', array_merge(array_values($fields), [$now, $az->userId, $row['id']]));
        $changed = array_keys(array_filter($fields, static fn ($v, $k) => (string) ($row[$k] ?? '') !== (string) ($v ?? ''), ARRAY_FILTER_USE_BOTH));
        $this->audit->record('integration.updated', 'success', 'integration', $pid, null, null, ['code' => $row['code'], 'changed' => $changed]);
        return $pid;
    }

    // ---- credentials ------------------------------------------------------------------------------------

    /**
     * Creates a credential and returns it ONCE: ['key_id' => …, 'secret' => …]. Older active keys keep working (rotation)
     * until someone revokes them.
     *
     * @return array{key_id: string, secret: string, public_id: string}
     */
    public function issueCredential(Authorizer $az, string $integrationPid, ?int $validDays): array
    {
        $this->require($az, 'core.integration.credentials');
        $i = $this->db->one('SELECT * FROM integrations WHERE public_id = ?', [$integrationPid]) ?? throw HttpException::notFound();
        if (!in_array($i['auth_method'], ['api_key', 'hmac'], true)) {
            throw new BusinessException('integration.error.no_keys_for_method', [], 409);
        }
        if ($validDays !== null && ($validDays < 1 || $validDays > 1095)) {
            throw new ValidationException(['valid_days' => 'integration.error.number']);
        }
        $keyId = 'rk_' . bin2hex(random_bytes(8));
        $secret = Crypto::token(32);
        $pid = Ulid::generate($this->clock);
        $now = $this->clock->now();
        $this->db->run(
            "INSERT INTO integration_credentials (public_id, created_at, created_by, updated_at, updated_by, integration_id, key_id, secret_enc, secret_hash, last4, status, valid_from, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)",
            [$pid, $now->format('Y-m-d H:i:s.u'), $az->userId, $now->format('Y-m-d H:i:s.u'), $az->userId, $i['id'], $keyId, $this->crypto->encrypt($secret), $this->crypto->keyedHash($secret),
                substr($secret, -4), $now->format('Y-m-d H:i:s.u'), $validDays !== null ? $now->modify('+' . $validDays . ' days')->format('Y-m-d H:i:s.u') : null]
        );
        $this->audit->record('integration.key_issued', 'success', 'integration', (string) $i['public_id'], null, null, ['key_id' => $keyId, 'expires_days' => $validDays]);
        return ['key_id' => $keyId, 'secret' => $secret, 'public_id' => $pid];
    }

    public function revokeCredential(Authorizer $az, string $credentialPid, string $reason): void
    {
        $this->require($az, 'core.integration.credentials');
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        $c = $this->db->one('SELECT c.*, i.public_id AS ipid FROM integration_credentials c JOIN integrations i ON i.id = c.integration_id WHERE c.public_id = ?', [$credentialPid]) ?? throw HttpException::notFound();
        if ($c['status'] !== 'active') {
            throw new BusinessException('integration.error.not_active', [], 409);
        }
        $now = $this->now();
        $this->db->run("UPDATE integration_credentials SET status = 'revoked', revoked_at = ?, revoked_by = ?, revoke_reason = ?, updated_at = ? WHERE id = ?", [$now, $az->userId, mb_substr($reason, 0, 255), $now, $c['id']]);
        $this->audit->record('integration.key_revoked', 'success', 'integration', (string) $c['ipid'], null, $reason, ['key_id' => $c['key_id']]);
    }

    /**
     * Looks up an active, unexpired credential of an integration and checks the secret (constant time).
     *
     * @return array<string, mixed>|null credential row
     */
    public function checkSecret(int $integrationId, string $keyId, string $secret): ?array
    {
        $c = $this->db->one("SELECT * FROM integration_credentials WHERE key_id = ? AND integration_id = ? AND status = 'active'", [$keyId, $integrationId]);
        if ($c === null || ($c['expires_at'] !== null && (string) $c['expires_at'] < $this->now())) {
            return null;
        }
        return hash_equals((string) $c['secret_hash'], $this->crypto->keyedHash($secret)) ? $c : null;
    }

    /** @return array<string, mixed>|null credential with its secret in clear — for HMAC verification only */
    public function secretFor(int $integrationId, string $keyId): ?array
    {
        $c = $this->db->one("SELECT * FROM integration_credentials WHERE key_id = ? AND integration_id = ? AND status = 'active'", [$keyId, $integrationId]);
        if ($c === null || ($c['expires_at'] !== null && (string) $c['expires_at'] < $this->now())) {
            return null;
        }
        $c['secret'] = $this->crypto->decrypt((string) $c['secret_enc']);
        return $c;
    }

    /**
     * Active, unexpired credentials of an integration with their secrets, newest first — Blue OS signs its OWN outgoing
     * calls with them (e.g. the Punch API). The newest key is tried first; during a rotation the older key still works.
     *
     * @return list<array<string, mixed>>
     */
    public function outboundCredentials(int $integrationId): array
    {
        $out = [];
        foreach ($this->db->all("SELECT * FROM integration_credentials WHERE integration_id = ? AND status = 'active' ORDER BY id DESC LIMIT 3", [$integrationId]) as $c) {
            if ($c['expires_at'] !== null && (string) $c['expires_at'] < $this->now()) {
                continue;
            }
            $c['secret'] = $this->crypto->decrypt((string) $c['secret_enc']);
            $out[] = $c;
        }
        return $out;
    }

    public function touch(int $credentialId): void
    {
        $this->db->run('UPDATE integration_credentials SET last_used_at = ? WHERE id = ?', [$this->now(), $credentialId]);
    }

    /** Remembers a nonce; false when it was seen before (replay). Old nonces are purged. */
    public function rememberNonce(string $keyId, string $nonce): bool
    {
        $this->db->run('DELETE FROM integration_nonces WHERE seen_at < ?', [$this->clock->now()->modify('-1 day')->format('Y-m-d H:i:s.u')]);
        return $this->db->run('INSERT IGNORE INTO integration_nonces (key_id, nonce, seen_at) VALUES (?, ?, ?)', [$keyId, $nonce, $this->now()])->rowCount() === 1;
    }

    // ---- log --------------------------------------------------------------------------------------------

    /**
     * @param array{operation: string, status: string, correlation_id?: ?string, idempotency_key?: ?string, key_id?: ?string, http_status?: ?int,
     *              duration_ms?: ?int, attempt?: int, records?: ?int, error_code?: ?string, error_detail?: ?string, reconciliation_ref?: ?string} $e
     */
    public function log(int $integrationId, string $direction, array $e): void
    {
        $this->db->run(
            'INSERT INTO integration_logs (integration_id, occurred_at, direction, operation, status, correlation_id, idempotency_key, key_id, http_status, duration_ms, attempt, records,
                error_code, error_detail, reconciliation_ref) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$integrationId, $this->now(), $direction, mb_substr($e['operation'], 0, 80), $e['status'], $e['correlation_id'] ?? null, $e['idempotency_key'] ?? null, $e['key_id'] ?? null,
                $e['http_status'] ?? null, $e['duration_ms'] ?? null, $e['attempt'] ?? 1, $e['records'] ?? null, $e['error_code'] ?? null,
                isset($e['error_detail']) ? mb_substr((string) $e['error_detail'], 0, 300) : null, $e['reconciliation_ref'] ?? null]
        );
    }

    /** Calls in the last minute (for the per-integration rate limit). */
    public function recentCalls(int $integrationId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM integration_logs WHERE integration_id = ? AND occurred_at >= ?', [$integrationId, $this->clock->now()->modify('-60 seconds')->format('Y-m-d H:i:s.u')]);
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    public function logs(Authorizer $az, string $pid, string $status, int $page, int $per): array
    {
        $this->require($az, 'core.integration.view');
        $i = $this->db->one('SELECT id FROM integrations WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $where = 'integration_id = ?';
        $params = [$i['id']];
        if ($status !== '') {
            $where .= ' AND status = ?';
            $params[] = $status;
        }
        return [
            'total' => (int) $this->db->scalar("SELECT COUNT(*) FROM integration_logs WHERE {$where}", $params),
            'rows' => $this->db->all("SELECT * FROM integration_logs WHERE {$where} ORDER BY id DESC LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per), $params),
        ];
    }

    private static function opt(string $v, int $max): ?string
    {
        $v = trim($v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function require(Authorizer $az, string $perm): void
    {
        if (!$az->scope($perm)->all) {
            throw HttpException::forbidden();
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }

    /** For the details page: is the credential still valid now. */
    public function isExpired(?string $expiresAt): bool
    {
        return $expiresAt !== null && new DateTimeImmutable($expiresAt) < $this->clock->now();
    }
}

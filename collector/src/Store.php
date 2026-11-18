<?php

declare(strict_types=1);

namespace BlueCollector;

use PDO;
use RuntimeException;

/**
 * Collector database (SQLite file, ADR-007). Holds what the reader adapter collected from the time clocks and what
 * the Punch API needs — nothing more:
 *   devices  — time clocks (id, serial, model, timezone, last contact, clock drift)
 *   punches  — one row per punch; `seq` is the read order (the API cursor), `event_id` never changes
 *   api_keys — key id + secret issued by Blue OS (Integrations → Time Collector → issue key)
 *   nonces   — seen request nonces (replay protection, kept 1 day)
 *   calls    — call times for the rate limit
 * There are NO columns for fingerprints, face images, templates or ID-card numbers (DEC-25) — the reader adapter
 * must only pass the user code shown on the device, the time and how the person verified.
 */
final class Store
{
    public const VERIFY = ['finger', 'face', 'card', 'password'];

    private PDO $pdo;

    public function __construct(string $file)
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create the data folder: ' . $dir);
        }
        $new = !is_file($file);
        $this->pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->migrate();
        if ($new) {
            @chmod($file, 0600); // the file holds API secrets
        }
    }

    private function migrate(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS devices (
            device_id TEXT PRIMARY KEY, serial TEXT NOT NULL, model TEXT NOT NULL, timezone TEXT NOT NULL,
            next_seq INTEGER NOT NULL DEFAULT 1, last_seen_at TEXT NULL, clock_drift_seconds INTEGER NULL, status TEXT NOT NULL DEFAULT \'active\', created_at TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS punches (
            seq INTEGER PRIMARY KEY AUTOINCREMENT, event_id TEXT NOT NULL UNIQUE, device_id TEXT NOT NULL, device_serial TEXT NOT NULL,
            device_user_code TEXT NOT NULL, occurred_at TEXT NOT NULL, device_local_time TEXT NOT NULL, device_timezone TEXT NOT NULL,
            verify_method TEXT NOT NULL, collected_at TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS api_keys (key_id TEXT PRIMARY KEY, secret TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, revoked_at TEXT NULL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS nonces (key_id TEXT NOT NULL, nonce TEXT NOT NULL, seen_at INTEGER NOT NULL, PRIMARY KEY (key_id, nonce))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS calls (at INTEGER NOT NULL)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS ix_calls_at ON calls (at)');
    }

    // ---- devices ---------------------------------------------------------------------------------------

    public function addDevice(string $id, string $serial, string $model, string $tz, int $now): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{2,40}$/', $id) !== 1 || preg_match('/^[A-Za-z0-9_-]{2,40}$/', $serial) !== 1) {
            throw new RuntimeException('Device id and serial: 2–40 letters, digits, - or _');
        }
        new \DateTimeZone($tz); // throws on an unknown timezone
        $this->pdo->prepare('INSERT INTO devices (device_id, serial, model, timezone, created_at) VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(device_id) DO UPDATE SET serial = excluded.serial, model = excluded.model, timezone = excluded.timezone')
            ->execute([$id, $serial, mb_substr($model, 0, 40), $tz, gmdate('Y-m-d\TH:i:s\Z', $now)]);
    }

    /** @return array<string, mixed>|null */
    public function device(string $id): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM devices WHERE device_id = ?');
        $s->execute([$id]);
        $r = $s->fetch();
        return $r === false ? null : $r;
    }

    /** @return list<array<string, mixed>> */
    public function devices(): array
    {
        return $this->pdo->query('SELECT * FROM devices ORDER BY device_id')->fetchAll();
    }

    public function touchDevice(string $id, int $now, ?int $drift): void
    {
        $this->pdo->prepare('UPDATE devices SET last_seen_at = ?, clock_drift_seconds = COALESCE(?, clock_drift_seconds) WHERE device_id = ?')
            ->execute([gmdate('Y-m-d\TH:i:s\Z', $now), $drift, $id]);
    }

    // ---- punches ---------------------------------------------------------------------------------------

    /**
     * Records one punch read from a device. `local` is the time shown on the device (Y-m-d H:i:s, device timezone).
     * The event id is HIP-<serial>-<device sequence> so the same punch read twice is stored once.
     */
    public function record(string $deviceId, string $userCode, string $local, string $verify, int $now, ?int $deviceSeq = null): ?string
    {
        $d = $this->device($deviceId) ?? throw new RuntimeException('Unknown device: ' . $deviceId);
        if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $userCode) !== 1) {
            throw new RuntimeException('Device user code: 1–32 letters or digits');
        }
        if (!in_array($verify, self::VERIFY, true)) {
            throw new RuntimeException('verify method: ' . implode('|', self::VERIFY));
        }
        $tz = new \DateTimeZone((string) $d['timezone']);
        $t = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $local, $tz);
        if ($t === false || $t->format('Y-m-d H:i:s') !== $local) {
            throw new RuntimeException('Local time must be Y-m-d H:i:s: ' . $local);
        }
        $this->pdo->beginTransaction();
        try {
            $seq = $deviceSeq ?? (int) $this->pdo->query('SELECT next_seq FROM devices WHERE device_id = ' . $this->pdo->quote($deviceId))->fetchColumn();
            $eventId = 'HIP-' . $d['serial'] . '-' . $seq;
            $ins = $this->pdo->prepare('INSERT OR IGNORE INTO punches (event_id, device_id, device_serial, device_user_code, occurred_at, device_local_time, device_timezone, verify_method, collected_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([$eventId, $deviceId, $d['serial'], $userCode, $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), $t->format('Y-m-d\TH:i:s'),
                $d['timezone'], $verify, gmdate('Y-m-d\TH:i:s\Z', $now)]);
            $added = $ins->rowCount() === 1;
            $this->pdo->prepare('UPDATE devices SET next_seq = MAX(next_seq, ?) WHERE device_id = ?')->execute([$seq + 1, $deviceId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->touchDevice($deviceId, $now, null);
        return $added ? $eventId : null;
    }

    /** @return list<array<string, mixed>> punches after a sequence number, in read order */
    public function after(int $seq, int $limit): array
    {
        $s = $this->pdo->prepare('SELECT * FROM punches WHERE seq > ? ORDER BY seq LIMIT ' . max(1, $limit));
        $s->execute([$seq]);
        return $s->fetchAll();
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM punches')->fetchColumn();
    }

    // ---- keys, nonces, rate limit ----------------------------------------------------------------------

    public function addKey(string $keyId, string $secret, int $now): void
    {
        if (preg_match('/^rk_[0-9a-f]{16}$/', $keyId) !== 1 || strlen($secret) < 20) {
            throw new RuntimeException('Key id looks like rk_ + 16 hex characters; the secret is the long value shown once in Blue OS');
        }
        $this->pdo->prepare("INSERT INTO api_keys (key_id, secret, status, created_at) VALUES (?, ?, 'active', ?)
            ON CONFLICT(key_id) DO UPDATE SET secret = excluded.secret, status = 'active', revoked_at = NULL")->execute([$keyId, $secret, gmdate('Y-m-d\TH:i:s\Z', $now)]);
    }

    public function revokeKey(string $keyId, int $now): bool
    {
        $s = $this->pdo->prepare("UPDATE api_keys SET status = 'revoked', revoked_at = ? WHERE key_id = ? AND status = 'active'");
        $s->execute([gmdate('Y-m-d\TH:i:s\Z', $now), $keyId]);
        return $s->rowCount() === 1;
    }

    public function secret(string $keyId): ?string
    {
        $s = $this->pdo->prepare("SELECT secret FROM api_keys WHERE key_id = ? AND status = 'active'");
        $s->execute([$keyId]);
        $v = $s->fetchColumn();
        return is_string($v) ? $v : null;
    }

    /** @return list<array<string, mixed>> keys without secrets */
    public function keys(): array
    {
        return $this->pdo->query('SELECT key_id, status, created_at, revoked_at FROM api_keys ORDER BY created_at')->fetchAll();
    }

    /** False when the nonce was used before (replay). */
    public function rememberNonce(string $keyId, string $nonce, int $now): bool
    {
        $this->pdo->prepare('DELETE FROM nonces WHERE seen_at < ?')->execute([$now - 86400]);
        $s = $this->pdo->prepare('INSERT OR IGNORE INTO nonces (key_id, nonce, seen_at) VALUES (?, ?, ?)');
        $s->execute([$keyId, $nonce, $now]);
        return $s->rowCount() === 1;
    }

    /** Counts this call and returns the number of calls in the last 60 seconds (including it). */
    public function countCall(int $now): int
    {
        $this->pdo->prepare('DELETE FROM calls WHERE at < ?')->execute([$now - 60]);
        $this->pdo->prepare('INSERT INTO calls (at) VALUES (?)')->execute([$now]);
        $s = $this->pdo->prepare('SELECT COUNT(*) FROM calls WHERE at >= ?');
        $s->execute([$now - 60]);
        return (int) $s->fetchColumn();
    }
}

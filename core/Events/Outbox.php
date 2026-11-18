<?php

declare(strict_types=1);

namespace Blue\Core\Events;

use Blue\Core\Audit\AuditContext;
use Blue\Core\Database\Connection;
use Blue\Core\Log\Logger;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;
use Throwable;

/**
 * Transactional outbox (P0-02 §6, 03A §4.2, 6.30.7).
 *
 * record() is called INSIDE the business transaction, so the event exists exactly when the change committed.
 * dispatch() (background job) hands due events to in-process consumers; each consumer runs at most once per
 * event (processed_events unique key) — duplicate delivery after a crash or retry is harmless. Failures back off
 * (1, 2, 4 … 60 min) and become "dead" after MAX_ATTEMPTS for an administrator to look at.
 * Payloads must not carry Restricted data (account numbers, salaries, ID numbers — P0-03 §11).
 */
final class Outbox
{
    public const MAX_ATTEMPTS = 8;

    /** @var array<string, array{0: string, 1: callable(array<string,mixed>): void}> consumer => [event pattern, handler] */
    private array $consumers = [];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditContext $ctx,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return string event id (the existing one when the idempotency key was already used)
     */
    public function record(string $eventName, array $payload, string $idempotencyKey, ?int $legalEntityId = null, ?int $siteId = null): string
    {
        $existing = $this->db->scalar('SELECT event_id FROM outbox_events WHERE idempotency_key = ?', [$idempotencyKey]);
        if ($existing !== null) {
            return (string) $existing;
        }
        $id = Ulid::generate($this->clock);
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $this->db->run(
            'INSERT INTO outbox_events (event_id, event_name, schema_version, legal_entity_id, site_id, actor_user_id, correlation_id, idempotency_key,
                payload_json, occurred_at, status, attempts, next_attempt_at)
             VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, \'pending\', 0, ?)',
            [$id, $eventName, $legalEntityId, $siteId, $this->ctx->actorId, $this->ctx->correlationId, $idempotencyKey,
                json_encode($payload, JSON_UNESCAPED_UNICODE), $now, $now]
        );
        return $id;
    }

    /**
     * @param string $pattern exact event name or prefix ending in ".*"
     * @param callable(array<string,mixed>): void $handler receives the full envelope (event, event_id, payload, …)
     */
    public function subscribe(string $consumer, string $pattern, callable $handler): void
    {
        $this->consumers[$consumer] = [$pattern, $handler];
    }

    /** @return array{delivered:int, failed:int, dead:int} */
    public function dispatch(int $limit = 50): array
    {
        $now = $this->clock->now();
        $stats = ['delivered' => 0, 'failed' => 0, 'dead' => 0];
        $due = $this->db->all(
            "SELECT * FROM outbox_events WHERE status IN ('pending','failed') AND next_attempt_at <= ? ORDER BY id LIMIT " . max(1, min(500, $limit)),
            [$now->format('Y-m-d H:i:s.u')]
        );
        foreach ($due as $ev) {
            $envelope = [
                'event' => $ev['event_name'], 'event_id' => $ev['event_id'], 'schema_version' => (int) $ev['schema_version'],
                'occurred_at' => $ev['occurred_at'], 'legal_entity_id' => $ev['legal_entity_id'], 'site_id' => $ev['site_id'],
                'actor_user_id' => $ev['actor_user_id'], 'correlation_id' => $ev['correlation_id'], 'idempotency_key' => $ev['idempotency_key'],
                'payload' => json_decode((string) $ev['payload_json'], true) ?? [],
            ];
            $error = null;
            foreach ($this->consumers as $name => [$pattern, $handler]) {
                if (!self::matches($pattern, (string) $ev['event_name'])) {
                    continue;
                }
                if ($this->db->scalar('SELECT id FROM processed_events WHERE event_id = ? AND consumer = ?', [$ev['event_id'], $name]) !== null) {
                    continue; // already handled — duplicate delivery is a no-op
                }
                try {
                    $this->db->transactional(function () use ($handler, $envelope, $ev, $name): void {
                        $handler($envelope);
                        $this->db->run(
                            'INSERT INTO processed_events (event_id, consumer, processed_at, result) VALUES (?, ?, ?, \'ok\')',
                            [$ev['event_id'], $name, $this->clock->now()->format('Y-m-d H:i:s.u')]
                        );
                    });
                } catch (Throwable $e) {
                    $error = $name . ': ' . mb_substr($e->getMessage(), 0, 200);
                    $this->logger->warning('outbox_consumer_failed', ['event_id' => $ev['event_id'], 'consumer' => $name, 'error' => $e->getMessage()]);
                }
            }
            $attempts = (int) $ev['attempts'] + 1;
            if ($error === null) {
                $this->db->run("UPDATE outbox_events SET status = 'delivered', attempts = ?, last_error = NULL WHERE id = ?", [$attempts, $ev['id']]);
                $stats['delivered']++;
            } elseif ($attempts >= self::MAX_ATTEMPTS) {
                $this->db->run("UPDATE outbox_events SET status = 'dead', attempts = ?, last_error = ? WHERE id = ?", [$attempts, $error, $ev['id']]);
                $this->logger->error('outbox_event_dead', ['event_id' => $ev['event_id'], 'error' => $error]);
                $stats['dead']++;
            } else {
                $wait = min(60, 2 ** ($attempts - 1));
                $this->db->run(
                    "UPDATE outbox_events SET status = 'failed', attempts = ?, last_error = ?, next_attempt_at = ? WHERE id = ?",
                    [$attempts, $error, $now->modify("+{$wait} minutes")->format('Y-m-d H:i:s.u'), $ev['id']]
                );
                $stats['failed']++;
            }
        }
        return $stats;
    }

    /** Sends a dead event round again (administrator action after fixing the cause). */
    public function requeue(string $eventId): bool
    {
        return $this->db->run(
            "UPDATE outbox_events SET status = 'pending', attempts = 0, next_attempt_at = ? WHERE event_id = ? AND status = 'dead'",
            [$this->clock->now()->format('Y-m-d H:i:s.u'), $eventId]
        )->rowCount() === 1;
    }

    /** @return array<string, int> counts per status (for the status page) */
    public function stats(): array
    {
        $out = ['pending' => 0, 'delivered' => 0, 'failed' => 0, 'dead' => 0];
        foreach ($this->db->all('SELECT status, COUNT(*) AS n FROM outbox_events GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }

    private static function matches(string $pattern, string $name): bool
    {
        return str_ends_with($pattern, '.*') ? str_starts_with($name, substr($pattern, 0, -1)) : $pattern === $name;
    }
}

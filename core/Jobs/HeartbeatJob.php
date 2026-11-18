<?php

declare(strict_types=1);

namespace Blue\Core\Jobs;

/**
 * Records that the scheduler is alive. The UI uses it to show honestly
 * "background tasks last ran at …" when nobody has visited the prototype (6.30.14).
 */
final class HeartbeatJob implements JobHandler
{
    public function type(): string
    {
        return 'system.heartbeat';
    }

    public function defaultIntervalMinutes(): int
    {
        return 5;
    }

    public function handle(array $payload, JobContext $ctx): void
    {
        $ctx->db->run(
            "INSERT INTO system_info (info_key, info_value, updated_at) VALUES ('scheduler_last_run', ?, ?)
             ON DUPLICATE KEY UPDATE info_value = VALUES(info_value), updated_at = VALUES(updated_at)",
            [$ctx->trigger, $ctx->clock->now()->format('Y-m-d H:i:s.u')]
        );
    }
}

<?php

declare(strict_types=1);

namespace Blue\Core\Log;

use Blue\Core\Support\Clock;
use Throwable;

/**
 * Structured JSON-lines logger (6.30.6).
 * - every line carries the correlation ID of the request/job
 * - context keys that look like secrets or personal identifiers are redacted
 * - one file per UTC day under LOG_PATH
 */
final class Logger
{
    public const LEVELS = ['debug' => 100, 'info' => 200, 'notice' => 250, 'warning' => 300, 'error' => 400, 'critical' => 500];

    private const REDACT = '/(pass(word)?|secret|token|api[_-]?key|app_key|authorization|cookie|session|otp|national_id|id_card|bank_account|salary)/i';

    private string $correlationId = '-';

    public function __construct(
        private readonly string $dir,
        private readonly Clock $clock,
        private readonly string $minLevel = 'info',
    ) {
    }

    public function setCorrelationId(string $id): void
    {
        $this->correlationId = $id;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        $rank = self::LEVELS[$level] ?? 200;
        if ($rank < (self::LEVELS[$this->minLevel] ?? 200)) {
            return;
        }
        $now = $this->clock->now();
        $line = json_encode([
            'ts' => $now->format('Y-m-d\TH:i:s.vP'),
            'level' => $level,
            'cid' => $this->correlationId,
            'msg' => $message,
            'ctx' => self::redact($context),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0750, true);
        }
        @file_put_contents($this->dir . '/blue-' . $now->format('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** @param array<string, mixed> $c */
    public function debug(string $m, array $c = []): void
    {
        $this->log('debug', $m, $c);
    }

    /** @param array<string, mixed> $c */
    public function info(string $m, array $c = []): void
    {
        $this->log('info', $m, $c);
    }

    /** @param array<string, mixed> $c */
    public function warning(string $m, array $c = []): void
    {
        $this->log('warning', $m, $c);
    }

    /** @param array<string, mixed> $c */
    public function error(string $m, array $c = []): void
    {
        $this->log('error', $m, $c);
    }

    /** @return array<string, mixed> */
    public static function exceptionContext(Throwable $e): array
    {
        return [
            'exception' => $e::class,
            'error' => $e->getMessage(),
            'file' => basename($e->getFile()) . ':' . $e->getLine(),
            'trace' => array_slice(array_map(
                static fn (array $f): string => ($f['class'] ?? '') . ($f['type'] ?? '') . $f['function'] . ' @' . basename($f['file'] ?? '?') . ':' . ($f['line'] ?? 0),
                $e->getTrace()
            ), 0, 15),
        ];
    }

    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    public static function redact(array $context): array
    {
        foreach ($context as $k => $v) {
            if (is_string($k) && preg_match(self::REDACT, $k)) {
                $context[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $context[$k] = self::redact($v);
            }
        }
        return $context;
    }
}

<?php

declare(strict_types=1);

namespace Blue\Core\Config;

use RuntimeException;

/**
 * Environment-level configuration (P0-02 §14 — nothing hard-coded to a host).
 *
 * Sources, lowest → highest priority:
 *   1. config/defaults.php            (in repo, no secrets)
 *   2. config/environments/<env>.php  (in repo, no secrets)
 *   3. config/env.php                 (NOT in repo — written by installer / server admin)
 *   4. real environment variables     (BLUE_<KEY>)
 *
 * Keys in LOCKED can never be overridden by any source (P0-07 AIP-02).
 * Business rules do NOT live here — they belong to the Config Registry (6.25, phase 3).
 */
final class AppConfig
{
    /** @var array<string, mixed> values that no environment/file may change (AIP-02) */
    public const LOCKED = [
        'AI_FREE_API_ONLY' => true,
        'AI_MAX_BILLABLE_COST' => 0,
    ];

    public const ENVIRONMENTS = ['local', 'ci', 'prototype', 'staging', 'production'];

    /** @var array<string, mixed> */
    private array $values;

    /** @var list<string> */
    private array $warnings = [];

    /** @param array<string, mixed> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * @param array<string, mixed> $overrides test-only overrides (applied before LOCKED)
     */
    public static function load(string $root, array $overrides = []): self
    {
        $values = self::readFile($root . '/config/defaults.php');

        $secretFile = $root . '/config/env.php';
        $fileValues = is_file($secretFile) ? self::readFile($secretFile) : [];

        $envName = (string) ($overrides['APP_ENV'] ?? (getenv('BLUE_APP_ENV') ?: ($fileValues['APP_ENV'] ?? $values['APP_ENV'] ?? 'local')));
        if (!in_array($envName, self::ENVIRONMENTS, true)) {
            throw new RuntimeException("Unknown APP_ENV '{$envName}'");
        }
        $envFile = $root . "/config/environments/{$envName}.php";
        if (is_file($envFile)) {
            $values = array_replace($values, self::readFile($envFile));
        }

        $values = array_replace($values, $fileValues);

        foreach (array_keys($values) as $key) {
            $env = getenv('BLUE_' . $key);
            if ($env !== false) {
                $values[$key] = self::cast($env, $values[$key]);
            }
        }
        $values = array_replace($values, $overrides);
        $values['APP_ENV'] = $envName;
        $values['INSTALLED'] = array_key_exists('INSTALLED', $overrides) ? (bool) $overrides['INSTALLED'] : is_file($secretFile);

        $config = new self($values);
        foreach (self::LOCKED as $key => $locked) {
            if (array_key_exists($key, $values) && $values[$key] !== $locked) {
                $config->warnings[] = "Config key {$key} is locked; attempted override ignored.";
            }
            $config->values[$key] = $locked;
        }
        return $config;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->values[$key] ?? $default;
        return is_scalar($v) ? (string) $v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->values[$key] ?? $default;
        return is_bool($v) ? $v : filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->values[$key] ?? $default;
        return is_numeric($v) ? (int) $v : $default;
    }

    public function env(): string
    {
        return $this->string('APP_ENV', 'local');
    }

    public function isProduction(): bool
    {
        return $this->env() === 'production';
    }

    /** Detailed errors only on developer machines / CI — never on prototype/staging/production. */
    public function debug(): bool
    {
        return in_array($this->env(), ['local', 'ci'], true) && $this->bool('APP_DEBUG');
    }

    public function installed(): bool
    {
        return (bool) $this->values['INSTALLED'];
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** Keys whose values must never reach logs or screens. */
    public static function isSecretKey(string $key): bool
    {
        return (bool) preg_match('/(PASS|SECRET|KEY|TOKEN)$/', $key);
    }

    /** @return array<string, mixed> */
    private static function readFile(string $file): array
    {
        $data = require $file;
        if (!is_array($data)) {
            throw new RuntimeException("Config file must return an array: {$file}");
        }
        return $data;
    }

    private static function cast(string $raw, mixed $like): mixed
    {
        return match (true) {
            is_bool($like) => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            is_int($like) => (int) $raw,
            default => $raw,
        };
    }
}

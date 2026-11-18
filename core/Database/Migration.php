<?php

declare(strict_types=1);

namespace Blue\Core\Database;

/**
 * One versioned schema change (DEC-16, P0-03 §14).
 *
 * File: <core|modules/<code>>/migrations/YYYYMMDDHHMM_<desc>.php returning an instance.
 * Rules:
 * - up() must be safe to re-run after a partial failure (MySQL DDL auto-commits),
 *   e.g. CREATE TABLE IF NOT EXISTS, guarded ALTERs (Schema helper)
 * - preCheck()/postCheck() return a list of problems; empty = OK
 * - destructive changes (DROP, data rewrite) must return true from destructive()
 *   and need explicit approval to run (6.30.11)
 * - an applied migration file must never be edited (checksum drift is refused) — add a new one
 */
abstract class Migration
{
    abstract public function description(): string;

    abstract public function up(Connection $db): void;

    /** @return list<string> */
    public function preCheck(Connection $db): array
    {
        return [];
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        return [];
    }

    public function destructive(): bool
    {
        return false;
    }

    /** 'rollback' when down() is implemented, otherwise 'forward-fix' (10.2). */
    public function recovery(): string
    {
        return 'forward-fix';
    }

    public function down(Connection $db): void
    {
        throw new \LogicException(static::class . ' has no rollback; use a forward-fix migration.');
    }
}

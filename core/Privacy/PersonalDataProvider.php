<?php

declare(strict_types=1);

namespace Blue\Core\Privacy;

use Blue\Core\App;

/**
 * What a module keeps about one person, for the person's own export (P-05) and data-subject requests (P-04, DEC-82).
 * Declared in module.json as "personal_data": "Blue\\Modules\\X\\XPersonalData".
 *
 *  export  sections of rows (machine keys, plain values) — restricted values masked the way the module shows them to
 *          the person (e.g. identifiers: type + last digits), never secrets.
 *  erase   what may be erased or anonymised NOW (not needed for a legal duty, no legal hold), with a dry-run; what is
 *          kept is listed with a reason. Never touches records the law or open processes need.
 */
interface PersonalDataProvider
{
    /** @return list<array{key: string, label: string, rows: list<array<string, scalar|null>>}> label = translation key */
    public static function export(App $app, int $userId, ?int $employeeId): array;

    /** @return array{done: array<string, int>, kept: array<string, string>} label key => count · label key => reason key */
    public static function erase(App $app, int $userId, ?int $employeeId, int $actorId, bool $dryRun): array;
}

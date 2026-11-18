<?php

declare(strict_types=1);

namespace Blue\Core\Kpi;

use Blue\Core\App;

/**
 * Read-only figures a module offers to the executive dashboard (M46, DEC-80). Declared in module.json as
 * "measures": "Blue\\Modules\\X\\XMeasures". The module owns the formula and reads only its own records; the
 * dashboard never stores transactions — it keeps KPI definitions and computed values with their timestamp.
 */
interface MeasureProvider
{
    /**
     * code (prefixed with the module code) => definition:
     *  label / formula / source  translation keys (the formula text is what users see as the definition)
     *  unit       count | percent | hours | days
     *  dims       filters the figure can honour: site, department
     *  drill      [href, permission] — the source screen, linked only for people with that permission
     *  point      true = a figure as of now (e.g. open positions), not a total over the period
     *
     * @return array<string, array{label: string, formula: string, source: string, unit: string, dims: list<string>, drill: array{0: string, 1: string}|null, point?: bool}>
     */
    public static function measures(): array;

    public static function compute(App $app, string $code, MeasureQuery $q): MeasureResult;
}

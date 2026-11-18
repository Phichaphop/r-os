<?php

declare(strict_types=1);

namespace Blue\Core\Kpi;

use Blue\Core\App;

/** The measures of every enabled module (manifest key "measures"). */
final class MeasureCatalog
{
    /** @return array<string, array{label: string, formula: string, source: string, unit: string, dims: list<string>, drill: array{0: string, 1: string}|null, point?: bool, module: string, provider: class-string<MeasureProvider>}> */
    public static function all(App $app): array
    {
        $out = [];
        foreach ($app->modules()->enabled() as $code => $m) {
            $class = (string) ($m['measures'] ?? '');
            if ($class === '' || !class_exists($class) || !is_subclass_of($class, MeasureProvider::class)) {
                continue;
            }
            foreach ($class::measures() as $mc => $def) {
                if (str_starts_with((string) $mc, $code . '.')) {
                    $out[(string) $mc] = $def + ['module' => (string) $code, 'provider' => $class];
                }
            }
        }
        ksort($out);
        return $out;
    }
}

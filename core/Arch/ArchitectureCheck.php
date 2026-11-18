<?php

declare(strict_types=1);

namespace Blue\Core\Arch;

/**
 * P0-02 §4 dependency rules, checked statically (phase 1):
 * 1. Views contain no SQL and no database access.
 * 2. Domain code never imports Infrastructure.
 * 3. A module never uses another module's Infrastructure/Domain/Http (only its Public Service — Application/Api).
 * 4. Module code reads time via Clock (no new DateTime()/time()/date()).
 * 5. Only core/Database and core/Install open PDO connections (collector/ is a separate office program with its own SQLite file).
 * 6. No two paths differ only by letter case — Windows/macOS (XAMPP) treat them as the same folder.
 */
final class ArchitectureCheck
{
    public function __construct(private readonly string $root)
    {
    }

    /** @return list<string> problems */
    public function run(): array
    {
        $problems = [];
        $seen = [];
        foreach (FileScan::files($this->root, '', []) as $rel) {
            $parts = explode('/', $rel);
            for ($i = 1; $i <= count($parts); $i++) {
                $p = implode('/', array_slice($parts, 0, $i));
                $k = strtolower($p);
                if (isset($seen[$k]) && $seen[$k] !== $p) {
                    $problems[] = "{$p}: clashes with {$seen[$k]} on case-insensitive file systems (rule 6)";
                }
                $seen[$k] ??= $p;
            }
        }
        $problems = array_values(array_unique($problems));
        $php = FileScan::files($this->root, '', ['php']);
        foreach ($php as $rel) {
            if (str_starts_with($rel, 'tests/') || str_starts_with($rel, 'vendor/')) {
                continue;
            }
            $src = (string) file_get_contents($this->root . '/' . $rel);
            $isView = str_contains($rel, '/views/') || str_contains($rel, '/Views/') || str_starts_with($rel, 'ui/components/');

            if ($isView && preg_match('/\b(SELECT\s+.+\s+FROM|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b|->db\(\)|\bPDO\b/is', $src)) {
                $problems[] = "{$rel}: view contains SQL/database access (rule 1)";
            }
            if (preg_match('#/Domain/#', $rel) && preg_match('/^use\s+[^;]*\\\\Infrastructure\\\\/m', $src)) {
                $problems[] = "{$rel}: Domain imports Infrastructure (rule 2)";
            }
            if (preg_match('#^modules/([a-z0-9_]+)/#', $rel, $m)) {
                $own = $m[1];
                if (preg_match_all('/Blue\\\\Modules\\\\([A-Za-z0-9_]+)\\\\(Infrastructure|Domain|Http)\\\\/', $src, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $hit) {
                        if (strtolower($hit[1]) !== $own) {
                            $problems[] = "{$rel}: uses {$hit[1]}\\{$hit[2]} of another module (rule 3)";
                        }
                    }
                }
                if (preg_match('/new\s+\\\\?DateTime(Immutable)?\s*\(|(?<![$>:\w])time\(\)|(?<![$>:\w])date\(/', $src)) { // $date( is the view's display helper
                    $problems[] = "{$rel}: reads system time directly — use Clock (rule 4)";
                }
            }
            if (preg_match('/new\s+\\\\?PDO\s*\(/', $src) && !str_starts_with($rel, 'core/Database/') && !str_starts_with($rel, 'core/Install/') && !str_starts_with($rel, 'collector/')) {
                $problems[] = "{$rel}: opens its own PDO connection (rule 5)";
            }
        }
        return $problems;
    }
}

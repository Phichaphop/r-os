<?php

declare(strict_types=1);

namespace Blue\Core\Arch;

/**
 * Phase-1 gate "no secret in repo" (P0-06 §9). Lightweight local scan; CI additionally runs gitleaks.
 * Scans files tracked by git (or all files when not a git checkout).
 */
final class SecretScan
{
    /** @var array<string, string> name => regex */
    private const PATTERNS = [
        'private key' => '/-----BEGIN (RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/',
        'GitHub token' => '/\bgh[pousr]_[A-Za-z0-9]{36,}\b/',
        'AWS access key' => '/\bAKIA[0-9A-Z]{16}\b/',
        'Slack token' => '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/',
        'Brevo/Sendinblue key' => '/\bxkeysib-[a-f0-9]{64}-[A-Za-z0-9]{16}\b/',
        'Google API key' => '/\bAIza[0-9A-Za-z\-_]{35}\b/',
        'generic API key' => '/\bsk-[A-Za-z0-9]{32,}\b/',
        'APP_KEY value' => "/'APP_KEY'\s*=>\s*'base64:[A-Za-z0-9+\/]{40,}={0,2}'/",
        'DB password value' => "/'DB_PASS'\s*=>\s*'(?!change-me'|')[^']{6,}'/",
    ];

    public function __construct(private readonly string $root)
    {
    }

    /** @return list<string> problems */
    public function run(): array
    {
        $problems = [];
        foreach ($this->files() as $rel) {
            if (str_starts_with($rel, 'core/Arch/SecretScan.php') || str_starts_with($rel, 'tests/')) {
                continue;
            }
            $path = $this->root . '/' . $rel;
            if (!is_file($path) || filesize($path) > 2_000_000) {
                continue;
            }
            $src = (string) file_get_contents($path);
            foreach (self::PATTERNS as $name => $re) {
                if (preg_match($re, $src)) {
                    $problems[] = "{$rel}: possible {$name}";
                }
            }
        }
        if (in_array('config/env.php', $this->files(), true)) {
            $problems[] = 'config/env.php is tracked by git — it holds secrets and must stay ignored';
        }
        return $problems;
    }

    /** @return list<string> */
    private function files(): array
    {
        static $cache = [];
        if (isset($cache[$this->root])) {
            return $cache[$this->root];
        }
        $files = [];
        if (is_dir($this->root . '/.git')) {
            $out = shell_exec('git -C ' . escapeshellarg($this->root) . ' ls-files --cached --others --exclude-standard 2>/dev/null');
            $files = array_values(array_filter(explode("\n", (string) $out)));
        }
        if ($files === []) {
            $files = FileScan::files($this->root, '', []);
        }
        return $cache[$this->root] = $files;
    }
}

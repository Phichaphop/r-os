<?php

declare(strict_types=1);

namespace Blue\Core\Auth;

/**
 * Password rules (DEC-51, P0-04 §3 — OWASP/NIST style):
 * - minimum length (config, default 12), maximum 128
 * - not in the common-password list, not containing the username / e-mail name / product name
 * - no forced composition rules and no periodic expiry
 * Returns translation keys so the UI can explain what to change.
 */
final class PasswordPolicy
{
    /** @var array<string, true>|null */
    private static ?array $common = null;

    public function __construct(private readonly int $minLength = 12)
    {
    }

    /** @return list<string> problems as translation keys (empty = acceptable) */
    public function check(string $password, string $username = '', string $email = ''): array
    {
        $problems = [];
        $len = mb_strlen($password);
        if ($len < $this->minLength) {
            $problems[] = 'auth.pw.too_short';
        }
        if ($len > 128) {
            $problems[] = 'auth.pw.too_long';
        }
        $lower = mb_strtolower($password);
        if (isset(self::common()[$lower]) || preg_match('/^(.)\1+$/u', $password) || preg_match('/^(0123456789|1234567890|abcdefghij)/', $lower)) {
            $problems[] = 'auth.pw.common';
        }
        $parts = array_filter([mb_strtolower($username), mb_strtolower((string) strstr($email, '@', true)), 'r-os', 'ros123', 'blue', 'blueos', 'blue123']);
        foreach ($parts as $p) {
            if (mb_strlen($p) >= 3 && str_contains($lower, $p)) {
                $problems[] = 'auth.pw.contains_name';
                break;
            }
        }
        return $problems;
    }

    public function minLength(): int
    {
        return $this->minLength;
    }

    /** @return array<string, true> */
    private static function common(): array
    {
        if (self::$common === null) {
            self::$common = [];
            foreach (file(__DIR__ . '/data/common-passwords.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if ($line[0] !== '#') {
                    self::$common[$line] = true;
                }
            }
        }
        return self::$common;
    }
}

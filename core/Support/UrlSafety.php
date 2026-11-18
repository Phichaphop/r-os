<?php

declare(strict_types=1);

namespace Blue\Core\Support;

/**
 * Checks a web address that people will open from the system (M31 links, M27 QR codes).
 * Errors block saving; warnings are shown so a person decides (P0-04 — no secrets in URLs, no script/data URLs).
 * Error/warning values are translation keys.
 */
final class UrlSafety
{
    public const MAX_LENGTH = 1000;

    /** Query/fragment names that carry credentials — never put them in a shared link or a printed QR. */
    private const SECRET_PARAMS = '/^(access[_-]?token|id[_-]?token|refresh[_-]?token|token|secret|client[_-]?secret|password|passwd|pwd|pass|api[_-]?key|apikey|key|access[_-]?key|auth|authorization|session|sessionid|sid|sig|signature|otp|pin)$/i';

    /**
     * @param list<string> $schemes allowed schemes
     * @return array{errors: list<string>, warnings: list<string>, host: string}
     */
    public static function check(string $url, array $schemes = ['https', 'http']): array
    {
        $errors = [];
        $warnings = [];
        $url = trim($url);
        if ($url === '' || mb_strlen($url) > self::MAX_LENGTH) {
            return ['errors' => ['url.error.length'], 'warnings' => [], 'host' => ''];
        }
        if (preg_match('/[\x00-\x20\x7F]/', $url)) {
            return ['errors' => ['url.error.spaces'], 'warnings' => [], 'host' => ''];
        }
        $p = parse_url($url);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        if ($p === false || $scheme === '' || !in_array($scheme, $schemes, true)) {
            // javascript:, data:, vbscript:, file:, relative paths …
            return ['errors' => ['url.error.scheme'], 'warnings' => [], 'host' => ''];
        }
        $host = strtolower((string) ($p['host'] ?? ''));
        if ($host === '') {
            return ['errors' => ['url.error.host'], 'warnings' => [], 'host' => ''];
        }
        if (isset($p['user']) || isset($p['pass'])) {
            $errors[] = 'url.error.credentials';
        }
        $names = [];
        foreach ([(string) ($p['query'] ?? ''), (string) ($p['fragment'] ?? '')] as $part) {
            foreach (explode('&', $part) as $pair) {
                if ($pair !== '') {
                    $names[] = urldecode(explode('=', $pair, 2)[0]);
                }
            }
        }
        foreach ($names as $n) {
            if (preg_match(self::SECRET_PARAMS, $n)) {
                $errors[] = 'url.error.secret';
                break;
            }
        }
        $ipHost = trim($host, '[]');
        if (filter_var($ipHost, FILTER_VALIDATE_IP) !== false) {
            $warnings[] = filter_var($ipHost, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false ? 'url.warn.private_ip' : 'url.warn.ip';
        } elseif (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host)) {
            $errors[] = 'url.error.host';
        } elseif (str_contains($host, 'xn--')) {
            $warnings[] = 'url.warn.punycode'; // look-alike domains
        }
        if ($scheme === 'http') {
            $warnings[] = 'url.warn.http';
        }
        return ['errors' => array_values(array_unique($errors)), 'warnings' => $warnings, 'host' => $host];
    }
}

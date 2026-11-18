<?php

declare(strict_types=1);

namespace Blue\Modules\Qr;

use Blue\Core\Support\UrlSafety;

/**
 * What may go into a QR code (M27 D — "ห้าม encode เลขบัตรประชาชน/ข้อมูลสุขภาพ/secret token ใน QR ที่อ่านได้ทั่วไป").
 * Errors block; warnings are shown before download so a person decides. Values are translation keys.
 */
final class QrPolicy
{
    public const MAX_TEXT = 1200;
    public const EXTERNAL_PARAM = 'openExternalBrowser';

    /** @return array{errors: list<string>, warnings: list<string>} */
    public static function check(string $content, bool $isUrl): array
    {
        $errors = [];
        $warnings = [];
        if (trim($content) === '') {
            return ['errors' => ['qr.error.empty'], 'warnings' => []];
        }
        if (mb_strlen($content) > self::MAX_TEXT || !mb_check_encoding($content, 'UTF-8')) {
            return ['errors' => ['qr.error.too_long'], 'warnings' => []];
        }
        if ($isUrl) {
            $u = UrlSafety::check($content);
            $errors = array_merge($errors, $u['errors']);
            $warnings = array_merge($warnings, $u['warnings']);
        } elseif (preg_match('#^\s*(javascript|data|vbscript):#i', $content)) {
            $errors[] = 'url.error.scheme';
        }
        if (self::hasThaiId($content)) {
            $errors[] = 'qr.error.national_id';
        }
        if (self::hasCardNumber($content)) {
            $errors[] = 'qr.error.card';
        }
        if (preg_match('/\b(password|passwd|pwd|secret|api[_-]?key|token)\s*[:=]/i', $content) || preg_match('/รหัสผ่าน\s*[:=]/u', $content)) {
            $errors[] = 'qr.error.secret';
        }
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $content) || preg_match('/(?<!\d)0[689]\d[- ]?\d{3}[- ]?\d{4}(?!\d)/', $content)) {
            $warnings[] = 'qr.warn.personal';
        }
        return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
    }

    /** 0.38.0: what a static code can hold — a managed code is always a link. */
    public const TYPES = ['url', 'text', 'tel', 'email', 'wifi', 'vcard'];

    /** Form fields of each type (besides the shared "content" box used by url and text). */
    public const TYPE_FIELDS = [
        'tel' => ['tel'],
        'email' => ['email_to', 'email_subject', 'email_body'],
        'wifi' => ['wifi_ssid', 'wifi_pass', 'wifi_enc', 'wifi_hidden'],
        'vcard' => ['vc_name', 'vc_org', 'vc_title', 'vc_tel', 'vc_email', 'vc_url'],
    ];

    /**
     * Builds the text that goes into the symbol for a type (standard payloads every phone camera understands:
     * tel:, mailto:, WIFI:…;;, vCard 3.0). url/text use $f['content'] as typed.
     *
     * @param array<string, string> $f
     * @return array{content: string, errors: array<string, string>}
     */
    public static function compose(string $type, array $f): array
    {
        $v = static fn (string $k, int $max = 200): string => mb_substr(trim((string) ($f[$k] ?? '')), 0, $max);
        switch ($type) {
            case 'tel':
                $tel = (string) preg_replace('/[\s().-]+/', '', $v('tel', 30));
                return preg_match('/^\+?[0-9]{3,20}$/', $tel) === 1 ? ['content' => 'tel:' . $tel, 'errors' => []] : ['content' => '', 'errors' => ['tel' => 'qr.error.tel']];
            case 'email':
                $to = $v('email_to', 254);
                if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                    return ['content' => '', 'errors' => ['email_to' => 'qr.error.email']];
                }
                $q = array_filter(['subject' => $v('email_subject', 150), 'body' => $v('email_body', 500)], static fn (string $x): bool => $x !== '');
                return ['content' => 'mailto:' . $to . ($q !== [] ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : ''), 'errors' => []];
            case 'wifi':
                $ssid = $v('wifi_ssid', 32);
                $enc = in_array($f['wifi_enc'] ?? '', ['WPA', 'WEP', 'nopass'], true) ? (string) $f['wifi_enc'] : 'WPA';
                $pass = $v('wifi_pass', 63);
                $errors = [];
                if ($ssid === '') {
                    $errors['wifi_ssid'] = 'qr.error.wifi_ssid';
                }
                if ($enc !== 'nopass' && $pass === '') {
                    $errors['wifi_pass'] = 'qr.error.wifi_pass';
                }
                if ($errors !== []) {
                    return ['content' => '', 'errors' => $errors];
                }
                $esc = static fn (string $x): string => (string) preg_replace('/([\\\\;,:"])/', '\\\\$1', $x);
                return ['content' => 'WIFI:T:' . $enc . ';S:' . $esc($ssid) . ';' . ($enc !== 'nopass' ? 'P:' . $esc($pass) . ';' : '') . (($f['wifi_hidden'] ?? '') === '1' ? 'H:true;' : '') . ';', 'errors' => []];
            case 'vcard':
                $name = $v('vc_name', 120);
                if (mb_strlen($name) < 2) {
                    return ['content' => '', 'errors' => ['vc_name' => 'qr.error.vc_name']];
                }
                $email = $v('vc_email', 254);
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    return ['content' => '', 'errors' => ['vc_email' => 'qr.error.email']];
                }
                $esc = static fn (string $x): string => str_replace(["\\", ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $x);
                $lines = ['BEGIN:VCARD', 'VERSION:3.0', 'N:' . $esc($name) . ';;;;', 'FN:' . $esc($name)];
                foreach (['ORG' => $v('vc_org', 120), 'TITLE' => $v('vc_title', 120), 'TEL;TYPE=CELL' => (string) preg_replace('/[\s().-]+/', '', $v('vc_tel', 30)), 'EMAIL' => $email, 'URL' => $v('vc_url', 300)] as $k => $x) {
                    if ($x !== '') {
                        $lines[] = $k . ':' . $esc($x);
                    }
                }
                $lines[] = 'END:VCARD';
                return ['content' => implode("\r\n", $lines), 'errors' => []];
            default:
                return ['content' => trim((string) ($f['content'] ?? '')), 'errors' => []];
        }
    }

    /**
     * Adds openExternalBrowser=1 as a query parameter (keeps path and #fragment, replaces an existing value) so
     * LINE and other in-app browsers hand the page to the phone's browser. The final URL is always shown to the user.
     */
    public static function withExternalBrowser(string $url): string
    {
        $hash = '';
        if (($p = strpos($url, '#')) !== false) {
            $hash = substr($url, $p);
            $url = substr($url, 0, $p);
        }
        $parts = explode('?', $url, 2);
        $pairs = isset($parts[1]) && $parts[1] !== '' ? explode('&', $parts[1]) : [];
        $pairs = array_values(array_filter($pairs, static fn (string $kv): bool => explode('=', $kv, 2)[0] !== self::EXTERNAL_PARAM));
        $pairs[] = self::EXTERNAL_PARAM . '=1';
        return $parts[0] . '?' . implode('&', $pairs) . $hash;
    }

    /** 13-digit Thai national ID with a valid check digit (with or without dashes/spaces). */
    public static function hasThaiId(string $s): bool
    {
        preg_match_all('/(?<![\d])(\d[\s-]?\d{4}[\s-]?\d{5}[\s-]?\d{2}[\s-]?\d)(?![\d])/', $s, $m);
        foreach ($m[1] as $cand) {
            $d = (string) preg_replace('/\D/', '', $cand);
            if (strlen($d) !== 13) {
                continue;
            }
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += (int) $d[$i] * (13 - $i);
            }
            if ((11 - $sum % 11) % 10 === (int) $d[12]) {
                return true;
            }
        }
        return false;
    }

    /** 13–19 digit numbers that pass the Luhn check (payment cards). */
    public static function hasCardNumber(string $s): bool
    {
        preg_match_all('/(?<!\d)(?:\d[ -]?){13,19}(?!\d)/', $s, $m);
        foreach ($m[0] as $cand) {
            $d = (string) preg_replace('/\D/', '', $cand);
            if (strlen($d) < 13 || strlen($d) > 19 || !preg_match('/^[2-6]/', $d)) {
                continue;
            }
            $sum = 0;
            $alt = false;
            for ($i = strlen($d) - 1; $i >= 0; $i--) {
                $n = (int) $d[$i];
                if ($alt) {
                    $n *= 2;
                    if ($n > 9) {
                        $n -= 9;
                    }
                }
                $sum += $n;
                $alt = !$alt;
            }
            if ($sum % 10 === 0) {
                return true;
            }
        }
        return false;
    }
}

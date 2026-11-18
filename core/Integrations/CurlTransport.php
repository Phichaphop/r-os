<?php

declare(strict_types=1);

namespace Blue\Core\Integrations;

/**
 * Real outgoing HTTP (cURL). TLS certificates are always verified, redirects are not followed (a redirect could
 * send the signed request elsewhere), and the answer is cut at $maxBytes.
 */
final class CurlTransport implements HttpTransport
{
    public function get(string $url, array $headers, int $timeoutSeconds, int $maxBytes): array
    {
        $start = microtime(true);
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_missing', 'ms' => 0];
        }
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        if (!isset(array_change_key_case($headers)['accept'])) {
            $lines[] = 'Accept: application/json';
        }
        $body = '';
        $tooBig = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true, CURLOPT_HTTPHEADER => $lines, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'BlueOS/attendance-pull',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooBig, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooBig = true;
                    return 0; // abort
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = $ok === false ? ($tooBig ? 'response_too_large' : 'network:' . curl_errno($ch)) : null;
        curl_close($ch);
        return ['status' => $ok === false ? 0 : $status, 'body' => $ok === false ? '' : $body, 'error' => $err, 'ms' => (int) round((microtime(true) - $start) * 1000)];
    }
}

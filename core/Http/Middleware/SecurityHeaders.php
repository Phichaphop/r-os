<?php

declare(strict_types=1);

namespace Blue\Core\Http\Middleware;

use Blue\Core\Http\Middleware;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/**
 * Security headers + strict CSP (P0-04, 8B). Self-hosted assets only (no CDN — P0-02 §11).
 * Inline scripts are allowed only with the per-request nonce (theme pre-paint script).
 */
final class SecurityHeaders implements Middleware
{
    public function process(Request $request, callable $next): Response
    {
        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $request->attributes['csp_nonce'] = $nonce;

        $response = $next($request);

        // a page may allow images from extra https origins (map tiles of the vehicle booking map — DEC-90); nothing else
        $imgExtra = '';
        foreach ((array) ($request->attributes['csp_img_origins'] ?? []) as $origin) {
            if (is_string($origin) && preg_match('#^https://[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+(:\d{2,5})?$#', $origin) === 1) {
                $imgExtra .= ' ' . $origin;
            }
        }
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self'",
            "img-src 'self' data: blob:" . $imgExtra,
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
        if (!isset($response->headers['Content-Security-Policy'])) {
            $response->withHeader('Content-Security-Policy', $csp); // file responses set a stricter policy of their own
        }
        $response->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', $response->headers['Referrer-Policy'] ?? 'same-origin') // outbound redirects (M27/M31) send no-referrer
            ->withHeader('X-Frame-Options', isset($response->headers['X-Frame-Options']) ? $response->headers['X-Frame-Options'] : 'DENY')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            // off by default; a page that needs the location (M57 check-in, DEC-71) sets its own "geolocation=(self)"
            ->withHeader('Permissions-Policy', $response->headers['Permissions-Policy'] ?? 'geolocation=(), camera=(), microphone=(), payment=()');
        if ($request->secure) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000');
        }
        if (!isset($response->headers['Cache-Control'])) {
            $response->withHeader('Cache-Control', 'no-store');
        }
        return $response;
    }
}

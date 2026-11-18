<?php

declare(strict_types=1);

namespace Blue\Core\Http\Middleware;

use Blue\Core\Http\Middleware;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/**
 * Hardened native PHP session. Idle/absolute timeouts and regeneration on login
 * are enforced by Auth in phase 2 (DEC-51: idle 30 min, absolute 12 h).
 */
final class Session implements Middleware
{
    public function process(Request $request, callable $next): Response
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_name('blue_sid');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $request->secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
        return $next($request);
    }
}

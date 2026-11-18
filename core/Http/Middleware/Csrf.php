<?php

declare(strict_types=1);

namespace Blue\Core\Http\Middleware;

use Blue\Core\Error\BusinessException;
use Blue\Core\Http\Middleware;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Http\SessionStore;

/** Synchroniser-token CSRF protection for every state-changing request. */
final class Csrf implements Middleware
{
    public const FIELD = '_csrf';

    public function __construct(private readonly SessionStore $session)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $token = $this->session->get('csrf_token');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set('csrf_token', $token);
        }
        $request->attributes['csrf_token'] = $token;

        // machine-to-machine endpoints carry their own credential (key / HMAC) and no session cookie — CSRF does not apply
        if (!$request->isSafeMethod() && !str_starts_with($request->path, '/m2m/')) {
            $sent = (string) ($request->body[self::FIELD] ?? $request->header('x-csrf-token'));
            if ($sent === '' || !hash_equals($token, $sent)) {
                throw new BusinessException('error.csrf', [], 419);
            }
        }
        return $next($request);
    }
}

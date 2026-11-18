<?php

declare(strict_types=1);

namespace Blue\Core\Http\Middleware;

use Blue\Core\Error\HttpException;
use Blue\Core\Http\Middleware;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Install\InstallState;

/**
 * DEC-17: before installation only /install is reachable; after installation
 * /install answers 404 forever (locked) — repairs use migrations (DEC-16).
 */
final class InstallGuard implements Middleware
{
    public function __construct(private readonly InstallState $state)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $isInstallPath = $request->path === '/install' || str_starts_with($request->path, '/install/');
        $isAsset = str_starts_with($request->path, '/assets/');
        if ($this->state->isLocked()) {
            if ($isInstallPath) {
                throw HttpException::notFound();
            }
            if ($this->state->isRestoring() && !$isAsset && $request->path !== '/health') {
                return Response::html(
                    '<!doctype html><html lang="th"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Blue OS</title>'
                    . '<body style="font-family:system-ui,sans-serif;padding:2rem;max-width:40rem;margin:auto"><h1>กำลังกู้คืนข้อมูล</h1>'
                    . '<p>ระบบปิดชั่วคราวระหว่างกู้คืนข้อมูลจากชุดสำรอง กรุณารอสักครู่แล้วลองใหม่</p><p lang="en">The system is being restored from a backup. Please try again shortly.</p></body></html>',
                    503
                )->withHeader('Retry-After', '120');
            }
        } elseif (!$isInstallPath && !$isAsset && $request->path !== '/health') {
            return Response::redirect($request->basePath() . '/install');
        }
        return $next($request);
    }
}

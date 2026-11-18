<?php

declare(strict_types=1);

namespace Blue\Core\Error;

use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\I18n\Translator;
use Blue\Core\Log\Logger;
use Blue\Core\Ui\View;
use Throwable;

/**
 * Turns any Throwable into a safe response (6.30.6, P0-06 §9 phase-1 gate):
 * - BusinessException → its translated message + HTTP status
 * - anything else     → generic message + reference code (correlation ID); full detail goes to the log only
 * - stack traces are shown ONLY when AppConfig::debug() (local/ci), never on prototype/staging/production
 */
final class ErrorRenderer
{
    public function __construct(
        private readonly Logger $logger,
        private readonly Translator $t,
        private readonly View $view,
        private readonly bool $debug,
    ) {
    }

    public function render(Throwable $e, Request $request): Response
    {
        $cid = $this->logger->correlationId();

        if ($e instanceof BusinessException) {
            $status = $e->httpStatus;
            $title = $this->t->get($e->messageKey, $e->params);
            $this->logger->info('business_error', ['key' => $e->messageKey, 'status' => $status, 'path' => $request->path]);
        } else {
            $status = 500;
            $title = $this->t->get('error.unexpected');
            $this->logger->error('unhandled_exception', Logger::exceptionContext($e) + ['path' => $request->path]);
        }

        $detail = $this->debug && !($e instanceof BusinessException)
            ? $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString()
            : null;

        if ($request->wantsJson()) {
            $body = ['error' => ['message' => $title, 'reference' => $cid]];
            if ($e instanceof BusinessException) {
                $body['error']['code'] = $e->messageKey;
            }
            if ($detail !== null) {
                $body['error']['debug'] = $detail;
            }
            return Response::json($body, $status);
        }

        try {
            $html = $this->view->render('errors/error', [
                'status' => $status,
                'title' => $title,
                'hint' => $this->t->get($status >= 500 ? 'error.hint_technical' : 'error.hint_business'),
                'reference' => $cid,
                'debugDetail' => $detail,
            ], 'layouts/minimal');
        } catch (Throwable $viewError) {
            // Last-resort page: no templates, no details.
            $this->logger->error('error_page_failed', Logger::exceptionContext($viewError));
            $html = '<!doctype html><meta charset="utf-8"><title>Error</title><p>An unexpected error occurred. Reference: '
                . htmlspecialchars($cid, ENT_QUOTES) . '</p>';
        }
        return Response::html($html, $status);
    }
}

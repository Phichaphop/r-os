<?php

declare(strict_types=1);

namespace Blue\Core\Http;

use Blue\Core\App;
use Blue\Core\Error\ValidationException;

/** Small helpers shared by controllers (Presentation layer — no SQL, no business rules here). */
abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }

    protected function ctx(Request $r): RequestContext
    {
        /** @var RequestContext $ctx */
        $ctx = $r->attributes['ctx'];
        return $ctx;
    }

    protected function store(Request $r): SessionStore
    {
        /** @var SessionStore $s */
        $s = $r->attributes['session_store'];
        return $s;
    }

    /** @param array<string, mixed> $data */
    protected function page(string $template, array $data = [], int $status = 200, ?string $layout = 'layouts/app'): Response
    {
        return Response::html($this->app->view()->render($template, $data, $layout), $status);
    }

    protected function redirect(Request $r, string $path): Response
    {
        return Response::redirect($r->basePath() . $path, 303);
    }

    /** @param array<string, scalar> $params */
    protected function flash(Request $r, string $tone, string $key, array $params = []): void
    {
        $this->store($r)->set('flash', ['tone' => $tone, 'text' => $this->app->translator()->get($key, $params)]);
    }

    /** @return array<string, string> field => translated message */
    protected function errors(ValidationException $e): array
    {
        $t = $this->app->translator();
        $min = $this->app->authSettings()->passwordMin;
        return array_map(static fn (string $k): string => $t->get($k, ['min' => $min]), $e->errors);
    }

    /** Translation key of the first field error (for flash messages on compact forms). */
    protected function firstError(ValidationException $e): string
    {
        return (string) (array_values($e->errors)[0] ?? 'form.has_errors');
    }

    protected function str(Request $r, string $key, int $max = 500): string
    {
        $v = $r->input($key, '');
        return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
    }

    /** Only same-site relative paths are accepted as "next" targets (no open redirect). */
    protected function safeNext(mixed $next): string
    {
        $next = is_string($next) ? $next : '/';
        return preg_match('#^/(?!/)[A-Za-z0-9/_\-.?=&%]*$#', $next) ? $next : '/';
    }
}

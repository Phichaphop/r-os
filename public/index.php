<?php

declare(strict_types=1);

/** Front controller — every request enters here (P0-02 §7). */

require __DIR__ . '/../core/bootstrap.php';

use Blue\Core\App;
use Blue\Core\Http\Kernel;
use Blue\Core\Http\Request;
use Blue\Core\Jobs\PiggybackTrigger;

$app = App::boot(BLUE_ROOT);

// Fresh copy or a newer release copied over an older one (zip / FTP without a build step):
// (re)publish the design-system assets when the ui/ sources changed.
$publisher = new \Blue\Core\Ui\AssetPublisher(BLUE_ROOT);
if (!$publisher->isCurrent()) {
    try {
        $publisher->publish();
    } catch (\Throwable $e) {
        $app->logger->error('assets_publish_failed', ['error' => $e->getMessage()]);
    }
}
$kernel = new Kernel($app);
$request = Request::fromGlobals();
$response = $kernel->handle($request);
$response->send();

// Prototype scheduler: run due jobs after the page is delivered (P0-02 §8.2, CF-16).
if (
    $app->config->string('SCHEDULER') === 'piggyback'
    && $app->install->isLocked()
    && $app->hasDatabase()
    && $request->method === 'GET'
    && $response->status < 400
    && str_contains($response->headers['Content-Type'] ?? '', 'text/html')
) {
    (new PiggybackTrigger(
        static fn () => $app->scheduler(),
        BLUE_ROOT . '/storage/cache/scheduler.next',
        $app->logger,
        $app->config->int('SCHEDULER_TIME_BUDGET_MS', 2000),
        $app->config->int('SCHEDULER_MAX_JOBS', 3),
    ))->afterResponse();
}

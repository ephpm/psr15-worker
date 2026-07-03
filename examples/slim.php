<?php

declare(strict_types=1);

/**
 * RECIPE — Slim 4 under ePHPm worker mode.
 *
 * This is a documentation example. It requires `slim/slim` and a PSR-7
 * implementation to be installed in your project and is NOT run in CI.
 *
 * A Slim 4 `App` implements Psr\Http\Server\RequestHandlerInterface, so it can
 * be handed straight to the Worker.
 *
 * Two ways to use it:
 *
 *   A) Point `[php] worker_script` at bin/ephpm-worker and pass this file's
 *      sibling "bootstrap" that `return`s the app (see the return at the end).
 *
 *   B) Copy this file into your project as the worker script and let it run the
 *      loop itself (the bottom block).
 */

use Ephpm\Psr15\Worker;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

$app->get('/', function ($request, $response) {
    $response->getBody()->write('Hello from Slim under ePHPm worker mode!');

    return $response;
});

$app->get('/hello/{name}', function ($request, $response, array $args) {
    $response->getBody()->write('Hello, ' . $args['name']);

    return $response;
});

// --- Option A: this file is a bootstrap consumed by bin/ephpm-worker. --------
// Uncomment to use with:  ephpm-worker examples/slim.php
// return $app;

// --- Option B: this file is itself the worker script. ------------------------
(new Worker($app))->run();

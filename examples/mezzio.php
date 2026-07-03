<?php

declare(strict_types=1);

/**
 * RECIPE — Mezzio under ePHPm worker mode.
 *
 * This is a documentation example. It requires a Mezzio application skeleton
 * (`mezzio/mezzio` + a DI container) in your project and is NOT run in CI.
 *
 * Mezzio's `Mezzio\Application` implements
 * Psr\Http\Server\RequestHandlerInterface, so once the container has wired it
 * up (routes + pipeline) it can be handed straight to the Worker.
 *
 * The bootstrapping below mirrors a standard Mezzio skeleton, where
 * config/container.php returns a PSR-11 container and the app pulls its
 * pipeline/routes from config/pipeline.php and config/routes.php.
 */

use Ephpm\Psr15\Worker;
use Mezzio\Application;

require __DIR__ . '/../vendor/autoload.php';

/** @var \Psr\Container\ContainerInterface $container */
$container = require __DIR__ . '/../config/container.php';

/** @var Application $app */
$app = $container->get(Application::class);

// Wire up the middleware pipeline and routing tables (skeleton convention).
(require __DIR__ . '/../config/pipeline.php')($app, $container->get(\Mezzio\MiddlewareFactory::class), $container);
(require __DIR__ . '/../config/routes.php')($app, $container->get(\Mezzio\MiddlewareFactory::class), $container);

// --- Option A: consumed by bin/ephpm-worker as a bootstrap. ------------------
// Uncomment to use with:  ephpm-worker examples/mezzio.php
// return $app;

// --- Option B: this file is itself the worker script. ------------------------
(new Worker($app))->run();

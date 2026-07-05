<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for ephpm/psr15-worker.
 *
 * At runtime the `Ephpm\Worker` functions (take_request / send_response /
 * send_response_stream) are provided natively by the ePHPm engine and are NOT
 * autoloaded from any PHP file (the ephpm/worker package ships an IDE stub
 * only, deliberately outside autoload.files). That lets the unit suite install
 * recording shims here so the response-sending paths of the Worker can be
 * exercised without a live engine — every call is captured by
 * {@see \Ephpm\Psr15\Tests\WorkerSpy}.
 */

namespace Ephpm\Worker {
    if (!\function_exists(__NAMESPACE__ . '\\send_response')) {
        /**
         * Test shim for the native send_response() primitive.
         *
         * @param array<string, string|list<string>> $headers
         */
        function send_response(int $status, array $headers, string $body): void
        {
            \Ephpm\Psr15\Tests\WorkerSpy::recordSend($status, $headers, $body);
        }
    }

    if (!\function_exists(__NAMESPACE__ . '\\send_response_stream')) {
        /**
         * Test shim for the native send_response_stream() primitive.
         *
         * @param array<string, string|list<string>> $headers
         * @param resource                           $body
         */
        function send_response_stream(int $status, array $headers, $body): void
        {
            \Ephpm\Psr15\Tests\WorkerSpy::recordStream($status, $headers, $body);
        }
    }
}

namespace {
    require __DIR__ . '/../vendor/autoload.php';
}

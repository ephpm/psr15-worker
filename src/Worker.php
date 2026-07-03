<?php

declare(strict_types=1);

namespace Ephpm\Psr15;

use Ephpm\Worker\Runtime;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Runs a PSR-15 {@see RequestHandlerInterface} under ePHPm persistent worker
 * mode.
 *
 * The worker keeps the framework bootstrapped in memory and services requests
 * in a loop, translating each ePHPm request Envelope into a PSR-7
 * {@see ServerRequestInterface}, dispatching it through the handler, and
 * translating the PSR-7 {@see ResponseInterface} back to the engine.
 *
 * Streaming caveat (Phase 1): the request body is buffered as a string
 * (`Envelope::bodyStream()` returns a string today) and the response body is
 * fully materialised before it is sent. True streaming is a later engine phase.
 */
final class Worker
{
    private readonly ServerRequestCreator $requestCreator;

    public function __construct(
        private readonly RequestHandlerInterface $handler,
        private readonly Psr17Factory $psr17Factory = new Psr17Factory(),
    ) {
        // Psr17Factory implements all four PSR-17 factory interfaces, so it can
        // act as request/URI/uploaded-file/stream factory for the creator.
        $this->requestCreator = new ServerRequestCreator(
            $this->psr17Factory,
            $this->psr17Factory,
            $this->psr17Factory,
            $this->psr17Factory,
        );
    }

    /**
     * Enter the worker loop.
     *
     * Blocks on {@see \Ephpm\Worker\take_request()}, dispatches each request,
     * and returns `0` when the engine signals shutdown/recycle (take_request()
     * returns null).
     *
     * A handler exception is turned into a `500` response so one bad request
     * cannot kill the loop; engine-level fatals are left to bubble so the
     * engine can recycle the worker.
     *
     * @return int process exit code (0 on clean shutdown)
     */
    public function run(): int
    {
        Runtime::assertAvailable();

        while (($envelope = \Ephpm\Worker\take_request()) !== null) {
            [$status, $headers, $body] = $this->handleEnvelope($envelope);
            \Ephpm\Worker\send_response($status, $headers, $body);
        }

        return 0;
    }

    /**
     * Translate one request Envelope into a `[status, headers, body]` triple.
     *
     * This is the pure, testable core of the loop: it takes anything shaped
     * like an Envelope and returns exactly what should be passed to
     * `send_response()`. It never calls a native primitive, so tests can drive
     * it with a fake envelope and a stub handler.
     *
     * @param object $envelope an Ephpm\Worker\Envelope (or a compatible fake)
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    public function handleEnvelope(object $envelope): array
    {
        try {
            $request = $this->buildRequest($envelope);
            $response = $this->handler->handle($request);
        } catch (Throwable $e) {
            return self::errorResponse($e);
        }

        return [
            $response->getStatusCode(),
            self::flattenHeaders($response),
            (string) $response->getBody(),
        ];
    }

    /**
     * Build a PSR-7 server request from an Envelope's superglobal-shaped data.
     *
     * @param object $envelope an Ephpm\Worker\Envelope (or a compatible fake)
     */
    public function buildRequest(object $envelope): ServerRequestInterface
    {
        /**
         * @var array<string, mixed>      $server
         * @var array<string, string>     $headers
         * @var array<string, string>     $cookies
         * @var array<string, mixed>      $query
         * @var array<string, mixed>|null $parsedBody
         * @var array<string, mixed>      $files
         * @var string                    $rawBody
         */
        $server = $envelope->serverVars();
        $headers = $envelope->headers();
        $cookies = $envelope->cookies();
        $query = $envelope->query();
        $parsedBody = $envelope->parsedBody();
        $files = $envelope->files();
        $rawBody = $envelope->rawBody();

        return $this->requestCreator->fromArrays(
            $server,
            $headers,
            $cookies,
            $query,
            $parsedBody,
            $files,
            $rawBody,
        );
    }

    /**
     * Flatten PSR-7 headers (`['Name' => ['v1', 'v2']]`) into the
     * `['Name' => 'v1, v2']` shape that `send_response()` expects.
     *
     * @return array<string, string>
     */
    public static function flattenHeaders(ResponseInterface $response): array
    {
        $flat = [];
        foreach ($response->getHeaders() as $name => $values) {
            $flat[$name] = \implode(', ', $values);
        }

        return $flat;
    }

    /**
     * Build the 500 response triple used when the handler throws.
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private static function errorResponse(Throwable $e): array
    {
        // Keep the body generic; the exception is left for the app's own error
        // logging / tracing to surface. We intentionally do not leak the
        // message to the client.
        unset($e);

        return [
            500,
            ['Content-Type' => 'text/plain; charset=utf-8'],
            'Internal Server Error',
        ];
    }
}

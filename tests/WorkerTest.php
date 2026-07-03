<?php

declare(strict_types=1);

namespace Ephpm\Psr15\Tests;

use Ephpm\Psr15\Worker;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Exercises the response-translation and header-flattening logic WITHOUT the
 * native ePHPm worker primitives. We feed a fake Envelope + a stub PSR-15
 * handler straight into {@see Worker::handleEnvelope()} and assert the
 * status/headers/body triple that would be passed to send_response().
 */
final class WorkerTest extends TestCase
{
    public function testHandleEnvelopeTranslatesResponse(): void
    {
        $handler = self::handlerReturning(
            (new Response())
                ->withStatus(201)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-Trace', 'abc')
                ->withBody((new Psr17Factory())->createStream('{"ok":true}'))
        );

        $worker = new Worker($handler);
        [$status, $headers, $body] = $worker->handleEnvelope(self::fakeEnvelope());

        self::assertSame(201, $status);
        self::assertSame('application/json', $headers['Content-Type']);
        self::assertSame('abc', $headers['X-Trace']);
        self::assertSame('{"ok":true}', $body);
    }

    public function testHandleEnvelopeExposesRequestDataToHandler(): void
    {
        $captured = null;
        $handler = new class($captured) implements RequestHandlerInterface {
            /** @param ServerRequestInterface|null $captured */
            public function __construct(private mixed &$captured)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;

                return new Response(200, [], 'ok');
            }
        };

        $worker = new Worker($handler);
        $worker->handleEnvelope(self::fakeEnvelope());

        self::assertInstanceOf(ServerRequestInterface::class, $captured);
        self::assertSame('POST', $captured->getMethod());
        self::assertSame(['page' => '2'], $captured->getQueryParams());
        self::assertSame('body-bytes', (string) $captured->getBody());
        self::assertSame(['sid' => 'xyz'], $captured->getCookieParams());
    }

    public function testHandlerExceptionBecomes500(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('boom');
            }
        };

        $worker = new Worker($handler);
        [$status, $headers, $body] = $worker->handleEnvelope(self::fakeEnvelope());

        self::assertSame(500, $status);
        self::assertSame('Internal Server Error', $body);
        self::assertArrayHasKey('Content-Type', $headers);
    }

    public function testFlattenHeadersJoinsMultipleValuesWithCommaSpace(): void
    {
        $response = (new Response())
            ->withHeader('Set-Cookie', 'a=1')
            ->withAddedHeader('Set-Cookie', 'b=2')
            ->withHeader('Content-Type', 'text/html');

        $flat = Worker::flattenHeaders($response);

        self::assertSame('a=1, b=2', $flat['Set-Cookie']);
        self::assertSame('text/html', $flat['Content-Type']);
    }

    private static function handlerReturning(ResponseInterface $response): RequestHandlerInterface
    {
        return new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    /**
     * A stand-in for Ephpm\Worker\Envelope with the same accessor surface.
     */
    private static function fakeEnvelope(): object
    {
        return new class {
            public function serverVars(): array
            {
                return [
                    'REQUEST_METHOD' => 'POST',
                    'REQUEST_URI' => '/thing?page=2',
                    'SERVER_NAME' => 'localhost',
                    'HTTP_HOST' => 'localhost',
                ];
            }

            public function headers(): array
            {
                return ['Host' => 'localhost', 'Content-Type' => 'text/plain'];
            }

            public function cookies(): array
            {
                return ['sid' => 'xyz'];
            }

            public function query(): array
            {
                return ['page' => '2'];
            }

            public function parsedBody(): ?array
            {
                return null;
            }

            public function files(): array
            {
                return [];
            }

            public function rawBody(): string
            {
                return 'body-bytes';
            }

            public function bodyStream(): string
            {
                return 'body-bytes';
            }
        };
    }
}

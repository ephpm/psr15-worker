<?php

declare(strict_types=1);

namespace Ephpm\Psr15\Tests;

use Ephpm\Psr15\Worker;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * Exercises the request/response translation logic WITHOUT the native ePHPm
 * worker primitives: {@see Worker::handleEnvelope()} is driven with fake
 * Envelopes and stub PSR-15 handlers against the `Ephpm\Worker` shims installed
 * by tests/bootstrap.php (recorded by {@see WorkerSpy}).
 *
 * The fake Envelope matches the REAL engine contract: `parsedBody()` is always
 * null and `files()` is always empty (the engine never parses bodies), and
 * `query()`/`cookies()` values arrive raw (not url-decoded) — so these tests
 * exercise the worker's own parsing, exactly as production does.
 */
final class WorkerTest extends TestCase
{
    protected function setUp(): void
    {
        WorkerSpy::reset();
        Worker::cleanupRequestTempFiles();
    }

    protected function tearDown(): void
    {
        Worker::cleanupRequestTempFiles();
    }

    // -------------------- Response translation (buffered) --------------------

    public function testHandleEnvelopeSendsBufferedResponse(): void
    {
        $worker = new Worker(self::handlerReturning(
            (new Response())
                ->withStatus(201)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-Trace', 'abc')
                ->withBody((new Psr17Factory())->createStream('{"ok":true}'))
        ));

        $worker->handleEnvelope(self::fakeEnvelope());

        self::assertSame(1, WorkerSpy::$sends);
        self::assertSame('buffered', WorkerSpy::$mode);
        self::assertSame(201, WorkerSpy::$status);
        self::assertSame('application/json', WorkerSpy::$headers['Content-Type'] ?? null);
        self::assertSame('abc', WorkerSpy::$headers['X-Trace'] ?? null);
        self::assertSame('{"ok":true}', WorkerSpy::$body);
    }

    public function testHandlerExceptionBecomesExactlyOne500(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('boom');
            }
        };

        (new Worker($handler))->handleEnvelope(self::fakeEnvelope());

        self::assertSame(1, WorkerSpy::$sends);
        self::assertSame(500, WorkerSpy::$status);
        self::assertSame('Internal Server Error', WorkerSpy::$body);
        self::assertArrayHasKey('Content-Type', WorkerSpy::$headers ?? []);
    }

    // -------------------- Request mapping (Envelope -> PSR-7) ----------------

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

        (new Worker($handler))->handleEnvelope(self::fakeEnvelope());

        self::assertInstanceOf(ServerRequestInterface::class, $captured);
        self::assertSame('POST', $captured->getMethod());
        self::assertSame(['page' => '2'], $captured->getQueryParams());
        self::assertSame('body-bytes', (string) $captured->getBody());
        self::assertSame(['sid' => 'xyz'], $captured->getCookieParams());
        self::assertSame(1, WorkerSpy::$sends);
    }

    /**
     * The engine NEVER parses bodies (`parsedBody()` is always null), so a POST
     * form body must be parsed out of the raw body by the worker itself.
     */
    public function testBuildRequestParsesFormBodyForPost(): void
    {
        $request = (new Worker(self::handlerReturning(new Response())))->buildRequest(
            self::fakeEnvelope(
                server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/form'],
                headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
                rawBody: 'name=ada&nums%5B%5D=1&nums%5B%5D=2',
            ),
        );

        self::assertSame(
            ['name' => 'ada', 'nums' => ['1', '2']],
            $request->getParsedBody(),
        );
    }

    public function testBuildRequestParsesFormBodyForPut(): void
    {
        $request = (new Worker(self::handlerReturning(new Response())))->buildRequest(
            self::fakeEnvelope(
                server: ['REQUEST_METHOD' => 'PUT', 'REQUEST_URI' => '/x'],
                headers: ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'],
                rawBody: 'title=Hello&draft=1',
            ),
        );

        self::assertSame(['title' => 'Hello', 'draft' => '1'], $request->getParsedBody());
    }

    public function testBuildRequestDoesNotParseJsonBody(): void
    {
        $request = (new Worker(self::handlerReturning(new Response())))->buildRequest(
            self::fakeEnvelope(
                server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/x'],
                headers: ['Content-Type' => 'application/json'],
                rawBody: '{"title":"Hello"}',
            ),
        );

        // JSON is NOT form-decoded into the parsed body; the app decodes it.
        self::assertNull($request->getParsedBody());
        self::assertSame('{"title":"Hello"}', (string) $request->getBody());
    }

    /**
     * The engine's query() is split on & and = only — the worker must re-parse
     * QUERY_STRING with parse_str so values are url-decoded and `a[]=` bracket
     * syntax builds arrays.
     */
    public function testBuildRequestUrlDecodesQueryString(): void
    {
        $request = (new Worker(self::handlerReturning(new Response())))->buildRequest(
            self::fakeEnvelope(
                server: [
                    'REQUEST_METHOD' => 'GET',
                    'REQUEST_URI' => '/s',
                    'QUERY_STRING' => 'q=a%20b&tags%5B%5D=x&tags%5B%5D=y',
                ],
                query: ['q' => 'a%20b'], // raw, as the engine would hand it over
                rawBody: '',
            ),
        );

        self::assertSame(
            ['q' => 'a b', 'tags' => ['x', 'y']],
            $request->getQueryParams(),
        );
    }

    /**
     * The engine's cookies() names/values arrive raw — the worker url-decodes
     * both.
     */
    public function testBuildRequestUrlDecodesCookies(): void
    {
        $request = (new Worker(self::handlerReturning(new Response())))->buildRequest(
            self::fakeEnvelope(
                cookies: ['se%20ssion' => 'v%201', 'plain' => 'ok'],
            ),
        );

        self::assertSame(
            ['se ssion' => 'v 1', 'plain' => 'ok'],
            $request->getCookieParams(),
        );
    }

    /**
     * The engine's files() is always empty — multipart bodies must be parsed by
     * the worker: fields into the parsed body, uploads spooled to temp files
     * exposed as PSR-7 UploadedFile instances, and the temp files removed after
     * the request.
     */
    public function testHandleEnvelopeParsesMultipartAndCleansUpTempFiles(): void
    {
        $boundary = 'XxBOUNDxX';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"title\"\r\n"
            . "\r\n"
            . "Hello\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"doc\"; filename=\"a.txt\"\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . "file-bytes\r\n"
            . "--{$boundary}--\r\n";

        $seen = [];
        $handler = new class($seen) implements RequestHandlerInterface {
            /** @param array<string, mixed> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $files = $request->getUploadedFiles();
                $doc = $files['doc'] ?? null;
                $this->seen = [
                    'parsed' => $request->getParsedBody(),
                    'doc' => $doc,
                    'content' => $doc instanceof UploadedFileInterface
                        ? (string) $doc->getStream()
                        : null,
                    'tmp' => $doc instanceof UploadedFileInterface
                        ? $doc->getStream()->getMetadata('uri')
                        : null,
                ];

                return new Response(200, [], 'ok');
            }
        };

        (new Worker($handler))->handleEnvelope(self::fakeEnvelope(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/upload'],
            headers: ['Content-Type' => "multipart/form-data; boundary={$boundary}"],
            rawBody: $body,
        ));

        self::assertSame(['title' => 'Hello'], $seen['parsed']);
        self::assertInstanceOf(UploadedFileInterface::class, $seen['doc']);
        self::assertSame('a.txt', $seen['doc']->getClientFilename());
        self::assertSame('text/plain', $seen['doc']->getClientMediaType());
        self::assertSame('file-bytes', $seen['content']);

        // The spooled temp file was unlinked after the request completed.
        self::assertIsString($seen['tmp']);
        self::assertFileDoesNotExist($seen['tmp']);
        self::assertSame(1, WorkerSpy::$sends);
    }

    /**
     * Multiple files posted under one `name="photos[]"` field must NOT collapse
     * last-wins under the literal key `photos[]` — PHP builds the pivoted
     * `$_FILES['photos']['name'][0..1]` shape, which the request creator turns
     * into a LIST of UploadedFile instances. A sibling scalar `name="avatar"`
     * upload keeps its single-file shape.
     */
    public function testMultipartArrayOfFilesBecomesUploadedFileList(): void
    {
        $boundary = 'XxMULTIxX';
        $body = self::filePart($boundary, 'photos[]', 'a.jpg', 'image/jpeg', 'AAA')
            . self::filePart($boundary, 'photos[]', 'b.jpg', 'image/jpeg', 'BBBB')
            . self::filePart($boundary, 'avatar', 'me.png', 'image/png', 'PNG')
            . "--{$boundary}--\r\n";

        // First, the raw $_FILES shape the parser produces: pivoted arrays for the
        // bracketed field, scalar for the plain one.
        [, $files] = Worker::parseMultipart($body, $boundary);
        self::assertSame(['a.jpg', 'b.jpg'], $files['photos']['name']);
        self::assertSame(['image/jpeg', 'image/jpeg'], $files['photos']['type']);
        self::assertSame([3, 4], $files['photos']['size']);
        self::assertCount(2, $files['photos']['tmp_name']);
        self::assertSame('me.png', $files['avatar']['name']);
        self::assertIsString($files['avatar']['tmp_name']);

        // Then, end to end: the request creator turns the pivoted shape into a
        // list of UploadedFileInterface (both files survive — not last-wins).
        // Read filenames + contents INSIDE the handler — handleEnvelope() unlinks
        // the spooled temp files in its finally, so the streams are only readable
        // while the request is being handled.
        $seen = [];
        $handler = new class($seen) implements RequestHandlerInterface {
            /** @param array<string, mixed> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $files = $request->getUploadedFiles();
                $photos = $files['photos'] ?? null;
                $avatar = $files['avatar'] ?? null;
                $this->seen = [
                    'photos_is_list' => \is_array($photos),
                    'photos_count' => \is_array($photos) ? \count($photos) : 0,
                    'photos_0' => $photos[0] instanceof UploadedFileInterface,
                    'photos_1' => $photos[1] instanceof UploadedFileInterface,
                    'name_0' => $photos[0]->getClientFilename() ?? null,
                    'name_1' => $photos[1]->getClientFilename() ?? null,
                    'body_0' => (string) $photos[0]->getStream(),
                    'body_1' => (string) $photos[1]->getStream(),
                    'avatar_is_file' => $avatar instanceof UploadedFileInterface,
                    'avatar_name' => $avatar instanceof UploadedFileInterface
                        ? $avatar->getClientFilename()
                        : null,
                ];

                return new Response(200, [], 'ok');
            }
        };

        (new Worker($handler))->handleEnvelope(self::fakeEnvelope(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/upload'],
            headers: ['Content-Type' => "multipart/form-data; boundary={$boundary}"],
            rawBody: $body,
        ));

        self::assertTrue($seen['photos_is_list']);
        self::assertSame(2, $seen['photos_count']);
        self::assertTrue($seen['photos_0']);
        self::assertTrue($seen['photos_1']);
        self::assertSame('a.jpg', $seen['name_0']);
        self::assertSame('b.jpg', $seen['name_1']);
        self::assertSame('AAA', $seen['body_0']);
        self::assertSame('BBBB', $seen['body_1']);
        self::assertTrue($seen['avatar_is_file']);
        self::assertSame('me.png', $seen['avatar_name']);
        self::assertSame(1, WorkerSpy::$sends);
    }

    /**
     * One `multipart/form-data` file part (no trailing boundary — the caller
     * appends the closing delimiter).
     */
    private static function filePart(
        string $boundary,
        string $name,
        string $filename,
        string $type,
        string $content,
    ): string {
        return "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n"
            . "Content-Type: {$type}\r\n"
            . "\r\n"
            . "{$content}\r\n";
    }

    // -------------------- Header flattening ----------------------------------

    public function testFlattenHeadersJoinsOrdinaryMultiValueHeaders(): void
    {
        $response = (new Response())
            ->withHeader('X-Multi', 'a')
            ->withAddedHeader('X-Multi', 'b')
            ->withHeader('Content-Type', 'text/html');

        $flat = Worker::flattenHeaders($response);

        self::assertSame('a, b', $flat['X-Multi']);
        self::assertSame('text/html', $flat['Content-Type']);
    }

    /**
     * Set-Cookie must NOT be comma-joined (cookie `expires=` contains commas):
     * the engine's array-value contract sends one wire header per list element.
     */
    public function testFlattenHeadersEmitsSetCookieAsList(): void
    {
        $response = (new Response())
            ->withHeader('Set-Cookie', 'a=1; Path=/')
            ->withAddedHeader('Set-Cookie', 'b=2; expires=Thu, 01 Jan 2027 00:00:00 GMT');

        $flat = Worker::flattenHeaders($response);

        self::assertIsArray($flat['Set-Cookie']);
        self::assertCount(2, $flat['Set-Cookie']);
        self::assertSame('a=1; Path=/', $flat['Set-Cookie'][0]);
        // The expires attribute keeps its (comma-containing) form intact.
        self::assertStringContainsString('expires=Thu, 01', $flat['Set-Cookie'][1]);
    }

    public function testHandleEnvelopeCarriesSetCookieListToTheWire(): void
    {
        $worker = new Worker(self::handlerReturning(
            (new Response(200, [], 'ok'))
                ->withHeader('Set-Cookie', 'a=1')
                ->withAddedHeader('Set-Cookie', 'b=2'),
        ));

        $worker->handleEnvelope(self::fakeEnvelope());

        self::assertSame(1, WorkerSpy::$sends);
        $sent = WorkerSpy::$headers['Set-Cookie'] ?? null;
        self::assertIsArray($sent);
        self::assertSame(['a=1', 'b=2'], $sent);
    }

    // -------------------- Sending (buffered vs streamed dispatch) ------------

    public function testSmallBodyStaysBuffered(): void
    {
        (new Worker(self::handlerReturning(new Response(200, [], 'small'))))
            ->handleEnvelope(self::fakeEnvelope());

        self::assertSame(1, WorkerSpy::$sends);
        self::assertSame('buffered', WorkerSpy::$mode);
        self::assertSame('small', WorkerSpy::$body);
    }

    public function testLargeBodyIsStreamedViaSendResponseStream(): void
    {
        $payload = \str_repeat('A', Worker::STREAM_THRESHOLD + 1);
        $body = (new Psr17Factory())->createStream($payload);

        (new Worker(self::handlerReturning(
            (new Response())->withStatus(200)->withBody($body),
        )))->handleEnvelope(self::fakeEnvelope());

        self::assertSame(1, WorkerSpy::$sends);
        self::assertSame('stream', WorkerSpy::$mode);
        self::assertSame(200, WorkerSpy::$status);
        // The whole payload arrives even though the handler left the write
        // pointer at the end of the stream (respond() rewinds it).
        self::assertSame(\strlen($payload), \strlen((string) WorkerSpy::$streamBody));
        self::assertSame($payload, WorkerSpy::$streamBody);
    }

    public function testUnknownSizeBodyIsStreamed(): void
    {
        // A body that cannot report its size (pipe-like) must be streamed even
        // though its actual content is small.
        $resource = \fopen('php://temp', 'r+b');
        self::assertIsResource($resource);
        \fwrite($resource, 'streamed-content');

        $stream = (new Psr17Factory())->createStreamFromResource($resource);
        $response = (new Response())->withBody(new UnknownSizeStream($stream));

        (new Worker(self::handlerReturning($response)))->handleEnvelope(self::fakeEnvelope());

        self::assertSame(1, WorkerSpy::$sends);
        self::assertSame('stream', WorkerSpy::$mode);
        self::assertSame('streamed-content', WorkerSpy::$streamBody);
    }

    // -------------------- Fixtures ------------------------------------------

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
     * A stand-in for Ephpm\Worker\Envelope matching the REAL engine contract:
     * `parsedBody()` is ALWAYS null and `files()` is ALWAYS empty (the engine
     * never parses bodies), and query/cookie values are raw (not url-decoded).
     *
     * @param array<string, mixed>  $server
     * @param array<string, string> $headers
     * @param array<string, mixed>  $cookies
     * @param array<string, mixed>  $query
     */
    private static function fakeEnvelope(
        array $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/thing?page=2',
            'SERVER_NAME' => 'localhost',
            'HTTP_HOST' => 'localhost',
        ],
        array $headers = ['Host' => 'localhost', 'Content-Type' => 'text/plain'],
        array $cookies = ['sid' => 'xyz'],
        array $query = ['page' => '2'],
        string $rawBody = 'body-bytes',
    ): object {
        return new class($server, $headers, $cookies, $query, $rawBody) {
            public function __construct(
                private readonly array $server,
                private readonly array $headers,
                private readonly array $cookies,
                private readonly array $query,
                private readonly string $rawBody,
            ) {
            }

            public function serverVars(): array
            {
                return $this->server;
            }

            public function headers(): array
            {
                return $this->headers;
            }

            public function cookies(): array
            {
                return $this->cookies;
            }

            public function query(): array
            {
                return $this->query;
            }

            /** The real engine NEVER parses bodies. */
            public function parsedBody(): ?array
            {
                return null;
            }

            /** The real engine NEVER populates files. */
            public function files(): array
            {
                return [];
            }

            public function rawBody(): string
            {
                return $this->rawBody;
            }

            /** @return resource */
            public function bodyStream()
            {
                $stream = \fopen('php://temp', 'r+b');
                \assert(\is_resource($stream));
                \fwrite($stream, $this->rawBody);
                \rewind($stream);

                return $stream;
            }
        };
    }
}

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
 * The engine deliberately does NOT parse request bodies: `Envelope::parsedBody()`
 * is always null and `Envelope::files()` is always empty. This worker therefore
 * parses `application/x-www-form-urlencoded` and `multipart/form-data` bodies
 * itself (for POST/PUT/PATCH/DELETE), spooling uploaded files to temp files that
 * are unlinked after each request via {@see cleanupRequestTempFiles()}. Likewise
 * the engine's `query()`/`cookies()` arrays are raw (not url-decoded), so the
 * query string is re-parsed with `parse_str()` and cookie names/values are
 * url-decoded here.
 *
 * Responses: small buffered bodies go through `send_response()`; bodies whose
 * size is unknown or larger than {@see STREAM_THRESHOLD} are detached to their
 * underlying resource and go through `send_response_stream()`, which forwards
 * the stream to the client in 64 KiB chunks with backpressure — large downloads
 * keep memory flat. The request body is still buffered as a string
 * (`Envelope::rawBody()`).
 *
 * Multiple `Set-Cookie` response headers are sent as a list array
 * (`'Set-Cookie' => [$c1, $c2]`), which the engine emits as one wire header per
 * element — comma-joining Set-Cookie would corrupt cookies whose `expires=`
 * attribute contains a comma. Other repeated headers are comma-joined per
 * RFC 9110.
 */
final class Worker
{
    /**
     * Response bodies at or below this size (bytes) are sent buffered via
     * `send_response()`; larger or unknown-size bodies are streamed via
     * `send_response_stream()`.
     */
    public const STREAM_THRESHOLD = 1_048_576; // 1 MiB

    private readonly ServerRequestCreator $requestCreator;

    /**
     * Whether a response has been sent for the request currently being handled.
     *
     * Reset at the top of {@see handleEnvelope()}, set by {@see respond()}
     * immediately before it calls a native send primitive. The error path
     * consults it so the fallback 500 never double-sends after a throwable that
     * escapes AFTER a response already went out (double-send = engine protocol
     * desync).
     */
    private bool $responded = false;

    /**
     * Temp files created for the current request's multipart uploads, unlinked
     * by {@see cleanupRequestTempFiles()} after the request completes.
     *
     * Static because the multipart parser runs in static seams; a worker
     * process handles exactly one request at a time, so there is no overlap.
     *
     * @var list<string>
     */
    private static array $requestTempFiles = [];

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
            $this->handleEnvelope($envelope);
        }

        return 0;
    }

    /**
     * Handle one request Envelope end-to-end: build the PSR-7 request, run the
     * handler, and send the response through the appropriate native primitive
     * (exactly once).
     *
     * A throwable from the handler (or from request/response translation)
     * becomes a generic 500 — unless a response already went out, in which case
     * it is rethrown so the engine can recycle the worker rather than risking a
     * double-send.
     *
     * Public so tests can drive it with a fake envelope against shimmed
     * `Ephpm\Worker` send primitives.
     *
     * @param object $envelope an Ephpm\Worker\Envelope (or a compatible fake)
     */
    public function handleEnvelope(object $envelope): void
    {
        $this->responded = false;

        try {
            try {
                $request = $this->buildRequest($envelope);
                $response = $this->handler->handle($request);
                $this->respond($response);
            } catch (Throwable $e) {
                if ($this->responded) {
                    // A send was already attempted; a fallback 500 would
                    // double-send and desync the engine protocol.
                    throw $e;
                }

                [$status, $headers, $body] = self::errorResponse($e);
                $this->responded = true;
                \Ephpm\Worker\send_response($status, $headers, $body);
            }
        } finally {
            self::cleanupRequestTempFiles();
        }
    }

    /**
     * Send a PSR-7 response through the appropriate ePHPm primitive.
     *
     * Bodies with a known size at or below {@see STREAM_THRESHOLD} are sent
     * buffered via `send_response()`. Bodies that are larger — or whose size is
     * unknown — are detached to their underlying resource and streamed via
     * `send_response_stream()` (64 KiB chunks with backpressure, flat memory).
     * A body that reports no underlying resource on `detach()` (exotic PSR-7
     * implementations) falls back to a buffered send.
     */
    public function respond(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        $headers = self::flattenHeaders($response);
        $body = $response->getBody();
        $size = $body->getSize();

        if ($size !== null && $size <= self::STREAM_THRESHOLD) {
            // Mark as responded up front: once we attempt a send, the fallback
            // 500 in handleEnvelope() must never fire (double-send risk).
            $this->responded = true;
            \Ephpm\Worker\send_response($status, $headers, (string) $body);

            return;
        }

        // Large or unknown-size body: hand the underlying resource straight to
        // the engine so the download streams with flat memory.
        $stream = $body->detach();

        if (!\is_resource($stream)) {
            // Exotic PSR-7 body with no underlying resource. detach() has
            // already rendered $body unusable per PSR-7, but implementations
            // without a resource typically keep their content readable; fall
            // back to a buffered send with whatever it still yields.
            try {
                $content = (string) $body;
            } catch (Throwable) {
                $content = '';
            }

            $this->responded = true;
            \Ephpm\Worker\send_response($status, $headers, $content);

            return;
        }

        // PSR-15 handlers usually leave the write pointer at the end of the
        // body; without a rewind the engine would stream zero bytes.
        if ((\stream_get_meta_data($stream)['seekable'] ?? false) === true) {
            \rewind($stream);
        }

        try {
            $this->responded = true;
            \Ephpm\Worker\send_response_stream($status, $headers, $stream);
        } finally {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }
    }

    /**
     * Build a PSR-7 server request from an Envelope.
     *
     * The engine hands us raw material only: `query()`/`cookies()` are NOT
     * url-decoded, `parsedBody()` is always null, and `files()` is always
     * empty. So this method:
     *
     *   - re-parses the query string with `parse_str()` (url-decodes and
     *     handles `a[]=` bracket syntax),
     *   - url-decodes cookie names and values,
     *   - parses `application/x-www-form-urlencoded` bodies for
     *     POST/PUT/PATCH/DELETE into the parsed body, and
     *   - parses `multipart/form-data` bodies into fields plus `$_FILES`-shaped
     *     uploads spooled to temp files (the request creator turns those into
     *     PSR-7 UploadedFile instances).
     *
     * @param object $envelope an Ephpm\Worker\Envelope (or a compatible fake)
     */
    public function buildRequest(object $envelope): ServerRequestInterface
    {
        /**
         * @var array<string, mixed>  $server
         * @var array<string, string> $headers
         * @var string                $rawBody
         */
        $headers = $envelope->headers();
        $server = self::normalizeServer($envelope->serverVars(), $headers);
        $rawBody = $envelope->rawBody();

        // The engine's query() is split on & and = only — never url-decoded.
        // Re-parse the query string ourselves: parse_str url-decodes and
        // handles bracket (a[]=) syntax exactly like PHP would for $_GET.
        \parse_str(self::queryString($server), $query);

        // Cookie names and values arrive raw from the engine.
        $cookies = [];
        foreach ($envelope->cookies() as $name => $value) {
            $cookies[\urldecode((string) $name)] = \urldecode((string) $value);
        }

        [$post, $files] = self::parseBody(
            \strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')),
            isset($server['CONTENT_TYPE']) ? (string) $server['CONTENT_TYPE'] : null,
            $rawBody,
        );

        return $this->requestCreator->fromArrays(
            $server,
            $headers,
            $cookies,
            $query,
            $post === [] ? null : $post,
            $files,
            $rawBody,
        );
    }

    /**
     * Flatten PSR-7 headers (`['Name' => ['v1', 'v2']]`) into the shape
     * `send_response()` / `send_response_stream()` expect.
     *
     * Ordinary multi-value headers are comma-joined per RFC 9110. `Set-Cookie`
     * is the exception: cookie `expires=` attributes contain commas, so the
     * engine's array-value contract is used instead — a list of strings emits
     * one wire header per element.
     *
     * @return array<string, string|list<string>>
     */
    public static function flattenHeaders(ResponseInterface $response): array
    {
        $flat = [];
        foreach ($response->getHeaders() as $name => $values) {
            if (\strcasecmp((string) $name, 'Set-Cookie') === 0) {
                $flat[$name] = \array_values($values);

                continue;
            }

            $flat[$name] = \implode(', ', $values);
        }

        return $flat;
    }

    /**
     * Parse a request body into `[$post, $files]`.
     *
     * The engine never parses bodies (`parsedBody()` is always null), so both
     * form content types are handled here for every body-carrying method — PHP
     * itself would only populate `$_POST` for POST:
     *
     *   - `application/x-www-form-urlencoded` → `parse_str()` into `$post`.
     *   - `multipart/form-data` → fields into `$post`, uploads into a
     *     `$_FILES`-shaped array (each file spooled to a temp file registered
     *     for {@see cleanupRequestTempFiles()}).
     *
     * Anything else (JSON, raw) leaves both empty — the app decodes the raw body.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function parseBody(string $method, ?string $contentType, string $rawBody): array
    {
        if (
            $rawBody === ''
            || $contentType === null
            || !\in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
        ) {
            return [[], []];
        }

        $ct = \strtolower($contentType);

        if (\str_starts_with($ct, 'application/x-www-form-urlencoded')) {
            \parse_str($rawBody, $post);

            return [$post, []];
        }

        if (\str_starts_with($ct, 'multipart/form-data')) {
            // Boundary extraction must use the original header — the token is
            // case-sensitive.
            $boundary = self::multipartBoundary($contentType);
            if ($boundary === null) {
                return [[], []];
            }

            return self::parseMultipart($rawBody, $boundary);
        }

        return [[], []];
    }

    /**
     * Extract the boundary token from a multipart Content-Type header.
     */
    public static function multipartBoundary(string $contentType): ?string
    {
        if (\preg_match('/boundary=(?:"([^"]+)"|([^;,\s]+))/i', $contentType, $m) !== 1) {
            return null;
        }

        $boundary = $m[1] !== '' ? $m[1] : ($m[2] ?? '');

        return $boundary !== '' ? $boundary : null;
    }

    /**
     * Parse a `multipart/form-data` body into `[$post, $files]`.
     *
     * A pragmatic parser (shared with the sibling ePHPm adapters): it covers
     * simple and nested (`name[]`, `name[key]`) field names and file uploads.
     * It does NOT try to be a byte-perfect clone of PHP's C rfc1867 parser.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function parseMultipart(string $body, string $boundary): array
    {
        $post = [];
        $files = [];

        $delimiter = '--' . $boundary;
        // Split on the delimiter; drop the preamble and the closing "--" epilogue.
        $parts = \explode($delimiter, $body);
        foreach ($parts as $part) {
            $part = \ltrim($part, "\r\n");
            if ($part === '' || \str_starts_with($part, '--')) {
                continue;
            }

            $split = \preg_split("/\r\n\r\n/", $part, 2);
            if ($split === false || \count($split) < 2) {
                continue;
            }
            [$rawHeaders, $value] = $split;
            // Strip the trailing CRLF that precedes the next delimiter.
            $value = \preg_replace('/\r\n$/', '', $value) ?? $value;

            $headers = self::parsePartHeaders($rawHeaders);
            $disposition = $headers['content-disposition'] ?? '';

            if (\preg_match('/name="([^"]*)"/', $disposition, $nm) !== 1) {
                continue;
            }
            $name = $nm[1];

            if (\preg_match('/filename="([^"]*)"/', $disposition, $fm) === 1) {
                self::assignFile(
                    $files,
                    $name,
                    $fm[1],
                    $headers['content-type'] ?? 'application/octet-stream',
                    $value,
                );

                continue;
            }

            self::assignField($post, $name, $value);
        }

        // Drop the internal bracket-accumulation scratch key.
        unset($post['__ephpm_bracket_buf__']);

        return [$post, $files];
    }

    /**
     * Unlink the temp files created for the current request's uploads.
     *
     * Idempotent; files already moved/removed by the application are skipped.
     */
    public static function cleanupRequestTempFiles(): void
    {
        foreach (self::$requestTempFiles as $path) {
            if ($path !== '' && \is_file($path)) {
                @\unlink($path);
            }
        }
        self::$requestTempFiles = [];
    }

    // ---------------------------------------------------------------------
    // Private helpers.
    // ---------------------------------------------------------------------

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

    /**
     * The raw query string for a request: `QUERY_STRING` when present,
     * otherwise the part of `REQUEST_URI` after `?`.
     *
     * @param array<string, mixed> $server
     */
    private static function queryString(array $server): string
    {
        $qs = isset($server['QUERY_STRING']) ? (string) $server['QUERY_STRING'] : '';
        if ($qs !== '') {
            return $qs;
        }

        $uri = isset($server['REQUEST_URI']) ? (string) $server['REQUEST_URI'] : '';
        $pos = \strpos($uri, '?');

        return $pos === false ? '' : \substr($uri, $pos + 1);
    }

    /**
     * Assign a scalar form field into `$post`, honouring `name[]` / `name[key]`
     * bracket syntax.
     *
     * PHP's rfc1867/urlencoded parser treats each `name[]` occurrence as an
     * append (successive values get indices 0, 1, 2, …). We reproduce that by
     * collecting all bracketed fields for the whole part list into a single
     * urlencoded query string and letting `parse_str` build the nested array in
     * one pass.
     *
     * @param array<string, mixed> $post
     */
    private static function assignField(array &$post, string $name, string $value): void
    {
        if (!\str_contains($name, '[')) {
            $post[$name] = $value;

            return;
        }

        // Accumulate bracketed pairs in a hidden query buffer, then re-parse the
        // whole buffer so parse_str applies real append/merge semantics.
        $buffer = $post['__ephpm_bracket_buf__'] ?? '';
        if ($buffer !== '') {
            $buffer .= '&';
        }
        $buffer .= self::encodeBracketName($name) . '=' . \rawurlencode($value);
        $post['__ephpm_bracket_buf__'] = $buffer;

        $parsed = [];
        \parse_str($buffer, $parsed);
        foreach ($parsed as $k => $v) {
            $post[$k] = $v;
        }
    }

    /**
     * URL-encode a bracketed field name so parse_str keeps the brackets but
     * encodes the key/segments.
     */
    private static function encodeBracketName(string $name): string
    {
        return \preg_replace_callback(
            '/[^\[\]]+/',
            static fn (array $m): string => \rawurlencode($m[0]),
            $name,
        ) ?? $name;
    }

    /**
     * Spool one uploaded file to a temp path, register the path for post-request
     * cleanup, and record it in `$files` in `$_FILES` shape (which the request
     * creator normalizes into PSR-7 UploadedFile instances).
     *
     * @param array<string, mixed> $files
     */
    private static function assignFile(
        array &$files,
        string $name,
        string $filename,
        string $type,
        string $content,
    ): void {
        $tmp = \tempnam(\sys_get_temp_dir(), 'ephpm-p15');
        $error = \UPLOAD_ERR_OK;
        if ($tmp === false) {
            $tmp = '';
            $error = \UPLOAD_ERR_CANT_WRITE;
        } elseif (\file_put_contents($tmp, $content) === false) {
            $error = \UPLOAD_ERR_CANT_WRITE;
        }

        if ($tmp !== '') {
            self::$requestTempFiles[] = $tmp;
        }

        $files[$name] = [
            'name' => $filename,
            'type' => $type,
            'tmp_name' => $tmp,
            'error' => $error,
            'size' => \strlen($content),
        ];
    }

    /**
     * Parse the header block of one multipart part into a lowercased map.
     *
     * @return array<string, string>
     */
    private static function parsePartHeaders(string $rawHeaders): array
    {
        $headers = [];
        foreach (\preg_split("/\r\n/", $rawHeaders) ?: [] as $line) {
            $pos = \strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $key = \strtolower(\trim(\substr($line, 0, $pos)));
            $headers[$key] = \trim(\substr($line, $pos + 1));
        }

        return $headers;
    }

    /**
     * Ensure the server bag carries the request headers as `HTTP_*` entries
     * (some engines only put a subset of headers in the server array), and that
     * `CONTENT_TYPE`/`CONTENT_LENGTH` live un-prefixed so body parsing can see
     * them. Duplicate request headers arrive from the engine pre-joined with
     * ", " (Cookie with "; ").
     *
     * @param array<string, mixed>  $server
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private static function normalizeServer(array $server, array $headers): array
    {
        foreach ($headers as $name => $value) {
            $key = 'HTTP_' . \strtoupper(\str_replace('-', '_', (string) $name));
            // Content-Type / Content-Length live un-prefixed in the server bag.
            if ($key === 'HTTP_CONTENT_TYPE') {
                $server['CONTENT_TYPE'] ??= $value;
            } elseif ($key === 'HTTP_CONTENT_LENGTH') {
                $server['CONTENT_LENGTH'] ??= $value;
            }
            $server[$key] ??= $value;
        }

        return $server;
    }
}

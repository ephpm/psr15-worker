<?php

declare(strict_types=1);

namespace Ephpm\Psr15\Tests;

/**
 * Records calls made to the shimmed `Ephpm\Worker` send primitives (installed
 * by tests/bootstrap.php) so tests can assert on exactly what would have been
 * handed to the engine — including the exactly-once invariant via {@see $sends}.
 */
final class WorkerSpy
{
    /** Total send_response + send_response_stream calls since reset(). */
    public static int $sends = 0;

    /** 'none', 'buffered' (send_response) or 'stream' (send_response_stream). */
    public static string $mode = 'none';

    public static ?int $status = null;

    /** @var array<string, string|list<string>>|null */
    public static ?array $headers = null;

    /** Body of the last buffered send. */
    public static ?string $body = null;

    /** Fully drained contents of the last streamed body resource. */
    public static ?string $streamBody = null;

    public static function reset(): void
    {
        self::$sends = 0;
        self::$mode = 'none';
        self::$status = null;
        self::$headers = null;
        self::$body = null;
        self::$streamBody = null;
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public static function recordSend(int $status, array $headers, string $body): void
    {
        ++self::$sends;
        self::$mode = 'buffered';
        self::$status = $status;
        self::$headers = $headers;
        self::$body = $body;
        self::$streamBody = null;
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @param resource                           $body
     */
    public static function recordStream(int $status, array $headers, $body): void
    {
        ++self::$sends;
        self::$mode = 'stream';
        self::$status = $status;
        self::$headers = $headers;
        self::$body = null;
        self::$streamBody = \is_resource($body) ? (string) \stream_get_contents($body) : null;
    }
}

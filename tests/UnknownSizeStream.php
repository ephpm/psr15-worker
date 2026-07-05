<?php

declare(strict_types=1);

namespace Ephpm\Psr15\Tests;

use Psr\Http\Message\StreamInterface;

/**
 * StreamInterface decorator that hides the underlying stream's size
 * (`getSize()` returns null), emulating pipe-like bodies. Used to assert that
 * the Worker streams unknown-size responses via send_response_stream().
 */
final class UnknownSizeStream implements StreamInterface
{
    public function __construct(private readonly StreamInterface $inner)
    {
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function __toString(): string
    {
        return $this->inner->__toString();
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->inner->rewind();
    }

    public function isWritable(): bool
    {
        return $this->inner->isWritable();
    }

    public function write(string $string): int
    {
        return $this->inner->write($string);
    }

    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    public function read(int $length): string
    {
        return $this->inner->read($length);
    }

    public function getContents(): string
    {
        return $this->inner->getContents();
    }

    public function getMetadata(?string $key = null)
    {
        return $this->inner->getMetadata($key);
    }
}

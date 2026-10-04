<?php

declare(strict_types=1);

namespace Rewloy\Http;

use Closure;

/**
 * A streaming answer: the status and headers, then the body as it arrives.
 */
final class HttpStream
{
    private bool $closed = false;

    /**
     * @param array<string, list<string>> $headers Names in lower case, every value of each.
     * @param iterable<string> $body The body in pieces of any size, as they arrive. It
     *                               throws {@see TransportException} when the connection
     *                               breaks or stays silent too long.
     * @param (Closure(): void)|null $close Ends the connection.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly iterable $body,
        private readonly ?Closure $close = null,
        public readonly string $reason = '',
    ) {
    }

    /** A header's values joined with ", ", or null when it is absent. */
    public function header(string $name): ?string
    {
        return Headers::line($this->headers, $name);
    }

    /**
     * The rest of the body as one string (an error answer), then closes.
     *
     * @throws TransportException
     */
    public function readAll(): string
    {
        $text = '';
        try {
            foreach ($this->body as $chunk) {
                $text .= $chunk;
            }
        } finally {
            $this->close();
        }
        return $text;
    }

    /** Ends the connection; calling it again does nothing. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if ($this->close !== null) {
            ($this->close)();
        }
    }
}

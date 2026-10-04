<?php

declare(strict_types=1);

namespace Rewloy\Http;

/**
 * A request as the client hands it to a {@see Transport}.
 */
final readonly class HttpRequest
{
    /**
     * @param array<string, string> $headers Names in lower case.
     * @param float $timeout Seconds for the whole exchange (for a stream: until
     *                       its headers have arrived); 0 for none.
     * @param float $idleTimeout Streams only: seconds the body may stay silent
     *                           before the connection counts as dead; 0 for none.
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public ?string $body,
        public float $timeout,
        public float $idleTimeout = 0.0,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}

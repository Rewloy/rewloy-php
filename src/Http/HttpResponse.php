<?php

declare(strict_types=1);

namespace Rewloy\Http;

/**
 * An answer with its whole body, as a {@see Transport} returns it.
 */
final readonly class HttpResponse
{
    /**
     * @param array<string, list<string>> $headers Names in lower case, every value of each.
     * @param string $reason The status line's reason phrase ("Bad Gateway"), when known.
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public string $reason = '',
    ) {
    }

    /** A header's values joined with ", ", or null when it is absent. */
    public function header(string $name): ?string
    {
        return Headers::line($this->headers, $name);
    }
}

<?php

declare(strict_types=1);

namespace Rewloy;

/**
 * The request budget the API reports on every answer to an authenticated call:
 * the `RateLimit-Limit`, `RateLimit-Remaining` and `RateLimit-Reset` headers.
 * `Response::rateLimit()` and `RewloyException::rateLimit()` return it.
 */
final readonly class RateLimit
{
    /**
     * @param int $limit `RateLimit-Limit`: requests allowed per minute.
     * @param int $remaining `RateLimit-Remaining`: requests left in this minute.
     * @param int $reset `RateLimit-Reset`: seconds until the limit renews.
     */
    public function __construct(
        public int $limit,
        public int $remaining,
        public int $reset,
    ) {
    }

    /**
     * Reads the three headers; null unless all of them are whole numbers.
     *
     * @param array<string, list<string>> $headers Names in lower case, as `Response::$headers`.
     */
    public static function fromHeaders(array $headers): ?self
    {
        $numbers = [];
        foreach (['ratelimit-limit', 'ratelimit-remaining', 'ratelimit-reset'] as $name) {
            $values = $headers[$name] ?? [];
            $value = $values === [] ? '' : trim($values[0]);
            if ($value === '' || !ctype_digit($value)) {
                return null;
            }
            $numbers[] = (int) $value;
        }
        return new self($numbers[0], $numbers[1], $numbers[2]);
    }
}

<?php

declare(strict_types=1);

namespace Rewloy\Http;

/**
 * @internal Header helpers shared by the HTTP classes.
 */
final class Headers
{
    private function __construct()
    {
    }

    /**
     * A header's values joined with ", " (as HTTP allows), or null when absent.
     *
     * @param array<string, list<string>> $headers
     */
    public static function line(array $headers, string $name): ?string
    {
        $values = $headers[strtolower($name)] ?? null;
        return $values === null || $values === [] ? null : implode(', ', $values);
    }
}

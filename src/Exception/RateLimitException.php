<?php

declare(strict_types=1);

namespace Rewloy\Exception;

use Throwable;

/**
 * 429 `RATE_LIMITED`: too many requests for this credential or this action.
 */
final class RateLimitException extends RewloyException
{
    /**
     * @param int|null $retryAfter Seconds to wait before trying again (`Retry-After`, else
     *                             `details.retryAfterSec`), when the API said.
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public readonly ?int $retryAfter,
        string $detail,
        ?string $title = null,
        mixed $details = null,
        ?string $docs = null,
        ?string $requestId = null,
        mixed $body = null,
        array $headers = [],
        ?string $operation = null,
        ?Throwable $previous = null,
        string $errorCode = 'RATE_LIMITED',
    ) {
        parent::__construct(429, $errorCode, $detail, $title, $details, $docs, $requestId, $body, $headers, $operation, $previous);
    }
}

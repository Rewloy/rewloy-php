<?php

declare(strict_types=1);

namespace Rewloy\Exception;

use Throwable;

/**
 * No answer arrived: the connection failed or broke (`CONNECTION_ERROR`,
 * status 0).
 */
class ConnectionException extends RewloyException
{
    public function __construct(
        string $detail,
        ?string $operation = null,
        ?string $requestId = null,
        ?Throwable $previous = null,
        string $errorCode = 'CONNECTION_ERROR',
    ) {
        parent::__construct(
            status: 0,
            errorCode: $errorCode,
            detail: $detail,
            requestId: $requestId,
            operation: $operation,
            previous: $previous,
        );
    }
}

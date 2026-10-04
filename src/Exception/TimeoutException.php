<?php

declare(strict_types=1);

namespace Rewloy\Exception;

use Throwable;

/**
 * No answer within `timeout` (`TIMEOUT`, status 0), or a stream fell silent
 * for `idleTimeout`.
 */
final class TimeoutException extends ConnectionException
{
    public function __construct(string $detail, ?string $operation = null, ?string $requestId = null, ?Throwable $previous = null)
    {
        parent::__construct($detail, $operation, $requestId, $previous, 'TIMEOUT');
    }
}

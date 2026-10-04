<?php

declare(strict_types=1);

namespace Rewloy\Http;

use RuntimeException;
use Throwable;

/**
 * No answer arrived: the connection failed, broke or timed out. A
 * {@see Transport} throws it; the client turns it into a
 * `ConnectionException` or `TimeoutException` naming the operation.
 */
final class TransportException extends RuntimeException
{
    /**
     * @param bool $timeout Whether a time limit ran out (`timeout`, `idleTimeout`).
     */
    public function __construct(string $message, public readonly bool $timeout = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

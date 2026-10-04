<?php

declare(strict_types=1);

namespace Rewloy\Exception;

use RuntimeException;

/**
 * A webhook delivery is not a genuine one: answer it with 400 and do not act
 * on it. `reason` says why.
 */
final class WebhookSignatureException extends RuntimeException
{
    /** No `Rewloy-Signature` header. */
    public const MISSING = 'missing';
    /** The header is not `t=<unix seconds>,v1=<hex>`. */
    public const MALFORMED = 'malformed';
    /** `t` is further from now than the tolerance. */
    public const EXPIRED = 'expired';
    /** No `v1` matches the body and the secret. */
    public const MISMATCH = 'mismatch';
    /** The signed body is not a JSON object. */
    public const PAYLOAD = 'payload';

    /**
     * @param self::MISSING|self::MALFORMED|self::EXPIRED|self::MISMATCH|self::PAYLOAD $reason
     */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

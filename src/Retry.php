<?php

declare(strict_types=1);

namespace Rewloy;

use DateTimeImmutable;
use DateTimeZone;

/**
 * @internal The client's retry rules, apart so that tests can pin them.
 *
 * Retried, when the request is safe to repeat (GET, HEAD, PUT, DELETE, or a
 * POST with an `Idempotency-Key`): network errors and timeouts, 429, 502–504,
 * Cloudflare's 520–524, and `409 IDEMPOTENCY_IN_PROGRESS`.
 */
final class Retry
{
    /** The first wait's ceiling, in seconds; it doubles with each attempt. */
    public const BASE = 0.5;
    /** The longest backoff, in seconds. */
    public const MAX = 8.0;
    /** A `Retry-After` longer than this is not waited for: the error goes to the caller. */
    public const MAX_RETRY_AFTER = 60.0;
    /** 502–504 and Cloudflare's 520–524 (the origin unreachable or too slow). */
    private const GATEWAY = [502, 503, 504, 520, 521, 522, 523, 524];

    private function __construct()
    {
    }

    /**
     * The wait before the retry after attempt `$attempt` (0-based), in seconds:
     * 0.5, 1, 2… up to 8, each with jitter between half and all of it.
     *
     * @param float|null $random In [0, 1]; random when null.
     */
    public static function backoff(int $attempt, ?float $random = null): float
    {
        $cap = min(self::MAX, self::BASE * 2 ** min(max(0, $attempt), 16));
        $random ??= random_int(0, 1_000_000) / 1_000_000;
        return round($cap / 2 + $random * $cap / 2, 3);
    }

    /**
     * `Retry-After` in seconds: delta-seconds or an HTTP date; null when absent
     * or unreadable.
     */
    public static function parseRetryAfter(?string $value, ?int $now = null): ?float
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d+(\.\d+)?$/', $v) === 1) {
            return (float) $v;
        }
        $at = DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $v, new DateTimeZone('UTC'));
        if ($at === false) {
            return null;
        }
        return (float) max(0, $at->getTimestamp() - ($now ?? time()));
    }

    /** An error answer that another attempt may get past. */
    public static function retryableStatus(int $status, string $errorCode): bool
    {
        return $status === 429
            || in_array($status, self::GATEWAY, true)
            || ($status === 409 && $errorCode === 'IDEMPOTENCY_IN_PROGRESS');
    }

    /** A status a stream's reconnection may get past (no answer at all counts too). */
    public static function transientStatus(int $status): bool
    {
        return $status === 0 || $status === 429 || in_array($status, self::GATEWAY, true);
    }
}

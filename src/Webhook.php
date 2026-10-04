<?php

declare(strict_types=1);

namespace Rewloy;

use InvalidArgumentException;
use JsonException;
use Rewloy\Exception\WebhookSignatureException;
use SensitiveParameter;

/**
 * Webhook signatures, exactly as the platform signs a delivery:
 *
 *     Rewloy-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>
 *
 * The key is the whole secret as shown once when the webhook was created
 * (`whsec_…`, prefix included); the message is the timestamp, a dot and the
 * body's bytes as they arrived. Each delivery attempt is signed anew, so a
 * retry carries a fresh `t`.
 *
 * Every delivery also carries `Rewloy-Event` (the event type, as `type` in the
 * body) and `Rewloy-Delivery` (the delivery's id: the same on every retry of
 * one delivery; deliveries are at least once, so skip an id already handled).
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'Rewloy-Signature';
    public const EVENT_HEADER = 'Rewloy-Event';
    public const DELIVERY_HEADER = 'Rewloy-Delivery';
    /** How far `t` may be from now, in seconds, either way. */
    public const TOLERANCE = 300;

    private const V1 = '/^[0-9a-f]{64}$/i';

    private function __construct()
    {
    }

    /**
     * Checks a delivery's `Rewloy-Signature` and returns its parsed body.
     *
     * Throws {@see WebhookSignatureException} when the header is missing or
     * malformed, `t` is further than `$tolerance` seconds from now, no `v1`
     * entry matches, or the signed body is not a JSON object. Every comparison
     * takes constant time.
     *
     * @param string $payload The body exactly as it arrived (`file_get_contents('php://input')`,
     *                        `$request->getContent()`), not a parsed or re-encoded one: that
     *                        changes the bytes the signature covers.
     * @param string|null $header The `Rewloy-Signature` header.
     * @param string|list<string> $secret The webhook's secret (`whsec_…`); several while you move
     *                                    from one webhook to another.
     * @param int $tolerance How far `t` may be from now, in seconds. Default 300.
     * @param int|null $now The current Unix time, for tests.
     * @return array{id?: string, type: string, created_at: string, data: array<string, mixed>}
     *         The event. Read the person behind `data.customer_id` from the API; new event
     *         types may appear, so keep a default branch.
     *
     * @throws WebhookSignatureException The delivery is not genuine: answer 400.
     * @throws InvalidArgumentException `$secret` is empty (a missing setting, not a bad delivery).
     */
    public static function verify(
        string $payload,
        ?string $header,
        #[SensitiveParameter] string|array $secret,
        int $tolerance = self::TOLERANCE,
        ?int $now = null,
    ): array
    {
        $secrets = array_values(array_filter(is_string($secret) ? [$secret] : $secret, static fn (string $s): bool => $s !== ''));
        if ($secrets === []) {
            throw new InvalidArgumentException('Webhook::verify: $secret is empty');
        }

        $value = trim((string) $header);
        if ($value === '') {
            throw new WebhookSignatureException(WebhookSignatureException::MISSING, 'No Rewloy-Signature header');
        }
        $t = null;
        $candidates = [];
        foreach (explode(',', $value) as $part) {
            $eq = strpos($part, '=');
            if ($eq === false) {
                continue;
            }
            $k = trim(substr($part, 0, $eq));
            $v = trim(substr($part, $eq + 1));
            if ($k === 't' && $t === null) {
                $t = $v;
            } elseif ($k === 'v1' && preg_match(self::V1, $v) === 1) {
                $candidates[] = strtolower($v);
            }
        }
        if ($t === null || preg_match('/^\d+$/', $t) !== 1 || $candidates === []) {
            throw new WebhookSignatureException(WebhookSignatureException::MALFORMED, 'Rewloy-Signature is not "t=<unix seconds>,v1=<hex>"');
        }

        $now ??= time();
        if (strlen($t) > 15 || abs($now - (int) $t) > $tolerance) {
            throw new WebhookSignatureException(
                WebhookSignatureException::EXPIRED,
                sprintf("The signature's time (t=%s) is more than %d seconds from now", $t, $tolerance),
            );
        }

        $match = false;
        foreach ($secrets as $s) {
            $expected = self::hmac($s, $t, $payload);
            foreach ($candidates as $candidate) {
                // Every pair is compared, in constant time, whether or not one matched already.
                if (hash_equals($expected, $candidate)) {
                    $match = true;
                }
            }
        }
        if (!$match) {
            throw new WebhookSignatureException(WebhookSignatureException::MISMATCH, 'No v1 signature matches the body and the secret');
        }

        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WebhookSignatureException(WebhookSignatureException::PAYLOAD, 'The signed body is not JSON');
        }
        if (!is_array($event) || (array_is_list($event) && $event !== [])) {
            throw new WebhookSignatureException(WebhookSignatureException::PAYLOAD, 'The signed body is not a JSON object');
        }
        /** @var array{id?: string, type: string, created_at: string, data: array<string, mixed>} $event */
        return $event;
    }

    /**
     * The `Rewloy-Signature` header the platform would send for this body: for
     * testing your own webhook handler.
     *
     * @param int|null $timestamp Unix time in seconds; default now.
     */
    public static function sign(string $payload, #[SensitiveParameter] string $secret, ?int $timestamp = null): string
    {
        if ($secret === '') {
            throw new InvalidArgumentException('Webhook::sign: $secret is empty');
        }
        $t = (string) ($timestamp ?? time());
        return 't=' . $t . ',v1=' . self::hmac($secret, $t, $payload);
    }

    private static function hmac(#[SensitiveParameter] string $secret, string $t, string $payload): string
    {
        return hash_hmac('sha256', $t . '.' . $payload, $secret);
    }
}

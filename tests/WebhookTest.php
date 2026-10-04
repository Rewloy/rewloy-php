<?php

declare(strict_types=1);

namespace Rewloy\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rewloy\Exception\WebhookSignatureException;
use Rewloy\Webhook;

/**
 * The vectors are made the way the platform signs a delivery (its
 * src/modules/webhooks/service.ts, `sign`), written out again here, not
 * imported:
 *
 *     `t=${t},v1=${createHmac('sha256', secret).update(`${t}.${body}`).digest('hex')}`
 *
 * with `body = JSON.stringify(payload)` and the secret `whsec_…` as the key.
 * They are the Node library's two fixed vectors (test/webhooks.test.ts there).
 */
final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_dGVzdC1zZWNyZXQtZm9yLXJld2xveS1ub2RlLXRlc3Rz';
    private const T = 1790000000;
    private const BODY = '{"id":"0192f7c1-8b2e-7a31-9c1d-2e4f5a6b7c8d","type":"pass.activity","created_at":"2026-10-03T12:00:00.000Z","data":{"kind":"earn","card":"ABCD-EFGH-JKLM","program_id":"0192f7c1-0000-7000-8000-000000000002","location_id":"0192f7c1-0000-7000-8000-000000000003","customer_id":"0192f7c1-0000-7000-8000-000000000004","unit":"stamp","delta":2}}';
    /** Computed once with the platform's formula; pins it against both sides changing together. */
    private const FIXED = 't=1790000000,v1=b17b337b887316b1e0e19c3f16bdc4936c03e93d16fbec3da121aacc0ec1eda7';
    private const TEST_BODY = '{"type":"webhook.test","created_at":"2026-10-03T12:00:00.000Z","data":{"message":"Rewloy webhook testi — ğüşıöç"}}';
    private const TEST_FIXED = 't=1790000000,v1=b06a92a7fb131aed835f2564fdf06a93461e0edb9216b4464598812f47704b1e';

    /** The platform's `sign`, in PHP. */
    private static function serverSign(string $secret, string $body, int $t): string
    {
        return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);
    }

    /**
     * @param string|list<string> $secret
     */
    private static function refused(string $reason, string $payload, ?string $header, string|array $secret = self::SECRET, ?int $now = self::T, int $tolerance = 300): void
    {
        try {
            Webhook::verify($payload, $header, $secret, $tolerance, $now);
        } catch (WebhookSignatureException $e) {
            self::assertSame($reason, $e->reason, (string) $header);
            return;
        }
        self::fail('accepted: ' . $header);
    }

    public function testAcceptsWhatThePlatformSigns(): void
    {
        self::assertSame(self::FIXED, self::serverSign(self::SECRET, self::BODY, self::T));
        self::assertSame(self::TEST_FIXED, self::serverSign(self::SECRET, self::TEST_BODY, self::T));
        $event = Webhook::verify(self::BODY, self::FIXED, self::SECRET, now: self::T + 10);
        self::assertSame('pass.activity', $event['type']);
        self::assertSame('0192f7c1-8b2e-7a31-9c1d-2e4f5a6b7c8d', $event['id'] ?? null);
        self::assertSame('ABCD-EFGH-JKLM', $event['data']['card'] ?? null);
        self::assertSame(2, $event['data']['delta'] ?? null);
        $test = Webhook::verify(self::TEST_BODY, self::TEST_FIXED, self::SECRET, now: self::T);
        self::assertSame(['type' => 'webhook.test', 'created_at' => '2026-10-03T12:00:00.000Z', 'data' => ['message' => 'Rewloy webhook testi — ğüşıöç']], $test);
    }

    public function testAcceptsOtherEntriesAroundV1AndUpperCaseHex(): void
    {
        self::assertSame('pass.activity', Webhook::verify(self::BODY, ' v0=abc, ' . str_replace(',', ' , ', self::FIXED) . ' ', self::SECRET, now: self::T)['type']);
        self::assertSame('pass.activity', Webhook::verify(self::BODY, 't=1790000000,v1=' . strtoupper(substr(self::FIXED, 16)), self::SECRET, now: self::T)['type']);
    }

    public function testAcceptsAnyOfSeveralV1SignaturesAndAnyOfSeveralSecrets(): void
    {
        $other = explode(',', self::serverSign('whsec_other', self::BODY, self::T))[1] ?? '';
        self::assertSame('pass.activity', Webhook::verify(self::BODY, self::FIXED . ',' . $other, self::SECRET, now: self::T)['type']);
        self::assertSame('pass.activity', Webhook::verify(self::BODY, 't=' . self::T . ',' . $other . ',' . substr(self::FIXED, strlen('t=1790000000,')), self::SECRET, now: self::T)['type']);
        self::assertSame('pass.activity', Webhook::verify(self::BODY, self::FIXED, ['whsec_new', self::SECRET], now: self::T)['type']);
        self::assertSame('pass.activity', Webhook::verify(self::BODY, self::FIXED, ['', self::SECRET], now: self::T)['type']);
    }

    public function testRefusesAChangedBodyTheWrongSecretAndAV1ForAnotherTime(): void
    {
        self::refused('mismatch', str_replace('"delta":2', '"delta":20', self::BODY), self::FIXED);
        self::refused('mismatch', self::BODY . "\n", self::FIXED);
        self::refused('mismatch', self::BODY, self::FIXED, 'whsec_wrong');
        self::refused('mismatch', self::BODY, str_replace('t=1790000000', 't=1790000001', self::FIXED));
        // The prefix is part of the key.
        self::refused('mismatch', self::BODY, self::FIXED, substr(self::SECRET, strlen('whsec_')));
        // A parsed and re-encoded body is not the body that was signed.
        self::refused('mismatch', (string) json_encode(json_decode(self::TEST_BODY, true)), self::TEST_FIXED);
    }

    public function testRefusesATimeOutsideTheToleranceEitherWay(): void
    {
        self::assertSame('pass.activity', Webhook::verify(self::BODY, self::FIXED, self::SECRET, now: self::T + 300)['type']);
        self::assertSame('pass.activity', Webhook::verify(self::BODY, self::FIXED, self::SECRET, now: self::T - 300)['type']);
        self::refused('expired', self::BODY, self::FIXED, now: self::T + 301);
        self::refused('expired', self::BODY, self::FIXED, now: self::T - 301);
        self::assertSame('pass.activity', Webhook::verify(self::BODY, self::FIXED, self::SECRET, 3600, self::T + 3600)['type']);
        // Real time: a 2026 signature is long expired.
        self::refused('expired', self::BODY, 't=1000,v1=' . str_repeat('a', 64), now: null);
        self::refused('expired', self::BODY, 't=99999999999999999999999,v1=' . str_repeat('a', 64));
    }

    public function testRefusesAMissingOrMalformedHeader(): void
    {
        foreach ([null, '', '  '] as $header) {
            self::refused('missing', self::BODY, $header);
        }
        foreach (['v1=' . str_repeat('a', 64), 't=1790000000', 't=abc,v1=' . str_repeat('a', 64), 't=1790000000,v1=xyz', 't=1790000000,v1=' . str_repeat('a', 63), 'garbage'] as $header) {
            self::refused('malformed', self::BODY, $header);
        }
    }

    public function testRefusesASignedBodyThatIsNotAJsonObject(): void
    {
        self::refused('payload', 'not json', self::serverSign(self::SECRET, 'not json', self::T));
        self::refused('payload', '[1,2]', self::serverSign(self::SECRET, '[1,2]', self::T));
        self::refused('payload', '"x"', self::serverSign(self::SECRET, '"x"', self::T));
    }

    public function testRefusesAnEmptySecret(): void
    {
        foreach (['', []] as $secret) {
            try {
                Webhook::verify(self::BODY, self::FIXED, $secret, now: self::T);
                self::fail('accepted an empty secret');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('$secret is empty', $e->getMessage());
            }
        }
    }

    public function testSignsAsThePlatformDoesForTestingYourOwnHandler(): void
    {
        self::assertSame(self::FIXED, Webhook::sign(self::BODY, self::SECRET, self::T));
        self::assertSame(self::TEST_FIXED, Webhook::sign(self::TEST_BODY, self::SECRET, self::T));
        $header = Webhook::sign(self::BODY, self::SECRET);
        self::assertSame('pass.activity', Webhook::verify(self::BODY, $header, self::SECRET)['type']);
        self::assertSame('Rewloy-Signature', Webhook::SIGNATURE_HEADER);
    }
}

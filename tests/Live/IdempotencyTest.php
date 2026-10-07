<?php

declare(strict_types=1);

namespace Rewloy\Tests\Live;

use InvalidArgumentException;
use Rewloy\Generated\ErrorCode;

/** Idempotency-Key: the same key replays the same answer; a different body under it is refused. */
final class IdempotencyTest extends LiveCase
{
    public const AREA = 'idempotency';

    public function testTheSameKeyReplaysAnAction(): void
    {
        $serial = Fixture::giftCard(10000);
        $key = Fixture::key('spend');
        $args = [
            'params' => ['serial' => $serial],
            'body' => ['action' => 'spend', 'locationId' => Fixture::locationId(), 'amountMinor' => 2500],
            'idempotencyKey' => $key,
        ];

        $first = $this->rewloy->passAction($args);
        $again = $this->rewloy->passAction($args);

        self::assertFalse($first['duplicate']);
        self::assertTrue($again['duplicate'], 'the second call is the first one replayed');
        self::assertSame(7500, $again['balance'], 'the card was charged once');
        self::assertSame($first['card']['serial'], $again['card']['serial'], 'the replay still carries the card');
        self::assertSame(7500, $again['card']['balance']);
        self::assertSame(7500, $this->rewloy->getPass(['params' => ['serial' => $serial]])['balance']);
    }

    public function testTheSameKeyReplaysASale(): void
    {
        $serial = Fixture::stampCard('idem-sale');
        $args = ['params' => ['serial' => $serial], 'body' => ['amountMinor' => 4200, 'reference' => 'fis-idem'], 'idempotencyKey' => Fixture::key('sale')];

        $first = $this->rewloy->recordSale($args);
        $again = $this->rewloy->recordSale($args);

        self::assertFalse($first['duplicate']);
        self::assertTrue($again['duplicate']);
        self::assertSame(1, $again['card']['balance'], 'one stamp, not two');
    }

    public function testTheSameKeyReplaysIssuingAPassWithTheReplayedHeader(): void
    {
        $args = [
            'body' => ['programId' => Fixture::stampProgramId(), 'email' => Fixture::email('idem-issue'), 'kvkkConsent' => true],
            'idempotencyKey' => Fixture::key('issue'),
        ];

        $first = $this->rewloy->request('issuePass', $args);
        $again = $this->rewloy->request('issuePass', $args);

        self::assertFalse($first->replayed);
        self::assertTrue($again->replayed, 'Idempotent-Replayed: true');
        self::assertIsArray($first->data);
        self::assertIsArray($again->data);
        self::assertSame($first->data['serial'] ?? null, $again->data['serial'] ?? null, 'the same card, not a second one');
    }

    public function testTheSameKeyWithAnotherBodyIsRefused(): void
    {
        $serial = Fixture::giftCard(10000);
        $key = Fixture::key('reuse');
        $body = ['action' => 'spend', 'locationId' => Fixture::locationId()];
        $this->rewloy->passAction(['params' => ['serial' => $serial], 'body' => $body + ['amountMinor' => 1000], 'idempotencyKey' => $key]);

        $refusal = $this->refusal(fn () => $this->rewloy->passAction([
            'params' => ['serial' => $serial],
            'body' => $body + ['amountMinor' => 999],
            'idempotencyKey' => $key,
        ]));

        self::assertSame(422, $refusal->status);
        self::assertSame(ErrorCode::IDEMPOTENCY_KEY_REUSED, $refusal->errorCode);
        self::assertSame(9000, $this->rewloy->getPass(['params' => ['serial' => $serial]])['balance'], 'only the first spend happened');
    }

    public function testTheLibraryWillNotSendASaleWithoutAKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->rewloy->request('recordSale', ['params' => ['serial' => 'AAAA-BBBB-CCCC'], 'body' => ['amountMinor' => 100]]);
    }
}

<?php

declare(strict_types=1);

namespace Rewloy\Tests\Live;

use Rewloy\Generated\ErrorCode;

/**
 * Sales and the operations list: recordSale with and without a receipt reference, reverseSale,
 * reverseAction, listPassOperations. (Receipt lines arrive with API 1.3: see the TODO list.)
 */
final class SalesTest extends LiveCase
{
    public const AREA = 'sales and operations';

    public function testASaleWithoutAReceiptReferenceEarnsAStamp(): void
    {
        $serial = Fixture::stampCard('sale-plain');

        $sale = $this->rewloy->recordSale([
            'params' => ['serial' => $serial],
            'body' => ['amountMinor' => 5000],
            'idempotencyKey' => Fixture::key('sale'),
        ]);

        self::assertSame('stamp', $sale['type']);
        self::assertSame('stamps', $sale['applied']);
        self::assertSame(1, $sale['credited']);
        self::assertSame(1, $sale['balance']);
        self::assertFalse($sale['duplicate']);
        self::assertSame($serial, $sale['card']['serial']);
    }

    public function testASaleWithAReceiptReferenceAndBranchIsInTheOperations(): void
    {
        $serial = Fixture::stampCard('sale-ref');
        $saleKey = Fixture::key('sale');
        $reference = 'fis-' . Fixture::run() . '-0042';

        $this->rewloy->recordSale([
            'params' => ['serial' => $serial],
            'body' => ['locationId' => Fixture::locationId(), 'amountMinor' => 12500, 'reference' => $reference],
            'idempotencyKey' => $saleKey,
        ]);

        $operations = $this->rewloy->listPassOperations(['params' => ['serial' => $serial]])['data'];
        self::assertCount(1, $operations);
        $op = $operations[0];
        self::assertSame('earn', $op['kind']);
        self::assertSame(1, $op['delta']);
        self::assertSame($reference, $op['reference']);
        self::assertSame($saleKey, $op['saleKey'] ?? null);
        self::assertSame(Fixture::locationId(), $op['locationId']);
        self::assertTrue($op['byCaller']);
        self::assertTrue($op['reversible']);
        self::assertSame('sale/reverse', $op['undoWith'] ?? null);
    }

    public function testASaleIsReversedByItsKey(): void
    {
        $serial = Fixture::stampCard('rev-key');
        $saleKey = Fixture::key('sale');
        $sale = ['params' => ['serial' => $serial], 'body' => ['amountMinor' => 3000], 'idempotencyKey' => $saleKey];
        $this->rewloy->recordSale($sale);

        $reversed = $this->rewloy->reverseSale(['params' => ['serial' => $serial], 'body' => ['saleKey' => $saleKey]]);

        self::assertSame(1, $reversed['reversed']);
        self::assertSame(0, $reversed['balance']);
        self::assertFalse($reversed['duplicate']);

        $operations = $this->rewloy->listPassOperations(['params' => ['serial' => $serial]])['data'];
        self::assertCount(2, $operations);
        $earn = array_values(array_filter($operations, static fn (array $o): bool => $o['kind'] === 'earn'))[0];
        $undo = array_values(array_filter($operations, static fn (array $o): bool => $o['kind'] === 'adjust'))[0];
        self::assertFalse($earn['reversible'], 'a reversed sale cannot be reversed twice');
        self::assertSame($undo['id'], $earn['reversedBy']);
        self::assertSame($earn['id'], $undo['reverses']);
    }

    public function testASaleIsReversedByItsReceiptReference(): void
    {
        $serial = Fixture::stampCard('rev-ref');
        $reference = 'fis-' . Fixture::run() . '-0043';
        $this->rewloy->recordSale([
            'params' => ['serial' => $serial],
            'body' => ['amountMinor' => 3000, 'reference' => $reference],
            'idempotencyKey' => Fixture::key('sale'),
        ]);

        $reversed = $this->rewloy->reverseSale(['params' => ['serial' => $serial], 'body' => ['reference' => $reference]]);

        self::assertSame(1, $reversed['reversed']);
        self::assertSame(0, $reversed['balance']);
    }

    public function testReversingASaleThatWasNeverMadeIsRefused(): void
    {
        $serial = Fixture::stampCard('rev-none');

        $refusal = $this->refusal(fn () => $this->rewloy->reverseSale(['params' => ['serial' => $serial], 'body' => ['saleKey' => 'phplive-never-made']]));

        self::assertSame(404, $refusal->status);
        self::assertSame(ErrorCode::SALE_NOT_FOUND, $refusal->errorCode);
    }

    public function testARedeemIsReversedByItsActionKey(): void
    {
        $serial = Fixture::stampCard('rev-action');
        $location = Fixture::locationId();
        $this->rewloy->passAction([
            'params' => ['serial' => $serial],
            'body' => ['action' => 'earn-stamps', 'locationId' => $location, 'count' => 5],
            'idempotencyKey' => Fixture::key('earn'),
        ]);
        $actionKey = Fixture::key('redeem');
        $redeem = $this->rewloy->passAction([
            'params' => ['serial' => $serial],
            'body' => ['action' => 'redeem-stamps', 'locationId' => $location, 'reference' => 'odul-iade'],
            'idempotencyKey' => $actionKey,
        ]);
        self::assertSame(0, $redeem['balance']);

        $undone = $this->rewloy->reverseAction(['params' => ['serial' => $serial], 'body' => ['actionKey' => $actionKey]]);

        self::assertSame('redeem', $undone['undone']);
        self::assertSame(5, $undone['balance']);
        self::assertSame(5, $undone['restored'] ?? null);
        self::assertTrue($undone['rewardReady']);
    }

    public function testASpendIsReversedAndTheBalanceComesBack(): void
    {
        $serial = Fixture::giftCard(10000);
        $actionKey = Fixture::key('spend');
        $this->rewloy->passAction([
            'params' => ['serial' => $serial],
            'body' => ['action' => 'spend', 'locationId' => Fixture::locationId(), 'amountMinor' => 4000, 'reference' => 'hediye-fis'],
            'idempotencyKey' => $actionKey,
        ]);

        $undone = $this->rewloy->reverseAction(['params' => ['serial' => $serial], 'body' => ['reference' => 'hediye-fis']]);

        self::assertSame('spend', $undone['undone']);
        self::assertSame(4000, $undone['restored'] ?? null);
        self::assertSame(10000, $undone['balance']);
    }

    public function testAnEarnIsNotUndoneThroughActions(): void
    {
        $serial = Fixture::stampCard('rev-earn');
        $actionKey = Fixture::key('earn');
        $this->rewloy->passAction([
            'params' => ['serial' => $serial],
            'body' => ['action' => 'earn-stamps', 'locationId' => Fixture::locationId(), 'count' => 1],
            'idempotencyKey' => $actionKey,
        ]);

        $refusal = $this->refusal(fn () => $this->rewloy->reverseAction(['params' => ['serial' => $serial], 'body' => ['actionKey' => $actionKey]]));

        self::assertSame(ErrorCode::ACTION_NOT_FOUND, $refusal->errorCode);
    }
}

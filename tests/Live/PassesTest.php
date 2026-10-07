<?php

declare(strict_types=1);

namespace Rewloy\Tests\Live;

/** Passes: issue, read, the till view and the till actions (stamp, redeem, spend). */
final class PassesTest extends LiveCase
{
    public const AREA = 'passes';

    public function testIssueAPassAndReadItBack(): void
    {
        $email = Fixture::email('issue');

        $issued = $this->rewloy->issuePass(['body' => [
            'programId' => Fixture::stampProgramId(),
            'email' => $email,
            'firstName' => 'Ayşe',
            'kvkkConsent' => true,
        ]]);

        self::assertTrue($issued['created']);
        self::assertMatchesRegularExpression('/^[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}$/', $issued['serial']);
        self::assertStringContainsString($issued['serial'], $issued['cardUrl']);

        $card = $this->rewloy->getPass(['params' => ['serial' => $issued['serial']]]);
        self::assertSame($issued['serial'], $card['serial']);
        self::assertSame('stamp', $card['type']);
        self::assertSame('active', $card['status']);
        self::assertSame(0, $card['balance']);
        self::assertSame(Fixture::stampProgramId(), $card['programId']);
        $actions = array_column($card['actions'], 'ready', 'action');
        self::assertTrue($actions['earn-stamps'] ?? false, 'earning is always ready');
        self::assertFalse($actions['redeem-stamps'] ?? true, 'nothing to redeem yet');
    }

    public function testTheTillView(): void
    {
        $serial = Fixture::stampCard('till');

        $till = $this->rewloy->getPassTill([
            'params' => ['serial' => $serial],
            'query' => ['locationId' => Fixture::locationId()],
        ]);

        self::assertTrue($till['allowed']);
        self::assertIsArray($till['notices']);
    }

    public function testStampsAreEarnedAndTheRewardIsRedeemed(): void
    {
        $serial = Fixture::stampCard('stamps');
        $location = Fixture::locationId();
        $args = static fn (array $body, string $label): array => [
            'params' => ['serial' => $serial],
            'body' => $body + ['locationId' => $location],
            'idempotencyKey' => Fixture::key($label),
        ];

        $first = $this->rewloy->passAction($args(['action' => 'earn-stamps', 'count' => 2], 'earn'));
        self::assertSame(2, $first['balance']);
        self::assertFalse($first['duplicate']);
        self::assertSame(2, $first['card']['stamps']['count'] ?? null);
        self::assertSame(5, $first['card']['stamps']['max'] ?? null);
        self::assertFalse($first['card']['rewardReady']);

        $full = $this->rewloy->passAction($args(['action' => 'earn-stamps', 'count' => 3], 'earn'));
        self::assertSame(5, $full['balance']);
        self::assertTrue($full['card']['rewardReady'], 'five of five stamps is a reward');

        $redeemed = $this->rewloy->passAction($args(['action' => 'redeem-stamps', 'reference' => 'odul-1'], 'redeem'));
        self::assertSame(0, $redeemed['balance']);
        self::assertFalse($redeemed['card']['rewardReady']);
    }

    public function testAGiftCardIsSpent(): void
    {
        $serial = Fixture::giftCard(10000);

        $card = $this->rewloy->getPass(['params' => ['serial' => $serial]]);
        self::assertSame('giftcard', $card['type']);
        self::assertSame(10000, $card['balance']);

        $spent = $this->rewloy->passAction([
            'params' => ['serial' => $serial],
            'body' => ['action' => 'spend', 'locationId' => Fixture::locationId(), 'amountMinor' => 2500],
            'idempotencyKey' => Fixture::key('spend'),
        ]);

        self::assertSame(7500, $spent['balance']);
        self::assertSame(7500, $spent['card']['balance']);
    }
}

<?php

declare(strict_types=1);

namespace Rewloy\Tests\Live;

use Rewloy\Generated\ErrorCode;

/**
 * API 1.3.0: product groups, earn rules, receipt lines on a sale (recordSale, previewSale, previewEarn),
 * the explanation of what a receipt earned (`earn`), and refunds of single lines.
 *
 * One stamp program has a coffee group and one rule, "1 stamp for each item in the group", set once
 * for the class; every sale has two lines, a latte (in the group) and water (in no group).
 */
final class EarnRulesTest extends LiveCase
{
    public const AREA = 'earn rules and receipt lines';

    private static ?string $programId = null;

    private static ?string $groupId = null;

    private function groupId(): string
    {
        if (self::$groupId === null) {
            $group = Fixture::client()->createEarnGroup(['body' => [
                'name' => 'Live kahveler ' . Fixture::run(),
                'members' => [['effect' => 'include', 'match' => 'category', 'value' => 'Kahve', 'withChildren' => true]],
            ]]);
            self::$groupId = $group['id'];
            Fixture::trackGroup($group['id']);
        }
        return self::$groupId;
    }

    /** The stamp program that earns one stamp per coffee. */
    private function programId(): string
    {
        if (self::$programId === null) {
            $id = Fixture::newProgram([
                'type' => 'stamp',
                'businessName' => 'Live Kahve',
                'programName' => 'Live kural ' . Fixture::run(),
                'maxStamps' => 10,
                'rewardName' => 'Bedava kahve',
            ]);
            $rules = Fixture::client()->putEarnRules(['params' => ['id' => $id], 'body' => [
                'revision' => 0,
                'rules' => [['kind' => 'stamp.perUnit', 'groupId' => $this->groupId(), 'stamps' => 1]],
            ]]);
            Fixture::trackRules($id);
            self::assertSame(1, $rules['revision']);
            self::$programId = $id;
        }
        return self::$programId;
    }

    private function card(string $label): string
    {
        $card = $this->rewloy->issuePass(['body' => [
            'programId' => $this->programId(),
            'email' => Fixture::email($label),
            'firstName' => 'Ayşe',
            'kvkkConsent' => true,
        ]]);
        return $card['serial'];
    }

    /**
     * A receipt of 200,00: two lattes (180,00) and a water (20,00).
     *
     * @return list<array<string, mixed>>
     */
    private static function lines(): array
    {
        return [
            ['lineId' => 'l1', 'name' => 'Latte', 'category' => ['Kahve', 'Sıcak'], 'quantity' => 2, 'unitPriceMinor' => 9000, 'totalMinor' => 18000],
            ['lineId' => 'l2', 'name' => 'Su', 'category' => 'Su', 'quantity' => 1, 'unitPriceMinor' => 2000, 'totalMinor' => 2000],
        ];
    }

    /**
     * @param array<string, mixed> $earn
     * @return array<string, array<string, mixed>> the explanation's lines by lineId
     */
    private static function earnLines(array $earn): array
    {
        $byId = [];
        $lines = $earn['lines'] ?? null;
        self::assertIsArray($lines);
        foreach ($lines as $line) {
            self::assertIsArray($line);
            self::assertIsString($line['lineId'] ?? null);
            $byId[$line['lineId']] = $line;
        }
        return $byId;
    }

    // ---- groups -----------------------------------------------------------------------------

    public function testAGroupIsCreatedListedChangedAndDeleted(): void
    {
        $name = 'Live grup ' . Fixture::run();
        $group = $this->rewloy->createEarnGroup(['body' => [
            'name' => $name,
            'members' => [
                ['effect' => 'include', 'match' => 'category', 'value' => 'Tatlı', 'withChildren' => true],
                ['effect' => 'exclude', 'match' => 'tag', 'value' => 'paketli'],
            ],
        ]]);
        Fixture::trackGroup($group['id']);
        self::assertSame($name, $group['name']);
        self::assertCount(2, $group['members']);
        self::assertSame([], $group['usedBy']);

        self::assertContains($group['id'], array_column($this->rewloy->listEarnGroups(), 'id'));
        self::assertSame($name, $this->rewloy->getEarnGroup(['params' => ['id' => $group['id']]])['name']);

        $renamed = $this->rewloy->updateEarnGroup(['params' => ['id' => $group['id']], 'body' => [
            'name' => $name . ' (yeni)',
            'removeMembers' => [array_values(array_filter($group['members'], static fn (array $m): bool => $m['match'] === 'tag'))[0]['id']],
            'addMembers' => [['effect' => 'include', 'match' => 'sku', 'value' => 'TATLI-1']],
        ]]);
        self::assertSame($name . ' (yeni)', $renamed['name']);
        self::assertCount(2, $renamed['members']);
        self::assertEqualsCanonicalizing(['category', 'sku'], array_column($renamed['members'], 'match'));

        $this->rewloy->deleteEarnGroup(['params' => ['id' => $group['id']]]);
        $refusal = $this->refusal(fn () => $this->rewloy->getEarnGroup(['params' => ['id' => $group['id']]]));
        self::assertSame(404, $refusal->status);
    }

    public function testAGroupInUseCannotBeDeleted(): void
    {
        $group = $this->groupId();
        $this->programId();

        self::assertContains($this->programId(), $this->rewloy->getEarnGroup(['params' => ['id' => $group]])['usedBy']);
        $refusal = $this->refusal(fn () => $this->rewloy->deleteEarnGroup(['params' => ['id' => $group]]));

        self::assertSame(409, $refusal->status);
        self::assertSame(ErrorCode::GROUP_IN_USE, $refusal->errorCode);
    }

    public function testTheTemplatesAreListedForAProgramType(): void
    {
        $templates = $this->rewloy->listEarnTemplates(['query' => ['type' => 'stamp']]);

        self::assertNotEmpty($templates);
        foreach ($templates as $template) {
            self::assertSame('stamp', $template['type']);
            self::assertNotSame('', $template['text']);
            self::assertNotEmpty($template['rules']);
        }
    }

    // ---- rules ------------------------------------------------------------------------------

    public function testRulesAreSavedAsRevisionsAndOneRuleIsChangedAndDeleted(): void
    {
        $id = Fixture::newProgram(['type' => 'stamp', 'businessName' => 'Live Kahve', 'programName' => 'Live revizyon ' . Fixture::run(), 'maxStamps' => 8, 'rewardName' => 'Kahve']);
        Fixture::trackRules($id);
        $params = ['id' => $id];

        $empty = $this->rewloy->getEarnRules(['params' => $params]);
        self::assertSame(0, $empty['revision']);
        self::assertFalse($empty['active']);
        self::assertSame([], $empty['rules']);

        $saved = $this->rewloy->putEarnRules(['params' => $params, 'body' => [
            'revision' => 0,
            'settings' => ['dailyCap' => 6],
            'rules' => [['kind' => 'stamp.perReceipt', 'stamps' => 1, 'minReceiptMinor' => 5000]],
        ]]);
        self::assertSame(1, $saved['revision']);
        self::assertTrue($saved['active']);
        self::assertSame(6, $saved['settings']['dailyCap']);
        self::assertCount(1, $saved['rules']);
        self::assertNotSame('', $saved['rules'][0]['text'], 'each rule comes as a sentence');
        self::assertNotNull($saved['text']);

        // A save from an older revision is refused: compare-and-set.
        $stale = $this->refusal(fn () => $this->rewloy->putEarnRules(['params' => $params, 'body' => ['revision' => 0, 'rules' => []]]));
        self::assertSame(409, $stale->status);
        self::assertSame(ErrorCode::REVISION_CONFLICT, $stale->errorCode);

        // A rule of another program type is refused.
        $wrongKind = $this->refusal(fn () => $this->rewloy->createEarnRule(['params' => $params, 'body' => ['kind' => 'points.rate', 'points' => 1, 'everyMinor' => 1000]]));
        self::assertSame(422, $wrongKind->status);
        self::assertSame(ErrorCode::RULE_KIND_NOT_FOR_TYPE, $wrongKind->errorCode);

        $added = $this->rewloy->createEarnRule(['params' => $params, 'body' => ['kind' => 'stamp.perReceipt', 'stamps' => 2, 'minReceiptMinor' => 20000]]);
        self::assertSame(2, $added['revision']);
        self::assertCount(2, $added['rules']);
        $ruleId = $added['rules'][1]['id'];

        $changed = $this->rewloy->updateEarnRule(['params' => $params + ['ruleId' => $ruleId], 'body' => ['stamps' => 3]]);
        self::assertSame(3, $changed['revision']);
        self::assertSame(3, $changed['rules'][1]['stamps'] ?? null);

        $removed = $this->rewloy->deleteEarnRule(['params' => $params + ['ruleId' => $ruleId]]);
        self::assertSame(4, $removed['revision']);
        self::assertCount(1, $removed['rules']);

        $missing = $this->refusal(fn () => $this->rewloy->deleteEarnRule(['params' => $params + ['ruleId' => 'r99']]));
        self::assertSame(404, $missing->status);
        self::assertSame(ErrorCode::EARN_RULE_NOT_FOUND, $missing->errorCode);

        // Every save is kept: newest first.
        $revisions = $this->rewloy->listEarnRuleRevisions(['params' => $params])['data'];
        self::assertSame([4, 3, 2, 1], array_column($revisions, 'revision'));

        $this->rewloy->deleteEarnRules(['params' => $params]);
        self::assertSame([], $this->rewloy->getEarnRules(['params' => $params])['rules']);
    }

    // ---- previews and sales with lines ------------------------------------------------------

    public function testPreviewEarnSaysWhatAReceiptWouldEarnWithoutACard(): void
    {
        $preview = $this->rewloy->previewEarn(['params' => ['id' => $this->programId()], 'body' => ['amountMinor' => 20000, 'lines' => self::lines()]]);

        self::assertSame(2, $preview['credited']);
        self::assertSame('stamps', $preview['unit']);
        self::assertSame('rules', $preview['earn']['source']);
        self::assertSame(1, $preview['earn']['revision']);
        $lines = self::earnLines($preview['earn']);
        self::assertSame('earned', $lines['l1']['status']);
        self::assertSame(2, $lines['l1']['earned']);
        self::assertContains($this->groupId(), $lines['l1']['groups'] ?? [], 'the line says which groups it was in');
        self::assertSame('no_rule', $lines['l2']['status']);
        self::assertSame(0, $lines['l2']['earned']);
        self::assertSame(2, $preview['earn']['total']['credited']);
        self::assertSame('l1', $preview['earn']['rules'][0]['lines'][0]);
    }

    public function testPreviewEarnJudgesADraftRuleSetInsteadOfTheSavedOne(): void
    {
        $preview = $this->rewloy->previewEarn(['params' => ['id' => $this->programId()], 'body' => [
            'amountMinor' => 20000,
            'lines' => self::lines(),
            'ruleSet' => ['rules' => [['kind' => 'stamp.perUnit', 'groupId' => $this->groupId(), 'stamps' => 3]]],
        ]]);

        self::assertSame(6, $preview['credited'], 'two lattes at three stamps each');
    }

    public function testPreviewSaleWritesNothing(): void
    {
        $serial = $this->card('preview');

        $preview = $this->rewloy->previewSale(['params' => ['serial' => $serial], 'body' => ['amountMinor' => 20000, 'lines' => self::lines()]]);

        self::assertTrue($preview['preview']);
        self::assertSame(2, $preview['credited']);
        self::assertSame('rules', $preview['earn']['source'] ?? null);
        $card = $this->rewloy->getPass(['params' => ['serial' => $serial]]);
        self::assertSame(0, $card['stamps']['count'] ?? null, 'a preview leaves the card as it was');
        self::assertSame([], $this->rewloy->listPassOperations(['params' => ['serial' => $serial]])['data']);
    }

    public function testASaleWithLinesEarnsByTheRulesAndExplainsItself(): void
    {
        $serial = $this->card('lines');
        $request = [
            'params' => ['serial' => $serial],
            'body' => ['locationId' => Fixture::locationId(), 'amountMinor' => 20000, 'lines' => self::lines(), 'reference' => 'fis-' . Fixture::run() . '-l1'],
            'idempotencyKey' => Fixture::key('lines'),
        ];

        $sale = $this->rewloy->recordSale($request);

        self::assertSame('stamps', $sale['applied']);
        self::assertSame(2, $sale['credited']);
        self::assertSame(2, $sale['balance']);
        self::assertFalse($sale['duplicate']);
        self::assertSame(2, $sale['card']['stamps']['count'] ?? null);
        $earn = $sale['earn'] ?? null;
        self::assertIsArray($earn);
        self::assertSame('rules', $earn['source']);
        self::assertSame('stamps', $earn['unit']);
        $lines = self::earnLines($earn);
        self::assertSame(['earned', 'no_rule'], [$lines['l1']['status'], $lines['l2']['status']]);
        self::assertSame(2, $earn['total']['credited']);
        self::assertSame([], $earn['total']['caps']);
        self::assertStringContainsString('stamp', $earn['rules'][0]['text']);

        // The same key again is the same sale, not a second one.
        $again = $this->rewloy->recordSale($request);
        self::assertTrue($again['duplicate']);
        self::assertSame(2, $again['card']['stamps']['count'] ?? null);
    }

    public function testASaleWithoutLinesOnAProgramWithRulesEarnsAsBefore(): void
    {
        $serial = $this->card('nolines');

        $sale = $this->rewloy->recordSale([
            'params' => ['serial' => $serial],
            'body' => ['amountMinor' => 20000],
            'idempotencyKey' => Fixture::key('nolines'),
        ]);

        self::assertSame(1, $sale['credited'], 'the rules say what a till without lines earns: the old one stamp a sale');
        self::assertSame('rules', $sale['earn']['source'] ?? null);
        self::assertSame([], $sale['earn']['lines'] ?? null, 'no lines, nothing to explain line by line');
        self::assertSame(1, $sale['earn']['total']['credited'] ?? null);
    }

    public function testLinesThatDoNotAddUpToTheAmountAreRefused(): void
    {
        $serial = $this->card('mismatch');

        $refusal = $this->refusal(fn () => $this->rewloy->recordSale([
            'params' => ['serial' => $serial],
            'body' => ['amountMinor' => 99999, 'lines' => self::lines()],
            'idempotencyKey' => Fixture::key('mismatch'),
        ]));

        self::assertSame(422, $refusal->status);
        self::assertSame(ErrorCode::LINES_TOTAL_MISMATCH, $refusal->errorCode);
    }

    public function testAReceiptOfTooManyLinesIsRefused(): void
    {
        $serial = $this->card('toomany');
        $lines = [];
        for ($i = 1; $i <= 501; $i++) {
            $lines[] = ['name' => 'Şeker', 'quantity' => 1, 'unitPriceMinor' => 10, 'totalMinor' => 10];
        }

        $refusal = $this->refusal(fn () => $this->rewloy->recordSale([
            'params' => ['serial' => $serial],
            'body' => ['amountMinor' => 5010, 'lines' => $lines],
            'idempotencyKey' => Fixture::key('toomany'),
        ]));

        self::assertContains($refusal->errorCode, [ErrorCode::TOO_MANY_LINES, ErrorCode::VALIDATION], 'the line limit is 500');
        self::assertContains($refusal->status, [400, 422]);
    }

    // ---- refunds of lines -------------------------------------------------------------------

    public function testOneLineIsRefundedAndTheDifferenceIsTakenBack(): void
    {
        $serial = $this->card('refund');
        $saleKey = Fixture::key('refund-sale');
        $this->rewloy->recordSale([
            'params' => ['serial' => $serial],
            'body' => ['amountMinor' => 20000, 'lines' => self::lines()],
            'idempotencyKey' => $saleKey,
        ]);

        // One of the two lattes comes back.
        $refund = [
            'params' => ['serial' => $serial],
            'body' => ['saleKey' => $saleKey, 'lines' => [['lineId' => 'l1', 'quantity' => 1]]],
            'idempotencyKey' => Fixture::key('refund-1'),
        ];
        $first = $this->rewloy->reverseSale($refund);
        self::assertSame(1, $first['reversed']);
        self::assertSame(1, $first['balance']);
        self::assertFalse($first['duplicate']);
        $left = array_column($first['linesLeft'] ?? [], null, 'lineId');
        self::assertSame(1, (int) $left['l1']['quantity']);
        self::assertSame(9000, $left['l1']['amountMinor']);
        self::assertSame(2000, $left['l2']['amountMinor']);
        self::assertSame(1, $first['earn']['total']['credited'] ?? null, 'the sale is judged again without the refunded line');

        // The same request again changes nothing.
        $replay = $this->rewloy->reverseSale($refund);
        self::assertTrue($replay['duplicate']);
        self::assertSame(1, $replay['balance']);

        // The other latte; then the line is gone.
        $second = $this->rewloy->reverseSale([
            'params' => ['serial' => $serial],
            'body' => ['saleKey' => $saleKey, 'lines' => [['lineId' => 'l1', 'quantity' => 1]]],
            'idempotencyKey' => Fixture::key('refund-2'),
        ]);
        self::assertSame(0, $second['balance']);
        $earnLines = self::earnLines($second['earn'] ?? []);
        self::assertSame('refunded', $earnLines['l1']['status']);

        $gone = $this->refusal(fn () => $this->rewloy->reverseSale([
            'params' => ['serial' => $serial],
            'body' => ['saleKey' => $saleKey, 'lines' => [['lineId' => 'l1', 'quantity' => 1]]],
            'idempotencyKey' => Fixture::key('refund-3'),
        ]));
        self::assertSame(409, $gone->status);
        self::assertSame(ErrorCode::LINE_ALREADY_REFUNDED, $gone->errorCode);
    }

    public function testALineThatWasNeverSoldCannotBeRefunded(): void
    {
        $serial = $this->card('refund-none');
        $saleKey = Fixture::key('refund-none-sale');
        $this->rewloy->recordSale([
            'params' => ['serial' => $serial],
            'body' => ['amountMinor' => 20000, 'lines' => self::lines()],
            'idempotencyKey' => $saleKey,
        ]);

        $refusal = $this->refusal(fn () => $this->rewloy->reverseSale([
            'params' => ['serial' => $serial],
            'body' => ['saleKey' => $saleKey, 'lines' => [['lineId' => 'l99']]],
            'idempotencyKey' => Fixture::key('refund-none'),
        ]));

        self::assertSame(404, $refusal->status);
        self::assertSame(ErrorCode::LINE_NOT_FOUND, $refusal->errorCode);
    }

    public function testASaleWithLinesCanStillBeReversedWhole(): void
    {
        $serial = $this->card('refund-whole');
        $saleKey = Fixture::key('refund-whole-sale');
        $this->rewloy->recordSale([
            'params' => ['serial' => $serial],
            'body' => ['amountMinor' => 20000, 'lines' => self::lines()],
            'idempotencyKey' => $saleKey,
        ]);

        $reversed = $this->rewloy->reverseSale(['params' => ['serial' => $serial], 'body' => ['saleKey' => $saleKey]]);

        self::assertSame(2, $reversed['reversed']);
        self::assertSame(0, $reversed['balance']);
    }

    // ---- what the lines taught the business -------------------------------------------------

    public function testTheCategoriesTheTillSentAreSeenAndCanBeIgnored(): void
    {
        $serial = $this->card('seen');
        $this->rewloy->recordSale([
            'params' => ['serial' => $serial],
            'body' => ['amountMinor' => 20000, 'lines' => self::lines()],
            'idempotencyKey' => Fixture::key('seen'),
        ]);

        $seen = $this->rewloy->listSeenLines()['data'];
        $keys = array_column($seen, 'key');
        self::assertContains('kahve', $keys);
        self::assertContains('su', $keys);

        $sources = $this->rewloy->listEarnSources();
        self::assertNotEmpty($sources);
        self::assertContains('key', array_column($sources, 'kind'));

        $this->rewloy->ignoreSeenLine(['body' => ['kind' => 'category', 'key' => 'su']]);
        $ignored = array_column($this->rewloy->listSeenLines()['data'], 'ignored', 'key');
        self::assertTrue($ignored['su']);
        $this->rewloy->unignoreSeenLine(['query' => ['kind' => 'category', 'key' => 'su']]);
        $ignored = array_column($this->rewloy->listSeenLines()['data'], 'ignored', 'key');
        self::assertFalse($ignored['su']);
    }

    // ---- copying a card ---------------------------------------------------------------------

    public function testOnlyAnInstrumentIsCopiedAndALoyaltyCardIsRefused(): void
    {
        $refusal = $this->refusal(fn () => $this->rewloy->copyProgram(['params' => ['id' => $this->programId()], 'body' => ['name' => 'Kopya']]));

        self::assertSame(422, $refusal->status);
        self::assertSame(ErrorCode::NOT_AN_INSTRUMENT, $refusal->errorCode);
    }

    public function testAGiftCardIsCopiedUnderANewName(): void
    {
        $copy = $this->rewloy->copyProgram(['params' => ['id' => Fixture::giftProgramId()], 'body' => ['name' => 'Live kopya ' . Fixture::run()]]);
        Fixture::adopt($copy['id'], $copy['name']);

        self::assertNotSame(Fixture::giftProgramId(), $copy['id']);
        self::assertSame('giftcard', $copy['type']);
        self::assertSame('Live kopya ' . Fixture::run(), $copy['name']);
        self::assertSame('active', $copy['status']);
    }
}

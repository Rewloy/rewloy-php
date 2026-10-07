<?php

declare(strict_types=1);

namespace Rewloy\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rewloy\Client;
use Rewloy\Exception\RewloyException;
use Rewloy\Generated\ErrorCode;
use Rewloy\Generated\Operations;
use Rewloy\Http\HttpRequest;
use Rewloy\Http\HttpResponse;
use Rewloy\Tests\Support\Api;
use Rewloy\Tests\Support\StubTransport;

/** The operations, fields and errors Rewloy API 1.3.0 added. */
final class V130Test extends TestCase
{
    private const PROGRAM = '0192f7c1-1111-7000-8000-0000000000a1';
    private const GROUP = '0192f7c1-2222-7000-8000-0000000000b2';

    /** @var array<string, mixed> what a sale with lines answers: the explanation of the earning */
    private const EARN = [
        'source' => 'rules',
        'revision' => 3,
        'unit' => 'stamps',
        'lines' => [
            ['lineId' => 'l1', 'status' => 'earned', 'groups' => [self::GROUP], 'rules' => ['r1'], 'earned' => 2],
            ['lineId' => 'l2', 'status' => 'no_rule', 'earned' => 0],
        ],
        'rules' => [['ruleId' => 'r1', 'kind' => 'stamp.perUnit', 'units' => 2, 'lines' => ['l1'], 'text' => '1 stamp for each item in Kahveler']],
        'total' => ['beforeRounding' => '2', 'rounded' => 2, 'receiptCap' => null, 'promotion' => null, 'caps' => [], 'credited' => 2],
    ];

    private StubTransport $stub;

    protected function setUp(): void
    {
        $this->stub = new StubTransport(static function (HttpRequest $req): HttpResponse {
            $path = StubTransport::target($req);
            if (str_ends_with($path, '/sale/preview')) {
                return Api::json(200, ['data' => ['type' => 'stamp', 'applied' => 'stamps', 'credited' => 2, 'balance' => 2, 'duplicate' => false, 'rewardReady' => false, 'rewardsReady' => 0, 'reversed' => false, 'card' => null, 'earn' => self::EARN, 'preview' => true]]);
            }
            if (str_ends_with($path, '/sale/reverse')) {
                return Api::json(200, ['data' => ['type' => 'stamp', 'applied' => 'stamps', 'reversed' => 1, 'balance' => 1, 'duplicate' => false, 'rewardReady' => false, 'rewardsReady' => 0, 'card' => null, 'earn' => self::EARN, 'linesLeft' => [['lineId' => 'l1', 'quantity' => 1, 'amountMinor' => 9000]]]]);
            }
            if (str_ends_with($path, '/sale')) {
                return Api::json(200, ['data' => ['type' => 'stamp', 'applied' => 'stamps', 'credited' => 2, 'balance' => 2, 'duplicate' => false, 'rewardReady' => false, 'rewardsReady' => 0, 'reversed' => false, 'card' => null, 'earn' => self::EARN]]);
            }
            if (str_ends_with($path, '/earn-rules/preview')) {
                return Api::json(200, ['data' => ['credited' => 2, 'unit' => 'stamps', 'earn' => self::EARN]]);
            }
            if ($path === '/v1/locations/frozen/freeze' || $path === '/v1/passes/' . Api::SERIAL . '/frozen') {
                return Api::json(409, Api::error('LOCATION_FROZEN', 409, 'Moda şubesi dondurulmuş; kasa işlemleri kapalı.'));
            }
            if ($path === '/v1/passes/' . Api::SERIAL . '/paused') {
                return Api::json(409, Api::error('BUSINESS_FROZEN', 409, 'İşletme şu an duraklatıldı.'));
            }
            if (str_contains($path, '/qr.png')) {
                return Api::raw(200, "\x89PNG\r\n\x1a\nbytes", ['content-type' => 'image/png']);
            }
            if (str_ends_with($path, '/qr/sheet.pdf') || str_contains($path, '/qr/sheet.pdf?')) {
                return Api::raw(200, '%PDF-1.7 bytes', ['content-type' => 'application/pdf']);
            }
            if (str_ends_with($path, '/qr.svg')) {
                return Api::raw(200, '<svg xmlns="http://www.w3.org/2000/svg"></svg>', ['content-type' => 'image/svg+xml']);
            }
            if (str_ends_with($path, '/copy')) {
                return Api::json(422, Api::error('NOT_AN_INSTRUMENT', 422, 'Yalnız hediye kartı, kupon ve indirim kartının kopyası oluşturulur'));
            }
            if (str_starts_with($path, '/v1/public/branches/')) {
                return Api::json(200, ['data' => ['code' => 'MODA42', 'url' => 'https://rewloy.com/s/MODA42', 'business' => ['name' => 'Kahve', 'slug' => 'kahve'], 'branch' => ['name' => 'Moda', 'address' => null, 'state' => 'live', 'reopensOn' => null, 'publicNote' => null], 'featured' => null, 'items' => [], 'otherBranches' => [], 'test' => false]]);
            }
            if ($path === '/v1/earn-groups' && $req->method === 'POST') {
                return Api::json(201, ['data' => ['id' => self::GROUP, 'name' => 'Kahveler', 'members' => [], 'lines30d' => 0, 'usedBy' => [], 'warnings' => [], 'createdAt' => '2026-10-07T09:00:00.000Z', 'updatedAt' => '2026-10-07T09:00:00.000Z']]);
            }
            return Api::json(200, ['data' => ['ok' => true]]);
        });
    }

    private function client(): Client
    {
        return new Client(apiKey: Api::KEY, baseUrl: 'https://api.test', transport: $this->stub);
    }

    public function testKnowsTheOperationsOfApi130AndNoneWasRemoved(): void
    {
        self::assertSame('1.3.0', Operations::API_VERSION);
        self::assertCount(298, Operations::ALL);
        foreach (['previewSale', 'previewEarn', 'copyProgram', 'extendProgramCards', 'updateBatch', 'listEarnGroups', 'createEarnGroup', 'getEarnGroup', 'updateEarnGroup',
            'deleteEarnGroup', 'listSeenLines', 'listEarnSources', 'ignoreSeenLine', 'unignoreSeenLine', 'getEarnRules', 'putEarnRules', 'deleteEarnRules', 'createEarnRule',
            'updateEarnRule', 'deleteEarnRule', 'listEarnRuleRevisions', 'listEarnTemplates', 'freezeLocation', 'updateLocationFreeze', 'cancelLocationFreeze', 'unfreezeLocation',
            'listLocationFreezes', 'publicBranch', 'holderBranch', 'joinHolderBranch', 'locationQrSvg', 'locationQrPng', 'locationQrSheetPdf', 'locationQrSheetSvg',
            'getLocationQrItems', 'putLocationQrItems', 'addQrItems', 'previewLocationQr'] as $id) {
            self::assertArrayHasKey($id, Operations::ALL, $id);
        }
        // The 0.2.4 operations are all still here.
        foreach (['recordSale', 'reverseSale', 'listPassOperations', 'listAllBatches', 'rotateWebhookSecret', 'deleteWebhook'] as $id) {
            self::assertArrayHasKey($id, Operations::ALL, $id);
        }
        self::assertTrue(Operations::ALL['listEarnRuleRevisions']['paged'] ?? false);
        self::assertTrue(Operations::ALL['listSeenLines']['paged'] ?? false);
        self::assertSame(['staff'], (Operations::ALL['freezeLocation']['auth'] ?? null), 'a freeze starts from a team session only');
        self::assertSame(['key', 'staff'], (Operations::ALL['unfreezeLocation']['auth'] ?? null));
        self::assertSame(['holder'], (Operations::ALL['holderBranch']['auth'] ?? null));
        self::assertSame('blob', (Operations::ALL['locationQrPng']['response'] ?? null));
    }

    public function testSendsReceiptLinesWithASaleAndReadsTheExplanation(): void
    {
        $lines = [
            ['lineId' => 'l1', 'name' => 'Latte', 'category' => ['Kahve', 'Sıcak'], 'quantity' => 2, 'unitPriceMinor' => 9000, 'totalMinor' => 18000],
            ['lineId' => 'l2', 'name' => 'Su', 'category' => 'Su', 'quantity' => '1.500', 'unit' => 'l', 'unitPriceMinor' => 1333, 'kind' => 'item', 'tags' => ['paketli']],
        ];

        $sale = $this->client()->recordSale([
            'params' => ['serial' => Api::SERIAL],
            'body' => ['locationId' => Api::LOCATION, 'amountMinor' => 20000, 'lines' => $lines, 'receiptDiscountMinor' => 0],
            'idempotencyKey' => 'kasa3-z0187-fis0042',
        ]);

        self::assertSame(['locationId' => Api::LOCATION, 'amountMinor' => 20000, 'lines' => $lines, 'receiptDiscountMinor' => 0], json_decode($this->stub->last()->body ?? '', true));
        self::assertSame('kasa3-z0187-fis0042', $this->stub->last()->header('idempotency-key'));
        self::assertSame(2, $sale['credited']);
        $earn = $sale['earn'] ?? null;
        self::assertIsArray($earn);
        self::assertSame('rules', $earn['source']);
        self::assertSame(['earned', 'no_rule'], array_column($earn['lines'], 'status'));
        self::assertSame([self::GROUP], $earn['lines'][0]['groups'] ?? null);
        self::assertNull($earn['total']['receiptCap']);
    }

    public function testPreviewSaleTakesNoIdempotencyKeyAndWritesNothing(): void
    {
        $preview = $this->client()->previewSale(['params' => ['serial' => Api::SERIAL], 'body' => ['amountMinor' => 20000, 'lines' => [['name' => 'Latte', 'unitPriceMinor' => 20000]]]]);

        self::assertSame('POST', $this->stub->last()->method);
        self::assertStringEndsWith('/sale/preview', StubTransport::target($this->stub->last()));
        self::assertNull($this->stub->last()->header('idempotency-key'));
        $this->expectException(InvalidArgumentException::class);
        $this->client()->previewSale(['params' => ['serial' => Api::SERIAL], 'body' => ['amountMinor' => 1], 'idempotencyKey' => 'kasa3-z0187-fis0042']);
    }

    public function testRecordSaleStillNeedsItsIdempotencyKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // (PHPStan refuses the typed call too: idempotencyKey is a required key of recordSale's argument.)
        $this->client()->request('recordSale', ['params' => ['serial' => Api::SERIAL], 'body' => ['amountMinor' => 100, 'lines' => []]]);
    }

    public function testPreviewEarnJudgesLinesAgainstADraftRuleSetWithoutACard(): void
    {
        $body = [
            'amountMinor' => 20000,
            'lines' => [['lineId' => 'l1', 'name' => 'Latte', 'category' => 'Kahve', 'quantity' => 2, 'unitPriceMinor' => 9000]],
            'ruleSet' => ['rules' => [['kind' => 'stamp.perUnit', 'groupId' => self::GROUP, 'stamps' => 1]]],
            'context' => ['earnedToday' => 4],
        ];

        $preview = $this->client()->previewEarn(['params' => ['id' => self::PROGRAM], 'body' => $body]);

        self::assertSame(2, $preview['credited']);
        self::assertSame('stamps', $preview['unit']);
        self::assertSame($body, json_decode($this->stub->last()->body ?? '', true));
        self::assertSame('/v1/programs/' . self::PROGRAM . '/earn-rules/preview', StubTransport::target($this->stub->last()));
    }

    public function testRefundsSingleLinesWithAnOptionalKeyAndReadsWhatIsLeft(): void
    {
        $refund = $this->client()->reverseSale([
            'params' => ['serial' => Api::SERIAL],
            'body' => ['saleKey' => 'kasa3-z0187-fis0042', 'lines' => [['lineId' => 'l1', 'quantity' => 1], ['lineId' => 'l2', 'amountMinor' => 500]]],
            'idempotencyKey' => 'kasa3-z0187-iade1',
        ]);

        self::assertSame(1, $refund['reversed']);
        self::assertSame([['lineId' => 'l1', 'quantity' => 1, 'amountMinor' => 9000]], $refund['linesLeft'] ?? null);
        self::assertSame('rules', $refund['earn']['source'] ?? null);
        self::assertSame('kasa3-z0187-iade1', $this->stub->last()->header('idempotency-key'));
        // A refund of a whole sale needs no key (the library makes one only where the API takes one).
        $this->client()->reverseSale(['params' => ['serial' => Api::SERIAL], 'body' => ['saleKey' => 'kasa3-z0187-fis0042']]);
        self::assertNotNull($this->stub->last()->header('idempotency-key'));
    }

    public function testCreatesAnEarnGroup(): void
    {
        $group = $this->client()->createEarnGroup(['body' => ['name' => 'Kahveler', 'members' => [['effect' => 'include', 'match' => 'category', 'value' => 'Kahve', 'withChildren' => true]]]]);

        self::assertSame(self::GROUP, $group['id']);
        self::assertSame('/v1/earn-groups', StubTransport::target($this->stub->last()));
        $sent = json_decode($this->stub->last()->body ?? '', true);
        self::assertIsArray($sent);
        self::assertSame([['effect' => 'include', 'match' => 'category', 'value' => 'Kahve', 'withChildren' => true]], $sent['members'] ?? null);
    }

    public function testSurfacesTheRefusalsOfAFrozenBranchAndAPausedBusiness(): void
    {
        foreach ([['frozen', ErrorCode::LOCATION_FROZEN], ['paused', ErrorCode::BUSINESS_FROZEN]] as [$path, $code]) {
            try {
                $this->stub = new StubTransport(static fn (HttpRequest $r): HttpResponse => Api::json(409, Api::error($code, 409, $code)));
                $this->client()->recordSale(['params' => ['serial' => Api::SERIAL], 'body' => ['locationId' => Api::LOCATION, 'amountMinor' => 100], 'idempotencyKey' => 'kasa3-z0187-' . $path]);
                self::fail("accepted $path");
            } catch (RewloyException $e) {
                self::assertSame(409, $e->status);
                self::assertSame($code, $e->errorCode);
            }
        }
    }

    public function testCopyProgramRefusesALoyaltyCardWithNotAnInstrument(): void
    {
        try {
            $this->client()->copyProgram(['params' => ['id' => self::PROGRAM], 'body' => ['name' => 'Kopya']]);
            self::fail('accepted');
        } catch (RewloyException $e) {
            self::assertSame(422, $e->status);
            self::assertSame(ErrorCode::NOT_AN_INSTRUMENT, $e->errorCode);
        }
    }

    public function testHasTheErrorCodesOfApi130(): void
    {
        foreach (['LOCATION_FROZEN', 'BUSINESS_FROZEN', 'ALREADY_FROZEN', 'NOT_FROZEN', 'LOCATION_ARCHIVED', 'FREEZE_LIMIT', 'FREEZE_STARTED', 'NOT_AN_INSTRUMENT',
            'BRANCH_NOT_FOUND', 'BRANCH_GONE', 'ITEM_NOT_OFFERED', 'PROOF_REQUIRED', 'QR_LIST_CHANGED', 'QR_ITEM_INVALID', 'NOT_VALID_HERE', 'RULE_KIND_NOT_FOR_TYPE',
            'REVISION_CONFLICT', 'EARN_RULE_NOT_FOUND', 'EARN_RULES_NOT_FOUND', 'TOO_MANY_LINES', 'LINE_AMOUNT_INVALID', 'LINES_TOTAL_MISMATCH', 'LINE_NOT_FOUND',
            'LINE_ALREADY_REFUNDED', 'BILL_REQUIRED', 'SPEND_SHARE_EXCEEDED', 'BATCH_NOT_OPEN', 'BATCH_CAP_REQUIRED', 'BATCH_PER_PERSON_REQUIRED', 'CLAIM_AFTER_CARD_END',
            'CAPACITY_BELOW_CLAIMED', 'GROUP_IN_USE'] as $code) {
            self::assertSame($code, constant(ErrorCode::class . '::' . $code));
            self::assertContains($code, ErrorCode::ALL);
            self::assertNotSame('', ErrorCode::title($code), $code);
        }
    }

    public function testReadsThePublicBranchPageWithoutAnyCredential(): void
    {
        $client = new Client(baseUrl: 'https://api.test', transport: $this->stub);

        $page = $client->publicBranch(['params' => ['code' => 'MODA42']]);

        self::assertSame('MODA42', $page['code']);
        self::assertSame('live', $page['branch']['state']);
        self::assertSame([], $page['items']);
        self::assertNull($this->stub->last()->header('authorization'));
        self::assertSame('/v1/public/branches/MODA42', StubTransport::target($this->stub->last()));
    }

    public function testDownloadsTheBranchQrAsBytes(): void
    {
        $png = $this->client()->locationQrPng(['params' => ['id' => Api::LOCATION], 'query' => ['size' => 1024]]);
        self::assertStringStartsWith("\x89PNG", $png);
        self::assertSame('/v1/locations/' . Api::LOCATION . '/qr.png?size=1024', StubTransport::target($this->stub->last()));

        self::assertStringStartsWith('<svg', $this->client()->locationQrSvg(['params' => ['id' => Api::LOCATION]]));
        $pdf = $this->client()->locationQrSheetPdf(['params' => ['id' => Api::LOCATION], 'query' => ['form' => 'a6']]);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame('/v1/locations/' . Api::LOCATION . '/qr/sheet.pdf?form=a6', StubTransport::target($this->stub->last()));
    }

    public function testFreezeTakesATeamSessionAndItsPassword(): void
    {
        $staff = new Client(staffSession: Api::STAFF, merchant: Api::MERCHANT, baseUrl: 'https://api.test', transport: $this->stub);

        $staff->freezeLocation(['params' => ['id' => Api::LOCATION], 'body' => ['reason' => 'renovation', 'reopensOn' => '2026-11-01', 'password' => 'sifre']]);

        self::assertSame('Bearer ' . Api::STAFF, $this->stub->last()->header('authorization'));
        self::assertSame('POST', $this->stub->last()->method);
        self::assertSame(['reason' => 'renovation', 'reopensOn' => '2026-11-01', 'password' => 'sifre'], json_decode($this->stub->last()->body ?? '', true));
    }
}

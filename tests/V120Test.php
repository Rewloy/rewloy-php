<?php

declare(strict_types=1);

namespace Rewloy\Tests;

use PHPUnit\Framework\TestCase;
use Rewloy\Client;
use Rewloy\Exception\RewloyException;
use Rewloy\Generated\ErrorCode;
use Rewloy\Generated\Operations;
use Rewloy\Http\HttpRequest;
use Rewloy\Http\HttpResponse;
use Rewloy\Tests\Support\Api;
use Rewloy\Tests\Support\StubTransport;

/** The operations and fields Rewloy API 1.2.0 added. */
final class V120Test extends TestCase
{
    private const WEBHOOK = '0192f7c1-0000-7000-8000-0000000000aa';

    /**
     * A webhook object as 1.2.0 answers: pausedUntil and resumableUntil are always present.
     *
     * @return array<string, mixed>
     */
    private static function webhookRow(?string $pausedUntil, ?string $resumableUntil): array
    {
        return [
            'id' => self::WEBHOOK,
            'url' => 'https://ornek.com/rewloy/webhook',
            'events' => ['pass.activity'],
            'status' => 'active',
            'failures' => 0,
            'disabledReason' => null,
            'createdAt' => '2026-10-06T09:00:00.000Z',
            'week' => ['delivered' => 3, 'failed' => 0, 'pending' => 1],
            'lastDelivered' => '2026-10-06T09:30:00.000Z',
            'createdByKey' => null,
            'pausedUntil' => $pausedUntil,
            'resumableUntil' => $resumableUntil,
        ];
    }

    private StubTransport $stub;

    protected function setUp(): void
    {
        $this->stub = new StubTransport(static function (HttpRequest $req): HttpResponse {
            $path = StubTransport::target($req);
            if (str_starts_with($path, '/v1/passes/' . Api::SERIAL . '/operations')) {
                return Api::json(200, [
                    'data' => [['id' => 'o1', 'kind' => 'earn', 'delta' => 1, 'unit' => 'stamp', 'saleKey' => 'kasa3-z0187-fis0042', 'undoWith' => 'sale/reverse', 'reversible' => true, 'byCaller' => true]],
                    'meta' => ['page' => 1, 'pageSize' => 50, 'total' => 1],
                ]);
            }
            if (preg_match('#^/v1/batches/(b-[a-z]+)/send$#', $path, $m) === 1) {
                $refusals = ['b-closed' => [410, 'BATCH_CLOSED'], 'b-expired' => [410, 'BATCH_EXPIRED'], 'b-full' => [410, 'BATCH_FULL'], 'b-archived' => [409, 'PROGRAM_ARCHIVED']];
                [$status, $code] = $refusals[$m[1]] ?? [500, 'INTERNAL'];
                return Api::json($status, Api::error($code, $status, $code));
            }
            if ($path === '/v1/developers/webhooks' && $req->method === 'GET') {
                return Api::json(200, ['data' => [self::webhookRow('2026-10-06T10:01:00.000Z', null), self::webhookRow(null, '2026-10-07T09:45:00.000Z')]]);
            }
            if ($path === '/v1/developers/webhooks/' . self::WEBHOOK && $req->method === 'PATCH') {
                return Api::json(200, ['data' => self::webhookRow(null, null)]);
            }
            if (str_starts_with($path, '/v1/batches')) {
                return Api::json(200, ['data' => [['id' => 'b1', 'status' => 'open', 'state' => 'archived']], 'meta' => ['page' => 1, 'pageSize' => 50, 'total' => 1]]);
            }
            if ($path === '/v1/developers/webhooks/' . self::WEBHOOK . '/rotate-secret') {
                return Api::json(200, ['data' => ['secret' => 'whsec_new', 'previousValidUntil' => '2026-10-07T10:00:00.000Z']]);
            }
            if ($path === '/v1/developers/webhooks/' . self::WEBHOOK && $req->method === 'DELETE') {
                return Api::raw(204, '');
            }
            if ($path === '/v1/developers/keys') {
                return Api::json(201, ['data' => ['token' => 'rwk_x_y', 'baseUrl' => 'https://app.rewloy.com']]);
            }
            if ($path === '/v1/test/environment/reset') {
                return Api::json(200, ['data' => ['keysRevoked' => true]]);
            }
            if ($path === '/v1/programs/p1/batches') {
                return Api::json(409, Api::error('PROGRAM_ARCHIVED', 409, 'Program arşivde'));
            }
            if (str_ends_with($path, '/sale')) {
                return Api::json(200, ['data' => ['type' => 'stamp', 'applied' => 'stamps', 'credited' => 1, 'balance' => 3, 'duplicate' => true, 'reversed' => true, 'rewardReady' => false, 'rewardsReady' => 0, 'card' => null]]);
            }
            return Api::json(200, ['data' => ['ok' => true]]);
        });
    }

    private function client(): Client
    {
        return new Client(apiKey: Api::KEY, baseUrl: 'https://api.test', transport: $this->stub);
    }

    public function testKnowsTheNewOperations(): void
    {
        foreach (['listPassOperations', 'listAllBatches', 'rotateWebhookSecret', 'deleteWebhook'] as $id) {
            self::assertArrayHasKey($id, Operations::ALL, $id);
        }
        self::assertTrue(Operations::ALL['listPassOperations']['paged'] ?? false);
        self::assertTrue(Operations::ALL['listAllBatches']['paged'] ?? false);
    }

    public function testListsACardsOperationsAndPagesThem(): void
    {
        $page = $this->client()->listPassOperations(['params' => ['serial' => Api::SERIAL], 'query' => ['limit' => 10]]);
        $first = reset($page['data']);
        self::assertIsArray($first);
        self::assertSame('sale/reverse', $first['undoWith']);
        self::assertStringEndsWith('/operations?limit=10', StubTransport::target($this->stub->last()));
        $ids = [];
        foreach ($this->client()->paginate('listPassOperations', ['params' => ['serial' => Api::SERIAL]]) as $op) {
            $ids[] = $op['id'] ?? null;
        }
        self::assertSame(['o1'], $ids);
    }

    public function testListsEveryBatchWithTheArchivedState(): void
    {
        $page = $this->client()->listAllBatches(['query' => ['status' => 'archived']]);
        $first = reset($page['data']);
        self::assertIsArray($first);
        self::assertSame('archived', $first['state']);
        self::assertSame('/v1/batches?status=archived', StubTransport::target($this->stub->last()));
    }

    public function testRotatesAndDeletesAWebhook(): void
    {
        $r = $this->client()->rotateWebhookSecret(['params' => ['id' => self::WEBHOOK]]);
        self::assertSame('whsec_new', $r['secret']);
        self::assertSame('POST', $this->stub->last()->method);
        $this->client()->deleteWebhook(['params' => ['id' => self::WEBHOOK]]);
        self::assertSame('DELETE', $this->stub->last()->method);
    }

    public function testCreatesAPosKeyAndResetsTheTestEnvironmentWithRevokeKeys(): void
    {
        $this->client()->createApiKey(['body' => ['kind' => 'pos', 'locationId' => Api::LOCATION, 'register' => 'Kasa 1', 'password' => 'x']]);
        self::assertSame(['kind' => 'pos', 'locationId' => Api::LOCATION, 'register' => 'Kasa 1', 'password' => 'x'], json_decode($this->stub->last()->body ?? '', true));
        $this->client()->resetTestEnvironment(['body' => ['revokeKeys' => true]]);
        self::assertSame(['revokeKeys' => true], json_decode($this->stub->last()->body ?? '', true));
    }

    public function testReadsCardAndReversedOnAReplayedSaleAndCardMayBeNull(): void
    {
        $sale = $this->client()->recordSale(['params' => ['serial' => Api::SERIAL], 'body' => ['locationId' => Api::LOCATION, 'amountMinor' => 100], 'idempotencyKey' => 'kasa3-z0187-fis0042']);
        self::assertTrue($sale['reversed']);
        self::assertNull($sale['card']);
    }

    public function testSurfacesProgramArchived(): void
    {
        try {
            $this->client()->createBatch(['params' => ['id' => 'p1'], 'body' => []]);
            self::fail('accepted');
        } catch (RewloyException $e) {
            self::assertSame(409, $e->status);
            self::assertSame(ErrorCode::PROGRAM_ARCHIVED, $e->errorCode);
        }
    }

    public function testReadsTheWebhookStateFieldsADateTimeOrNull(): void
    {
        $rows = $this->client()->listWebhooks();
        $paused = $rows[0] ?? null;
        $resumable = $rows[1] ?? null;
        self::assertIsArray($paused);
        self::assertIsArray($resumable);
        self::assertSame('2026-10-06T10:01:00.000Z', $paused['pausedUntil']);
        self::assertNull($paused['resumableUntil']);
        self::assertNull($resumable['pausedUntil']);
        self::assertSame('2026-10-07T09:45:00.000Z', $resumable['resumableUntil']);

        $turnedOn = $this->client()->setWebhookStatus(['params' => ['id' => self::WEBHOOK], 'body' => ['active' => true]]);
        self::assertNull($turnedOn['pausedUntil']);
        self::assertNull($turnedOn['resumableUntil']);
        self::assertSame('PATCH', $this->stub->last()->method);
    }

    public function testSurfacesWhatSendBatchLinkRefuses(): void
    {
        $expected = [
            'b-closed' => [410, ErrorCode::BATCH_CLOSED],
            'b-expired' => [410, ErrorCode::BATCH_EXPIRED],
            'b-full' => [410, ErrorCode::BATCH_FULL],
            'b-archived' => [409, ErrorCode::PROGRAM_ARCHIVED],
        ];
        foreach ($expected as $batch => [$status, $code]) {
            try {
                $this->client()->sendBatchLink(['params' => ['id' => $batch], 'body' => ['email' => 'ali@ornek.com']]);
                self::fail("accepted $batch");
            } catch (RewloyException $e) {
                self::assertSame($status, $e->status, $code);
                self::assertSame($code, $e->errorCode);
            }
        }
    }
}

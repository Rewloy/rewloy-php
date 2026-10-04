<?php

declare(strict_types=1);

namespace Rewloy\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rewloy\Client;
use Rewloy\Generated\Operations;
use Rewloy\Http\CurlTransport;
use Rewloy\Http\HttpRequest;
use Rewloy\Http\HttpResponse;
use Rewloy\Response;
use Rewloy\ServerSentEvent;
use Rewloy\Tests\Support\Api;
use Rewloy\Tests\Support\StubTransport;

final class ClientTest extends TestCase
{
    private StubTransport $stub;

    protected function setUp(): void
    {
        $this->stub = new StubTransport(static function (HttpRequest $req): HttpResponse {
            $path = StubTransport::target($req);
            if (str_starts_with($path, '/v1/customers')) {
                return Api::json(200, ['data' => [['personId' => 'p1']], 'meta' => ['page' => 1, 'pageSize' => 50, 'total' => 1]]);
            }
            if (str_starts_with($path, '/v1/developers/keys/')) {
                return Api::raw(204, '', ['x-request-id' => 'r-204']);
            }
            if (str_ends_with($path, '/map.png')) {
                return Api::raw(200, "\x89PNG", ['content-type' => 'image/png']);
            }
            if ($path === '/v1/openapi.json') {
                return Api::json(200, ['openapi' => '3.1.0', 'paths' => []]);
            }
            if ($path === '/v1/campaigns' && $req->method === 'POST') {
                return Api::json(201, ['data' => ['id' => 'c1']], ['idempotent-replayed' => 'true', 'rewloy-mode' => 'test', 'x-request-id' => 'r-campaign']);
            }
            return Api::json(200, ['data' => ['ok' => true]]);
        });
    }

    /** A client of the stub, with this credential (by kind) or none. */
    private function client(?string $kind = null): Client
    {
        return match ($kind) {
            'key' => new Client(apiKey: Api::KEY, baseUrl: 'https://api.test', transport: $this->stub),
            'staff' => new Client(staffSession: Api::STAFF, baseUrl: 'https://api.test', transport: $this->stub),
            'holder' => new Client(holderSession: Api::HOLDER, baseUrl: 'https://api.test', transport: $this->stub),
            default => new Client(baseUrl: 'https://api.test', transport: $this->stub),
        };
    }

    public function testTakesOneCredentialOfTheRightKind(): void
    {
        self::assertSame('key', (new Client(apiKey: Api::KEY))->credential);
        self::assertSame(Api::MERCHANT, (new Client(staffSession: Api::STAFF, merchant: Api::MERCHANT))->merchant);
        self::assertSame('staff', (new Client(staffSession: Api::STAFF))->credential);
        self::assertSame('holder', (new Client(holderSession: Api::HOLDER))->credential);
        self::assertNull((new Client())->credential);
        self::assertSame('key', (new Client(Api::KEY))->credential, 'the first argument is the API key');

        self::refused('apiKey must start with "rwk_"', static fn () => new Client(apiKey: Api::STAFF));
        self::refused('staffSession must start with "rws_"', static fn () => new Client(staffSession: Api::KEY));
        self::refused('holderSession must start with "rwh_"', static fn () => new Client(holderSession: 'abc'));
        self::refused('give one credential, not apiKey and holderSession', static fn () => new Client(apiKey: Api::KEY, holderSession: Api::HOLDER));
        self::refused('`merchant` goes with a staffSession', static fn () => new Client(apiKey: Api::KEY, merchant: Api::MERCHANT));
        self::refused('`timeout` must be 0 (none) or more seconds', static fn () => new Client(timeout: -1));
        self::refused('`maxRetries` must be 0 or more', static fn () => new Client(maxRetries: -1));
    }

    /** The last request the stub received carried this header with this value (null: not at all). */
    private function assertSent(string $header, ?string $value): void
    {
        self::assertSame($value, $this->stub->last()->header($header), $header);
    }

    /** The call is refused as a programming error, with this in its message. */
    private static function refused(string $message, callable $call): void
    {
        try {
            $call();
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
            return;
        }
        self::fail('accepted: ' . $message);
    }

    public function testHasDefaults(): void
    {
        $c = new Client(apiKey: Api::KEY);
        self::assertSame('https://app.rewloy.com', $c->baseUrl);
        self::assertSame(60.0, $c->timeout);
        self::assertSame(2, $c->maxRetries);
        self::assertSame('http://localhost:3000', (new Client(baseUrl: 'http://localhost:3000/'))->baseUrl);
        self::assertSame(2.5, (new Client(timeout: 2.5))->timeout);
    }

    public function testKeepsTheCredentialOutOfDumps(): void
    {
        $c = new Client(apiKey: Api::KEY);
        self::assertStringNotContainsString(Api::KEY, print_r($c, true));
        ob_start();
        var_dump($c);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString(Api::KEY, $dump);
        self::assertStringContainsString(CurlTransport::class, $dump);
    }

    public function testSaysTheSameVersionAsTheChangelog(): void
    {
        $changelog = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
        if (preg_match('/^## (\d+\.\d+\.\d+)/m', $changelog, $m) !== 1) {
            self::fail('CHANGELOG.md has no release heading');
        }
        self::assertSame($m[1], Client::VERSION);
    }

    public function testHasAMethodForEveryOperation(): void
    {
        self::assertGreaterThan(200, count(Operations::ALL));
        foreach (array_keys(Operations::ALL) as $id) {
            self::assertTrue(method_exists(Client::class, $id), $id);
        }
    }

    public function testSendsTheApiKeyTheClientAndNoMerchant(): void
    {
        $c = new Client(apiKey: Api::KEY, baseUrl: 'https://api.test', transport: $this->stub, userAgent: 'KasaPOS/4.2');
        self::assertSame(['ok' => true], $c->getPass(['params' => ['serial' => Api::SERIAL]]));
        $r = $this->stub->last();
        self::assertSame('GET', $r->method);
        self::assertSame('https://api.test/v1/passes/' . Api::SERIAL, $r->url);
        self::assertSame('Bearer ' . Api::KEY, $r->header('authorization'));
        self::assertSame('rewloy-php/' . Client::VERSION . ' PHP/' . PHP_VERSION . ' KasaPOS/4.2', $r->header('user-agent'));
        self::assertSame('application/json', $r->header('accept'));
        self::assertNull($r->header('rewloy-merchant'));
        self::assertNull($r->header('content-type'));
        self::assertNull($r->header('idempotency-key'));
        self::assertNull($r->body);
        self::assertSame(60.0, $r->timeout);
        self::assertSame(0.0, $r->idleTimeout);
    }

    public function testSendsAStaffSessionWithItsMerchantOverridablePerCall(): void
    {
        $c = new Client(staffSession: Api::STAFF, merchant: Api::MERCHANT, baseUrl: 'https://api.test', transport: $this->stub);
        $c->listPrograms();
        $this->assertSent('authorization', 'Bearer ' . Api::STAFF);
        $this->assertSent('rewloy-merchant', Api::MERCHANT);
        $c->listPrograms(['merchant' => 'other-merchant']);
        $this->assertSent('rewloy-merchant', 'other-merchant');
        // An operation without the header never gets it.
        $c->login(['body' => ['email' => 'a@b.co', 'password' => 'x']]);
        $this->assertSent('rewloy-merchant', null);
    }

    public function testSendsAHolderSession(): void
    {
        $this->client('holder')->holderCards(['query' => ['merchant' => 'kahve-dukkani']]);
        $this->assertSent('authorization', 'Bearer ' . Api::HOLDER);
        self::assertSame('/v1/holder/cards?merchant=kahve-dukkani', StubTransport::target($this->stub->last()));
    }

    public function testCallsAnOperationThatDoesNotTakeThisCredentialButWorksWithoutOneWithoutIt(): void
    {
        $key = $this->client('key');
        $key->login(['body' => ['email' => 'a@b.co', 'password' => 'x']]);
        $this->assertSent('authorization', null);
        $key->holderProviderNonce(['body' => ['provider' => 'google']]);
        $this->assertSent('authorization', null);
        $holder = $this->client('holder');
        $holder->holderProviderNonce(['body' => ['provider' => 'google']]);
        $this->assertSent('authorization', 'Bearer ' . Api::HOLDER);
        $holder->publicProgram(['params' => ['id' => Api::LOCATION]]);
        $this->assertSent('authorization', 'Bearer ' . Api::HOLDER);
        // Not public: the credential goes, and the API answers whether it may.
        $key->holderCards();
        $this->assertSent('authorization', 'Bearer ' . Api::KEY);
        $this->client()->holderCards();
        $this->assertSent('authorization', null);
    }

    public function testSendsJsonBodiesAndAnEmptyObjectWhenAnAllOptionalBodyIsLeftOut(): void
    {
        $c = $this->client('key');
        $c->issuePass(['body' => ['programId' => Api::LOCATION, 'email' => 'ayse@example.com', 'firstName' => 'Ayşe', 'kvkkConsent' => true]]);
        self::assertSame('POST', $this->stub->last()->method);
        self::assertSame('application/json', $this->stub->last()->header('content-type'));
        self::assertSame('{"programId":"' . Api::LOCATION . '","email":"ayse@example.com","firstName":"Ayşe","kvkkConsent":true}', $this->stub->last()->body);
        $c->updateProgram(['params' => ['id' => Api::LOCATION]]);
        self::assertSame('PATCH', $this->stub->last()->method);
        self::assertSame('{}', $this->stub->last()->body);
        $c->updateProgram(['params' => ['id' => Api::LOCATION], 'body' => []]);
        self::assertSame('{}', $this->stub->last()->body, 'an empty PHP array is an empty object');
        $c->closeBatch(['params' => ['id' => Api::LOCATION]]);
        self::assertNull($this->stub->last()->body);
        self::assertNull($this->stub->last()->header('content-type'));
    }

    public function testGeneratesAnIdempotencyKeyWhenNoneIsGivenAndSendsTheGivenOne(): void
    {
        $c = $this->client('key');
        $c->passAction(['params' => ['serial' => Api::SERIAL], 'body' => ['action' => 'earn-stamps', 'locationId' => Api::LOCATION, 'count' => 2]]);
        self::assertMatchesRegularExpression(Api::UUID, (string) $this->stub->last()->header('idempotency-key'));
        $c->passAction(['params' => ['serial' => Api::SERIAL], 'body' => ['action' => 'earn-stamps', 'locationId' => Api::LOCATION], 'idempotencyKey' => 'fis-000123']);
        self::assertSame('fis-000123', $this->stub->last()->header('idempotency-key'));
    }

    public function testEncodesPathParametersAndTheQuery(): void
    {
        $c = $this->client('key');
        $c->getPass(['params' => ['serial' => 'AB/CD EF']]);
        self::assertSame('/v1/passes/AB%2FCD%20EF', StubTransport::target($this->stub->last()));
        $c->listCustomers(['query' => ['q' => 'Ayşe Yılmaz', 'blocked' => false, 'page' => 2, 'limit' => 10, 'status' => null]]);
        self::assertSame('/v1/customers?q=Ay%C5%9Fe%20Y%C4%B1lmaz&blocked=false&page=2&limit=10', StubTransport::target($this->stub->last()));
        self::refused('Rewloy: getPass needs params.serial', static fn () => $c->request('getPass', ['params' => []]));
        self::refused('Rewloy: getPass needs params.serial', static fn () => $c->request('getPass', ['params' => ['serial' => '']]));
    }

    public function testReadsEachKindOfAnswer(): void
    {
        $c = $this->client('staff');
        self::assertSame(['data' => [['personId' => 'p1']], 'meta' => ['page' => 1, 'pageSize' => 50, 'total' => 1]], $c->listCustomers());
        $c->revokeApiKey(['params' => ['id' => Api::LOCATION]]);
        self::assertSame('DELETE', $this->stub->last()->method);
        self::assertSame("\x89PNG", $c->locationMap(['params' => ['id' => Api::LOCATION]]));
        self::assertSame('*/*', $this->stub->last()->header('accept'));
        self::assertSame(['openapi' => '3.1.0', 'paths' => []], $c->openapi());
    }

    public function testGivesTheWholeAnswerThroughRequest(): void
    {
        $c = $this->client('key');
        $res = $c->request('sendCampaign', ['body' => ['body' => 'Bu hafta kahveler 2 damga!']]);
        self::assertInstanceOf(Response::class, $res);
        self::assertSame(201, $res->status);
        self::assertSame(['id' => 'c1'], $res->data);
        self::assertNull($res->meta);
        self::assertSame('r-campaign', $res->requestId);
        self::assertSame('test', $res->mode);
        self::assertTrue($res->replayed);
        self::assertSame('application/json; charset=utf-8', $res->header('Content-Type'));
        $list = $c->request('listCustomers');
        self::assertSame(['page' => 1, 'pageSize' => 50, 'total' => 1], $list->meta);
        self::assertSame([['personId' => 'p1']], $list->data);
        self::assertNull($list->mode);
        self::assertFalse($list->replayed);
        $none = $this->client('staff')->request('revokeApiKey', ['params' => ['id' => Api::LOCATION]]);
        self::assertSame(204, $none->status);
        self::assertNull($none->data);
        self::assertSame('r-204', $none->requestId);
    }

    public function testOpensStreamsThroughTheirMethodsNotRequest(): void
    {
        $c = $this->client('key');
        $stream = $c->liveFeed(['reconnect' => false]);
        self::assertInstanceOf(\Generator::class, $stream);
        self::assertSame([], $this->stub->requests, 'nothing is sent before the loop asks');

        self::refused("liveFeed is a stream; use stream('liveFeed')", static fn () => $c->request('liveFeed'));
        self::refused('getPass is not a paged list', static function () use ($c): void {
            foreach ($c->paginate('getPass') as $item) {
                self::fail('a page of getPass');
            }
        });
        self::refused("getPass is not a stream; use request('getPass')", static function () use ($c): void {
            foreach ($c->stream('getPass') as $event) {
                self::fail('an event of getPass');
            }
        });
        self::refused('unknown operation "noSuchOperation"', static fn () => $c->request('noSuchOperation'));
    }

    public function testRefusesAnArgumentTheOperationDoesNotTake(): void
    {
        $c = $this->client('key');
        $refused = [
            'passAction does not take `idempotency_key`; it takes params, body, idempotencyKey, merchant, timeout, maxRetries'
                => ['passAction', ['params' => ['serial' => Api::SERIAL], 'idempotency_key' => 'x']],
            'getPass does not take `query`' => ['getPass', ['params' => ['serial' => Api::SERIAL], 'query' => ['x' => 1]]],
            'login does not take `merchant`' => ['login', ['merchant' => Api::MERCHANT]],
            'listPrograms does not take `reconnect`' => ['listPrograms', ['reconnect' => false]],
            'listPrograms: `timeout` must be 0 (none) or more seconds' => ['listPrograms', ['timeout' => '5']],
            'listPrograms: `maxRetries` must be an integer, 0 or more' => ['listPrograms', ['maxRetries' => -1]],
            'getPass: params.serial must be a string, a number or a boolean' => ['getPass', ['params' => ['serial' => ['x']]]],
        ];
        foreach ($refused as $message => [$id, $args]) {
            self::refused($message, static fn () => $c->request($id, $args));
        }
        self::assertSame([], $this->stub->requests);
    }

    public function testPassesTimeoutsToTheTransport(): void
    {
        $c = new Client(apiKey: Api::KEY, baseUrl: 'https://api.test', transport: $this->stub, timeout: 5);
        $c->listPrograms();
        self::assertSame(5.0, $this->stub->last()->timeout);
        $c->listPrograms(['timeout' => 0.5]);
        self::assertSame(0.5, $this->stub->last()->timeout);
    }

    public function testGivesStreamEventsAsObjects(): void
    {
        $event = new ServerSentEvent('event', '{"kind":"earn","delta":2}');
        self::assertSame(['kind' => 'earn', 'delta' => 2], $event->json());
        self::assertSame('', $event->id);
    }
}

<?php

declare(strict_types=1);

namespace Rewloy\Tests;

use PHPUnit\Framework\TestCase;
use Rewloy\Client;
use Rewloy\Exception\ConnectionException;
use Rewloy\Exception\RateLimitException;
use Rewloy\Exception\RewloyException;
use Rewloy\Generated\ErrorCode;
use Rewloy\Http\HttpRequest;
use Rewloy\Http\HttpResponse;
use Rewloy\Http\TransportException;
use Rewloy\Tests\Support\Api;
use Rewloy\Tests\Support\StubTransport;
use RuntimeException;
use Throwable;

final class ErrorTest extends TestCase
{
    /** @var \Closure(): (HttpResponse|Throwable) */
    private \Closure $answer;

    private Client $client;

    protected function setUp(): void
    {
        $this->answer = static fn (): HttpResponse => Api::json(200, ['data' => []]);
        $stub = new StubTransport(fn (HttpRequest $r): HttpResponse|Throwable => $this->respond());
        $this->client = new Client(apiKey: Api::KEY, baseUrl: 'https://api.test', transport: $stub, maxRetries: 0);
    }

    private function respond(): HttpResponse|Throwable
    {
        return ($this->answer)();
    }

    /** @phpstan-impure */
    private static function caught(callable $call): RewloyException
    {
        try {
            $call();
        } catch (RewloyException $e) {
            return $e;
        }
        self::fail('did not throw');
    }

    public function testMapsAnApiErrorBody(): void
    {
        $this->answer = static fn (): HttpResponse => Api::json(409, Api::error('INSUFFICIENT_BALANCE', 409, 'bakiye yetersiz: 40,00 ₺ var'), ['x-request-id' => '0192f7c1-8b2e-7a31-9c1d-000000000009']);
        $c = $this->client;
        $e = self::caught(static fn () => $c->passAction(['params' => ['serial' => Api::SERIAL], 'body' => ['action' => 'spend', 'locationId' => Api::LOCATION, 'amountMinor' => 5000], 'idempotencyKey' => 'fis-000123']));
        self::assertSame(RewloyException::class, $e::class);
        self::assertInstanceOf(RuntimeException::class, $e);
        self::assertSame(409, $e->status);
        self::assertSame(409, $e->getCode(), 'getCode() is the HTTP status');
        self::assertSame('INSUFFICIENT_BALANCE', $e->errorCode);
        self::assertSame(ErrorCode::INSUFFICIENT_BALANCE, $e->errorCode);
        self::assertSame('Bakiye yetersiz', $e->title);
        self::assertSame(ErrorCode::TITLES['INSUFFICIENT_BALANCE'], $e->title);
        self::assertSame('bakiye yetersiz: 40,00 ₺ var', $e->detail);
        self::assertSame('https://rewloy.com/gelistiriciler/hatalar#INSUFFICIENT_BALANCE', $e->docs);
        self::assertSame('0192f7c1-8b2e-7a31-9c1d-000000000009', $e->requestId, 'the header wins over the body');
        self::assertSame('passAction', $e->operation);
        self::assertSame(Api::error('INSUFFICIENT_BALANCE', 409, 'bakiye yetersiz: 40,00 ₺ var'), $e->body);
        self::assertSame('application/json; charset=utf-8', $e->header('Content-Type'));
        self::assertSame('409 INSUFFICIENT_BALANCE: bakiye yetersiz: 40,00 ₺ var (passAction, requestId 0192f7c1-8b2e-7a31-9c1d-000000000009)', $e->getMessage());
    }

    public function testKeepsTheValidationDetails(): void
    {
        $details = [['field' => 'body', 'rule' => 'maxLength', 'message' => 'en fazla 180 karakter olmalı']];
        $this->answer = static fn (): HttpResponse => Api::json(400, Api::error('VALIDATION', 400, 'Gönderilen bilgiler geçersiz (gövde): body en fazla 180 karakter olmalı', $details));
        $c = $this->client;
        $e = self::caught(static fn () => $c->sendCampaign(['body' => ['body' => str_repeat('x', 200)], 'idempotencyKey' => 'kampanya-0001']));
        self::assertSame('VALIDATION', $e->errorCode);
        self::assertSame($details, $e->details);
        self::assertSame('Gönderilen bilgiler geçersiz', $e->title);
    }

    public function testMakes429ARateLimitExceptionWithRetryAfterFromTheHeaderElseFromTheDetails(): void
    {
        $c = $this->client;
        $this->answer = static fn (): HttpResponse => Api::json(429, Api::error('RATE_LIMITED', 429, 'sınır', ['retryAfterSec' => 12]), ['retry-after' => '7']);
        $e = self::caught(static fn () => $c->listPrograms());
        self::assertInstanceOf(RateLimitException::class, $e);
        self::assertSame(7, $e->retryAfter);
        self::assertSame(429, $e->status);
        $this->answer = static fn (): HttpResponse => Api::json(429, Api::error('RATE_LIMITED', 429, 'çok fazla canlı bağlantı', ['retryAfterSec' => 12]));
        $e = self::caught(static fn () => $c->listPrograms());
        self::assertInstanceOf(RateLimitException::class, $e);
        self::assertSame(12, $e->retryAfter);
        $this->answer = static fn (): HttpResponse => Api::json(429, Api::error('RATE_LIMITED', 429, 'çok fazla canlı bağlantı'));
        $e = self::caught(static fn () => $c->listPrograms());
        self::assertInstanceOf(RateLimitException::class, $e);
        self::assertNull($e->retryAfter);
    }

    public function testNamesAnAnswerThatIsNotRewloysByItsStatus(): void
    {
        $this->answer = static fn (): HttpResponse => Api::raw(502, '<html><body>Bad gateway</body></html>', ['content-type' => 'text/html']);
        $c = $this->client;
        $e = self::caught(static fn () => $c->getPass(['params' => ['serial' => Api::SERIAL]]));
        self::assertSame(502, $e->status);
        self::assertSame('HTTP_502', $e->errorCode);
        self::assertSame('Bad Gateway', $e->detail);
        self::assertNull($e->title);
        self::assertNull($e->requestId);
        self::assertSame('<html><body>Bad gateway</body></html>', $e->body);
        $this->answer = static fn (): HttpResponse => new HttpResponse(599, [], '');
        $e = self::caught(static fn () => $c->getPass(['params' => ['serial' => Api::SERIAL]]));
        self::assertSame('HTTP 599', $e->detail);
        self::assertNull($e->body);
    }

    public function testRefusesA2xxAnswerThatIsNotTheDocumentedJson(): void
    {
        $c = $this->client;
        $this->answer = static fn (): HttpResponse => Api::raw(200, '<html>captive portal</html>', ['content-type' => 'text/html']);
        $e = self::caught(static fn () => $c->getPass(['params' => ['serial' => Api::SERIAL]]));
        self::assertSame('INVALID_RESPONSE', $e->errorCode);
        self::assertSame(200, $e->status);
        self::assertSame('the answer is not the JSON the API documents (text/html)', $e->detail);
        $this->answer = static fn (): HttpResponse => Api::json(200, ['serial' => Api::SERIAL]);
        $noEnvelope = self::caught(static fn () => $c->getPass(['params' => ['serial' => Api::SERIAL]]));
        self::assertSame('INVALID_RESPONSE', $noEnvelope->errorCode);
        $this->answer = static fn (): HttpResponse => Api::json(200, [['data' => 1]]);
        $aList = self::caught(static fn () => $c->getPass(['params' => ['serial' => Api::SERIAL]]));
        self::assertSame('INVALID_RESPONSE', $aList->errorCode);
    }

    public function testReportsAConnectionThatCannotBeMade(): void
    {
        $this->answer = static fn (): Throwable => new TransportException('Failed to connect to 127.0.0.1 port 9: Connection refused');
        $c = $this->client;
        $e = self::caught(static fn () => $c->getPass(['params' => ['serial' => Api::SERIAL]]));
        self::assertInstanceOf(ConnectionException::class, $e);
        self::assertSame(0, $e->status);
        self::assertSame('CONNECTION_ERROR', $e->errorCode);
        self::assertSame('getPass', $e->operation);
        self::assertSame('CONNECTION_ERROR: Failed to connect to 127.0.0.1 port 9: Connection refused (getPass)', $e->getMessage());
        self::assertInstanceOf(TransportException::class, $e->getPrevious());
    }

    public function testListsEveryCodeOfTheCatalogue(): void
    {
        self::assertContains('IDEMPOTENCY_IN_PROGRESS', ErrorCode::ALL);
        self::assertSame('Aynı istek hâlâ işleniyor', ErrorCode::title(ErrorCode::IDEMPOTENCY_IN_PROGRESS));
        self::assertNull(ErrorCode::title('NO_SUCH_CODE'));
        foreach (ErrorCode::ALL as $code) {
            self::assertSame($code, constant(ErrorCode::class . '::' . $code));
        }
    }
}

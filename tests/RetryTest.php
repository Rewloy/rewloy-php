<?php

declare(strict_types=1);

namespace Rewloy\Tests;

use PHPUnit\Framework\TestCase;
use Rewloy\Client;
use Rewloy\Exception\ConnectionException;
use Rewloy\Exception\RateLimitException;
use Rewloy\Exception\RewloyException;
use Rewloy\Exception\TimeoutException;
use Rewloy\Http\HttpRequest;
use Rewloy\Http\HttpResponse;
use Rewloy\Http\TransportException;
use Rewloy\Retry;
use Rewloy\Tests\Support\Api;
use Rewloy\Tests\Support\Sleeps;
use Rewloy\Tests\Support\StubTransport;
use Throwable;

final class RetryTest extends TestCase
{
    private const ACTION = ['params' => ['serial' => Api::SERIAL], 'body' => ['action' => 'earn-stamps', 'locationId' => Api::LOCATION]];

    private Sleeps $sleeps;

    protected function setUp(): void
    {
        $this->sleeps = new Sleeps();
    }

    /**
     * @param \Closure(HttpRequest, int): (HttpResponse|Throwable) $handler
     */
    private function stub(\Closure $handler): StubTransport
    {
        return new StubTransport($handler);
    }

    private function client(StubTransport $stub, int $maxRetries = 2): Client
    {
        return new Client(apiKey: Api::KEY, baseUrl: 'https://api.test', transport: $stub, maxRetries: $maxRetries, sleep: $this->sleeps->sleeper());
    }

    /**
     * @param class-string<RewloyException> $class
     */
    private static function caught(callable $call, string $class = RewloyException::class): RewloyException
    {
        try {
            $call();
        } catch (RewloyException $e) {
            self::assertInstanceOf($class, $e);
            return $e;
        }
        self::fail('did not throw');
    }

    public function testBacksOffFromHalfASecondDoublingToEightWithJitterBetweenHalfAndAll(): void
    {
        self::assertSame(0.25, Retry::backoff(0, 0.0));
        self::assertSame(0.5, Retry::backoff(0, 1.0));
        self::assertSame(1.0, Retry::backoff(1, 1.0));
        self::assertSame(1.5, Retry::backoff(2, 0.5));
        self::assertSame(8.0, Retry::backoff(10, 1.0));
        self::assertSame(4.0, Retry::backoff(10, 0.0));
        for ($i = 0; $i < 50; $i++) {
            $wait = Retry::backoff(1);
            self::assertGreaterThanOrEqual(0.5, $wait);
            self::assertLessThanOrEqual(1.0, $wait);
        }
    }

    public function testReadsRetryAfterAsSecondsOrAnHttpDate(): void
    {
        self::assertSame(2.0, Retry::parseRetryAfter('2'));
        self::assertSame(1.5, Retry::parseRetryAfter(' 1.5 '));
        $now = (int) strtotime('2026-10-03T12:00:00Z');
        self::assertSame(3.0, Retry::parseRetryAfter('Sat, 03 Oct 2026 12:00:03 GMT', $now));
        self::assertSame(0.0, Retry::parseRetryAfter('Sat, 03 Oct 2026 11:59:00 GMT', $now));
        self::assertNull(Retry::parseRetryAfter(null));
        self::assertNull(Retry::parseRetryAfter('soon'));
        self::assertNull(Retry::parseRetryAfter('tomorrow'));
    }

    public function testRetriesAGetOn502503504WithBackoffThenSucceeds(): void
    {
        $statuses = [503, 502, 200];
        $stub = $this->stub(static function (HttpRequest $r, int $n) use ($statuses): HttpResponse {
            $status = $statuses[$n - 1] ?? 200;
            return $status === 200 ? Api::json(200, ['data' => ['ok' => $n]]) : Api::json($status, Api::error('INTERNAL', 503, 'x'));
        });
        self::assertSame(['ok' => 3], $this->client($stub)->getPass(['params' => ['serial' => Api::SERIAL]]));
        self::assertCount(3, $stub->requests);
        self::assertCount(2, $this->sleeps->waits);
        $first = $this->sleeps->waits[0] ?? -1.0;
        $second = $this->sleeps->waits[1] ?? -1.0;
        self::assertTrue($first >= 0.25 && $first <= 0.5, 'first wait ' . $first);
        self::assertTrue($second >= 0.5 && $second <= 1.0, 'second wait ' . $second);
    }

    public function testRetriesCloudflaresOriginErrors(): void
    {
        foreach ([520, 521, 522, 523, 524] as $status) {
            $stub = $this->stub(static fn (HttpRequest $r, int $n): HttpResponse => $n === 1
                ? Api::raw($status, '<html>cloudflare</html>', ['content-type' => 'text/html'])
                : Api::json(200, ['data' => []]));
            self::assertSame([], $this->client($stub)->listPrograms());
            self::assertCount(2, $stub->requests, (string) $status);
        }
    }

    public function testGivesUpAfterMaxRetriesAndThrowsTheLastAnswer(): void
    {
        $stub = $this->stub(static fn (): HttpResponse => Api::json(504, Api::error('INTERNAL', 504, 'gateway')));
        $c = $this->client($stub);
        $e = self::caught(static fn () => $c->getPass(['params' => ['serial' => Api::SERIAL]]));
        self::assertSame(504, $e->status);
        self::assertCount(3, $stub->requests);
        self::assertCount(2, $this->sleeps->waits);
        self::caught(static fn () => $c->getPass(['params' => ['serial' => Api::SERIAL], 'maxRetries' => 0]));
        self::assertCount(4, $stub->requests);
        self::caught(fn () => $this->client($stub, 5)->getPass(['params' => ['serial' => Api::SERIAL]]));
        self::assertCount(10, $stub->requests);
    }

    public function testHonoursRetryAfterOn429(): void
    {
        $stub = $this->stub(static fn (HttpRequest $r, int $n): HttpResponse => $n === 1
            ? Api::json(429, Api::error('RATE_LIMITED', 429, 'Bu anahtarın dakikalık istek sınırı aşıldı', ['retryAfterSec' => 2]), ['retry-after' => '2'])
            : Api::json(200, ['data' => []]));
        self::assertSame([], $this->client($stub)->listPrograms());
        self::assertSame([2.0], $this->sleeps->waits);
    }

    public function testDoesNotWaitOutALongRetryAfterTheCallerGetsRateLimitException(): void
    {
        $stub = $this->stub(static fn (): HttpResponse => Api::json(429, Api::error('RATE_LIMITED', 429, 'Çok fazla hatalı kod.', ['retryAfterSec' => 900]), ['retry-after' => '900']));
        $c = $this->client($stub);
        $e = self::caught(static fn () => $c->listPrograms(), RateLimitException::class);
        self::assertInstanceOf(RateLimitException::class, $e);
        self::assertSame(900, $e->retryAfter);
        self::assertCount(1, $stub->requests);
        self::assertSame([], $this->sleeps->waits);
    }

    public function testNeverRetriesAPostWithoutAnIdempotencyKeyNorAPatch(): void
    {
        $stub = $this->stub(static fn (): HttpResponse => Api::json(503, Api::error('INTERNAL', 503, 'busy')));
        $c = $this->client($stub);
        self::assertSame(503, self::caught(static fn () => $c->createSegment(['body' => ['name' => 'Sabit müşteriler', 'rule' => ['minVisits' => 3]]]))->status);
        self::assertCount(1, $stub->requests);
        self::caught(static fn () => $c->updateProgram(['params' => ['id' => Api::LOCATION], 'body' => []]));
        self::assertCount(2, $stub->requests);
        self::assertSame([], $this->sleeps->waits);
    }

    public function testRetriesPutAndDelete(): void
    {
        $stub = $this->stub(static function (HttpRequest $r, int $n): HttpResponse {
            if ($n % 2 === 1) {
                return Api::json(503, Api::error('INTERNAL', 503, 'busy'));
            }
            return $n === 2 ? Api::json(200, ['data' => ['hosts' => []]]) : Api::raw(204, '');
        });
        $c = $this->client($stub);
        $c->setEmbedHosts(['body' => ['hosts' => []]]);
        $c->unblockEmail(['params' => ['hash' => 'abc']]);
        self::assertSame(['PUT', 'PUT', 'DELETE', 'DELETE'], array_map(static fn (HttpRequest $r): string => $r->method, $stub->requests));
    }

    public function testRetriesATillActionWithTheSameIdempotencyKey(): void
    {
        $stub = $this->stub(static fn (HttpRequest $r, int $n): HttpResponse => $n === 1
            ? Api::json(503, Api::error('INTERNAL', 503, 'busy'))
            : Api::json(200, ['data' => ['balance' => 3, 'duplicate' => false]]));
        $c = $this->client($stub);
        self::assertSame(['balance' => 3, 'duplicate' => false], $c->passAction(self::ACTION));
        self::assertCount(2, $stub->requests);
        $first = (string) $stub->request(0)->header('idempotency-key');
        self::assertSame(36, strlen($first));
        self::assertSame($first, $stub->request(1)->header('idempotency-key'));
        $c->passAction(self::ACTION + ['idempotencyKey' => 'fis-42-0001']);
        self::assertSame('fis-42-0001', $stub->request(2)->header('idempotency-key'));
    }

    public function testWaitsOutIdempotencyInProgressOnACampaignSendThenReadsTheReplayedAnswer(): void
    {
        $stub = $this->stub(static fn (HttpRequest $r, int $n): HttpResponse => $n === 1
            ? Api::json(409, Api::error('IDEMPOTENCY_IN_PROGRESS', 409, 'Bu anahtarla gelen ilk istek hâlâ işleniyor'))
            : Api::json(201, ['data' => ['id' => 'c1']], ['idempotent-replayed' => 'true']));
        $res = $this->client($stub)->request('sendCampaign', ['body' => ['body' => 'Merhaba'], 'idempotencyKey' => 'kampanya-2026-10-03']);
        self::assertTrue($res->replayed);
        self::assertCount(1, $this->sleeps->waits);
        self::assertSame(['kampanya-2026-10-03', 'kampanya-2026-10-03'], array_map(static fn (HttpRequest $r): ?string => $r->header('idempotency-key'), $stub->requests));
    }

    public function testDoesNotRetryOtherConflicts(): void
    {
        $stub = $this->stub(static fn (): HttpResponse => Api::json(409, Api::error('INSUFFICIENT_BALANCE', 409, 'bakiye yetersiz')));
        $c = $this->client($stub);
        self::assertSame('INSUFFICIENT_BALANCE', self::caught(static fn () => $c->passAction(self::ACTION))->errorCode);
        self::assertCount(1, $stub->requests);
    }

    public function testRetriesWhenTheConnectionBreaksForAGetOnly(): void
    {
        $stub = $this->stub(static fn (HttpRequest $r, int $n): HttpResponse|Throwable => $n === 1 || $n === 3
            ? new TransportException('Connection reset by peer')
            : Api::json(200, ['data' => ['ok' => true]]));
        $c = $this->client($stub);
        self::assertSame(['ok' => true], $c->getPass(['params' => ['serial' => Api::SERIAL]]));
        self::assertCount(1, $this->sleeps->waits);
        $e = self::caught(static fn () => $c->createSegment(['body' => ['name' => 'Sabit müşteriler', 'rule' => ['minVisits' => 3]]]), ConnectionException::class);
        self::assertSame(0, $e->status);
        self::assertSame('CONNECTION_ERROR', $e->errorCode);
        self::assertSame('Connection reset by peer', $e->detail);
        self::assertSame('createSegment', $e->operation);
        self::assertInstanceOf(TransportException::class, $e->getPrevious());
        self::assertCount(3, $stub->requests);
    }

    public function testTimesOutASilentAttemptAndRetriesIt(): void
    {
        $stub = $this->stub(static fn (HttpRequest $r, int $n): HttpResponse|Throwable => $n === 1
            ? new TransportException('Operation timed out after 50 milliseconds', true)
            : Api::json(200, ['data' => ['ok' => true]]));
        self::assertSame(['ok' => true], $this->client($stub)->getPass(['params' => ['serial' => Api::SERIAL]]));
        self::assertCount(2, $stub->requests);
        self::assertCount(1, $this->sleeps->waits);
    }

    public function testThrowsTimeoutExceptionWhenEveryAttemptTimesOut(): void
    {
        $stub = $this->stub(static fn (): Throwable => new TransportException('Operation timed out', true));
        $c = new Client(apiKey: Api::KEY, baseUrl: 'https://api.test', transport: $stub, timeout: 0.03, maxRetries: 1, sleep: $this->sleeps->sleeper());
        $e = self::caught(static fn () => $c->getPass(['params' => ['serial' => Api::SERIAL]]), TimeoutException::class);
        self::assertInstanceOf(ConnectionException::class, $e);
        self::assertSame('TIMEOUT', $e->errorCode);
        self::assertSame('no answer within 0.03 s', $e->detail);
        self::assertCount(2, $stub->requests);
        // A per-call timeout wins over the client's.
        self::caught(static fn () => $c->getPass(['params' => ['serial' => Api::SERIAL], 'timeout' => 0.02, 'maxRetries' => 0]), TimeoutException::class);
        self::assertSame(0.02, $stub->last()->timeout);
    }

    public function testDoesNotRetryAHeaderTheTransportCannotSend(): void
    {
        $server = \Rewloy\Tests\Support\LocalServer::start();
        try {
            $c = new Client(apiKey: Api::KEY . "\n", baseUrl: $server->url, sleep: $this->sleeps->sleeper());
            try {
                $c->request('getPass', ['params' => ['serial' => Api::SERIAL]]);
                self::fail('sent');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Rewloy: the authorization header contains a line break', $e->getMessage());
                self::assertStringNotContainsString(Api::KEY, $e->getMessage());
            }
            self::assertSame([], $this->sleeps->waits, 'not retried');
        } finally {
            $server->stop();
        }
    }

    public function testTheDefaultSleeperWaitsForReal(): void
    {
        // The default sleeper really sleeps: a 0.01 s wait, measured.
        $stub = $this->stub(static fn (HttpRequest $r, int $n): HttpResponse => $n === 1
            ? Api::json(503, Api::error('INTERNAL', 503, 'busy'), ['retry-after' => '0.01'])
            : Api::json(200, ['data' => ['ok' => true]]));
        $c = new Client(apiKey: Api::KEY, baseUrl: 'https://api.test', transport: $stub);
        $started = microtime(true);
        self::assertSame(['ok' => true], $c->getPass(['params' => ['serial' => Api::SERIAL]]));
        $took = microtime(true) - $started;
        self::assertGreaterThanOrEqual(0.009, $took);
        self::assertLessThan(1.0, $took);
    }
}

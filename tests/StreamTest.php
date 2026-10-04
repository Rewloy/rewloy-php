<?php

declare(strict_types=1);

namespace Rewloy\Tests;

use Generator;
use PHPUnit\Framework\TestCase;
use Rewloy\Client;
use Rewloy\Exception\ConnectionException;
use Rewloy\Exception\RewloyException;
use Rewloy\Exception\TimeoutException;
use Rewloy\Http\HttpRequest;
use Rewloy\Http\HttpResponse;
use Rewloy\Http\HttpStream;
use Rewloy\Http\TransportException;
use Rewloy\ServerSentEvent;
use Rewloy\Tests\Support\Api;
use Rewloy\Tests\Support\Sleeps;
use Rewloy\Tests\Support\StubTransport;

final class StreamTest extends TestCase
{
    private Sleeps $sleeps;

    protected function setUp(): void
    {
        $this->sleeps = new Sleeps();
    }

    private function client(StubTransport $stub, string $credential = Api::KEY, int $maxRetries = 2): Client
    {
        return match (substr($credential, 0, 4)) {
            'rwh_' => new Client(holderSession: $credential, baseUrl: 'https://api.test', transport: $stub, maxRetries: $maxRetries, sleep: $this->sleeps->sleeper()),
            'rws_' => new Client(staffSession: $credential, merchant: 'm-1', baseUrl: 'https://api.test', transport: $stub, maxRetries: $maxRetries, sleep: $this->sleeps->sleeper()),
            default => new Client(apiKey: $credential, baseUrl: 'https://api.test', transport: $stub, maxRetries: $maxRetries, sleep: $this->sleeps->sleeper()),
        };
    }

    /**
     * Reads a stream, up to `$limit` events.
     *
     * @phpstan-impure
     *
     * @param Generator<int, ServerSentEvent, mixed, void> $stream
     * @return list<array{string, string, string}>
     */
    private static function collect(Generator $stream, int $limit = PHP_INT_MAX): array
    {
        $out = [];
        foreach ($stream as $e) {
            $out[] = [$e->event, $e->data, $e->id];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    public function testReadsTheApisStreamFromPiecesSplitAnywhereEvenInsideACharacter(): void
    {
        $bytes = "event: event\r\ndata: {\"name\":\"Ayşe\",\"location\":\"Moda Şubesi\"}\r\n\r\n: hb\r\n\r\n";
        $stub = new StubTransport(static fn (): HttpStream => Api::sse(str_split($bytes, 3), ['x-request-id' => 'r-live']));
        $events = self::collect($this->client($stub)->stream('liveFeed', ['reconnect' => false]));
        self::assertSame([['event', '{"name":"Ayşe","location":"Moda Şubesi"}', '']], $events);
        self::assertSame(1, $stub->closed);
    }

    public function testSendsTheCredentialMerchantAndAccept(): void
    {
        $stub = new StubTransport(static fn (): HttpStream => Api::sse(["retry: 5000\n\n", "event: event\nda", "ta: {\"kind\":\"scan\"}\n", "\n"]));
        $events = self::collect($this->client($stub, 'rws_x')->liveFeed(['reconnect' => false, 'idleTimeout' => 40]));
        self::assertSame([['event', '{"kind":"scan"}', '']], $events);
        $r = $stub->request(0);
        self::assertSame('GET', $r->method);
        self::assertSame('/v1/live', StubTransport::target($r));
        self::assertSame('text/event-stream', $r->header('accept'));
        self::assertSame('Bearer rws_x', $r->header('authorization'));
        self::assertSame('m-1', $r->header('rewloy-merchant'));
        self::assertNull($r->header('last-event-id'));
        self::assertSame(40.0, $r->idleTimeout);
        self::assertSame(60.0, $r->timeout, 'the timeout covers the headers');
    }

    public function testReconnectsAfterTheServersRetryDelayWithLastEventId(): void
    {
        $stub = new StubTransport(static fn (HttpRequest $r, int $n): HttpStream => $n === 1
            ? Api::sse(["retry: 1234\n\nid: 7\nevent: changed\ndata: 1\n\n"])
            : Api::sse(["event: changed\ndata: 2\n\n"]));
        $events = self::collect($this->client($stub, Api::HOLDER)->holderCardEvents(['params' => ['serial' => Api::SERIAL]]), 2);
        self::assertSame([['changed', '1', '7'], ['changed', '2', '7']], $events);
        self::assertSame([1.234], $this->sleeps->waits);
        self::assertSame('/v1/holder/cards/' . Api::SERIAL . '/events', StubTransport::target($stub->request(0)));
        self::assertSame('Bearer ' . Api::HOLDER, $stub->request(0)->header('authorization'));
        self::assertSame('7', $stub->request(1)->header('last-event-id'));
        self::assertSame(2, $stub->closed, 'the first connection when it ended, the second on break');
    }

    public function testWaitsThreeSecondsBeforeReconnectingUntilTheServerSaysOtherwise(): void
    {
        $stub = new StubTransport(static fn (HttpRequest $r, int $n): HttpStream => Api::sse(["data: {$n}\n\n"]));
        self::assertSame([['message', '1', ''], ['message', '2', ''], ['message', '3', '']], self::collect($this->client($stub)->liveFeed(), 3));
        self::assertSame([3.0, 3.0], $this->sleeps->waits);
    }

    public function testClosesTheConnectionWhenTheCallerLeavesTheLoop(): void
    {
        $closed = 0;
        $stub = new StubTransport(static function () use (&$closed): HttpStream {
            // The server keeps the stream open: heartbeats for ever.
            $pieces = (static function (): Generator {
                yield "event: event\ndata: first\n\n";
                while (true) {
                    yield ": hb\n\n";
                }
            })();
            return new HttpStream(200, ['content-type' => ['text/event-stream']], $pieces, static function () use (&$closed): void {
                $closed++;
            });
        });
        $c = $this->client($stub);
        foreach ($c->liveFeed() as $event) {
            self::assertSame('first', $event->data);
            break;
        }
        self::assertSame(1, $closed);
        self::assertCount(1, $stub->requests);

        $stream = $c->stream('liveFeed');
        foreach ($stream as $event) {
            break;
        }
        unset($stream);
        self::assertSame(2, $closed, 'closed once the generator is let go');
    }

    public function testEndsWithTheErrorAReconnectionCannotFix(): void
    {
        $stub = new StubTransport(static fn (HttpRequest $r, int $n): HttpResponse|HttpStream => $n === 1
            ? Api::sse(["event: changed\ndata: 1\n\n"])
            : Api::json(401, Api::error('TOKEN_INVALID', 401, 'Oturum geçersiz ya da süresi dolmuş; yeniden giriş yapın')));
        $got = [];
        try {
            foreach ($this->client($stub, Api::HOLDER)->holderCardEvents(['params' => ['serial' => Api::SERIAL]]) as $event) {
                $got[] = $event->data;
            }
            self::fail('the stream did not end with an error');
        } catch (RewloyException $e) {
            self::assertSame('TOKEN_INVALID', $e->errorCode);
            self::assertSame(401, $e->status);
            self::assertSame('holderCardEvents', $e->operation);
        }
        self::assertSame(['1'], $got);
        self::assertCount(2, $stub->requests);
    }

    public function testDoesNotReconnectAfterA404(): void
    {
        $stub = new StubTransport(static fn (): HttpResponse => Api::json(404, Api::error('PASS_NOT_FOUND', 404, 'Kart bulunamadı')));
        try {
            self::collect($this->client($stub, Api::HOLDER)->holderCardEvents(['params' => ['serial' => Api::SERIAL]]));
            self::fail('no error');
        } catch (RewloyException $e) {
            self::assertSame('PASS_NOT_FOUND', $e->errorCode);
        }
        self::assertCount(1, $stub->requests);
        self::assertSame([], $this->sleeps->waits);
    }

    public function testReconnectsThroughTransientFailures(): void
    {
        $stub = new StubTransport(static fn (HttpRequest $r, int $n): HttpResponse|HttpStream => $n <= 4
            ? Api::json(503, Api::error('INTERNAL', 503, 'busy'))
            : Api::sse(["data: back\n\n"]));
        self::assertSame([['message', 'back', '']], self::collect($this->client($stub, Api::KEY, 1)->liveFeed(), 1));
        // Two connections of two attempts each failed; the fifth request got through.
        self::assertCount(5, $stub->requests);
        self::assertCount(4, $this->sleeps->waits);
        self::assertSame(3.0, $this->sleeps->waits[1] ?? null, 'the reconnection waits at least the retry delay');
        self::assertSame(4.0, $this->sleeps->waits[3] ?? null, 'then backs off: 2 s, 4 s, 8 s… up to 30 s');
        self::assertSame(5, $stub->closed, 'each error answer once read, and the stream on break');
    }

    public function testReconnectsWhenTheConnectionBreaks(): void
    {
        $stub = new StubTransport(static fn (HttpRequest $r, int $n): HttpStream => $n === 1
            ? Api::sse(["data: a\n\n", new TransportException('Connection reset by peer')])
            : Api::sse(["data: b\n\n"]));
        self::assertSame([['message', 'a', ''], ['message', 'b', '']], self::collect($this->client($stub)->liveFeed(), 2));
        self::assertSame([3.0], $this->sleeps->waits);
        self::assertSame(2, $stub->closed);
    }

    public function testRefusesAMissingParameterAtTheCallNotAtTheFirstIteration(): void
    {
        $stub = new StubTransport(static fn (): HttpStream => Api::sse([]));
        $c = $this->client($stub, Api::HOLDER);
        try {
            $c->stream('holderCardEvents', ['params' => []]);
            self::fail('accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Rewloy: holderCardEvents needs params.serial', $e->getMessage());
        }
        self::assertSame([], $stub->requests);
    }

    public function testEndsWhenTheServerEndsAStreamThatDoesNotReconnect(): void
    {
        $stub = new StubTransport(static fn (): HttpStream => Api::sse(["data: only\n\n", 'data: half an event']));
        self::assertSame([['message', 'only', '']], self::collect($this->client($stub)->liveFeed(['reconnect' => false])));
        self::assertCount(1, $stub->requests);
    }

    public function testTreatsASilentConnectionAsDropped(): void
    {
        $stub = new StubTransport(static fn (): HttpStream => Api::sse([": hb\n\n", new TransportException('no data for 0.04 s', true)], ['x-request-id' => 'r-live']));
        try {
            self::collect($this->client($stub)->liveFeed(['reconnect' => false, 'idleTimeout' => 0.04]));
            self::fail('no error');
        } catch (TimeoutException $e) {
            self::assertSame('TIMEOUT', $e->errorCode);
            self::assertSame('no data for 0.04 s', $e->detail);
            self::assertSame('r-live', $e->requestId);
            self::assertSame('liveFeed', $e->operation);
        }
        self::assertSame(0.04, $stub->last()->idleTimeout);
    }

    public function testEndsWithTheDropWhenItDoesNotReconnect(): void
    {
        $stub = new StubTransport(static fn (): HttpStream => Api::sse(["data: a\n\n", new TransportException('Connection reset by peer')]));
        $got = [];
        try {
            foreach ($this->client($stub)->liveFeed(['reconnect' => false]) as $event) {
                $got[] = $event->data;
            }
            self::fail('no error');
        } catch (ConnectionException $e) {
            self::assertNotInstanceOf(TimeoutException::class, $e);
            self::assertSame('CONNECTION_ERROR', $e->errorCode);
        }
        self::assertSame(['a'], $got);
    }
}

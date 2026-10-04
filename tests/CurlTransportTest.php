<?php

declare(strict_types=1);

namespace Rewloy\Tests;

use PHPUnit\Framework\TestCase;
use Rewloy\Client;
use Rewloy\Exception\ConnectionException;
use Rewloy\Http\CurlTransport;
use Rewloy\Http\HttpRequest;
use Rewloy\Http\TransportException;
use Rewloy\Tests\Support\Api;
use Rewloy\Tests\Support\LocalServer;

/**
 * The default transport against a real HTTP peer: PHP's built-in server on
 * 127.0.0.1, one per test.
 */
final class CurlTransportTest extends TestCase
{
    private LocalServer $server;
    private CurlTransport $curl;

    protected function setUp(): void
    {
        $this->server = LocalServer::start();
        $this->curl = new CurlTransport();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $path, array $headers = [], ?string $body = null, float $timeout = 5.0, float $idle = 0.0): HttpRequest
    {
        return new HttpRequest($method, $this->server->url . $path, $headers, $body, $timeout, $idle);
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($value);
        /** @var array<string, mixed> $value */
        return $value;
    }

    public function testSendsTheRequestAndReadsTheAnswer(): void
    {
        $res = $this->curl->send($this->request('POST', '/echo?q=Ay%C5%9Fe', [
            'accept' => 'application/json',
            'authorization' => 'Bearer rwk_x',
            'content-type' => 'application/json',
            'idempotency-key' => 'fis-1',
        ], '{"a":"ğ"}'));
        self::assertSame(200, $res->status);
        self::assertSame('OK', $res->reason);
        self::assertSame('req-echo', $res->header('X-Request-Id'));
        self::assertSame(['a', 'b'], $res->headers['x-multi'] ?? null);
        self::assertSame('a, b', $res->header('x-multi'));
        $seen = self::decode($res->body);
        self::assertSame('POST', $seen['method'] ?? null);
        self::assertSame('/echo?q=Ay%C5%9Fe', $seen['uri'] ?? null);
        self::assertSame('{"a":"ğ"}', $seen['body'] ?? null);
        $headers = $seen['headers'] ?? null;
        self::assertIsArray($headers);
        self::assertSame('Bearer rwk_x', $headers['authorization'] ?? null);
        self::assertSame('fis-1', $headers['idempotency-key'] ?? null);
        self::assertSame('application/json', $headers['content-type'] ?? null);
        self::assertArrayNotHasKey('expect', $headers);
    }

    public function testSendsABodilessPostWithAnEmptyBodyAndNoFormType(): void
    {
        $seen = self::decode($this->curl->send($this->request('POST', '/echo'))->body);
        $headers = $seen['headers'] ?? null;
        self::assertIsArray($headers);
        self::assertSame('0', $headers['content-length'] ?? null);
        self::assertArrayNotHasKey('content-type', $headers);
        self::assertSame('', $seen['body'] ?? null);
        $deleted = self::decode($this->curl->send($this->request('DELETE', '/echo'))->body);
        self::assertSame('DELETE', $deleted['method'] ?? null);
    }

    public function testReadsAnErrorAnswerWithItsReasonPhrase(): void
    {
        $res = $this->curl->send($this->request('GET', '/status?code=502'));
        self::assertSame(502, $res->status);
        self::assertSame('Bad Gateway', $res->reason);
        self::assertSame('<html><body>502</body></html>', $res->body);
    }

    public function testDecodesACompressedAnswer(): void
    {
        self::assertSame('{"data":{"zipped":true}}', $this->curl->send($this->request('GET', '/gzip'))->body);
    }

    public function testReportsARefusedConnection(): void
    {
        $url = $this->server->url;
        $this->server->stop();
        try {
            $this->curl->send(new HttpRequest('GET', $url . '/echo', [], null, 5.0));
            self::fail('no error');
        } catch (TransportException $e) {
            self::assertFalse($e->timeout);
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testTimesOut(): void
    {
        $started = microtime(true);
        try {
            $this->curl->send($this->request('GET', '/slow?ms=2000', timeout: 0.2));
            self::fail('no timeout');
        } catch (TransportException $e) {
            self::assertTrue($e->timeout, $e->getMessage());
        }
        self::assertLessThan(1.5, microtime(true) - $started);
    }

    public function testRefusesAHeaderWithALineBreak(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Rewloy: the x-evil header contains a line break');
        $this->curl->send($this->request('GET', '/echo', ['x-evil' => "a\r\nInjected: yes"]));
    }

    public function testStreamsTheBodyAsItArrives(): void
    {
        $started = microtime(true);
        $stream = $this->curl->stream($this->request('GET', '/sse?events=3&pause=300', ['accept' => 'text/event-stream']));
        self::assertSame(200, $stream->status);
        self::assertSame('req-sse', $stream->header('x-request-id'));
        $text = '';
        $firstEventAt = null;
        foreach ($stream->body as $chunk) {
            $text .= $chunk;
            if ($firstEventAt === null && str_contains($text, '"n":1')) {
                $firstEventAt = microtime(true) - $started;
            }
        }
        $stream->close();
        self::assertNotNull($firstEventAt);
        self::assertLessThan(0.75, $firstEventAt, 'the first event came before the stream ended');
        self::assertGreaterThan(0.8, microtime(true) - $started);
        self::assertSame(3, substr_count($text, 'event: tick'));
        self::assertStringContainsString('"name":"Ayşe"', $text);
    }

    public function testEndsASilentStream(): void
    {
        $stream = $this->curl->stream($this->request('GET', '/sse?events=1&hang=3000', idle: 0.3));
        $text = '';
        $started = microtime(true);
        try {
            foreach ($stream->body as $chunk) {
                $text .= $chunk;
            }
            self::fail('no idle timeout');
        } catch (TransportException $e) {
            self::assertTrue($e->timeout);
            self::assertSame('no data for 0.3 s', $e->getMessage());
        } finally {
            $stream->close();
        }
        self::assertStringContainsString('"n":1', $text);
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function testTimesOutAStreamWhoseHeadersDoNotCome(): void
    {
        try {
            $this->curl->stream($this->request('GET', '/slow?ms=2000', timeout: 0.2));
            self::fail('no timeout');
        } catch (TransportException $e) {
            self::assertTrue($e->timeout);
            self::assertSame('no answer within 0.2 s', $e->getMessage());
        }
    }

    public function testReadsAStreamsErrorAnswer(): void
    {
        $stream = $this->curl->stream($this->request('GET', '/status?code=401'));
        self::assertSame(401, $stream->status);
        self::assertSame('Unauthorized', $stream->reason);
        self::assertSame('<html><body>401</body></html>', $stream->readAll());
    }

    public function testClosesTheConnectionWhenTheCallerLeavesTheLoop(): void
    {
        // The router finds the marker's name in the User-Agent, and writes the file once its
        // heartbeats fail to go out: the client has closed the socket.
        $marker = 'rewloy-curl-' . bin2hex(random_bytes(6));
        $file = sys_get_temp_dir() . '/' . $marker;
        $client = new Client(apiKey: Api::KEY, baseUrl: $this->server->url, transport: $this->curl, userAgent: 'marker/' . $marker);
        try {
            $seen = [];
            foreach ($client->liveFeed(['reconnect' => false]) as $event) {
                $seen[] = $event->json();
                break;
            }
            self::assertSame([['n' => 1, 'name' => 'Ayşe']], $seen);
            $deadline = microtime(true) + 3;
            while (!is_file($file) && microtime(true) < $deadline) {
                usleep(20000);
            }
            self::assertFileExists($file, 'the server saw the connection close');
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testCarriesAWholeCallThroughTheClient(): void
    {
        $client = new Client(apiKey: Api::KEY, baseUrl: $this->server->url, userAgent: 'KasaPOS/4.2');
        // The local server echoes the request instead of a card: read it through request().
        $seen = $client->request('getPass', ['params' => ['serial' => Api::SERIAL]])->data;
        self::assertIsArray($seen);
        self::assertSame('GET', $seen['method'] ?? null);
        self::assertSame('/v1/passes/' . Api::SERIAL, $seen['uri'] ?? null);
        $headers = $seen['headers'] ?? null;
        self::assertIsArray($headers);
        self::assertSame('Bearer ' . Api::KEY, $headers['authorization'] ?? null);
        self::assertSame('rewloy-php/' . Client::VERSION . ' PHP/' . PHP_VERSION . ' KasaPOS/4.2', $headers['user-agent'] ?? null);

        $events = [];
        foreach ($client->liveFeed(['reconnect' => false]) as $event) {
            $events[] = [$event->event, $event->json()];
        }
        self::assertSame([['tick', ['n' => 1, 'name' => 'Ayşe']], ['tick', ['n' => 2, 'name' => 'Ayşe']], ['tick', ['n' => 3, 'name' => 'Ayşe']]], $events);

        $url = $this->server->url;
        $this->server->stop();
        try {
            (new Client(apiKey: Api::KEY, baseUrl: $url, maxRetries: 0))->getPass(['params' => ['serial' => Api::SERIAL]]);
            self::fail('no error');
        } catch (ConnectionException $e) {
            self::assertSame('CONNECTION_ERROR', $e->errorCode);
            self::assertSame('getPass', $e->operation);
        }
    }
}

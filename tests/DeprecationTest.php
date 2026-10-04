<?php

declare(strict_types=1);

namespace Rewloy\Tests;

use ErrorException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Rewloy\Client;
use Rewloy\Exception\RewloyException;
use Rewloy\Http\HttpRequest;
use Rewloy\Http\HttpResponse;
use Rewloy\Http\HttpStream;
use Rewloy\Tests\Support\Api;
use Rewloy\Tests\Support\StubTransport;

final class DeprecationTest extends TestCase
{
    /** @var list<string> */
    private array $notices = [];

    private StubTransport $stub;

    /**
     * What the platform sends on every answer of a deprecated operation
     * (src/api/kit.ts deprecationHeaders there).
     *
     * @return array<string, string>
     */
    private static function deprecated(string $id): array
    {
        $link = 'https://rewloy.com/gelistiriciler/degisiklikler#' . $id;
        return [
            'deprecation' => '@' . (int) strtotime('2026-10-03T00:00:00Z'),
            'sunset' => gmdate('D, d M Y H:i:s', (int) strtotime('2027-04-01T00:00:00Z')) . ' GMT',
            'link' => '<' . $link . '>; rel="deprecation"; type="text/html", <' . $link . '>; rel="sunset"; type="text/html"',
        ];
    }

    protected function setUp(): void
    {
        // One notice per operation per process: start each test with none given yet.
        (new ReflectionProperty(Client::class, 'warned'))->setValue(null, []);
        set_error_handler(function (int $level, string $message): bool {
            if ($level !== E_USER_DEPRECATED) {
                return false;
            }
            $this->notices[] = $message;
            return true;
        });
        $this->stub = new StubTransport(static function (HttpRequest $req): HttpResponse|HttpStream {
            $path = StubTransport::target($req);
            if (str_starts_with($path, '/v1/passes/')) {
                return Api::json(200, ['data' => ['serial' => Api::SERIAL]], self::deprecated('getPass'));
            }
            if ($path === '/v1/programs') {
                return Api::json(200, ['data' => []]);
            }
            if ($path === '/v1/locations') {
                return Api::json(200, ['data' => []], self::deprecated('listLocations'));
            }
            if ($path === '/v1/live') {
                return Api::sse(["data: x\n\n"], self::deprecated('liveFeed'));
            }
            return Api::json(404, Api::error('PROGRAM_NOT_FOUND', 404, 'Program bulunamadı'), self::deprecated('getProgram'));
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    private function client(): Client
    {
        return new Client(apiKey: Api::KEY, baseUrl: 'https://api.test', transport: $this->stub, maxRetries: 0);
    }

    public function testWarnsOncePerOperationNamingTheSunsetAndTheLink(): void
    {
        $c = $this->client();
        $c->getPass(['params' => ['serial' => Api::SERIAL]]);
        self::assertCount(1, $this->notices);
        self::assertSame(
            'Rewloy API operation getPass (GET /v1/passes/{serial}) is deprecated. Sunset: Thu, 01 Apr 2027 00:00:00 GMT.'
            . ' See https://rewloy.com/gelistiriciler/degisiklikler#getPass',
            $this->notices[0] ?? null,
        );
        $c->getPass(['params' => ['serial' => Api::SERIAL]]);
        $this->client()->getPass(['params' => ['serial' => Api::SERIAL]]);
        self::assertCount(1, $this->notices, 'still one for getPass, from any client');
    }

    public function testSaysNothingForAnOperationThatIsNotDeprecated(): void
    {
        $this->client()->listPrograms();
        self::assertSame([], $this->notices);
    }

    public function testWarnsOnAnErrorAnswerToo(): void
    {
        try {
            $this->client()->getProgram(['params' => ['id' => Api::LOCATION]]);
            self::fail('no error');
        } catch (RewloyException $e) {
            self::assertSame('PROGRAM_NOT_FOUND', $e->errorCode);
        }
        self::assertCount(1, $this->notices);
        self::assertStringStartsWith('Rewloy API operation getProgram (GET /v1/programs/{id}) is deprecated.', $this->notices[0] ?? '');
    }

    public function testWarnsWhenAStreamConnects(): void
    {
        foreach ($this->client()->liveFeed(['reconnect' => false]) as $event) {
            self::assertSame('x', $event->data);
        }
        self::assertCount(1, $this->notices);
        self::assertStringStartsWith('Rewloy API operation liveFeed (GET /v1/live) is deprecated.', $this->notices[0] ?? '');
    }

    public function testDoesNotTurnAnAnswerIntoAFailureWhenTheErrorHandlerThrows(): void
    {
        // An application that turns every error into an exception, deprecations included.
        set_error_handler(static function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level);
        });
        $log = (string) tempnam(sys_get_temp_dir(), 'rewloy-log');
        $previous = ini_set('error_log', $log);
        try {
            self::assertSame([], $this->client()->listLocations(), 'the call keeps its answer');
            self::assertStringContainsString('Rewloy API operation listLocations (GET /v1/locations) is deprecated.', (string) file_get_contents($log));
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            restore_error_handler();
            unlink($log);
        }
    }
}

<?php

declare(strict_types=1);

namespace Rewloy\Tests;

use PHPUnit\Framework\TestCase;
use Rewloy\Dev\Generator;
use RuntimeException;

final class GenerateTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    private static function snapshot(): mixed
    {
        return json_decode((string) file_get_contents(self::ROOT . '/openapi/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The generated files by name.
     *
     * @return array<string, string>
     */
    private static function files(mixed $document): array
    {
        $out = [];
        foreach (Generator::generate($document) as $file) {
            $out[basename($file['path'])] = $file['content'];
        }
        return $out;
    }

    /** One generated file of a document. */
    private static function file(mixed $document, string $name): string
    {
        return self::files($document)[$name] ?? self::fail('no ' . $name);
    }

    public function testIsDeterministicAndTheCommittedOutputIsCurrent(): void
    {
        $first = Generator::generate(self::snapshot());
        self::assertSame($first, Generator::generate(self::snapshot()));
        self::assertSame(['src/Generated/Methods.php', 'src/Generated/Operations.php', 'src/Generated/ErrorCode.php'], array_column($first, 'path'));
        foreach ($first as $file) {
            self::assertTrue(
                file_get_contents(self::ROOT . '/' . $file['path']) === $file['content'],
                $file['path'] . ' is out of date: run `composer generate -- --file openapi/openapi.json`',
            );
        }
    }

    public function testMakesOneMethodPerOperationOfTheSnapshot(): void
    {
        $doc = self::snapshot();
        self::assertIsArray($doc);
        $paths = $doc['paths'] ?? null;
        self::assertIsArray($paths);
        $ids = [];
        foreach ($paths as $item) {
            self::assertIsArray($item);
            foreach ($item as $op) {
                if (is_array($op) && is_string($op['operationId'] ?? null)) {
                    $ids[] = $op['operationId'];
                }
            }
        }
        self::assertGreaterThan(200, count($ids));
        self::assertSame(count($ids), count(array_unique($ids)));
        $methods = self::file($doc, 'Methods.php');
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('/^    public function ' . $id . '\(array \$args(?: = \[\])?\): /m', $methods, $id);
        }
    }

    /**
     * A small document with the cases the live one does not have yet.
     *
     * @return array<string, mixed>
     */
    private static function fixture(bool $keyRequired = false): array
    {
        $envelope = static fn (mixed $data): array => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => $data]]]]];
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Fixture', 'version' => '9.9.9'],
            'paths' => [
                '/v1/things/{id}' => [
                    'get' => [
                        'operationId' => 'getThing', 'tags' => ['Şeyler'], 'summary' => 'Bir şey',
                        'deprecated' => true,
                        'description' => "**Kullanımdan kalkıyor:** 1 Nisan 2027 tarihine kadar çalışır; yerine `getThingV2`. Ayrıntı: https://rewloy.com/gelistiriciler/degisiklikler#getThing\n\nYorum */ kapanmasın.\n@internal bir etiket değil.",
                        'x-credentials' => ['key', 'staff'],
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid'], 'description' => 'Şeyin kimliği'],
                            ['name' => 'Rewloy-Merchant', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string']],
                        ],
                        'responses' => [
                            '200' => $envelope(['type' => 'object', 'additionalProperties' => false, 'required' => ['state', 'weird-name'], 'properties' => [
                                'state' => ['type' => ['string', 'null'], 'enum' => ['on', 'off', null]],
                                'weird-name' => ['oneOf' => [['const' => 'all'], ['type' => 'array', 'items' => ['anyOf' => [['type' => 'string'], ['type' => 'integer']]]]]],
                                'note' => ['type' => 'string', 'description' => "Tırnak ' ve ters bölü \\ içerir"],
                                'extra' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                                'quote' => ['enum' => ["it's", 'back\\slash'], 'deprecated' => true, 'description' => 'Eski alan; yerine `state`.'],
                                'list' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['n' => ['type' => 'number']]]],
                            ]]),
                            '404' => ['description' => 'x', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error'], 'examples' => ['NOT_FOUND' => ['summary' => 'Bulunamadı', 'value' => []]]]]],
                        ],
                    ],
                    'post' => [
                        'operationId' => 'touchThing', 'tags' => ['Şeyler'], 'summary' => 'Dokun', 'x-credentials' => ['key'],
                        'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']], ['name' => 'X-Trace', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']]],
                        'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => [
                            'count' => ['type' => 'integer', 'description' => 'Kaç kez'],
                            'blob' => ['type' => 'object', 'additionalProperties' => true, 'properties' => ['a' => ['type' => 'string']]],
                        ]]]]],
                        'responses' => ['202' => $envelope(['type' => ['object', 'null'], 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok']])],
                    ],
                ],
                '/v1/things/{id}/events' => [
                    'get' => [
                        'operationId' => 'thingEvents', 'tags' => ['Şeyler'], 'summary' => 'Akış', 'x-credentials' => ['holder'],
                        'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                        'responses' => ['200' => ['description' => 'x', 'content' => ['text/event-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]]],
                    ],
                    'delete' => [
                        'operationId' => 'forgetThing', 'tags' => ['Şeyler'], 'summary' => 'Sil', 'security' => [['apiKey' => []], []],
                        'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']], ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => $keyRequired, 'schema' => ['type' => 'string']]],
                        'responses' => ['204' => ['description' => 'Tamam — gövde yok.']],
                    ],
                ],
                '/v1/things' => [
                    'get' => [
                        'operationId' => 'listThings', 'tags' => ['Şeyler'], 'summary' => 'Liste', 'x-credentials' => ['key'],
                        'parameters' => [['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']]],
                        'responses' => ['200' => ['description' => 'x', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['data', 'meta'], 'properties' => [
                            'data' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['id'], 'properties' => ['id' => ['type' => 'string'], 'email' => ['type' => 'string', 'deprecated' => true, 'description' => 'Kalkıyor.']]]],
                            'meta' => ['$ref' => '#/components/schemas/PageMeta'],
                        ]]]]]],
                    ],
                ],
                '/v1/things.png' => [
                    'get' => [
                        'operationId' => 'thingImage', 'tags' => ['Şeyler'], 'summary' => 'Resim', 'x-credentials' => ['public'],
                        'responses' => ['200' => ['description' => 'x', 'content' => ['image/png' => ['schema' => ['type' => 'string', 'format' => 'binary']]]]],
                    ],
                ],
            ],
            'components' => ['schemas' => [
                'Error' => ['type' => 'object', 'required' => ['error'], 'properties' => ['error' => ['type' => 'object', 'required' => ['code', 'message', 'requestId'], 'properties' => [
                    'code' => ['type' => 'string', 'enum' => ['NOT_FOUND', 'INTERNAL']], 'message' => ['type' => 'string'], 'requestId' => ['type' => 'string']]]]],
                'PageMeta' => ['type' => 'object', 'required' => ['page', 'pageSize', 'total'], 'properties' => ['page' => ['type' => 'integer'], 'pageSize' => ['type' => 'integer'], 'total' => ['type' => 'integer']]],
            ]],
        ];
    }

    public function testMarksADeprecatedOperationWithItsSunsetAndReplacement(): void
    {
        self::assertSame(['sunset' => '2027-04-01', 'use' => 'getThingV2'], Generator::parseDeprecation('**Kullanımdan kalkıyor:** 1 Nisan 2027 tarihine kadar çalışır; yerine `getThingV2`.'));
        self::assertSame(['sunset' => '2026-12-15', 'use' => null], Generator::parseDeprecation('**Kullanımdan kalkıyor:** 15 Aralık 2026 tarihine kadar çalışır.'));
        self::assertStringContainsString('@deprecated The API stops answering this operation after 2027-04-01. Use `getThingV2()` instead. https://rewloy.com/gelistiriciler/degisiklikler#getThing', self::file(self::fixture(), 'Methods.php'));
        self::assertStringContainsString("'getThing' => ['method' => 'GET', 'path' => '/v1/things/{id}', 'auth' => ['key', 'staff'], 'merchant' => true, 'idempotency' => null, 'body' => false, 'query' => false, 'headers' => [], 'response' => 'json', 'paged' => false, 'stream' => false, 'deprecated' => ['sunset' => '2027-04-01', 'use' => 'getThingV2']],", self::file(self::fixture(), 'Operations.php'));
    }

    public function testKeepsCommentsClosedAndTextOutOfTags(): void
    {
        $methods = self::file(self::fixture(), 'Methods.php');
        self::assertStringContainsString('Yorum *\/ kapanmasın.', $methods);
        self::assertStringContainsString('     * &#64;internal bir etiket değil.', $methods);
    }

    public function testWritesTheTypesTheSchemasSay(): void
    {
        $methods = self::file(self::fixture(), 'Methods.php');
        self::assertStringContainsString(implode("\n", [
            '     * @return array{',
            "     *     state: 'on'|'off'|null,",
            "     *     'weird-name': 'all'|list<string|int>,",
            '     *     note?: string,',
            '     *     extra?: array<string, int>,',
            "     *     quote?: 'it\\'s'|'back\\\\slash',",
            '     *     list?: list<array{n?: int|float}>,',
            '     * }',
        ]), $methods);
        // Known keys and others besides: no portable shape, so a map. And a required header.
        self::assertStringContainsString(implode("\n", [
            '     * @param array{',
            '     *     params: array{id: string},',
            '     *     body?: array{count?: int, blob?: array<string, mixed>},',
            '     *     headers: array{\'X-Trace\': string},',
            '     *     timeout?: int|float|null,',
            '     *     maxRetries?: int|null,',
            '     * } $args',
            '     * @return array{ok: bool}|null',
        ]), $methods);
        self::assertStringContainsString('public function touchThing(array $args): ?array', $methods);
        self::assertStringContainsString(implode("\n", [
            '     * @return array{',
            '     *     data: list<array{id: string, email?: string}>,',
            '     *     meta: array{page: int, pageSize: int, total: int},',
            '     * }',
        ]), $methods);
        self::assertStringContainsString('public function listThings(array $args = []): array', $methods);
        self::assertStringContainsString("     * @return string\n", $methods);
        self::assertStringContainsString('public function thingImage(array $args = []): string', $methods);
    }

    public function testListsArgumentsAndFieldsMarkedForRemoval(): void
    {
        $methods = self::file(self::fixture(), 'Methods.php');
        self::assertStringContainsString("     * Arguments:\n     * - `params.id`: Şeyin kimliği\n", $methods);
        self::assertStringContainsString('     * - `body.count`: Kaç kez', $methods);
        self::assertStringContainsString("     * Fields of the answer marked for removal:\n     * - `data.quote`: Eski alan; yerine `state`.", $methods);
        self::assertStringContainsString("     * Fields of the answer marked for removal:\n     * - `data[].email`: Kalkıyor.", $methods);
    }

    public function testReadsStreamsEmptyAnswersAndCredentialsFromSecurity(): void
    {
        $ops = self::file(self::fixture(), 'Operations.php');
        self::assertStringContainsString("'thingEvents' => ['method' => 'GET', 'path' => '/v1/things/{id}/events', 'auth' => ['holder'], 'merchant' => false, 'idempotency' => null, 'body' => false, 'query' => false, 'headers' => [], 'response' => 'stream', 'paged' => false, 'stream' => true, 'deprecated' => null],", $ops);
        self::assertStringContainsString("'forgetThing' => ['method' => 'DELETE', 'path' => '/v1/things/{id}/events', 'auth' => ['key', 'public'], 'merchant' => false, 'idempotency' => 'optional', 'body' => false, 'query' => false, 'headers' => [], 'response' => 'none'", $ops);
        self::assertStringContainsString("'touchThing' => ['method' => 'POST', 'path' => '/v1/things/{id}', 'auth' => ['key'], 'merchant' => false, 'idempotency' => null, 'body' => true, 'query' => false, 'headers' => ['x-trace'], 'response' => 'json'", $ops);
        self::assertStringContainsString("'listThings' => ['method' => 'GET', 'path' => '/v1/things', 'auth' => ['key'], 'merchant' => false, 'idempotency' => null, 'body' => false, 'query' => true, 'headers' => [], 'response' => 'json', 'paged' => true", $ops);
        self::assertStringContainsString("'thingImage' => ['method' => 'GET', 'path' => '/v1/things.png', 'auth' => ['public'], 'merchant' => false, 'idempotency' => null, 'body' => false, 'query' => false, 'headers' => [], 'response' => 'blob'", $ops);
        self::assertStringContainsString("public const API_VERSION = '9.9.9';", $ops);
        $methods = self::file(self::fixture(), 'Methods.php');
        self::assertStringContainsString("    public function thingEvents(array \$args): Generator\n    {\n        return \$this->open('thingEvents', \$args);\n    }", $methods);
        self::assertStringContainsString("     *     reconnect?: bool|null,\n     *     idleTimeout?: int|float|null,\n", $methods);
        self::assertStringContainsString("    public function forgetThing(array \$args): void\n    {\n        \$this->call('forgetThing', \$args);\n    }", $methods);
        self::assertStringContainsString("     *     idempotencyKey?: string|null,\n", $methods);
        self::assertStringContainsString('printable ASCII characters. When it is left out', $methods);
        self::assertStringContainsString(implode("\n", [
            '     * @param array{',
            '     *     query?: array{page?: int|null},',
            '     *     timeout?: int|float|null,',
            '     *     maxRetries?: int|null,',
            '     * } $args',
        ]), $methods);
        $codes = self::file(self::fixture(), 'ErrorCode.php');
        self::assertStringContainsString("    /** Bulunamadı */\n    public const NOT_FOUND = 'NOT_FOUND';\n    public const INTERNAL = 'INTERNAL';\n", $codes);
        self::assertStringContainsString("    public const TITLES = [\n        'NOT_FOUND' => 'Bulunamadı',\n    ];", $codes);
    }

    public function testMakesTheIdempotencyKeyRequiredWhereTheDocumentRequiresTheHeader(): void
    {
        $files = self::files(self::fixture(true));
        self::assertStringContainsString("     *     idempotencyKey: string,\n", ($files['Methods.php'] ?? ''));
        self::assertStringNotContainsString("     *     idempotencyKey?: string|null,\n", ($files['Methods.php'] ?? ''));
        self::assertStringContainsString("'idempotency' => 'required'", ($files['Operations.php'] ?? ''));
    }

    public function testWritesPhpThatParses(): void
    {
        $dir = sys_get_temp_dir() . '/rewloy-generate-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            foreach (self::files(self::fixture()) as $name => $content) {
                file_put_contents($dir . '/' . $name, $content);
                exec(escapeshellarg(PHP_BINARY) . ' -n -l ' . escapeshellarg($dir . '/' . $name) . ' 2>&1', $output, $code);
                self::assertSame(0, $code, $name . ': ' . implode("\n", $output));
            }
        } finally {
            foreach (glob($dir . '/*.php') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function testRefusesWhatItCannotGenerate(): void
    {
        $refused = [
            'not an OpenAPI 3 document' => [],
            'the document has no operations' => ['openapi' => '3.1.0', 'paths' => []],
        ];
        $duplicate = self::fixture();
        self::setId($duplicate, '/v1/things/{id}/events', 'get', 'getThing');
        $refused['operationId "getThing" is used twice'] = $duplicate;
        $folded = self::fixture();
        self::setId($folded, '/v1/things/{id}/events', 'get', 'getthing');
        $refused['operationId "getthing" is used twice (as "getThing"; PHP method names ignore case)'] = $folded;
        $reserved = self::fixture();
        self::setId($reserved, '/v1/things/{id}/events', 'get', 'paginate');
        $refused['operationId "paginate" collides with a client method'] = $reserved;
        $call = self::fixture();
        self::setId($call, '/v1/things/{id}/events', 'get', 'call');
        $refused['operationId "call" collides with a client method'] = $call;
        $snake = self::fixture();
        self::setId($snake, '/v1/things/{id}/events', 'get', 'get_thing');
        $refused['operationId "get_thing" is not camelCase'] = $snake;
        $allOf = self::fixture();
        $allOf['components'] = ['schemas' => ['PageMeta' => ['allOf' => [['type' => 'object']]]]];
        $refused['allOf is not supported'] = $allOf;
        $cycle = self::fixture();
        $cycle['components'] = ['schemas' => ['PageMeta' => ['type' => 'object', 'properties' => ['next' => ['$ref' => '#/components/schemas/PageMeta']]]]];
        $refused['the $ref #/components/schemas/PageMeta refers to itself'] = $cycle;

        foreach ($refused as $message => $document) {
            try {
                Generator::generate($document);
                self::fail('generated: ' . $message);
            } catch (RuntimeException $e) {
                self::assertSame('generate: ' . $message, $e->getMessage());
            }
        }
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function setId(array &$document, string $path, string $method, string $id): void
    {
        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);
        $item = $paths[$path] ?? null;
        self::assertIsArray($item);
        $op = $item[$method] ?? null;
        self::assertIsArray($op);
        $op['operationId'] = $id;
        $item[$method] = $op;
        $paths[$path] = $item;
        $document['paths'] = $paths;
    }
}

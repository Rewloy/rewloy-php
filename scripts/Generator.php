<?php

declare(strict_types=1);

namespace Rewloy\Dev;

use RuntimeException;

/**
 * The generator: Rewloy's OpenAPI 3.1 document in, the PHP of src/Generated/
 * out. Pure (no I/O), so that a test can run it on the committed snapshot and
 * compare (tests/GenerateTest.php); scripts/generate.php is the command around
 * it.
 *
 * It reads only what the document says, the way the platform writes it (and as
 * the Node library's scripts/generator.ts reads it): inline JSON Schemas,
 * `Error` and `PageMeta` as components, `x-credentials` for the credential
 * kinds, the `Rewloy-Merchant` and `Idempotency-Key` header parameters, the
 * `{ data[, meta] }` envelope and one example per error code.
 *
 * Types are PHPDoc array shapes written inline at each method, so that every
 * tool reads them: PhpStorm, Intelephense and PHPStan alike.
 *
 * Output (deterministic: the document's order, no dates):
 *   Methods.php     one method per operation, named by its operationId: a trait the client uses
 *   Operations.php  the metadata table, and the API version
 *   ErrorCode.php   a constant per error code, with the catalogue's titles
 *
 * @phpstan-type Param array{name: string, schema: mixed, required: bool, description: string|null}
 * @phpstan-type Op array{
 *     id: string,
 *     method: string,
 *     path: string,
 *     tag: string,
 *     summary: string,
 *     description: string,
 *     auth: list<string>,
 *     deprecated: array{sunset: string|null, use: string|null}|null,
 *     pathParams: list<Param>,
 *     queryParams: list<Param>,
 *     merchant: Param|null,
 *     idempotency: Param|null,
 *     otherHeaders: list<Param>,
 *     body: array{schema: mixed, required: bool}|null,
 *     response: 'json'|'none'|'blob'|'raw-json'|'stream',
 *     paged: bool,
 *     data: mixed,
 * }
 */
final class Generator
{
    public const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete'];
    private const MERCHANT_HEADER = 'rewloy-merchant';
    private const IDEMPOTENCY_HEADER = 'idempotency-key';
    private const SECURITY_KINDS = ['apiKey' => 'key', 'staffSession' => 'staff', 'holderSession' => 'holder'];
    private const REFERENCE = 'https://rewloy.com/gelistiriciler/api';
    private const CHANGELOG = 'https://rewloy.com/gelistiriciler/degisiklikler';
    /** Methods the client defines itself: an operationId may not take them (PHP ignores case in method names). */
    private const RESERVED_METHODS = ['request', 'paginate', 'stream', 'call', 'open'];
    private const TR_MONTHS = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    /** A shape whose one-line form is longer than this goes on several lines. */
    private const LINE = 72;
    private const INDENT = '    ';

    /** @var array<mixed> components.schemas */
    private array $components;

    /** @var list<string> The $refs being written, to refuse a cycle. */
    private array $resolving = [];

    /** @param array<mixed> $components */
    private function __construct(array $components)
    {
        $this->components = $components;
    }

    /**
     * The files of src/Generated/ for a document (decoded with objects as arrays).
     *
     * @return list<array{path: string, content: string}>
     */
    public static function generate(mixed $document): array
    {
        $doc = self::obj($document);
        $openapi = $doc['openapi'] ?? null;
        if ($doc === null || !is_string($openapi) || !str_starts_with($openapi, '3.')) {
            self::fail('not an OpenAPI 3 document');
        }
        $version = self::str(self::obj($doc['info'] ?? null)['version'] ?? null) ?? '0.0.0';
        $paths = self::obj($doc['paths'] ?? null) ?? self::fail('the document has no paths');
        $components = self::obj(self::obj($doc['components'] ?? null)['schemas'] ?? null) ?? [];
        $generator = new self($components);

        $ops = [];
        $seen = [];
        foreach ($paths as $path => $item) {
            $item = self::obj($item);
            if ($item === null) {
                continue;
            }
            foreach (self::HTTP_METHODS as $method) {
                $raw = self::obj($item[$method] ?? null);
                if ($raw === null) {
                    continue;
                }
                $op = self::readOp((string) $path, $method, $raw);
                $folded = strtolower($op['id']);
                if (isset($seen[$folded])) {
                    self::fail(sprintf('operationId "%s" is used twice%s', $op['id'], $seen[$folded] !== $op['id'] ? sprintf(' (as "%s"; PHP method names ignore case)', $seen[$folded]) : ''));
                }
                $seen[$folded] = $op['id'];
                $ops[] = $op;
            }
        }
        if ($ops === []) {
            self::fail('the document has no operations');
        }

        $codes = [];
        $error = self::obj($components['Error'] ?? null);
        $codeSchema = self::obj(self::obj(self::obj(self::obj($error['properties'] ?? null)['error'] ?? null)['properties'] ?? null)['code'] ?? null);
        foreach (self::listOf($codeSchema['enum'] ?? null) as $code) {
            if (is_string($code)) {
                $codes[] = $code;
            }
        }
        $titles = self::errorTitles($paths);
        $ordered = [];
        foreach ($codes as $code) {
            if (isset($titles[$code])) {
                $ordered[$code] = $titles[$code];
            }
        }
        $ordered += $titles;

        return [
            ['path' => 'src/Generated/Methods.php', 'content' => $generator->methodsFile($version, $ops)],
            ['path' => 'src/Generated/Operations.php', 'content' => self::operationsFile($version, $ops)],
            ['path' => 'src/Generated/ErrorCode.php', 'content' => self::errorCodeFile($version, $codes !== [] ? $codes : array_keys($titles), $ordered)],
        ];
    }

    /**
     * "1 Nisan 2027" (the document's deprecation sentence) → "2027-04-01", and
     * the operation that replaces it ("yerine `x`").
     *
     * @return array{sunset: string|null, use: string|null}
     */
    public static function parseDeprecation(string $description): array
    {
        $sunset = null;
        if (preg_match('/(\d{1,2}) (' . implode('|', self::TR_MONTHS) . ') (\d{4})/u', $description, $m) === 1) {
            $month = (int) array_search($m[2], self::TR_MONTHS, true) + 1;
            $sunset = sprintf('%s-%02d-%02d', $m[3], $month, (int) $m[1]);
        }
        $use = preg_match('/yerine `([A-Za-z0-9_]+)`/', $description, $u) === 1 ? $u[1] : null;
        return ['sunset' => $sunset, 'use' => $use];
    }

    /* ------------------------------------------------------------ reading */

    /**
     * @param array<mixed> $op
     * @return Op
     */
    private static function readOp(string $path, string $method, array $op): array
    {
        $id = self::str($op['operationId'] ?? null) ?? self::fail(sprintf('%s %s has no operationId', strtoupper($method), $path));
        if (preg_match('/^[a-z][A-Za-z0-9]*$/', $id) !== 1) {
            self::fail(sprintf('operationId "%s" is not camelCase', $id));
        }
        if (in_array(strtolower($id), self::RESERVED_METHODS, true)) {
            self::fail(sprintf('operationId "%s" collides with a client method', $id));
        }

        $headers = self::params($op, 'header');
        $merchant = null;
        $idempotency = null;
        $other = [];
        foreach ($headers as $h) {
            $name = strtolower($h['name']);
            if ($name === self::MERCHANT_HEADER && $merchant === null) {
                $merchant = $h;
            } elseif ($name === self::IDEMPOTENCY_HEADER && $idempotency === null) {
                $idempotency = $h;
            } else {
                $other[] = $h;
            }
        }

        $auth = [];
        if (is_array($op['x-credentials'] ?? null)) {
            foreach ($op['x-credentials'] as $c) {
                $auth[] = is_scalar($c) ? (string) $c : '';
            }
        } else {
            foreach (self::listOf($op['security'] ?? null) as $s) {
                $s = self::obj($s);
                if ($s === null) {
                    continue;
                }
                if ($s === []) {
                    $auth[] = 'public';
                }
                foreach (array_keys($s) as $k) {
                    $auth[] = self::SECURITY_KINDS[$k] ?? (string) $k;
                }
            }
        }

        $body = null;
        $requestBody = self::obj($op['requestBody'] ?? null);
        if ($requestBody !== null) {
            $json = self::obj(self::obj($requestBody['content'] ?? null)['application/json'] ?? null)
                ?? self::fail(sprintf('%s: only application/json request bodies are supported', $id));
            $body = ['schema' => $json['schema'] ?? null, 'required' => ($requestBody['required'] ?? false) === true];
        }

        $responses = self::obj($op['responses'] ?? null) ?? self::fail(sprintf('%s has no responses', $id));
        $ok = [];
        foreach (array_keys($responses) as $status) {
            if (preg_match('/^2\d\d$/', (string) $status) === 1) {
                $ok[] = (string) $status;
            }
        }
        sort($ok, SORT_STRING);
        if ($ok === []) {
            self::fail(sprintf('%s has no 2xx response', $id));
        }
        $content = self::obj(self::obj($responses[$ok[0]] ?? null)['content'] ?? null);
        $response = 'none';
        $paged = false;
        $data = null;
        if ($content !== null && $content !== [] && $ok[0] !== '204') {
            $type = (string) array_key_first($content);
            $schema = self::obj(self::obj($content[$type] ?? null)['schema'] ?? null) ?? [];
            $isJson = preg_match('#^application/([a-z.+-]+\+)?json\b#', $type) === 1;
            $properties = self::obj($schema['properties'] ?? null);
            if (str_starts_with($type, 'text/event-stream')) {
                $response = 'stream';
            } elseif ($isJson && $properties !== null && array_key_exists('data', $properties)) {
                $response = 'json';
                $data = $properties['data'];
                $paged = array_key_exists('meta', $properties);
            } else {
                $response = $isJson ? 'raw-json' : 'blob';
            }
        }

        $description = self::str($op['description'] ?? null) ?? '';
        $tags = self::listOf($op['tags'] ?? null);
        return [
            'id' => $id,
            'method' => strtoupper($method),
            'path' => $path,
            'tag' => is_string($tags[0] ?? null) ? $tags[0] : '',
            'summary' => self::str($op['summary'] ?? null) ?? '',
            'description' => $description,
            'auth' => $auth,
            'deprecated' => ($op['deprecated'] ?? false) === true ? self::parseDeprecation($description) : null,
            'pathParams' => self::params($op, 'path'),
            'queryParams' => self::params($op, 'query'),
            'merchant' => $merchant,
            'idempotency' => $idempotency,
            'otherHeaders' => $other,
            'body' => $body,
            'response' => $response,
            'paged' => $paged,
            'data' => $data,
        ];
    }

    /**
     * @param array<mixed> $op
     * @return list<Param>
     */
    private static function params(array $op, string $where): array
    {
        $out = [];
        foreach (self::listOf($op['parameters'] ?? null) as $p) {
            $p = self::obj($p);
            if ($p === null || ($p['in'] ?? null) !== $where) {
                continue;
            }
            $schema = $p['schema'] ?? null;
            $out[] = [
                'name' => self::str($p['name'] ?? null) ?? self::fail('a parameter without a name'),
                'schema' => $schema,
                'required' => ($p['required'] ?? false) === true,
                'description' => self::str($p['description'] ?? null) ?? self::str(self::obj($schema)['description'] ?? null),
            ];
        }
        return $out;
    }

    /**
     * Error titles, from each error code's example (`summary` is the catalogue's title).
     *
     * @param array<mixed> $paths
     * @return array<string, string>
     */
    private static function errorTitles(array $paths): array
    {
        $titles = [];
        foreach ($paths as $item) {
            $item = self::obj($item) ?? [];
            foreach (self::HTTP_METHODS as $method) {
                $responses = self::obj(self::obj($item[$method] ?? null)['responses'] ?? null) ?? [];
                foreach ($responses as $r) {
                    $media = self::obj(self::obj(self::obj($r)['content'] ?? null)['application/json'] ?? null);
                    foreach (self::obj($media['examples'] ?? null) ?? [] as $code => $example) {
                        $title = self::str(self::obj($example)['summary'] ?? null);
                        if ($title !== null && $title !== '' && !isset($titles[(string) $code])) {
                            $titles[(string) $code] = $title;
                        }
                    }
                }
            }
        }
        return $titles;
    }

    /* ------------------------------------------------------------ types */

    /**
     * A schema's PHPDoc type; `$indent` is the indentation of the line it
     * starts on, for the lines of a multi-line shape.
     */
    private function type(mixed $schema, string $indent): string
    {
        return implode('|', $this->members($schema, $indent));
    }

    /**
     * The members of a schema's type union.
     *
     * @return list<string>
     */
    private function members(mixed $schema, string $indent): array
    {
        if ($schema === null || $schema === true || $schema === []) {
            return ['mixed'];
        }
        if ($schema === false) {
            return ['never'];
        }
        if (!is_array($schema)) {
            self::fail('a schema that is neither an object nor a boolean');
        }
        if (is_string($schema['$ref'] ?? null)) {
            $out = $this->ref($schema['$ref'], $indent);
        } elseif (array_key_exists('const', $schema)) {
            $out = [self::literal($schema['const'])];
        } elseif (is_array($schema['enum'] ?? null)) {
            $out = array_map(self::literal(...), array_values($schema['enum']));
        } elseif (is_array($schema['oneOf'] ?? null) || is_array($schema['anyOf'] ?? null)) {
            $out = [];
            foreach (self::listOf($schema['oneOf'] ?? $schema['anyOf'] ?? null) as $member) {
                array_push($out, ...$this->members($member, $indent));
            }
        } elseif (array_key_exists('allOf', $schema)) {
            self::fail('allOf is not supported');
        } else {
            $declared = $schema['type'] ?? null;
            if (is_array($declared)) {
                $types = array_map(static fn (mixed $t): string => is_string($t) ? $t : '', array_values($declared));
            } elseif (is_string($declared)) {
                $types = [$declared];
            } elseif (array_key_exists('properties', $schema) || array_key_exists('additionalProperties', $schema)) {
                $types = ['object'];
            } elseif (array_key_exists('items', $schema)) {
                $types = ['array'];
            } else {
                $types = [];
            }
            $out = [];
            foreach ($types as $t) {
                array_push($out, ...$this->single($t, $schema, $indent));
            }
            if ($out === []) {
                $out = ['mixed'];
            }
        }
        if (($schema['nullable'] ?? false) === true) {
            $out[] = 'null';
        }
        return self::union($out);
    }

    /**
     * @param array<mixed> $schema
     * @return list<string>
     */
    private function single(string $type, array $schema, string $indent): array
    {
        return match ($type) {
            'string' => ['string'],
            'integer' => ['int'],
            'number' => ['int', 'float'],
            'boolean' => ['bool'],
            'null' => ['null'],
            'array' => ['list<' . $this->type($schema['items'] ?? null, $indent) . '>'],
            'object' => [$this->object($schema, $indent)],
            default => ['mixed'],
        };
    }

    /**
     * An array shape, one key per JSON property. An object that may carry
     * other properties as well has no portable shape: it is `array<string, mixed>`.
     *
     * @param array<mixed> $schema
     */
    private function object(array $schema, string $indent): string
    {
        $properties = self::obj($schema['properties'] ?? null) ?? [];
        $required = array_map(static fn (mixed $r): string => is_scalar($r) ? (string) $r : '', self::listOf($schema['required'] ?? null));
        $hasExtra = array_key_exists('additionalProperties', $schema);
        $extra = $schema['additionalProperties'] ?? null;
        if ($properties === []) {
            if ($hasExtra && $extra === false) {
                return 'array{}';
            }
            if (!$hasExtra || $extra === true || $extra === [] || $extra === null) {
                return 'array<string, mixed>';
            }
            return 'array<string, ' . $this->type($extra, $indent) . '>';
        }
        if ($hasExtra && $extra !== false) {
            return 'array<string, mixed>';
        }
        $inner = $indent . self::INDENT;
        $members = [];
        foreach ($properties as $name => $property) {
            $members[] = self::key((string) $name) . (in_array((string) $name, $required, true) ? '' : '?') . ': ' . $this->type($property, $inner);
        }
        return self::shape($members, $indent);
    }

    /** @return list<string> */
    private function ref(string $ref, string $indent): array
    {
        if (preg_match('#^\#/components/schemas/(.+)$#', $ref, $m) !== 1 || !array_key_exists($m[1], $this->components)) {
            self::fail(sprintf('unsupported $ref %s', $ref));
        }
        if (in_array($ref, $this->resolving, true)) {
            self::fail(sprintf('the $ref %s refers to itself', $ref));
        }
        $this->resolving[] = $ref;
        try {
            return $this->members($this->components[$m[1]], $indent);
        } finally {
            array_pop($this->resolving);
        }
    }

    /**
     * `array{a: int, b?: string}` on one line when it is short and flat, else one key per line.
     *
     * @param list<string> $members
     */
    private static function shape(array $members, string $indent): string
    {
        $one = 'array{' . implode(', ', $members) . '}';
        if (!str_contains($one, "\n") && strlen($one) <= self::LINE) {
            return $one;
        }
        $inner = $indent . self::INDENT;
        return "array{\n" . implode("\n", array_map(static fn (string $m): string => $inner . $m . ',', $members)) . "\n" . $indent . '}';
    }

    /**
     * Distinct members, in order; `mixed` absorbs the rest, `never` disappears beside others.
     *
     * @param list<string> $members
     * @return list<string>
     */
    private static function union(array $members): array
    {
        $unique = array_values(array_unique($members));
        if (in_array('mixed', $unique, true)) {
            return ['mixed'];
        }
        $unique = array_values(array_filter($unique, static fn (string $m): bool => $m !== 'never'));
        return $unique === [] ? ['never'] : $unique;
    }

    /** A PHPDoc literal type for a JSON value. */
    private static function literal(mixed $value): string
    {
        if (is_string($value)) {
            if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                self::fail(sprintf('the value %s has a control character, which a PHPDoc literal cannot carry', json_encode($value)));
            }
            return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return var_export($value, true);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        return 'mixed';
    }

    /** An array shape key: bare when it is an identifier, quoted otherwise. */
    private static function key(string $name): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 ? $name : self::literal($name);
    }

    /** No required property at the top level: `{}` is a valid body. */
    private static function requiredFree(mixed $schema): bool
    {
        return self::listOf(self::obj($schema)['required'] ?? null) === [];
    }

    /* ------------------------------------------------------------ files */

    private static function header(string $version): string
    {
        return "<?php\n\n"
            . "// Generated by scripts/generate.php from the Rewloy OpenAPI document\n"
            . '// (openapi/openapi.json, API ' . $version . "). Do not edit: run `composer generate`.\n\n"
            . "declare(strict_types=1);\n\n"
            . "namespace Rewloy\\Generated;\n";
    }

    /** @param list<Op> $ops */
    private function methodsFile(string $version, array $ops): string
    {
        $out = self::header($version) . "\n"
            . "use Generator;\n"
            . "use Rewloy\\Exception\\RewloyException;\n"
            . "use Rewloy\\ServerSentEvent;\n\n"
            . self::doc([
                'One method per operation of the API, named by its operationId. `Rewloy\Client` uses it: call them on a client.',
                "Each takes one array: `params`, `query` and `body` as the operation needs, `merchant` and\n`idempotencyKey` where it takes those headers, and `timeout` (seconds) and `maxRetries`.\nEach returns the answer's `data`: for paged lists `['data' => …, 'meta' => …]`.",
            ], '')
            . "trait Methods\n{\n"
            . self::doc([], self::INDENT, ['@internal Implemented by the client.', '@param array<string, mixed> $args'])
            . self::INDENT . "abstract protected function call(string \$operation, array \$args): mixed;\n\n"
            . self::doc([], self::INDENT, ['@internal Implemented by the client.', '@param array<string, mixed> $args', '@return Generator<int, ServerSentEvent, mixed, void>'])
            . self::INDENT . "abstract protected function open(string \$operation, array \$args): Generator;\n";
        $tag = null;
        foreach ($ops as $op) {
            if ($op['tag'] !== $tag) {
                $tag = $op['tag'];
                $out .= "\n" . self::INDENT . '// ' . str_repeat('-', 60) . ' ' . $tag . "\n";
            }
            $out .= "\n" . $this->method($op);
        }
        return $out . "}\n";
    }

    /** @param Op $op */
    private function method(array $op): string
    {
        $sections = [];
        $text = trim(implode("\n\n", array_filter([$op['summary'], $op['description']], static fn (string $s): bool => $s !== '')));
        if ($text !== '') {
            $sections[] = $text;
        }
        $sections[] = '`' . $op['method'] . ' ' . $op['path'] . '`';
        $arguments = $this->argumentNotes($op);
        if ($arguments !== []) {
            $sections[] = "Arguments:\n" . implode("\n", $arguments);
        }
        $fields = [];
        if ($op['response'] === 'json') {
            $this->deprecatedFields($op['data'], 'data', $fields);
        }
        if ($fields !== []) {
            $sections[] = "Fields of the answer marked for removal:\n" . implode("\n", $fields);
        }

        [$returnDoc, $native] = $this->returnType($op);
        $tags = ['@see ' . self::REFERENCE . '#op-' . $op['id'] . ' API referansı'];
        if ($op['deprecated'] !== null) {
            $tags[] = '@deprecated ' . self::deprecationNote($op);
        }
        $tags[] = '';
        $tags[] = '@param ' . $this->argsType($op) . ' $args';
        if ($returnDoc !== null) {
            $tags[] = '@return ' . $returnDoc;
        }
        $tags[] = '';
        $tags[] = '@throws RewloyException';

        $in = self::INDENT;
        $body = $in . $in;
        $signature = $in . 'public function ' . $op['id'] . '(array $args' . (self::argsOptional($op) ? ' = []' : '') . '): ' . $native;
        $call = sprintf("\$this->call('%s', \$args)", $op['id']);
        $statements = match ($op['response']) {
            'none' => $body . $call . ";\n",
            'raw-json' => $body . 'return ' . $call . ";\n",
            'stream' => $body . sprintf("return \$this->open('%s', \$args);\n", $op['id']),
            default => self::doc([], $body, ['@var ' . (string) $returnDoc . ' $data'])
                . $body . '$data = ' . $call . ";\n"
                . $body . "return \$data;\n",
        };
        return self::doc($sections, $in, $tags) . $signature . "\n" . $in . "{\n" . $statements . $in . "}\n";
    }

    /**
     * The PHPDoc and native return types of an operation's method.
     *
     * @param Op $op
     * @return array{string|null, string}
     */
    private function returnType(array $op): array
    {
        switch ($op['response']) {
            case 'none':
                return [null, 'void'];
            case 'blob':
                return ['string', 'string'];
            case 'raw-json':
                return ['mixed', 'mixed'];
            case 'stream':
                return ['Generator<int, ServerSentEvent, mixed, void>', 'Generator'];
        }
        if ($op['paged']) {
            $items = self::obj($op['data'])['items'] ?? null;
            $meta = $this->type(['$ref' => '#/components/schemas/PageMeta'], self::INDENT);
            return [self::shape(['data: list<' . $this->type($items, self::INDENT) . '>', 'meta: ' . $meta], ''), 'array'];
        }
        $members = $this->members($op['data'], '');
        $arrays = array_filter($members, static fn (string $m): bool => str_starts_with($m, 'array') || str_starts_with($m, 'list<'));
        $native = 'mixed';
        if (count($arrays) === count($members)) {
            $native = 'array';
        } elseif (count($arrays) === count($members) - 1 && in_array('null', $members, true)) {
            $native = '?array';
        } elseif ($members === ['string'] || $members === ['int'] || $members === ['bool']) {
            $native = $members[0];
        }
        return [implode('|', $members), $native];
    }

    /**
     * The `$args` shape: what the operation needs, then the options every call takes.
     *
     * @param Op $op
     */
    private function argsType(array $op): string
    {
        $inner = self::INDENT;
        $members = [];
        if ($op['pathParams'] !== []) {
            $members[] = 'params: ' . $this->paramsShape($op['pathParams'], $inner);
        }
        if ($op['queryParams'] !== []) {
            $members[] = 'query' . (self::anyRequired($op['queryParams']) ? '' : '?') . ': ' . $this->paramsShape($op['queryParams'], $inner);
        }
        if ($op['body'] !== null) {
            $required = $op['body']['required'] && !self::requiredFree($op['body']['schema']);
            $members[] = 'body' . ($required ? '' : '?') . ': ' . $this->type($op['body']['schema'], $inner);
        }
        // Null leaves an option out, as the client reads it: handy when the value comes from a nullable variable.
        if ($op['idempotency'] !== null) {
            $members[] = $op['idempotency']['required'] ? 'idempotencyKey: string' : 'idempotencyKey?: string|null';
        }
        if ($op['merchant'] !== null) {
            $members[] = 'merchant?: string|null';
        }
        if ($op['otherHeaders'] !== []) {
            $members[] = 'headers' . (self::anyRequired($op['otherHeaders']) ? '' : '?') . ': ' . $this->paramsShape($op['otherHeaders'], $inner);
        }
        $members[] = 'timeout?: int|float|null';
        $members[] = 'maxRetries?: int|null';
        if ($op['response'] === 'stream') {
            $members[] = 'reconnect?: bool|null';
            $members[] = 'idleTimeout?: int|float|null';
        }
        return self::shape($members, '');
    }

    /**
     * Path, query or header parameters. An optional one may also be null: the
     * client leaves it out of the URL or the headers.
     *
     * @param list<Param> $params
     */
    private function paramsShape(array $params, string $indent): string
    {
        $members = [];
        foreach ($params as $p) {
            $type = $this->members($p['schema'], $indent . self::INDENT);
            if (!$p['required'] && !in_array('null', $type, true) && $type !== ['mixed']) {
                $type[] = 'null';
            }
            $members[] = self::key($p['name']) . ($p['required'] ? '' : '?') . ': ' . implode('|', $type);
        }
        return self::shape($members, $indent);
    }

    /**
     * What the API says of each argument: path and query parameters, the
     * body's top-level fields and the headers.
     *
     * @param Op $op
     * @return list<string>
     */
    private function argumentNotes(array $op): array
    {
        $notes = [];
        foreach ($op['pathParams'] as $p) {
            if ($p['description'] !== null && $p['description'] !== '') {
                $notes[] = self::note('params.' . $p['name'], $p['description']);
            }
        }
        foreach ($op['queryParams'] as $p) {
            if ($p['description'] !== null && $p['description'] !== '') {
                $notes[] = self::note('query.' . $p['name'], $p['description']);
            }
        }
        if ($op['body'] !== null) {
            foreach (self::obj(self::obj($op['body']['schema'])['properties'] ?? null) ?? [] as $name => $property) {
                $description = self::str(self::obj($property)['description'] ?? null);
                if ($description !== null && $description !== '') {
                    $notes[] = self::note('body.' . $name, $description);
                }
            }
        }
        if ($op['idempotency'] !== null) {
            $notes[] = self::note('idempotencyKey', trim(($op['idempotency']['description'] ?? '')
                . ($op['idempotency']['required']
                    ? ' The `Idempotency-Key` header, required: 8–64 printable ASCII characters. The client never makes one up (a generated key would not survive a restart of your app); it sends this one on every retry of the call.'
                    : ' The `Idempotency-Key` header: 8–64 printable ASCII characters. When it is left out, the client generates one and sends the same one on every retry of this call.')));
        }
        if ($op['merchant'] !== null) {
            $notes[] = self::note('merchant', trim(($op['merchant']['description'] ?? '')
                . " The `Rewloy-Merchant` header; the client's `merchant` by default."));
        }
        foreach ($op['otherHeaders'] as $h) {
            if ($h['description'] !== null && $h['description'] !== '') {
                $notes[] = self::note('headers.' . $h['name'], $h['description']);
            }
        }
        return $notes;
    }

    /**
     * Response fields marked for removal (`deprecated: true`), by path.
     *
     * @param list<string> $out
     */
    private function deprecatedFields(mixed $schema, string $path, array &$out): void
    {
        $schema = self::obj($schema);
        if ($schema === null) {
            return;
        }
        foreach (self::obj($schema['properties'] ?? null) ?? [] as $name => $property) {
            $p = self::obj($property);
            if ($p !== null && ($p['deprecated'] ?? false) === true) {
                $out[] = self::note($path . '.' . $name, self::str($p['description'] ?? null) ?? '');
            }
            $this->deprecatedFields($property, $path . '.' . $name, $out);
        }
        if (array_key_exists('items', $schema)) {
            $this->deprecatedFields($schema['items'], $path . '[]', $out);
        }
        foreach (['oneOf', 'anyOf'] as $k) {
            foreach (self::listOf($schema[$k] ?? null) as $member) {
                $this->deprecatedFields($member, $path, $out);
            }
        }
    }

    /** @param Op $op */
    private static function argsOptional(array $op): bool
    {
        if ($op['pathParams'] !== [] || self::anyRequired($op['queryParams']) || self::anyRequired($op['otherHeaders'])) {
            return false;
        }
        return $op['body'] === null || !$op['body']['required'] || self::requiredFree($op['body']['schema']);
    }

    /** @param list<Param> $params */
    private static function anyRequired(array $params): bool
    {
        foreach ($params as $p) {
            if ($p['required']) {
                return true;
            }
        }
        return false;
    }

    private static function note(string $name, string $text): string
    {
        return '- `' . $name . '`: ' . str_replace("\n", "\n  ", trim($text));
    }

    /** @param Op $op */
    private static function deprecationNote(array $op): string
    {
        $d = $op['deprecated'] ?? ['sunset' => null, 'use' => null];
        return implode(' ', array_filter([
            $d['sunset'] !== null ? 'The API stops answering this operation after ' . $d['sunset'] . '.' : 'The API will stop answering this operation.',
            $d['use'] !== null ? 'Use `' . $d['use'] . '()` instead.' : '',
            self::CHANGELOG . '#' . $op['id'],
        ], static fn (string $s): bool => $s !== ''));
    }

    /** @param list<Op> $ops */
    private static function operationsFile(string $version, array $ops): string
    {
        $in = self::INDENT;
        $rows = '';
        foreach ($ops as $op) {
            $fields = [
                "'method' => " . self::php($op['method']),
                "'path' => " . self::php($op['path']),
                "'auth' => [" . implode(', ', array_map(self::php(...), $op['auth'])) . ']',
                "'merchant' => " . ($op['merchant'] !== null ? 'true' : 'false'),
                "'idempotency' => " . ($op['idempotency'] !== null ? self::php($op['idempotency']['required'] ? 'required' : 'optional') : 'null'),
                "'body' => " . ($op['body'] !== null ? 'true' : 'false'),
                "'query' => " . ($op['queryParams'] !== [] ? 'true' : 'false'),
                "'headers' => [" . implode(', ', array_map(static fn (array $h): string => self::php(strtolower($h['name'])), $op['otherHeaders'])) . ']',
                "'response' => " . self::php($op['response']),
                "'paged' => " . ($op['paged'] ? 'true' : 'false'),
                "'stream' => " . ($op['response'] === 'stream' ? 'true' : 'false'),
                "'deprecated' => " . ($op['deprecated'] !== null
                    ? "['sunset' => " . self::phpOrNull($op['deprecated']['sunset']) . ", 'use' => " . self::phpOrNull($op['deprecated']['use']) . ']'
                    : 'null'),
            ];
            $rows .= $in . $in . self::php($op['id']) . ' => [' . implode(', ', $fields) . "],\n";
        }
        return self::header($version) . "\n"
            . self::doc([
                "The metadata table: per operation, its method and path, the credential kinds it accepts\n"
                . "(`key`, `staff`, `holder`, `public`), whether it takes `Rewloy-Merchant`, an `Idempotency-Key`,\n"
                . "a body and a query, how its answer is read, and whether it is paged, streams or is deprecated\n"
                . '(`sunset`: the last day it works; `use`: the operation that replaces it).',
            ], '', [
                "@phpstan-type OperationMeta array{\n"
                . "    method: 'GET'|'POST'|'PUT'|'PATCH'|'DELETE',\n"
                . "    path: string,\n"
                . "    auth: list<'key'|'staff'|'holder'|'public'>,\n"
                . "    merchant: bool,\n"
                . "    idempotency: 'required'|'optional'|null,\n"
                . "    body: bool,\n"
                . "    query: bool,\n"
                . "    headers: list<string>,\n"
                . "    response: 'json'|'none'|'blob'|'raw-json'|'stream',\n"
                . "    paged: bool,\n"
                . "    stream: bool,\n"
                . "    deprecated: array{sunset: string|null, use: string|null}|null,\n"
                . '}',
            ])
            . "final class Operations\n{\n"
            . self::doc(['The version of the API document this was generated from (`info.version`).'], $in)
            . $in . 'public const API_VERSION = ' . self::php($version) . ";\n\n"
            . self::doc([], $in, ['@var array<string, OperationMeta>'])
            . $in . "public const ALL = [\n" . $rows . $in . "];\n\n"
            . self::doc(["An operation's row, or null for an unknown operationId."], $in, ['@return OperationMeta|null'])
            . $in . "public static function get(string \$operation): ?array\n"
            . $in . "{\n"
            . $in . $in . "return self::ALL[\$operation] ?? null;\n"
            . $in . "}\n\n"
            . $in . "private function __construct()\n"
            . $in . "{\n"
            . $in . "}\n"
            . "}\n";
    }

    /**
     * @param list<string> $codes
     * @param array<string, string> $titles
     */
    private static function errorCodeFile(string $version, array $codes, array $titles): string
    {
        $in = self::INDENT;
        $constants = '';
        foreach ($codes as $code) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $code) !== 1 || $code === 'CLASS') {
                self::fail(sprintf('the error code "%s" cannot be a PHP constant', $code));
            }
            $title = $titles[$code] ?? '';
            $constants .= ($title !== '' ? self::doc([$title], $in) : '') . $in . 'public const ' . $code . ' = ' . self::php($code) . ";\n";
        }
        $all = implode('', array_map(static fn (string $c): string => $in . $in . self::php($c) . ",\n", $codes));
        $map = '';
        foreach ($titles as $code => $title) {
            $map .= $in . $in . self::php($code) . ' => ' . self::php($title) . ",\n";
        }
        return self::header($version) . "\n"
            . self::doc([
                "Every error code the API can answer with, as constants, and each code's one-line title in the\n"
                . 'catalogue (https://rewloy.com/gelistiriciler/hatalar). New codes may be added without notice:'
                . "\nkeep a default branch.",
                "    if (\$e->errorCode === ErrorCode::INSUFFICIENT_BALANCE) { … }",
            ], '')
            . "final class ErrorCode\n{\n"
            . $constants . "\n"
            . self::doc(['Every code, in the catalogue\'s order.'], $in, ['@var list<string>'])
            . $in . "public const ALL = [\n" . $all . $in . "];\n\n"
            . self::doc(["Each code's one-line title in the catalogue."], $in, ['@var array<string, string>'])
            . $in . "public const TITLES = [\n" . $map . $in . "];\n\n"
            . self::doc(["A code's title, or null when the catalogue has none."], $in)
            . $in . "public static function title(string \$code): ?string\n"
            . $in . "{\n"
            . $in . $in . "return self::TITLES[\$code] ?? null;\n"
            . $in . "}\n\n"
            . $in . "private function __construct()\n"
            . $in . "{\n"
            . $in . "}\n"
            . "}\n";
    }

    /**
     * A docblock: the document's text first (made safe), then the generator's own tags.
     *
     * In the document's text a `*` followed by `/` cannot end the comment
     * early, and an `@` at a line start is not a tag.
     *
     * @param list<string> $sections Paragraphs, from the document.
     * @param list<string> $tags The generator's own lines (`@param …`); '' for a blank line.
     */
    private static function doc(array $sections, string $indent, array $tags = []): string
    {
        $parts = [];
        foreach ($sections as $s) {
            $safe = trim((string) preg_replace('/^(\s*)@/m', '$1&#64;', str_replace('*/', '*\\/', $s)));
            if ($safe !== '') {
                $parts[] = $safe;
            }
        }
        $lines = [];
        foreach ($parts as $i => $part) {
            if ($i > 0) {
                $lines[] = '';
            }
            array_push($lines, ...explode("\n", $part));
        }
        if ($tags !== []) {
            if ($lines !== []) {
                $lines[] = '';
            }
            foreach ($tags as $tag) {
                array_push($lines, ...explode("\n", $tag));
            }
        }
        if ($lines === []) {
            return '';
        }
        if (count($lines) === 1 && $tags === []) {
            return $indent . '/** ' . rtrim($lines[0]) . " */\n";
        }
        $body = array_map(static fn (string $l): string => rtrim($indent . ' * ' . $l), $lines);
        return $indent . "/**\n" . implode("\n", $body) . "\n" . $indent . " */\n";
    }

    /** A PHP single-quoted string literal. */
    private static function php(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    private static function phpOrNull(?string $value): string
    {
        return $value === null ? 'null' : self::php($value);
    }

    /* ------------------------------------------------------------ JSON helpers */

    /** @return array<mixed>|null */
    private static function obj(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    /** @return list<mixed> */
    private static function listOf(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function fail(string $message): never
    {
        throw new RuntimeException('generate: ' . $message);
    }
}

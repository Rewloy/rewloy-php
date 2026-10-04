<?php

/**
 * composer generate                               fetch the live document, keep it as
 *                                                 openapi/openapi.json, write src/Generated/
 * composer generate -- --file <path>              generate from a saved document, e.g. the
 *                                                 committed snapshot (reproducible builds)
 * composer generate -- --url <url>                fetch from another address
 *
 * The generator checks the document (it refuses what it does not understand)
 * before anything is written; files that did not change are left alone. The
 * snapshot is written as the Node library writes its own (two-space JSON), so
 * the two can be compared byte for byte.
 */

declare(strict_types=1);

require_once __DIR__ . '/Generator.php';

use Rewloy\Dev\Generator;

const LIVE = 'https://app.rewloy.com/v1/openapi.json';

$root = dirname(__DIR__);
$snapshot = $root . '/openapi/openapi.json';

/** The value after a flag, or null when the flag is absent. */
function option(string $name): ?string
{
    global $argv;
    $i = array_search($name, $argv, true);
    if ($i === false) {
        return null;
    }
    $value = $argv[$i + 1] ?? '';
    if ($value === '' || str_starts_with($value, '--')) {
        throw new RuntimeException($name . ' needs a value');
    }
    return $value;
}

function fetch(string $url): string
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: rewloy-php-generator'],
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if (!is_string($body)) {
        throw new RuntimeException('GET ' . $url . ': ' . curl_error($ch));
    }
    if ($status !== 200) {
        throw new RuntimeException('GET ' . $url . ': HTTP ' . $status);
    }
    return $body;
}

/** Writes when the content differs; returns whether it did. */
function put(string $path, string $content): bool
{
    if (is_file($path) && file_get_contents($path) === $content) {
        return false;
    }
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('cannot create ' . dirname($path));
    }
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('cannot write ' . $path);
    }
    return true;
}

/** The document as the Node library writes it: two-space JSON, a final newline. */
function pretty(string $json): string
{
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;
    // Objects stay objects (stdClass), so that an empty one is written as {} again.
    $text = json_encode(json_decode($json, false, 512, JSON_THROW_ON_ERROR), $flags);
    return preg_replace_callback('/^(?: {4})+/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[0]), 2)), $text) . "\n";
}

try {
    $file = option('--file');
    $fromFile = $file !== null;
    if ($fromFile) {
        $json = file_get_contents($file);
        if ($json === false) {
            throw new RuntimeException('cannot read ' . $file);
        }
    } else {
        $json = fetch(option('--url') ?? LIVE);
    }
    $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $files = Generator::generate($document);

    $changed = [];
    if (!$fromFile && put($snapshot, pretty($json))) {
        $changed[] = 'openapi/openapi.json';
    }
    foreach ($files as $f) {
        if (put($root . '/' . $f['path'], $f['content'])) {
            $changed[] = $f['path'];
        }
    }

    $count = 0;
    foreach (is_array($document) && is_array($document['paths'] ?? null) ? $document['paths'] : [] as $item) {
        $count += is_array($item) ? count(array_intersect(array_keys($item), Generator::HTTP_METHODS)) : 0;
    }
    echo $count, ' operations; ', $changed !== [] ? 'changed: ' . implode(', ', $changed) : 'nothing changed', PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

<?php

declare(strict_types=1);

namespace Rewloy\Tests\Live;

use Rewloy\Client;
use Rewloy\Exception\RewloyException;
use Throwable;

/**
 * The gate in front of the live tests. They run only against a Rewloy that says it is a DEV
 * server, and only with a test-mode key. Everything here happens before the first test, and
 * a refusal stops the whole run (exit code 2): the live tests must never reach live.
 */
final class Guard
{
    /** Why every test skips (no server configured), or null when the run goes ahead. */
    public static ?string $skip = null;

    public static string $baseUrl = '';

    public static string $apiKey = '';

    private function __construct()
    {
    }

    public static function boot(): void
    {
        $base = trim((string) getenv('REWLOY_BASE_URL'));
        $key = trim((string) getenv('REWLOY_API_KEY'));
        if ($base === '' || $key === '') {
            self::$skip = 'REWLOY_BASE_URL and REWLOY_API_KEY are not both set: the live tests are skipped.';
            return;
        }
        if (preg_match('#^https?://#i', $base) !== 1) {
            self::refuse('REWLOY_BASE_URL must start with http:// or https:// (got "' . $base . '").');
        }
        // Only a test-mode key, and only a well-formed one. Checked before any request is made,
        // so no other kind of key ever leaves this machine.
        if (preg_match('/^rwk_test_[0-9a-f]{10}_\S+$/', $key) !== 1) {
            self::refuse('REWLOY_API_KEY must be a test-mode key (rwk_test_…). Any other key is refused: these tests create and reset data.');
        }

        // The server names its own environment. No key is sent for this question.
        try {
            $meta = (new Client(baseUrl: $base, timeout: 20, maxRetries: 1))->getMeta();
        } catch (RewloyException $e) {
            self::refuse('GET /v1/meta failed (' . $e->getMessage() . '): the environment cannot be verified.');
        } catch (Throwable $e) {
            self::refuse('GET /v1/meta could not be reached at ' . $base . ' (' . $e->getMessage() . '): the environment cannot be verified.');
        }
        // `environment` is newer than the 0.2.4 array shape (a TODO for the regeneration), so read it as data.
        $environment = array_key_exists('environment', $meta) ? $meta['environment'] : null;
        if ($environment !== 'dev') {
            self::refuse(sprintf(
                'GET /v1/meta says environment=%s, not "dev": the live tests run only against a dev server.',
                is_string($environment) ? '"' . $environment . '"' : 'missing',
            ));
        }
        self::$baseUrl = $base;
        self::$apiKey = $key;
    }

    /** @return never */
    private static function refuse(string $message): void
    {
        fwrite(STDERR, "\nREFUSED: " . $message . "\n\n");
        exit(2);
    }
}

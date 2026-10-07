<?php

declare(strict_types=1);

namespace Rewloy\Tests\Live;

use PHPUnit\Framework\TestCase;
use Rewloy\Client;
use Rewloy\Exception\RewloyException;
use Throwable;

/**
 * Base of every live test: skips cleanly when no server is configured, and gives the tests
 * the shared client and a few typed readers (PHPStan max, no mixed leaks).
 */
abstract class LiveCase extends TestCase
{
    /** The area this class reports under in the summary. */
    public const AREA = 'live';

    protected Client $rewloy;

    protected function setUp(): void
    {
        if (Guard::$skip !== null) {
            self::markTestSkipped(Guard::$skip);
        }
        $this->rewloy = Fixture::client();
    }

    /**
     * Runs a call that must be refused and returns the refusal.
     *
     * @param callable(): mixed $call
     */
    protected function refusal(callable $call): RewloyException
    {
        try {
            $call();
        } catch (RewloyException $e) {
            return $e;
        } catch (Throwable $e) {
            self::fail('expected a RewloyException, got ' . $e::class . ': ' . $e->getMessage());
        }
        self::fail('expected the API to refuse the call, but it was accepted');
    }

    /**
     * @param array<string, mixed> $array
     * @return array<string, mixed>
     */
    protected static function arr(array $array, string $key): array
    {
        $value = $array[$key] ?? null;
        self::assertIsArray($value, $key . ' should be an object');
        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @param array<string, mixed> $array */
    protected static function str(array $array, string $key): string
    {
        $value = $array[$key] ?? null;
        self::assertIsString($value, $key . ' should be a string');
        return $value;
    }

    /** @param array<string, mixed> $array */
    protected static function int(array $array, string $key): int
    {
        $value = $array[$key] ?? null;
        self::assertIsInt($value, $key . ' should be an integer');
        return $value;
    }
}

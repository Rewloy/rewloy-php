<?php

declare(strict_types=1);

namespace Rewloy\Tests\Live;

use InvalidArgumentException;

/** GET /v1/meta, the key's own identity and the test business. */
final class MetaTest extends LiveCase
{
    public const AREA = 'meta and business';

    public function testMetaNamesTheVersionAndTheDevEnvironment(): void
    {
        $meta = $this->rewloy->request('getMeta')->data;

        self::assertIsArray($meta);
        self::assertIsString($meta['version'] ?? null);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $meta['version']);
        self::assertSame('v1', $meta['apiVersion'] ?? null);
        self::assertSame('dev', $meta['environment'] ?? null);
    }

    public function testTheKeyIsATestKeyOfATestBusiness(): void
    {
        $me = $this->rewloy->request('me');

        self::assertSame(200, $me->status);
        self::assertSame('test', $me->mode, 'the Rewloy-Mode header of a test key');
        self::assertIsArray($me->data);
        self::assertSame('key', $me->data['kind'] ?? null);
        self::assertSame('test', $me->data['mode'] ?? null);
    }

    public function testTheBusinessIsTheTestBusiness(): void
    {
        $business = $this->rewloy->getBusiness();

        self::assertStringEndsWith('· Test', $business['name']);
        self::assertSame(3, strlen($business['currency']));
        self::assertNotSame('', $business['id']);
    }

    public function testTheTestBusinessHasABranch(): void
    {
        $locations = $this->rewloy->listLocations();

        self::assertNotEmpty($locations);
        self::assertSame(Fixture::locationId(), $locations[0]['id']);
    }

    public function testTheLibraryRefusesAnArgumentTheOperationDoesNotTake(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->rewloy->getBusiness(['query' => ['nope' => 1]]);
    }
}

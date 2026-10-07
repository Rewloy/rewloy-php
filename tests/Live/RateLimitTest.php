<?php

declare(strict_types=1);

namespace Rewloy\Tests\Live;

/** The RateLimit-* headers on a response. */
final class RateLimitTest extends LiveCase
{
    public const AREA = 'rate limit';

    public function testResponsesCarryTheLimitHeaders(): void
    {
        $first = $this->rewloy->request('getBusiness')->rateLimit();
        $second = $this->rewloy->request('getBusiness')->rateLimit();

        self::assertNotNull($first, 'RateLimit-Limit/-Remaining/-Reset on a key-authenticated call');
        self::assertNotNull($second);
        self::assertGreaterThan(0, $first->limit);
        self::assertGreaterThanOrEqual(0, $first->remaining);
        self::assertLessThanOrEqual($first->limit, $first->remaining);
        self::assertGreaterThanOrEqual(0, $first->reset);
        self::assertSame($first->limit, $second->limit);
        self::assertLessThanOrEqual($second->limit, $second->remaining);
    }

    public function testAnErrorCarriesThemToo(): void
    {
        $refusal = $this->refusal(fn () => $this->rewloy->getPass(['params' => ['serial' => 'ZZZZ-ZZZZ-ZZZZ']]));

        $limit = $refusal->rateLimit();
        self::assertNotNull($limit);
        self::assertGreaterThan(0, $limit->limit);
    }
}

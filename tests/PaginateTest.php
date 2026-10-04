<?php

declare(strict_types=1);

namespace Rewloy\Tests;

use PHPUnit\Framework\TestCase;
use Rewloy\Client;
use Rewloy\Http\HttpRequest;
use Rewloy\Http\HttpResponse;
use Rewloy\Tests\Support\Api;
use Rewloy\Tests\Support\StubTransport;

final class PaginateTest extends TestCase
{
    /** A stub list of `$total` customers, paged as the API pages. */
    private static function customers(int $total): StubTransport
    {
        return new StubTransport(static function (HttpRequest $req) use ($total): HttpResponse {
            parse_str((string) parse_url($req->url, PHP_URL_QUERY), $q);
            $page = (int) (is_numeric($q['page'] ?? null) ? $q['page'] : 1);
            $size = (int) (is_numeric($q['limit'] ?? null) ? $q['limit'] : 50);
            $data = [];
            for ($i = ($page - 1) * $size; $i < min($total, $page * $size); $i++) {
                $data[] = ['personId' => 'p' . ($i + 1)];
            }
            return Api::json(200, ['data' => $data, 'meta' => ['page' => $page, 'pageSize' => $size, 'total' => $total]]);
        });
    }

    private static function client(StubTransport $stub): Client
    {
        return new Client(apiKey: Api::KEY, baseUrl: 'https://api.test', transport: $stub);
    }

    /**
     * @param iterable<array<string, mixed>> $items
     * @return list<mixed>
     */
    private static function ids(iterable $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[] = $item['personId'] ?? null;
        }
        return $out;
    }

    public function testWalksEveryPageAndStopsAtTheTotal(): void
    {
        $stub = self::customers(5);
        self::assertSame(['p1', 'p2', 'p3', 'p4', 'p5'], self::ids(self::client($stub)->paginate('listCustomers', ['query' => ['limit' => 2, 'consent' => 'yes']])));
        self::assertSame([
            '/v1/customers?limit=2&consent=yes&page=1',
            '/v1/customers?limit=2&consent=yes&page=2',
            '/v1/customers?limit=2&consent=yes&page=3',
        ], array_map(StubTransport::target(...), $stub->requests));
    }

    public function testGivesEveryItemItsOwnKey(): void
    {
        $items = iterator_to_array(self::client(self::customers(5))->paginate('listCustomers', ['query' => ['limit' => 2]]));
        self::assertSame([0, 1, 2, 3, 4], array_keys($items), 'iterator_to_array keeps every page');
    }

    public function testDoesNotAskPastAFullLastPage(): void
    {
        $stub = self::customers(4);
        self::assertSame(['p1', 'p2', 'p3', 'p4'], self::ids(self::client($stub)->paginate('listCustomers', ['query' => ['limit' => 2]])));
        self::assertCount(2, $stub->requests);
    }

    public function testStartsAtThePageGivenAndHandlesAnEmptyList(): void
    {
        self::assertSame(['p3', 'p4', 'p5'], self::ids(self::client(self::customers(5))->paginate('listCustomers', ['query' => ['limit' => 2, 'page' => 2]])));
        $empty = self::customers(0);
        self::assertSame([], self::ids(self::client($empty)->paginate('listCustomers')));
        self::assertCount(1, $empty->requests);
    }

    public function testStopsAskingWhenTheCallerStopsReading(): void
    {
        $stub = self::customers(500);
        $n = 0;
        foreach (self::client($stub)->paginate('listCustomers', ['query' => ['limit' => 10]]) as $customer) {
            if (++$n === 15) {
                break;
            }
        }
        self::assertCount(2, $stub->requests);
    }

    public function testAsksNothingBeforeTheLoopDoes(): void
    {
        $stub = self::customers(3);
        $pages = self::client($stub)->paginate('listCustomers');
        self::assertSame([], $stub->requests);
        self::assertSame(['p1', 'p2', 'p3'], self::ids($pages));
    }

    public function testGivesAPageWithItsMetaThroughTheMethodItself(): void
    {
        $page = self::client(self::customers(3))->listCustomers(['query' => ['limit' => 2]]);
        self::assertCount(2, $page['data']);
        self::assertSame(['page' => 1, 'pageSize' => 2, 'total' => 3], $page['meta']);
    }
}

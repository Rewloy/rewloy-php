<?php

declare(strict_types=1);

namespace Rewloy\Tests\Live;

/** Paged lists: meta, explicit pages and paginate(). */
final class PaginationTest extends LiveCase
{
    public const AREA = 'pagination';

    public function testPagesAndPaginateWalkEveryCustomer(): void
    {
        $prefix = 'phplive-' . Fixture::run() . '-page';
        $emails = [];
        foreach (['a', 'b', 'c'] as $letter) {
            $emails[] = Fixture::email('page' . $letter);
            Fixture::stampCard('page' . $letter);
        }
        $query = ['q' => $prefix, 'limit' => 1, 'sort' => 'name'];

        $page1 = $this->rewloy->listCustomers(['query' => $query + ['page' => 1]]);
        self::assertSame(3, $page1['meta']['total']);
        self::assertSame(1, $page1['meta']['pageSize']);
        self::assertSame(1, $page1['meta']['page']);
        self::assertCount(1, $page1['data']);

        $page3 = $this->rewloy->listCustomers(['query' => $query + ['page' => 3]]);
        self::assertSame(3, $page3['meta']['page']);
        self::assertCount(1, $page3['data']);
        self::assertNotSame($page1['data'][0]['personId'], $page3['data'][0]['personId']);

        $beyond = $this->rewloy->listCustomers(['query' => $query + ['page' => 4]]);
        self::assertSame([], $beyond['data'], 'past the last page is empty, not an error');

        $seen = [];
        foreach ($this->rewloy->paginate('listCustomers', ['query' => $query]) as $customer) {
            self::assertIsArray($customer);
            $seen[] = $customer['email'] ?? null;
        }
        sort($seen);
        sort($emails);
        self::assertSame($emails, $seen, 'paginate visits each customer once and stops after the last page');
    }

    public function testAnUnpagedListIsAPlainList(): void
    {
        $programs = $this->rewloy->listPrograms();

        self::assertTrue(array_is_list($programs));
    }
}

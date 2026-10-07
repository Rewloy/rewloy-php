<?php

declare(strict_types=1);

namespace Rewloy\Tests\Live;

/** Customers: search, read, timeline. */
final class CustomersTest extends LiveCase
{
    public const AREA = 'customers';

    public function testSearchFindsACustomerByEmail(): void
    {
        $email = Fixture::email('search');
        $serial = Fixture::stampCard('search');

        $found = $this->rewloy->listCustomers(['query' => ['q' => $email]]);

        self::assertSame(1, $found['meta']['total']);
        $customer = $found['data'][0];
        self::assertSame($email, $customer['email']);
        self::assertSame(1, $customer['passCount']);
        self::assertSame($serial, $customer['cards'][0]['serial'] ?? null);
        self::assertTrue($customer['marketingConsent']);
    }

    public function testSearchFindsNothingForAnUnknownAddress(): void
    {
        $found = $this->rewloy->listCustomers(['query' => ['q' => 'phplive-nobody-' . Fixture::run() . '@ornek.com']]);

        self::assertSame(0, $found['meta']['total']);
        self::assertSame([], $found['data']);
    }

    public function testReadACustomerAndTheirTimeline(): void
    {
        $email = Fixture::email('timeline');
        $serial = Fixture::stampCard('timeline');
        $this->rewloy->recordSale([
            'params' => ['serial' => $serial],
            'body' => ['amountMinor' => 1500],
            'idempotencyKey' => Fixture::key('sale'),
        ]);
        $personId = $this->rewloy->listCustomers(['query' => ['q' => $email]])['data'][0]['personId'];

        $customer = $this->rewloy->getCustomer(['params' => ['id' => $personId]]);
        self::assertSame($personId, $customer['personId']);
        self::assertSame($email, $customer['email']);

        $timeline = $this->rewloy->customerTimeline(['params' => ['id' => $personId]])['data'];
        self::assertNotEmpty($timeline);
        self::assertSame($serial, $timeline[0]['serial']);
        self::assertSame('earn', $timeline[0]['kind']);
    }
}

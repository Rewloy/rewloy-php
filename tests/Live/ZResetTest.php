<?php

declare(strict_types=1);

namespace Rewloy\Tests\Live;

use Rewloy\Client;
use Rewloy\Exception\RewloyException;
use Rewloy\Generated\ErrorCode;

/**
 * The test reset, last (its file name sorts last and phpunit.live.xml lists it last).
 *
 * `resetTestEnvironment` takes a team session, not an API key. To run it, give the suite a
 * session of someone with a seat in the business: REWLOY_STAFF_SESSION (an rws_ token), or
 * REWLOY_STAFF_EMAIL and REWLOY_STAFF_PASSWORD (a login without a second factor). Without one,
 * the reset is skipped (and said so), and the tidy-up leaves only what a reset alone can remove:
 * customers, cards and messages.
 */
final class ZResetTest extends LiveCase
{
    public const AREA = 'test reset';

    private static ?Client $staff = null;

    private static ?string $staffError = null;

    private static bool $resolved = false;

    private function staff(): Client
    {
        if (!self::$resolved) {
            self::$resolved = true;
            self::$staff = $this->openStaffSession();
        }
        if (self::$staff === null) {
            self::markTestSkipped(self::$staffError ?? 'no team session given');
        }
        return self::$staff;
    }

    private function openStaffSession(): ?Client
    {
        $merchant = $this->rewloy->getBusiness()['id'];
        $token = trim((string) getenv('REWLOY_STAFF_SESSION'));
        if ($token === '') {
            $email = trim((string) getenv('REWLOY_STAFF_EMAIL'));
            $password = (string) getenv('REWLOY_STAFF_PASSWORD');
            if ($email === '' || $password === '') {
                self::$staffError = 'the reset needs a team session: set REWLOY_STAFF_SESSION, or REWLOY_STAFF_EMAIL and REWLOY_STAFF_PASSWORD';
                return null;
            }
            $login = (new Client(baseUrl: Guard::$baseUrl))->login(['body' => ['email' => $email, 'password' => $password]]);
            if ($login['mfaRequired']) {
                self::$staffError = 'the staff login asks for a second factor: give REWLOY_STAFF_SESSION of a proven session instead';
                return null;
            }
            $token = $login['token'];
        }
        return new Client(staffSession: $token, merchant: $merchant, baseUrl: Guard::$baseUrl, userAgent: 'rewloy-php-live-tests');
    }

    public function testAnApiKeyCannotResetTheTestBusiness(): void
    {
        $e = $this->refusal(fn () => $this->rewloy->resetTestEnvironment());

        self::assertSame(403, $e->status);
        self::assertSame(ErrorCode::CREDENTIAL_NOT_ALLOWED, $e->errorCode);
    }

    public function testTheTeamSessionSeesTheTestEnvironment(): void
    {
        $env = $this->staff()->getTestEnvironment();

        self::assertNotNull($env['test']);
        self::assertSame($this->rewloy->getBusiness()['id'], $env['test']['merchantId'], 'the key and the session are in the same test business');
        self::assertTrue($env['seated']);
        self::assertGreaterThan(0, $env['cards'], 'the earlier tests left cards for the reset to remove');
    }

    public function testResetLeavesTheTestBusinessCleanAndTheKeyWorking(): void
    {
        $staff = $this->staff();
        $before = $staff->getTestEnvironment();

        try {
            $reset = $staff->resetTestEnvironment();
        } catch (RewloyException $e) {
            if ($e->errorCode === ErrorCode::RATE_LIMITED) {
                self::markTestSkipped('a business resets at most 5 times a day: ' . $e->detail);
            }
            throw $e;
        }

        self::assertSame($before['test']['merchantId'] ?? null, $reset['merchantId'], 'the same test business, not a new one');
        self::assertFalse($reset['keysRevoked']);
        self::assertNull($reset['closed']);
        self::assertSame($before['cards'], $reset['deleted']['cards']);
        self::assertSame($before['customers'], $reset['deleted']['customers']);
        self::assertGreaterThanOrEqual(1, $reset['kept']['keys'], 'the key that ran this suite survives a reset');

        // The key is still good, and the business is empty of customers, cards, codes and messages.
        self::assertSame($reset['merchantId'], $this->rewloy->getBusiness()['id']);
        self::assertSame(0, $this->rewloy->listCustomers()['meta']['total']);
        self::assertSame(0, $this->rewloy->listAllBatches()['meta']['total']);
        self::assertSame(0, $this->rewloy->listTestMessages()['meta']['total']);
        $after = $staff->getTestEnvironment();
        self::assertSame(0, $after['cards']);
        self::assertSame(0, $after['customers']);
        self::assertSame(0, $after['outbox']);

        // With no cards left, the programs this run made can go too.
        Fixture::cleanup();
    }

    public function testTheTeamSessionIsClosed(): void
    {
        $this->staff()->logout();

        $e = $this->refusal(fn () => $this->staff()->getTestEnvironment());
        self::assertSame(401, $e->status);
    }
}

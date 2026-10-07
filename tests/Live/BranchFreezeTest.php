<?php

declare(strict_types=1);

namespace Rewloy\Tests\Live;

use Rewloy\Generated\ErrorCode;

/**
 * API 1.3.0 branch freeze. Starting a freeze is staff-only (a team session; an API key is refused) and asks the
 * team member's password; editing the note, calling off a planned freeze and opening the branch
 * work with a key. So these tests need a team session (REWLOY_STAFF_SESSION, or REWLOY_STAFF_EMAIL
 * and REWLOY_STAFF_PASSWORD) and REWLOY_STAFF_PASSWORD; without them they skip and say so.
 *
 * Each test freezes a branch of its own, made for the run, never the test business's own branch:
 * a business whose every branch is frozen is paused (BUSINESS_FROZEN), and a branch may be frozen
 * only four times in 12 months.
 */
final class BranchFreezeTest extends LiveCase
{
    public const AREA = 'branch freeze';

    private function password(): string
    {
        $password = Fixture::staffPassword();
        if ($password === null) {
            self::markTestSkipped('freezing a branch asks the team member\'s password: set REWLOY_STAFF_PASSWORD');
        }
        return $password;
    }

    private function staff(): \Rewloy\Client
    {
        $staff = Fixture::staff();
        if ($staff === null) {
            self::markTestSkipped('freezing a branch ' . Fixture::staffError());
        }
        $this->password();
        return $staff;
    }

    public function testAnApiKeyCannotFreezeABranch(): void
    {
        $id = Fixture::newLocation('freeze-key');

        $refusal = $this->refusal(fn () => $this->rewloy->freezeLocation(['params' => ['id' => $id], 'body' => ['reason' => 'other', 'password' => 'x']]));

        self::assertSame(403, $refusal->status);
        self::assertSame(ErrorCode::CREDENTIAL_NOT_ALLOWED, $refusal->errorCode);
        // Opening a branch, changing its note and calling off a planned freeze do work with a key.
        $unfreeze = $this->refusal(fn () => $this->rewloy->unfreezeLocation(['params' => ['id' => $id]]));
        self::assertSame(ErrorCode::NOT_FROZEN, $unfreeze->errorCode);
        $note = $this->refusal(fn () => $this->rewloy->updateLocationFreeze(['params' => ['id' => $id], 'body' => ['publicNote' => 'x']]));
        self::assertSame(ErrorCode::NOT_FROZEN, $note->errorCode);
    }

    public function testAFrozenBranchRefusesTheTillAndReopens(): void
    {
        $staff = $this->staff();
        $password = $this->password();
        $id = Fixture::newLocation('freeze');
        $other = Fixture::locationId();
        $serial = Fixture::stampCard('freeze');
        $code = $this->rewloy->getLocation(['params' => ['id' => $id]])['qr']['code'];

        // (A wrong password is deliberately not tried: step-up failures are rate limited per person, and the
        // person is whoever owns the session the suite was given.)
        self::assertNull($this->rewloy->getLocation(['params' => ['id' => $id]])['frozen']);

        $reopens = gmdate('Y-m-d', time() + 3 * 86400);
        $frozen = null;
        try {
            $frozen = $staff->freezeLocation(['params' => ['id' => $id], 'body' => ['reason' => 'renovation', 'publicNote' => 'Boya yapılıyor', 'reopensOn' => $reopens, 'password' => $password]]);
            self::assertSame('frozen', $frozen['qr']['state']);
            self::assertIsArray($frozen['frozen']);
            self::assertSame('renovation', $frozen['frozen']['reason'] ?? null);
            self::assertSame($reopens, $frozen['frozen']['reopensOn'] ?? null);

            $again = $this->refusal(fn () => $staff->freezeLocation(['params' => ['id' => $id], 'body' => ['reason' => 'renovation', 'password' => $password]]));
            self::assertSame(409, $again->status);
            self::assertSame(ErrorCode::ALREADY_FROZEN, $again->errorCode);

            // The branch's till takes nothing new; another branch is not affected.
            $sale = ['params' => ['serial' => $serial], 'body' => ['locationId' => $id, 'amountMinor' => 5000]];
            $refused = $this->refusal(fn () => $this->rewloy->recordSale($sale + ['idempotencyKey' => Fixture::key('frozen-sale')]));
            self::assertSame(409, $refused->status);
            self::assertSame(ErrorCode::LOCATION_FROZEN, $refused->errorCode);
            $preview = $this->refusal(fn () => $this->rewloy->previewSale($sale));
            self::assertSame(ErrorCode::LOCATION_FROZEN, $preview->errorCode);
            $elsewhere = $this->rewloy->recordSale([
                'params' => ['serial' => $serial],
                'body' => ['locationId' => $other, 'amountMinor' => 5000],
                'idempotencyKey' => Fixture::key('other-branch-sale'),
            ]);
            self::assertSame(1, $elsewhere['credited']);

            // The key sees it everywhere.
            self::assertSame('frozen', $this->rewloy->getLocation(['params' => ['id' => $id]])['qr']['state']);
            $page = $this->rewloy->publicBranch(['params' => ['code' => $code]]);
            self::assertSame('Boya yapılıyor', $page['branch']['publicNote']);
            self::assertSame($reopens, $page['branch']['reopensOn']);
            $freezes = $this->rewloy->listLocationFreezes(['params' => ['id' => $id]]);
            self::assertCount(1, $freezes['freezes']);
            self::assertFalse($freezes['freezes'][0]['cancelled']);
            self::assertSame(90, $freezes['freeDays']['used'] + $freezes['freeDays']['left']);

            // The note is edited; a started freeze cannot be called off, only reopened.
            $edited = $this->rewloy->updateLocationFreeze(['params' => ['id' => $id], 'body' => ['publicNote' => 'Boya bitiyor']]);
            self::assertSame('Boya bitiyor', $edited['frozen']['publicNote'] ?? null);
            $cancel = $this->refusal(fn () => $this->rewloy->cancelLocationFreeze(['params' => ['id' => $id]]));
            self::assertSame(409, $cancel->status);
            self::assertSame(ErrorCode::FREEZE_STARTED, $cancel->errorCode);
        } finally {
            if ($frozen !== null) {
                $this->rewloy->unfreezeLocation(['params' => ['id' => $id]]);
            }
        }

        $open = $this->rewloy->getLocation(['params' => ['id' => $id]]);
        self::assertNull($open['frozen']);
        self::assertNotSame('frozen', $open['qr']['state']);
        $notFrozen = $this->refusal(fn () => $this->rewloy->unfreezeLocation(['params' => ['id' => $id]]));
        self::assertSame(409, $notFrozen->status);
        self::assertSame(ErrorCode::NOT_FROZEN, $notFrozen->errorCode);
        $reopened = $this->rewloy->recordSale([
            'params' => ['serial' => $serial],
            'body' => ['locationId' => $id, 'amountMinor' => 5000],
            'idempotencyKey' => Fixture::key('reopened-sale'),
        ]);
        self::assertSame(1, $reopened['credited'], 'the till takes sales again');
        self::assertTrue($this->rewloy->listLocationFreezes(['params' => ['id' => $id]])['freezes'][0]['endedAt'] !== null);
    }

    public function testAFreezeThatStartsLaterCanBeCalledOff(): void
    {
        $staff = $this->staff();
        $password = $this->password();
        $id = Fixture::newLocation('freeze-later');

        $planned = $staff->freezeLocation(['params' => ['id' => $id], 'body' => [
            'reason' => 'seasonal',
            'startsOn' => gmdate('Y-m-d', time() + 20 * 86400),
            'reopensOn' => gmdate('Y-m-d', time() + 40 * 86400),
            'password' => $password,
        ]]);
        self::assertSame('seasonal', $planned['frozen']['reason'] ?? null);
        self::assertNotSame('frozen', $this->rewloy->getLocation(['params' => ['id' => $id]])['qr']['state'], 'a planned freeze has not started');

        $this->rewloy->cancelLocationFreeze(['params' => ['id' => $id]]);

        $freezes = $this->rewloy->listLocationFreezes(['params' => ['id' => $id]])['freezes'];
        self::assertCount(1, $freezes);
        self::assertTrue($freezes[0]['cancelled']);
        self::assertNull($this->rewloy->getLocation(['params' => ['id' => $id]])['frozen']);
    }
}

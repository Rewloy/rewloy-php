<?php

declare(strict_types=1);

namespace Rewloy\Tests\Live;

use Rewloy\Generated\ErrorCode;

/** Programs: list, create (stamp and gift card), read, update, archive, restore, delete. */
final class ProgramsTest extends LiveCase
{
    public const AREA = 'programs';

    public function testCreateAStampProgram(): void
    {
        $id = Fixture::stampProgramId();

        $program = $this->rewloy->getProgram(['params' => ['id' => $id]]);

        self::assertSame($id, $program['id']);
        self::assertSame('stamp', $program['type']);
        self::assertSame('active', $program['status']);
        self::assertSame(5, $program['config']['maxStamps'] ?? null);
        self::assertStringContainsString($id, $program['joinUrl']);
        self::assertSame('stamps', $program['sale']['writes']);
    }

    public function testCreateAGiftCardProgram(): void
    {
        $program = $this->rewloy->getProgram(['params' => ['id' => Fixture::giftProgramId()]]);

        self::assertSame('giftcard', $program['type']);
        self::assertSame('active', $program['status']);
        self::assertSame('none', $program['sale']['writes'], 'a gift card is not earned on a sale');
    }

    public function testListProgramsFindsBothAndFiltersByType(): void
    {
        $stamp = Fixture::stampProgramId();
        $gift = Fixture::giftProgramId();

        $all = array_column($this->rewloy->listPrograms(), 'id');
        $stamps = array_column($this->rewloy->listPrograms(['query' => ['type' => 'stamp']]), 'id');
        $gifts = array_column($this->rewloy->listPrograms(['query' => ['type' => 'giftcard']]), 'id');

        self::assertContains($stamp, $all);
        self::assertContains($gift, $all);
        self::assertContains($stamp, $stamps);
        self::assertNotContains($gift, $stamps);
        self::assertContains($gift, $gifts);
    }

    public function testUpdateArchiveRestoreAndDeleteAProgram(): void
    {
        $id = Fixture::newProgram(['type' => 'stamp', 'businessName' => 'Live Kahve', 'programName' => 'Gecici ' . Fixture::run(), 'maxStamps' => 4]);
        $args = ['params' => ['id' => $id]];

        $renamed = $this->rewloy->updateProgram($args + ['body' => ['programName' => 'Gecici yeni']]);
        self::assertSame('Gecici yeni', $renamed['programName']);

        $archived = $this->rewloy->archiveProgram($args);
        self::assertSame('archived', $archived['status']);
        self::assertContains($id, array_column($this->rewloy->listPrograms(['query' => ['status' => 'archived']]), 'id'));
        self::assertNotContains($id, array_column($this->rewloy->listPrograms(['query' => ['status' => 'active']]), 'id'));

        $restored = $this->rewloy->restoreProgram($args);
        self::assertSame('active', $restored['status']);

        // A program nobody holds a card of can be deleted, by naming it.
        $this->rewloy->deleteProgram($args + ['query' => ['confirmName' => 'Gecici yeni']]);
        Fixture::forgetProgram($id);
        self::assertSame(ErrorCode::PROGRAM_NOT_FOUND, $this->refusal(fn () => $this->rewloy->getProgram($args))->errorCode);
    }

    public function testAProgramWithACardCannotBeDeleted(): void
    {
        $id = Fixture::newProgram(['type' => 'stamp', 'businessName' => 'Live Kahve', 'programName' => 'Kartli ' . Fixture::run(), 'maxStamps' => 4]);
        $this->rewloy->issuePass(['body' => ['programId' => $id, 'email' => Fixture::email('kartli'), 'kvkkConsent' => true]]);

        $refusal = $this->refusal(fn () => $this->rewloy->deleteProgram([
            'params' => ['id' => $id],
            'query' => ['confirmName' => 'Kartli ' . Fixture::run()],
        ]));

        self::assertSame(409, $refusal->status);
        self::assertSame(ErrorCode::PROGRAM_HAS_CARDS, $refusal->errorCode);
    }
}

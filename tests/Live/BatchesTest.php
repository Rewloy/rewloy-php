<?php

declare(strict_types=1);

namespace Rewloy\Tests\Live;

use Rewloy\Generated\ErrorCode;

/** Gift card codes (batches): create, list per program and for the whole business, send a link and its refusals. */
final class BatchesTest extends LiveCase
{
    public const AREA = 'batches';

    /** @return array<string, mixed> */
    private function newBatch(string $programId, string $name): array
    {
        $batch = $this->rewloy->createBatch([
            'params' => ['id' => $programId],
            'body' => ['name' => $name, 'valueMinor' => 5000, 'capacity' => 3],
        ]);
        Fixture::trackBatch($batch['id']);
        return $batch;
    }

    public function testCreateAndReadABatch(): void
    {
        $batch = $this->newBatch(Fixture::giftProgramId(), 'Kod ' . Fixture::run());

        self::assertSame('open', $batch['status']);
        self::assertSame(5000, $batch['valueMinor']);
        self::assertSame(3, $batch['capacity']);
        self::assertSame('giftcard', $batch['type']);
        self::assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $batch['code']);
        self::assertStringContainsString($batch['code'], $batch['claimUrl']);

        $read = $this->rewloy->getBatch(['params' => ['id' => $batch['id']]]);
        self::assertSame($batch['id'], $read['id']);
        self::assertSame(0, $read['cards']['registered']);
    }

    public function testListBatchesOfAProgramAndOfTheWholeBusiness(): void
    {
        $program = Fixture::giftProgramId();
        $batch = $this->newBatch($program, 'Liste ' . Fixture::run());

        $ofProgram = $this->rewloy->listBatches(['params' => ['id' => $program]]);
        self::assertContains($batch['id'], array_column($ofProgram, 'id'));

        $all = $this->rewloy->listAllBatches(['query' => ['programId' => $program, 'status' => 'open']]);
        self::assertContains($batch['id'], array_column($all['data'], 'id'));
        self::assertGreaterThanOrEqual(1, $all['meta']['total']);

        // The same list through paginate(): every code of the program, one by one.
        $ids = [];
        foreach ($this->rewloy->paginate('listAllBatches', ['query' => ['programId' => $program, 'limit' => 1]]) as $row) {
            self::assertIsArray($row);
            $ids[] = $row['id'] ?? null;
        }
        self::assertContains($batch['id'], $ids);
    }

    public function testASendLinkIsQueuedAndLandsInTheUnsentMessages(): void
    {
        $batch = $this->newBatch(Fixture::giftProgramId(), 'Gonder ' . Fixture::run());

        $sent = $this->rewloy->sendBatchLink(['params' => ['id' => $batch['id']], 'body' => ['email' => Fixture::email('batch-link')]]);

        self::assertSame('queued', $sent['result']);
        $messages = $this->rewloy->listTestMessages()['data'];
        $link = array_values(array_filter($messages, static fn (array $m): bool => str_contains($m['body'] ?? '', (string) $batch['code'])));
        self::assertNotEmpty($link, 'the test business sends nothing: the e-mail is in "Gönderilmeyenler"');
        self::assertSame('mail', $link[0]['channel']);
        self::assertStringNotContainsString(Fixture::email('batch-link'), (string) $link[0]['recipient'], 'the recipient is masked');
    }

    public function testASendLinkOfAClosedBatchIsRefused(): void
    {
        $batch = $this->newBatch(Fixture::giftProgramId(), 'Kapali ' . Fixture::run());
        $closed = $this->rewloy->closeBatch(['params' => ['id' => $batch['id']]]);
        self::assertSame('closed', $closed['status']);

        $refusal = $this->refusal(fn () => $this->rewloy->sendBatchLink(['params' => ['id' => $batch['id']], 'body' => ['email' => Fixture::email('closed')]]));

        self::assertSame(410, $refusal->status);
        self::assertSame(ErrorCode::BATCH_CLOSED, $refusal->errorCode);
        self::assertContains($batch['id'], array_column($this->rewloy->listAllBatches(['query' => ['status' => 'closed', 'programId' => Fixture::giftProgramId()]])['data'], 'id'));
    }

    public function testAnArchivedProgramTakesNoNewCodeAndSendsNoLink(): void
    {
        $program = Fixture::newProgram(['type' => 'giftcard', 'businessName' => 'Live Kahve', 'programName' => 'Arsiv ' . Fixture::run()]);
        $batch = $this->newBatch($program, 'Arsivli ' . Fixture::run());
        $this->rewloy->archiveProgram(['params' => ['id' => $program]]);

        // No new code in an archived program.
        $refusal = $this->refusal(fn () => $this->rewloy->createBatch(['params' => ['id' => $program], 'body' => ['valueMinor' => 5000]]));
        self::assertSame(409, $refusal->status);
        self::assertSame(ErrorCode::PROGRAM_ARCHIVED, $refusal->errorCode);

        // The code made before the archive sends no link: archiving closes it (BATCH_CLOSED); a code
        // that is still open under an archived program would be PROGRAM_ARCHIVED (409). Either way no e-mail.
        $refusal = $this->refusal(fn () => $this->rewloy->sendBatchLink(['params' => ['id' => $batch['id']], 'body' => ['email' => Fixture::email('archived')]]));
        self::assertContains($refusal->errorCode, [ErrorCode::BATCH_CLOSED, ErrorCode::PROGRAM_ARCHIVED]);
        self::assertContains($refusal->status, [409, 410]);

        $listed = $this->rewloy->listAllBatches(['query' => ['programId' => $program]])['data'];
        self::assertContains($batch['id'], array_column($listed, 'id'));
    }

    public function testACodeCannotExpireInThePast(): void
    {
        $refusal = $this->refusal(fn () => $this->rewloy->createBatch([
            'params' => ['id' => Fixture::giftProgramId()],
            'body' => ['valueMinor' => 5000, 'validUntil' => '2020-01-01'],
        ]));

        self::assertSame(422, $refusal->status);
        self::assertSame(ErrorCode::INVALID_BATCH, $refusal->errorCode);
    }
}

<?php

declare(strict_types=1);

namespace Rewloy\Tests\Live;

use Rewloy\Client;
use Rewloy\Generated\ErrorCode;

/**
 * API 1.3.0 branch QR: every branch has a permanent QR (`qr` on the branch), a public page that
 * lists its cards, the QR's image and printable sheets, and the list the business arranges.
 * The holder operations take a Rewloy Cüzdan session, which the suite has none of: only their
 * refusal for an API key is tested.
 */
final class BranchQrTest extends LiveCase
{
    public const AREA = 'branch QR';

    /** @return array{id: string, code: string} */
    private function branch(): array
    {
        $location = $this->rewloy->getLocation(['params' => ['id' => Fixture::locationId()]]);
        self::assertSame(6, strlen($location['qr']['code']), 'a branch code is six characters');
        return ['id' => $location['id'], 'code' => $location['qr']['code']];
    }

    public function testEveryBranchCarriesItsQrAndNumbers(): void
    {
        $location = $this->rewloy->getLocation(['params' => ['id' => Fixture::locationId()]]);

        self::assertMatchesRegularExpression('#^https?://[^/]+/s/' . $location['qr']['code'] . '$#', $location['qr']['url']);
        self::assertContains($location['qr']['state'], ['live', 'empty', 'frozen', 'archived']);
        self::assertIsInt($location['stats']['qrCards30']);
        self::assertNull($location['frozen'], 'the test branch is not frozen');
        $listed = array_column($this->rewloy->listLocations(), 'qr', 'id');
        self::assertSame($location['qr'], $listed[$location['id']] ?? null);
    }

    public function testThePublicBranchPageNeedsNoCredentialAndListsTheCards(): void
    {
        $branch = $this->branch();
        $program = Fixture::stampProgramId();

        $page = (new Client(baseUrl: Guard::$baseUrl, userAgent: 'rewloy-php-live-tests'))->publicBranch(['params' => ['code' => $branch['code']]]);

        self::assertSame($branch['code'], $page['code']);
        self::assertTrue($page['test'], 'a test business says so');
        self::assertSame('live', $page['branch']['state']);
        self::assertNotSame('', $page['business']['name']);
        $offered = array_column($page['items'], 'programId');
        if ($page['featured'] !== null) {
            $offered[] = $page['featured']['programId'] ?? null;
        }
        self::assertContains($program, $offered, 'a new card is offered at every branch by default');
        foreach ($page['items'] as $item) {
            self::assertStringContainsString('/join/', $item['joinUrl']);
            self::assertNotSame('', $item['offerLine']);
        }
        // The same page with the key.
        self::assertSame($page['branch'], $this->rewloy->publicBranch(['params' => ['code' => $branch['code']]])['branch']);
    }

    public function testAnUnknownBranchCodeIsNotFound(): void
    {
        $refusal = $this->refusal(fn () => $this->rewloy->publicBranch(['params' => ['code' => 'ZZZZZZ']]));

        self::assertSame(404, $refusal->status);
        self::assertSame(ErrorCode::BRANCH_NOT_FOUND, $refusal->errorCode);
    }

    public function testTheQrIsDownloadedAsSvgAndPng(): void
    {
        $id = $this->branch()['id'];

        $svg = $this->rewloy->locationQrSvg(['params' => ['id' => $id]]);
        self::assertStringStartsWith('<svg', ltrim($svg));
        self::assertStringContainsString('</svg>', $svg);

        $png = $this->rewloy->locationQrPng(['params' => ['id' => $id], 'query' => ['size' => 512]]);
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        $size = getimagesizefromstring($png);
        self::assertIsArray($size);
        self::assertSame(512, $size[0]);
        self::assertSame(512, $size[1]);

        $default = $this->rewloy->locationQrPng(['params' => ['id' => $id]]);
        $defaultSize = getimagesizefromstring($default);
        self::assertIsArray($defaultSize);
        self::assertSame(2048, $defaultSize[0], 'the default is 2048 pixels');
    }

    public function testThePrintedSheetsComeAsPdfAndSvgInThreeForms(): void
    {
        $id = $this->branch()['id'];

        foreach (['a4', 'a6', 'sticker'] as $form) {
            $pdf = $this->rewloy->locationQrSheetPdf(['params' => ['id' => $id], 'query' => ['form' => $form]]);
            self::assertStringStartsWith('%PDF-', $pdf, $form);
            $svg = $this->rewloy->locationQrSheetSvg(['params' => ['id' => $id], 'query' => ['form' => $form]]);
            self::assertStringContainsString('<svg', $svg, $form);
        }
        self::assertStringStartsWith('%PDF-', $this->rewloy->locationQrSheetPdf(['params' => ['id' => $id]]), 'a form is optional');
    }

    public function testASizeOutsideTheLimitsIsRefused(): void
    {
        $id = $this->branch()['id'];

        $refusal = $this->refusal(fn () => $this->rewloy->locationQrPng(['params' => ['id' => $id], 'query' => ['size' => 64]]));

        self::assertSame(400, $refusal->status);
        self::assertSame(ErrorCode::VALIDATION, $refusal->errorCode);
    }

    public function testTheListOfCardsOnTheQrIsReadPreviewedAndWrittenBack(): void
    {
        $program = Fixture::stampProgramId();
        // A branch of its own: the list is rewritten here.
        $id = Fixture::newLocation('qr-list');
        $branch = ['id' => $id, 'code' => $this->rewloy->getLocation(['params' => ['id' => $id]])['qr']['code']];

        $list = $this->rewloy->getLocationQrItems(['params' => ['id' => $branch['id']]]);
        self::assertIsInt($list['version']);
        self::assertContains($program, array_column($list['items'], 'programId'));

        $preview = $this->rewloy->previewLocationQr(['params' => ['id' => $branch['id']], 'query' => ['as' => 'new']]);
        self::assertSame($branch['code'], $preview['code']);

        // Hide the stamp card (a hidden card stays hidden), then bring it back.
        $gift = $program;
        $items = static fn (bool $hidden): array => array_values(array_map(
            static fn (array $item): array => ['programId' => $item['programId'], 'hidden' => $item['programId'] === $gift ? $hidden : $item['hidden']],
            array_filter($list['items'], static fn (array $item): bool => $item['batchId'] === null),
        ));
        $hidden = $this->rewloy->putLocationQrItems(['params' => ['id' => $branch['id']], 'body' => ['version' => $list['version'], 'items' => $items(true)]]);
        self::assertGreaterThan($list['version'], $hidden['version']);
        $row = array_values(array_filter($hidden['items'], static fn (array $item): bool => $item['programId'] === $gift));
        self::assertCount(1, $row);
        self::assertTrue($row[0]['hidden']);
        self::assertSame('hidden', $row[0]['state']);
        $shown = $this->rewloy->putLocationQrItems(['params' => ['id' => $branch['id']], 'body' => ['version' => $hidden['version'], 'items' => $items(false)]]);
        self::assertGreaterThan($hidden['version'], $shown['version']);

        // A write from the old version is refused: somebody changed the list meanwhile.
        $stale = $this->refusal(fn () => $this->rewloy->putLocationQrItems(['params' => ['id' => $branch['id']], 'body' => ['version' => $list['version'], 'items' => $items(false)]]));
        self::assertSame(409, $stale->status);
        self::assertSame(ErrorCode::QR_LIST_CHANGED, $stale->errorCode);
    }

    public function testACardAddedToTheListAppearsFeatured(): void
    {
        $id = Fixture::newLocation('qr-add');
        $program = Fixture::stampProgramId();

        $results = $this->rewloy->addQrItems(['body' => ['programId' => $program, 'locationIds' => [$id], 'featured' => true, 'position' => 'first']])['results'];

        self::assertCount(1, $results);
        self::assertSame($id, $results[0]['locationId']);
        self::assertTrue($results[0]['ok'], (string) ($results[0]['message'] ?? ''));
        $list = $this->rewloy->getLocationQrItems(['params' => ['id' => $id]]);
        $row = array_values(array_filter($list['items'], static fn (array $item): bool => $item['programId'] === $program));
        self::assertCount(1, $row);
        self::assertTrue($row[0]['featured']);
        self::assertSame('row', $row[0]['source'], 'it is now a row of its own, not an automatic one');
    }

    public function testAGiftCardGoesOnTheQrOnlyThroughACode(): void
    {
        $id = Fixture::newLocation('qr-gift');

        $results = $this->rewloy->addQrItems(['body' => ['programId' => Fixture::giftProgramId(), 'locationIds' => [$id]]])['results'];

        self::assertFalse($results[0]['ok']);
        self::assertNotNull($results[0]['message'], 'a coupon, discount or gift card is added with a code (batchId)');
    }

    public function testANewBranchGetsItsQrAndCanCopyAnotherBranchsList(): void
    {
        $name = 'Live QR şube ' . Fixture::run();
        $location = $this->rewloy->createLocation(['body' => ['name' => $name, 'qrListFrom' => Fixture::locationId()]]);
        Fixture::trackLocation($location['id']);

        self::assertSame(6, strlen($location['qr']['code']));
        self::assertNotSame($this->branch()['code'], $location['qr']['code']);
        self::assertNull($location['frozen']);
        $page = $this->rewloy->publicBranch(['params' => ['code' => $location['qr']['code']]]);
        self::assertSame($name, $page['branch']['name']);
    }

    public function testTheHolderOperationsRefuseAnApiKey(): void
    {
        $code = $this->branch()['code'];

        $read = $this->refusal(fn () => $this->rewloy->holderBranch(['params' => ['code' => $code]]));
        self::assertSame(403, $read->status);
        self::assertSame(ErrorCode::CREDENTIAL_NOT_ALLOWED, $read->errorCode);

        $join = $this->refusal(fn () => $this->rewloy->joinHolderBranch(['params' => ['code' => $code], 'body' => ['kvkkConsent' => true]]));
        self::assertSame(403, $join->status);
        self::assertSame(ErrorCode::CREDENTIAL_NOT_ALLOWED, $join->errorCode);
    }
}

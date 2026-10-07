<?php

declare(strict_types=1);

namespace Rewloy\Tests\Live;

use Rewloy\Generated\ErrorCode;

/**
 * Webhooks: create, list, read, pause, rotate the secret, delete.
 *
 * A dev server that runs as production refuses an https address that does not resolve publicly
 * (422 BAD_WEBHOOK_URL); a laptop server accepts it. Both are documented, so the test takes either.
 * Only `pass.voided` is subscribed, which nothing here triggers: no delivery leaves the server.
 */
final class WebhooksTest extends LiveCase
{
    public const AREA = 'webhooks';

    private const UNRESOLVABLE = 'https://rewloy-live-tests.invalid/hook';

    /**
     * A webhook that exists: the unresolvable address if the server takes it, else a public one
     * (REWLOY_WEBHOOK_URL, default https://example.com/rewloy-live-tests).
     *
     * @return array{id: string, secret: string}
     */
    private function newWebhook(): array
    {
        $url = self::UNRESOLVABLE;
        try {
            $made = $this->rewloy->createWebhook(['body' => ['url' => $url, 'events' => ['pass.voided']]]);
        } catch (\Rewloy\Exception\RewloyException $e) {
            self::assertSame(ErrorCode::BAD_WEBHOOK_URL, $e->errorCode);
            $public = trim((string) getenv('REWLOY_WEBHOOK_URL'));
            $made = $this->rewloy->createWebhook(['body' => ['url' => $public !== '' ? $public : 'https://example.com/rewloy-live-tests', 'events' => ['pass.voided']]]);
        }
        $id = $made['webhook']['id'];
        Fixture::trackWebhook($id);
        return ['id' => $id, 'secret' => $made['secret']];
    }

    public function testAnAddressThatDoesNotResolveIsRefusedOrCreatedAsDocumented(): void
    {
        try {
            $made = $this->rewloy->createWebhook(['body' => ['url' => self::UNRESOLVABLE, 'events' => ['pass.voided']]]);
        } catch (\Rewloy\Exception\RewloyException $e) {
            self::assertSame(422, $e->status);
            self::assertSame(ErrorCode::BAD_WEBHOOK_URL, $e->errorCode);
            self::assertNotSame('', $e->detail);
            return;
        }
        Fixture::trackWebhook($made['webhook']['id']);
        self::assertSame(self::UNRESOLVABLE, $made['webhook']['url']);
        self::assertSame('active', $made['webhook']['status']);
        self::assertStringStartsWith('whsec_', $made['secret']);
    }

    public function testAHttpAddressIsRefusedOrComesWithAProductionWarning(): void
    {
        try {
            $made = $this->rewloy->createWebhook(['body' => ['url' => 'http://localhost:9/hook', 'events' => ['pass.voided']]]);
        } catch (\Rewloy\Exception\RewloyException $e) {
            self::assertSame(ErrorCode::BAD_WEBHOOK_URL, $e->errorCode);
            return;
        }
        Fixture::trackWebhook($made['webhook']['id']);
        self::assertNotEmpty($made['warnings'] ?? [], 'a non-production server says what production would refuse');
    }

    public function testCreateListAndReadAWebhook(): void
    {
        $webhook = $this->newWebhook();
        self::assertStringStartsWith('whsec_', $webhook['secret']);

        self::assertContains($webhook['id'], array_column($this->rewloy->listWebhooks(), 'id'));

        $read = $this->rewloy->getWebhook(['params' => ['id' => $webhook['id']]]);
        self::assertSame($webhook['id'], $read['id']);
        self::assertSame(['pass.voided'], $read['events']);
        self::assertSame('active', $read['status']);
        self::assertSame(0, $read['failures']);
        self::assertNull($read['pausedUntil']);
        self::assertNull($read['resumableUntil']);
        self::assertSame([], $this->rewloy->listWebhookDeliveries(['params' => ['id' => $webhook['id']]])['data']);
    }

    public function testTheEventCatalogue(): void
    {
        $events = array_column($this->rewloy->webhookEvents()['events'], 'event');

        self::assertContains('pass.issued', $events);
        self::assertContains('pass.activity', $events);
        self::assertContains('pass.voided', $events);
        foreach (['pass.extended', 'location.frozen', 'location.unfrozen', 'business.paused', 'business.resumed'] as $event) {
            self::assertContains($event, $events, "API 1.3.0 event $event");
        }
    }

    public function testAWebhookCanSubscribeToTheEventsOfApi130(): void
    {
        $events = ['pass.extended', 'location.frozen', 'location.unfrozen', 'business.paused', 'business.resumed'];
        try {
            $made = $this->rewloy->createWebhook(['body' => ['url' => self::UNRESOLVABLE, 'events' => $events]]);
        } catch (\Rewloy\Exception\RewloyException $e) {
            self::assertSame(ErrorCode::BAD_WEBHOOK_URL, $e->errorCode);
            $public = trim((string) getenv('REWLOY_WEBHOOK_URL'));
            $made = $this->rewloy->createWebhook(['body' => ['url' => $public !== '' ? $public : 'https://example.com/rewloy-live-tests', 'events' => $events]]);
        }
        Fixture::trackWebhook($made['webhook']['id']);

        self::assertEqualsCanonicalizing($events, $made['webhook']['events']);
    }

    public function testRotateTheSecret(): void
    {
        $webhook = $this->newWebhook();

        $rotated = $this->rewloy->rotateWebhookSecret(['params' => ['id' => $webhook['id']]]);

        self::assertStringStartsWith('whsec_', $rotated['secret']);
        self::assertNotSame($webhook['secret'], $rotated['secret']);
        self::assertSame($webhook['id'], $rotated['webhook']['id']);
        self::assertNotSame('', $rotated['previousValidUntil'], 'the old secret keeps working for a while');
    }

    public function testDeleteAWebhook(): void
    {
        $webhook = $this->newWebhook();

        $this->rewloy->deleteWebhook(['params' => ['id' => $webhook['id']]]);

        self::assertNotContains($webhook['id'], array_column($this->rewloy->listWebhooks(), 'id'));
        $refusal = $this->refusal(fn () => $this->rewloy->getWebhook(['params' => ['id' => $webhook['id']]]));
        self::assertSame(404, $refusal->status);
        self::assertSame(ErrorCode::WEBHOOK_NOT_FOUND, $refusal->errorCode);
    }
}

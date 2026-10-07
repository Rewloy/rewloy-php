<?php

declare(strict_types=1);

namespace Rewloy\Tests\Live;

use Rewloy\Client;
use Rewloy\Exception\RewloyException;
use Rewloy\Generated\ErrorCode;

/** The error object: code, status, request id, docs link. */
final class ErrorsTest extends LiveCase
{
    public const AREA = 'errors';

    public function testANotFoundCarriesCodeStatusAndRequestId(): void
    {
        $e = $this->refusal(fn () => $this->rewloy->getPass(['params' => ['serial' => 'ZZZZ-ZZZZ-ZZZZ']]));

        self::assertInstanceOf(RewloyException::class, $e);
        self::assertSame(404, $e->status);
        self::assertSame(404, $e->getCode());
        self::assertSame(ErrorCode::PASS_NOT_FOUND, $e->errorCode);
        self::assertNotSame('', $e->detail);
        self::assertNotEmpty($e->requestId, 'x-request-id, the number support asks for');
        self::assertNotNull($e->docs);
        self::assertStringContainsString('PASS_NOT_FOUND', $e->docs);
        self::assertSame('getPass', $e->operation);
        self::assertStringContainsString((string) $e->requestId, $e->getMessage());
    }

    public function testAValidationErrorNamesTheField(): void
    {
        $e = $this->refusal(fn () => $this->rewloy->issuePass(['body' => ['programId' => 'not-a-uuid']]));

        self::assertSame(400, $e->status);
        self::assertSame(ErrorCode::VALIDATION, $e->errorCode);
        self::assertNotEmpty($e->requestId);
        self::assertStringContainsString('programId', $e->detail);
    }

    public function testAMissingRequiredQueryIsAValidationError(): void
    {
        $serial = Fixture::stampCard('till-missing');

        $e = $this->refusal(fn () => $this->rewloy->request('getPassTill', ['params' => ['serial' => $serial]]));

        self::assertSame(400, $e->status);
        self::assertSame(ErrorCode::VALIDATION, $e->errorCode);
        self::assertStringContainsString('locationId', $e->detail);
    }

    public function testAWrongKeyIsRefusedAsInvalid(): void
    {
        $wrong = new Client(apiKey: 'rwk_test_0123456789_aaaaaaaaaaaaaaaaaaaaaaaaaaa', baseUrl: \Rewloy\Tests\Live\Guard::$baseUrl, maxRetries: 0);

        $e = $this->refusal(fn () => $wrong->getBusiness());

        self::assertSame(401, $e->status);
        self::assertSame(ErrorCode::INVALID_API_KEY, $e->errorCode);
        self::assertNotEmpty($e->requestId);
    }

    public function testNoCredentialIsRefusedAsUnauthenticated(): void
    {
        $anonymous = new Client(baseUrl: Guard::$baseUrl, maxRetries: 0);

        $e = $this->refusal(fn () => $anonymous->getBusiness());

        self::assertSame(401, $e->status);
        self::assertSame(ErrorCode::UNAUTHENTICATED, $e->errorCode);
    }

    public function testAnOperationForTeamSessionsRefusesAnApiKey(): void
    {
        $e = $this->refusal(fn () => $this->rewloy->getTestEnvironment());

        self::assertSame(403, $e->status);
        self::assertSame(ErrorCode::CREDENTIAL_NOT_ALLOWED, $e->errorCode);
    }
}

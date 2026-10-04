<?php

declare(strict_types=1);

namespace Rewloy\Tests\Support;

use Closure;
use Generator;
use Rewloy\Http\HttpResponse;
use Rewloy\Http\HttpStream;
use Throwable;

/**
 * Answers as the API writes them, and the identifiers the tests use.
 */
final class Api
{
    public const SERIAL = 'ABCD-EFGH-JKLM';
    public const LOCATION = '0192f7c1-8b2e-7a31-9c1d-2e4f5a6b7c8d';
    public const MERCHANT = '0192f7c1-0000-7000-8000-000000000001';
    public const KEY = 'rwk_abcdefghij_secretpart';
    public const STAFF = 'rws_staffsessiontoken';
    public const HOLDER = 'rwh_holdersessiontoken';
    public const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private const REASONS = [200 => 'OK', 201 => 'Created', 204 => 'No Content', 400 => 'Bad Request', 401 => 'Unauthorized',
        404 => 'Not Found', 409 => 'Conflict', 429 => 'Too Many Requests', 500 => 'Internal Server Error', 502 => 'Bad Gateway',
        503 => 'Service Unavailable', 504 => 'Gateway Timeout'];

    private function __construct()
    {
    }

    /**
     * A JSON answer, with `x-request-id: req-0001` unless the headers say otherwise.
     *
     * @param array<string, string> $headers
     */
    public static function json(int $status, mixed $body, array $headers = []): HttpResponse
    {
        return new HttpResponse(
            $status,
            self::headers(array_change_key_case($headers) + ['content-type' => 'application/json; charset=utf-8', 'x-request-id' => 'req-0001']),
            json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::REASONS[$status] ?? '',
        );
    }

    /**
     * Any answer.
     *
     * @param array<string, string> $headers
     */
    public static function raw(int $status, string $body, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, self::headers(array_change_key_case($headers)), $body, self::REASONS[$status] ?? '');
    }

    /**
     * An error body as the API writes it.
     *
     * @return array{error: array<string, mixed>}
     */
    public static function error(string $code, int $status, string $message, mixed $details = null): array
    {
        $error = ['code' => $code, 'message' => $message, 'requestId' => 'req-body', 'status' => $status,
            'docs' => 'https://rewloy.com/gelistiriciler/hatalar#' . $code];
        if ($details !== null) {
            $error['details'] = $details;
        }
        return ['error' => $error];
    }

    /**
     * A server-sent event stream that sends these pieces and ends; a Throwable
     * among them is thrown where it stands (a broken connection).
     *
     * @param list<string|Throwable> $chunks
     * @param array<string, string> $headers
     * @param (Closure(): void)|null $onClose
     */
    public static function sse(array $chunks, array $headers = [], ?Closure $onClose = null): HttpStream
    {
        return new HttpStream(
            200,
            self::headers(array_change_key_case($headers) + ['content-type' => 'text/event-stream; charset=utf-8']),
            self::pieces($chunks),
            $onClose,
            'OK',
        );
    }

    /**
     * @param list<string|Throwable> $chunks
     * @return Generator<int, string, mixed, void>
     */
    private static function pieces(array $chunks): Generator
    {
        foreach ($chunks as $chunk) {
            if ($chunk instanceof Throwable) {
                throw $chunk;
            }
            yield $chunk;
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, list<string>>
     */
    private static function headers(array $headers): array
    {
        return array_map(static fn (string $v): array => [$v], $headers);
    }
}

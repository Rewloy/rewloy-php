<?php

declare(strict_types=1);

namespace Rewloy\Exception;

use RuntimeException;
use Throwable;

/**
 * The API answered with an error, or the call failed on the way.
 *
 * Act on `errorCode`: it is stable, while `detail` is a human sentence in
 * Turkish that may change. Besides the API's codes
 * (https://rewloy.com/gelistiriciler/hatalar, and the constants of
 * `Rewloy\Generated\ErrorCode`) the client uses:
 * - `CONNECTION_ERROR` and `TIMEOUT` (status 0): no answer arrived;
 * - `INVALID_RESPONSE`: a 2xx answer that is not the documented JSON;
 * - `HTTP_<status>`: an error answer without Rewloy's error body (a proxy's 502 page).
 *
 * The code is `errorCode`, not `code`: PHP's own `getCode()` is final and
 * returns an integer, so here it returns the HTTP status.
 */
class RewloyException extends RuntimeException
{
    /**
     * @param int $status The HTTP status; 0 when no answer arrived.
     * @param string $errorCode The API's stable machine code, e.g. `INSUFFICIENT_BALANCE`.
     * @param string $detail What happened, in the API's words (`error.message`).
     * @param string|null $title The code's one-line title in the catalogue, e.g. "Bakiye yetersiz".
     * @param mixed $details The API's `error.details`, when it sent any: for `VALIDATION` a list
     *                       of `['field' => …, 'rule' => …, 'message' => …]`, for others what the
     *                       catalogue says (`left`, `channels`, `request`…).
     * @param string|null $docs Where the catalogue explains the code (`error.docs`).
     * @param string|null $requestId `x-request-id`: quote it to Rewloy support.
     * @param mixed $body The parsed answer body (or its text, when it is not JSON).
     * @param array<string, list<string>> $headers The answer's headers, names in lower case.
     * @param string|null $operation The operationId of the call.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly string $detail,
        public readonly ?string $title = null,
        public readonly mixed $details = null,
        public readonly ?string $docs = null,
        public readonly ?string $requestId = null,
        public readonly mixed $body = null,
        public readonly array $headers = [],
        public readonly ?string $operation = null,
        ?Throwable $previous = null,
    ) {
        $where = implode(', ', array_filter(
            [$operation, $requestId !== null ? 'requestId ' . $requestId : null],
            static fn (?string $part): bool => $part !== null && $part !== '',
        ));
        parent::__construct(
            ($status !== 0 ? $status . ' ' : '') . $errorCode . ': ' . $detail . ($where !== '' ? ' (' . $where . ')' : ''),
            $status,
            $previous,
        );
    }

    /** A header of the answer, its values joined with ", ", or null. */
    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? null;
        return $values === null || $values === [] ? null : implode(', ', $values);
    }
}

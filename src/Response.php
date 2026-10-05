<?php

declare(strict_types=1);

namespace Rewloy;

/**
 * The whole answer to a call, as `Client::request()` returns it.
 */
final readonly class Response
{
    /**
     * @param mixed $data What `data` held: arrays for JSON, a string for files, null for 204.
     * @param array{page: int, pageSize: int, total: int}|null $meta Paging, on paged lists.
     * @param int $status The HTTP status: 200, 201, 202 or 204. Some operations answer 200
     *                    when they found what they would have created.
     * @param array<string, list<string>> $headers Names in lower case, every value of each.
     * @param string|null $requestId `x-request-id`: quote it to Rewloy support.
     * @param string|null $mode `Rewloy-Mode`: which mode answered (`test` for test keys, once
     *                          the platform has test mode); null when the answer does not say.
     * @param bool $replayed `Idempotent-Replayed: true`: the API replayed the first answer to
     *                       this `Idempotency-Key`.
     */
    public function __construct(
        public mixed $data,
        public ?array $meta,
        public int $status,
        public array $headers,
        public ?string $requestId,
        public ?string $mode,
        public bool $replayed,
    ) {
    }

    /** The `RateLimit-*` headers of the answer; null when it carries none (anonymous calls). */
    public function rateLimit(): ?RateLimit
    {
        return RateLimit::fromHeaders($this->headers);
    }

    /** A header's values joined with ", ", or null when it is absent. */
    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? null;
        return $values === null || $values === [] ? null : implode(', ', $values);
    }
}

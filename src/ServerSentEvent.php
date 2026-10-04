<?php

declare(strict_types=1);

namespace Rewloy;

use JsonException;

/**
 * One event of a server-sent event stream (`liveFeed`, `holderCardEvents`).
 */
final readonly class ServerSentEvent
{
    /**
     * @param string $event The type: the `event:` field, `message` when the event had none.
     * @param string $data The `data:` lines, joined with "\n".
     * @param string $id The last event ID: the latest `id:` the stream has sent ("" if none).
     */
    public function __construct(
        public string $event,
        public string $data,
        public string $id = '',
    ) {
    }

    /**
     * `data` parsed as JSON, objects as arrays.
     *
     * @throws JsonException When `data` is not JSON.
     */
    public function json(): mixed
    {
        return json_decode($this->data, true, 512, JSON_THROW_ON_ERROR);
    }
}

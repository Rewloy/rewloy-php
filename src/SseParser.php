<?php

declare(strict_types=1);

namespace Rewloy;

/**
 * Server-sent events (`text/event-stream`) as the HTML standard parses them
 * (https://html.spec.whatwg.org/multipage/server-sent-events.html), fed bytes
 * in pieces of any size: a line, an event, a CRLF or the byte order mark may
 * be split anywhere between two pieces.
 *
 * The API's streams start with `retry: 5000`, send `: hb` every 25 seconds and
 * events as `event: <type>` + `data: <text>`; they carry no `id:` today.
 */
final class SseParser
{
    private const BOM = "\xEF\xBB\xBF";

    /** The reconnection time the stream asked for (`retry:`), in milliseconds. */
    public ?int $retry = null;

    /**
     * The last event ID: set from the `id:` buffer at every blank line, kept
     * from one event to the next, as the standard says.
     */
    public string $lastEventId;

    private string $id;
    private string $line = '';
    private string $data = '';
    private string $event = '';
    private bool $afterCr = false;
    private bool $started = false;
    /** The stream's first bytes, held while they may still be a byte order mark. */
    private string $head = '';

    public function __construct(string $lastEventId = '')
    {
        $this->lastEventId = $lastEventId;
        $this->id = $lastEventId;
    }

    /**
     * Feeds bytes; returns the events they completed.
     *
     * @return list<ServerSentEvent>
     */
    public function push(string $chunk): array
    {
        if (!$this->started) {
            $chunk = $this->head . $chunk;
            $this->head = '';
            if ($chunk === '') {
                return [];
            }
            if (strlen($chunk) < strlen(self::BOM) && str_starts_with(self::BOM, $chunk)) {
                $this->head = $chunk;
                return [];
            }
            $this->started = true;
            if (str_starts_with($chunk, self::BOM)) {
                $chunk = substr($chunk, strlen(self::BOM));
            }
        }

        $out = [];
        $i = 0;
        $n = strlen($chunk);
        // A CR ended the previous piece: an LF right after it belongs to the same line ending.
        if ($this->afterCr && $i < $n) {
            if ($chunk[$i] === "\n") {
                $i++;
            }
            $this->afterCr = false;
        }
        while ($i < $n) {
            $j = $i + strcspn($chunk, "\r\n", $i);
            if ($j >= $n) {
                $this->line .= substr($chunk, $i);
                break;
            }
            $line = $this->line . substr($chunk, $i, $j - $i);
            $this->line = '';
            if ($chunk[$j] === "\r") {
                if ($j + 1 < $n) {
                    if ($chunk[$j + 1] === "\n") {
                        $j++;
                    }
                } else {
                    $this->afterCr = true;
                }
            }
            $i = $j + 1;
            $this->take($line, $out);
        }
        return $out;
    }

    /** The stream ended: an event without its blank line is dropped, as the standard says. */
    public function end(): void
    {
        $this->line = '';
        $this->data = '';
        $this->event = '';
        $this->afterCr = false;
        $this->head = '';
    }

    /** @param list<ServerSentEvent> $out */
    private function take(string $line, array &$out): void
    {
        if ($line === '') {
            $this->lastEventId = $this->id;
            if ($this->data === '') {
                $this->event = '';
                return;
            }
            $out[] = new ServerSentEvent(
                $this->event !== '' ? $this->event : 'message',
                str_ends_with($this->data, "\n") ? substr($this->data, 0, -1) : $this->data,
                $this->lastEventId,
            );
            $this->data = '';
            $this->event = '';
            return;
        }
        if ($line[0] === ':') {
            return;
        }
        $colon = strpos($line, ':');
        $field = $colon === false ? $line : substr($line, 0, $colon);
        $value = $colon === false ? '' : substr($line, $colon + 1);
        if (str_starts_with($value, ' ')) {
            $value = substr($value, 1);
        }
        switch ($field) {
            case 'event':
                $this->event = $value;
                break;
            case 'data':
                $this->data .= $value . "\n";
                break;
            case 'id':
                if (!str_contains($value, "\0")) {
                    $this->id = $value;
                }
                break;
            case 'retry':
                if (preg_match('/^[0-9]+$/', $value) === 1) {
                    $this->retry = (int) $value;
                }
                break;
        }
    }
}

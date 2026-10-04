<?php

declare(strict_types=1);

namespace Rewloy\Http;

/**
 * @internal What curl's callbacks and the stream's reader share for one
 *           streaming transfer.
 */
final class StreamState
{
    /** The status line and headers, filled by curl's header callback. */
    public readonly ResponseHead $head;
    /** Bytes of the body received and not yet handed out. */
    public string $buffer = '';
    /** The transfer has ended, well or not. */
    public bool $finished = false;
    /** curl's result code once finished (0 = CURLE_OK). */
    public int $errno = 0;
    public string $error = '';
    /** The caller closed the stream. */
    public bool $closed = false;

    public function __construct()
    {
        $this->head = new ResponseHead();
    }
}

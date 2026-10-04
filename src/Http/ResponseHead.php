<?php

declare(strict_types=1);

namespace Rewloy\Http;

/**
 * @internal Collects an answer's status line and headers from curl's header
 *           callback, one line at a time.
 */
final class ResponseHead
{
    public int $status = 0;
    public string $reason = '';
    /** @var array<string, list<string>> */
    public array $headers = [];
    /** The final header block (not an interim 1xx one) has ended. */
    public bool $complete = false;
    private ?string $last = null;

    public function line(string $raw): void
    {
        $line = rtrim($raw, "\r\n");
        if (preg_match('#^HTTP/\S+\s+(\d{3})(?:\s+(.*))?$#', $line, $m) === 1) {
            // A new block: the final answer after an interim one (100 Continue, 103 Early Hints).
            $this->status = (int) $m[1];
            $this->reason = trim($m[2] ?? '');
            $this->headers = [];
            $this->complete = false;
            $this->last = null;
            return;
        }
        if ($line === '') {
            if ($this->status >= 200) {
                $this->complete = true;
            }
            return;
        }
        if (($line[0] === ' ' || $line[0] === "\t") && $this->last !== null) {
            // Obsolete line folding: the line continues the previous header's value.
            $values = $this->headers[$this->last] ?? [];
            $previous = array_pop($values);
            $values[] = trim(($previous ?? '') . ' ' . trim($line));
            $this->headers[$this->last] = $values;
            return;
        }
        $colon = strpos($line, ':');
        if ($colon === false) {
            return;
        }
        $name = strtolower(trim(substr($line, 0, $colon)));
        $this->headers[$name][] = trim(substr($line, $colon + 1));
        $this->last = $name;
    }
}

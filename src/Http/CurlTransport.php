<?php

declare(strict_types=1);

namespace Rewloy\Http;

use CurlHandle;
use CurlMultiHandle;
use Generator;
use InvalidArgumentException;
use LogicException;

/**
 * The default transport, on PHP's curl extension.
 *
 * - Ordinary calls share one handle, so its connections stay open between
 *   them (keep-alive and TLS session reuse).
 * - A stream gets its own handle, driven with curl_multi so that its body is
 *   read as it arrives, in pieces, while the caller iterates.
 * - Redirects are not followed, and only http and https are spoken.
 * - Compressed answers are accepted and decoded (gzip, deflate, br as curl
 *   supports).
 */
final class CurlTransport implements Transport
{
    /** Reason phrases for answers whose status line carries none (HTTP/2). */
    private const REASONS = [
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
        301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified',
        307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
        405 => 'Method Not Allowed', 406 => 'Not Acceptable', 408 => 'Request Timeout', 409 => 'Conflict',
        410 => 'Gone', 411 => 'Length Required', 412 => 'Precondition Failed', 413 => 'Content Too Large',
        414 => 'URI Too Long', 415 => 'Unsupported Media Type', 422 => 'Unprocessable Content',
        425 => 'Too Early', 428 => 'Precondition Required', 429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway',
        503 => 'Service Unavailable', 504 => 'Gateway Timeout',
    ];

    /** The longest single wait while a stream is silent, in seconds. */
    private const POLL = 1.0;

    private ?CurlHandle $handle = null;

    /**
     * @param array<int, mixed> $options Extra curl options for every request, e.g.
     *        `[CURLOPT_PROXY => 'http://proxy:3128']` or `[CURLOPT_CAINFO => '/etc/ssl/ca.pem']`.
     *        They cannot replace the options the transport works by (the URL,
     *        method, headers, body, callbacks, timeouts and protocols).
     */
    public function __construct(private readonly array $options = [])
    {
        if (!extension_loaded('curl')) {
            throw new LogicException('Rewloy: the curl extension is required (or pass your own Transport)');
        }
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $ch = $this->handle ??= self::init();
        // A reset keeps the handle's open connections and caches, and clears every option.
        curl_reset($ch);
        $head = new ResponseHead();
        $ms = self::milliseconds($request->timeout);
        $this->configure($ch, $request, $head, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $ms,
            CURLOPT_CONNECTTIMEOUT_MS => $ms,
        ]);
        $body = curl_exec($ch);
        if (!is_string($body)) {
            $errno = curl_errno($ch);
            throw new TransportException(self::describe($errno, curl_error($ch)), $errno === CURLE_OPERATION_TIMEDOUT);
        }
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($status <= 0) {
            $status = $head->status;
        }
        return new HttpResponse($status, $head->headers, $body, self::reason($status, $head));
    }

    public function stream(HttpRequest $request): HttpStream
    {
        $ch = self::init();
        // The callbacks fill the state; every read goes through it, after the pump that may have changed it.
        $state = new StreamState();
        $this->configure($ch, $request, $state->head, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $data) use ($state): int {
                if ($state->closed) {
                    return 0; // a short count makes curl abort the transfer
                }
                $state->buffer .= $data;
                return strlen($data);
            },
            // No limit on the whole transfer: the stream lasts as long as the caller reads it.
            CURLOPT_TIMEOUT_MS => 0,
            CURLOPT_CONNECTTIMEOUT_MS => self::milliseconds($request->timeout),
        ]);
        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $ch);
        $close = static function () use ($multi, $ch, $state): void {
            if ($state->closed) {
                return;
            }
            $state->closed = true;
            // Removing an unfinished transfer closes its connection.
            curl_multi_remove_handle($multi, $ch);
        };

        $deadline = $request->timeout > 0 ? microtime(true) + $request->timeout : null;
        try {
            for (;;) {
                self::pump($multi, $state);
                if ($state->head->complete) {
                    break;
                }
                if ($state->finished) {
                    throw new TransportException($state->errno !== 0
                        ? self::describe($state->errno, $state->error)
                        : 'the connection closed before the answer\'s headers', $state->errno === CURLE_OPERATION_TIMEDOUT);
                }
                $wait = self::POLL;
                if ($deadline !== null) {
                    $left = $deadline - microtime(true);
                    if ($left <= 0) {
                        throw new TransportException(sprintf('no answer within %s s', self::seconds($request->timeout)), true);
                    }
                    $wait = min($wait, $left);
                }
                self::select($multi, $wait);
            }
        } catch (TransportException $e) {
            $close();
            throw $e;
        }

        $head = $state->head;
        return new HttpStream(
            $head->status,
            $head->headers,
            self::body($multi, $state, $request->idleTimeout),
            $close,
            self::reason($head->status, $head),
        );
    }

    /**
     * The body as it arrives.
     *
     * @return Generator<int, string, mixed, void>
     */
    private static function body(CurlMultiHandle $multi, StreamState $state, float $idle): Generator
    {
        $last = microtime(true);
        while (!$state->closed) {
            if ($state->buffer !== '') {
                $chunk = $state->buffer;
                $state->buffer = '';
                $last = microtime(true);
                yield $chunk;
                continue;
            }
            if ($state->finished) {
                if ($state->errno !== 0) {
                    throw new TransportException(self::describe($state->errno, $state->error), $state->errno === CURLE_OPERATION_TIMEDOUT);
                }
                return;
            }
            $wait = self::POLL;
            if ($idle > 0) {
                $left = $last + $idle - microtime(true);
                if ($left <= 0) {
                    throw new TransportException(sprintf('no data for %s s', self::seconds($idle)), true);
                }
                $wait = min($wait, $left);
            }
            self::select($multi, $wait);
            self::pump($multi, $state);
        }
    }

    /**
     * Options for one request: the extra ones first, then the transport's own,
     * which win.
     *
     * @param array<int, mixed> $own
     */
    private function configure(CurlHandle $ch, HttpRequest $request, ResponseHead $head, array $own): void
    {
        $headers = ['Expect:']; // no 100-continue round trip before a body
        foreach ($request->headers as $name => $value) {
            if (preg_match('/[\r\n\0]/', $name . $value) === 1) {
                // A caller's mistake (a token read with its newline), not a failed connection: never retried.
                // The message names the header, never its value, which may be a credential.
                throw new InvalidArgumentException(sprintf('Rewloy: the %s header contains a line break', $name));
            }
            $headers[] = $name . ': ' . $value;
        }
        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use ($head): int {
                $head->line($line);
                return strlen($line);
            },
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
            CURLOPT_SUPPRESS_CONNECT_HEADERS => true,
        ];
        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        } elseif (in_array($request->method, ['POST', 'PUT', 'PATCH'], true)) {
            // An empty body with Content-Length: 0, and not the form type curl would add.
            $options[CURLOPT_POSTFIELDS] = '';
            if ($request->header('content-type') === null) {
                $headers[] = 'Content-Type:';
            }
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        if (!curl_setopt_array($ch, $own + $options + $this->options)) {
            throw new TransportException('curl refused an option: ' . curl_error($ch));
        }
    }

    private static function init(): CurlHandle
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new TransportException('curl_init failed');
        }
        return $ch;
    }

    /** Runs the transfer as far as it can go now, and notes when it has ended. */
    private static function pump(CurlMultiHandle $multi, StreamState $state): void
    {
        $running = 0;
        do {
            $code = curl_multi_exec($multi, $running);
        } while ($code === CURLM_CALL_MULTI_PERFORM);
        if ($code !== CURLM_OK) {
            $state->finished = true;
            $state->errno = -1;
            $state->error = curl_multi_strerror($code) ?? 'curl_multi_exec failed';
            return;
        }
        while (($info = curl_multi_info_read($multi)) !== false) {
            if (($info['msg'] ?? null) === CURLMSG_DONE) {
                $result = $info['result'] ?? null;
                $state->finished = true;
                $state->errno = is_int($result) ? $result : -1;
                $state->error = curl_strerror($state->errno) ?? '';
            }
        }
    }

    private static function select(CurlMultiHandle $multi, float $seconds): void
    {
        if (curl_multi_select($multi, max(0.0, $seconds)) === -1) {
            // Nothing to wait on yet (some systems answer -1 at once): do not spin.
            usleep(5000);
        }
    }

    private static function milliseconds(float $seconds): int
    {
        return $seconds > 0 ? (int) ceil($seconds * 1000) : 0;
    }

    private static function seconds(float $seconds): string
    {
        return rtrim(rtrim(sprintf('%.3f', $seconds), '0'), '.');
    }

    private static function reason(int $status, ResponseHead $head): string
    {
        return $head->reason !== '' ? $head->reason : (self::REASONS[$status] ?? '');
    }

    private static function describe(int $errno, string $error): string
    {
        if ($error !== '') {
            return $error;
        }
        return curl_strerror($errno) ?? sprintf('curl error %d', $errno);
    }
}

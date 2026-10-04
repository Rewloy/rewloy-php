<?php

declare(strict_types=1);

namespace Rewloy\Http;

/**
 * How the client talks HTTP. The default is {@see CurlTransport}; pass your own
 * to `new Client(transport: …)` for a proxy, instrumentation or tests.
 *
 * An implementation sends the request as given (it adds no headers of its own
 * beyond what HTTP needs), follows no redirects, and throws
 * {@see TransportException} when no answer arrived: the client decides about
 * retries, and maps every HTTP status itself. A request it cannot send as
 * given (a header value with a line break) is an `InvalidArgumentException`,
 * which the client does not retry.
 */
interface Transport
{
    /**
     * One exchange. Returns the answer, its whole body read, whatever its
     * status; `$request->timeout` covers the whole exchange.
     *
     * @throws TransportException
     */
    public function send(HttpRequest $request): HttpResponse;

    /**
     * Opens a streaming answer (server-sent events). Returns as soon as the
     * headers are in, within `$request->timeout`; the body then arrives
     * through {@see HttpStream::$body}, which throws {@see TransportException}
     * when the connection breaks or stays silent for `$request->idleTimeout`.
     *
     * @throws TransportException
     */
    public function stream(HttpRequest $request): HttpStream;
}

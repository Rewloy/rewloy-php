<?php

declare(strict_types=1);

namespace Rewloy\Tests\Support;

use Closure;
use LogicException;
use Rewloy\Http\HttpRequest;
use Rewloy\Http\HttpResponse;
use Rewloy\Http\HttpStream;
use Rewloy\Http\Transport;
use Throwable;

/**
 * A stub of the API for the tests: records every request and answers with
 * whatever the test says. No network.
 */
final class StubTransport implements Transport
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    /** How many streams the client closed. */
    public int $closed = 0;

    /**
     * @param Closure(HttpRequest, int): (HttpResponse|HttpStream|Throwable) $handler
     *        Answers request number n (1-based); a Throwable is thrown, as a transport would.
     */
    public function __construct(private readonly Closure $handler)
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $answer = $this->answer($request);
        if ($answer instanceof HttpStream) {
            return new HttpResponse($answer->status, $answer->headers, $answer->readAll(), $answer->reason);
        }
        return $answer;
    }

    public function stream(HttpRequest $request): HttpStream
    {
        $answer = $this->answer($request);
        if ($answer instanceof HttpResponse) {
            return new HttpStream($answer->status, $answer->headers, [$answer->body], function (): void {
                $this->closed++;
            }, $answer->reason);
        }
        return new HttpStream($answer->status, $answer->headers, $answer->body, function () use ($answer): void {
            $this->closed++;
            $answer->close();
        }, $answer->reason);
    }

    /** @phpstan-impure */
    public function last(): HttpRequest
    {
        return $this->requests[count($this->requests) - 1] ?? throw new LogicException('no request yet');
    }

    /** @phpstan-impure */
    public function request(int $n): HttpRequest
    {
        return $this->requests[$n] ?? throw new LogicException('no request ' . $n);
    }

    /**
     * A request's path and query, as the server would see them.
     */
    public static function target(HttpRequest $request): string
    {
        $parts = parse_url($request->url);
        $path = is_array($parts) ? ($parts['path'] ?? '/') : '/';
        $query = is_array($parts) && isset($parts['query']) ? '?' . $parts['query'] : '';
        return $path . $query;
    }

    private function answer(HttpRequest $request): HttpResponse|HttpStream
    {
        $this->requests[] = $request;
        $answer = ($this->handler)($request, count($this->requests));
        if ($answer instanceof Throwable) {
            throw $answer;
        }
        return $answer;
    }
}

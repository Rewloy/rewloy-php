<?php

declare(strict_types=1);

namespace Rewloy;

use BackedEnum;
use Closure;
use Generator;
use InvalidArgumentException;
use JsonException;
use Rewloy\Exception\ConnectionException;
use Rewloy\Exception\RateLimitException;
use Rewloy\Exception\RewloyException;
use Rewloy\Exception\TimeoutException;
use Rewloy\Generated\ErrorCode;
use Rewloy\Generated\Methods;
use Rewloy\Generated\Operations;
use Rewloy\Http\CurlTransport;
use Rewloy\Http\HttpRequest;
use Rewloy\Http\HttpResponse;
use Rewloy\Http\HttpStream;
use Rewloy\Http\Transport;
use Rewloy\Http\TransportException;
use SensitiveParameter;
use Stringable;
use Throwable;

/**
 * A client of the Rewloy API (`https://app.rewloy.com/v1`).
 *
 *     $rewloy = new \Rewloy\Client(apiKey: getenv('REWLOY_API_KEY'));
 *     $card = $rewloy->getPass(['params' => ['serial' => 'ABCD-EFGH-JKLM']]);
 *
 * Every operation of the API is a method named by its operationId (they come
 * from {@see Methods}, generated from the OpenAPI document). Each takes one
 * array with `params`, `query` and `body` as the operation needs, plus
 * `merchant`, `idempotencyKey`, `timeout` and `maxRetries`, and returns the
 * answer's `data`.
 *
 * @phpstan-import-type OperationMeta from Operations
 */
class Client
{
    use Methods;

    /** This library's version (the latest in CHANGELOG.md; a test keeps them equal). */
    public const VERSION = '0.2.1';
    public const DEFAULT_BASE_URL = 'https://app.rewloy.com';
    /** Seconds allowed for one attempt. */
    public const DEFAULT_TIMEOUT = 60.0;
    public const DEFAULT_MAX_RETRIES = 2;
    /** Seconds a stream may stay silent before it counts as dropped (the API's heartbeat is every 25). */
    public const DEFAULT_IDLE_TIMEOUT = 60.0;
    /** A stream's wait before reconnecting until the server says otherwise (`retry:`), in seconds. */
    private const DEFAULT_RECONNECT = 3.0;
    /** A failing stream's longest wait between reconnections, in seconds. */
    private const MAX_RECONNECT = 30.0;
    private const IDEMPOTENT_METHODS = ['GET', 'HEAD', 'PUT', 'DELETE'];
    /** The prefix and kind of each credential. */
    private const CREDENTIALS = ['apiKey' => ['rwk_', 'key'], 'staffSession' => ['rws_', 'staff'], 'holderSession' => ['rwh_', 'holder']];

    /** @var array<string, true> Operations already warned about: one notice per operation per process. */
    private static array $warned = [];

    /** The API's origin, without `/v1`. */
    public readonly string $baseUrl;
    /** Seconds allowed for one attempt; 0 for none. */
    public readonly float $timeout;
    /** Retries after a failed attempt, when retrying is safe. */
    public readonly int $maxRetries;
    /** The kind of credential this client sends: `key`, `staff`, `holder`, or null for none. */
    public readonly ?string $credential;
    /** The default `Rewloy-Merchant` of a staff session. */
    public readonly ?string $merchant;
    private readonly ?string $token;
    private readonly Transport $transport;
    private readonly string $userAgent;
    /** @var Closure(float): void */
    private readonly Closure $sleep;

    /**
     * Give one credential, or none for the endpoints that need none (sign-in,
     * joining a programme…).
     *
     * @param string|null $apiKey An API key, `rwk_…`: a till, a shop, your own system.
     * @param string|null $staffSession A staff session, `rws_…` (`login`): a person's business app.
     * @param string|null $merchant With a staff session: the business it acts for
     *                              (`Rewloy-Merchant`), when the person has seats in several.
     * @param string|null $holderSession A card holder's session, `rwh_…`: a Rewloy Cüzdan app.
     * @param string $baseUrl The API's address: `https://app.rewloy.com` or `https://app.rewloy.com/v1`
     *                        (a trailing `/v1` and trailing slashes are dropped; the client adds `/v1` itself).
     * @param int|float $timeout Seconds allowed for one attempt; 0 for none.
     * @param int $maxRetries Retries after a failed attempt, when retrying is safe.
     * @param Transport|null $transport How to talk HTTP; curl when null.
     * @param string|null $userAgent Added to the `User-Agent` this client sends, e.g. `KasaPOS/4.2`.
     * @param (callable(float): void)|null $sleep Replaces the wait between retries, given in
     *                                            seconds (tests, custom schedulers).
     */
    public function __construct(
        #[SensitiveParameter] ?string $apiKey = null,
        #[SensitiveParameter] ?string $staffSession = null,
        ?string $merchant = null,
        #[SensitiveParameter] ?string $holderSession = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        int|float $timeout = self::DEFAULT_TIMEOUT,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        ?Transport $transport = null,
        ?string $userAgent = null,
        ?callable $sleep = null,
    ) {
        $tokens = ['apiKey' => $apiKey, 'staffSession' => $staffSession, 'holderSession' => $holderSession];
        $given = array_keys(array_filter($tokens, static fn (?string $t): bool => $t !== null));
        if (count($given) > 1) {
            throw new InvalidArgumentException('Rewloy: give one credential, not ' . implode(' and ', $given));
        }
        $token = null;
        $kind = null;
        foreach ($given as $which) {
            [$prefix, $kind] = self::CREDENTIALS[$which];
            $token = (string) $tokens[$which];
            if (!str_starts_with($token, $prefix)) {
                throw new InvalidArgumentException(sprintf('Rewloy: %s must start with "%s"', $which, $prefix));
            }
        }
        $this->token = $token;
        $this->credential = $kind;
        if ($merchant !== null && $staffSession === null) {
            throw new InvalidArgumentException('Rewloy: `merchant` goes with a staffSession');
        }
        if ($timeout < 0) {
            throw new InvalidArgumentException('Rewloy: `timeout` must be 0 (none) or more seconds');
        }
        if ($maxRetries < 0) {
            throw new InvalidArgumentException('Rewloy: `maxRetries` must be 0 or more');
        }
        $this->merchant = $merchant;
        $this->baseUrl = self::normalizeBaseUrl($baseUrl);
        $this->timeout = (float) $timeout;
        $this->maxRetries = $maxRetries;
        $this->transport = $transport ?? new CurlTransport();
        $suffix = trim((string) $userAgent);
        $this->userAgent = 'rewloy-php/' . self::VERSION . ' PHP/' . PHP_VERSION . ($suffix !== '' ? ' ' . $suffix : '');
        $this->sleep = $sleep !== null ? $sleep(...) : static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
    }

    /**
     * Calls an operation and returns the whole answer: `data`, `meta` on paged
     * lists, the status, headers, `requestId`, `mode` and `replayed`.
     *
     *     $res = $rewloy->request('sendCampaign', ['body' => ['body' => 'Bu hafta kahveler 2 damga!']]);
     *     $res->status; $res->replayed; $res->data['id'];
     *
     * @param array<string, mixed> $args What the operation's method takes.
     *
     * @throws RewloyException
     * @throws InvalidArgumentException An unknown operation, a stream, or an argument it does not take.
     */
    public function request(string $operation, array $args = []): Response
    {
        $op = $this->operation($operation);
        if ($op['stream']) {
            throw new InvalidArgumentException(sprintf("Rewloy: %s is a stream; use stream('%s')", $operation, $operation));
        }
        $res = $this->send($operation, $op, $args);
        [$data, $meta] = $this->read($operation, $op, $res);
        return new Response(
            $data,
            $meta,
            $res->status,
            $res->headers,
            $res->header('x-request-id'),
            $res->header('rewloy-mode'),
            $res->header('idempotent-replayed') === 'true',
        );
    }

    /**
     * Walks a paged list item by item, asking for the next page (`page`) while
     * `meta` says there is one. `query.page` sets where to start and
     * `query.limit` the page size. Stop reading and it stops asking.
     *
     *     foreach ($rewloy->paginate('listCustomers', ['query' => ['consent' => 'yes']]) as $customer) { … }
     *
     * @param array<string, mixed> $args What the operation's method takes.
     * @return Generator<int, array<string, mixed>, mixed, void>
     *
     * @throws RewloyException While iterating.
     * @throws InvalidArgumentException An unknown operation, one that is not a paged list, or an
     *                                  argument it does not take.
     */
    public function paginate(string $operation, array $args = []): Generator
    {
        $op = $this->operation($operation);
        if (!$op['paged']) {
            throw new InvalidArgumentException(sprintf('Rewloy: %s is not a paged list', $operation));
        }
        $this->check($operation, $op, $args);
        $this->url($operation, $op, $args); // a missing parameter fails here, not at the first iteration
        return $this->pages($operation, $op, $args);
    }

    /**
     * Opens a server-sent event stream (`liveFeed`, `holderCardEvents`) and
     * yields its events. It reconnects by itself unless `reconnect` is false;
     * stop it by leaving the loop.
     *
     *     foreach ($rewloy->stream('liveFeed') as $event) {
     *         if ($event->event === 'event') { $what = $event->json(); }
     *     }
     *
     * @param array<string, mixed> $args What the operation's method takes, plus `reconnect`
     *                                   (default true) and `idleTimeout` (seconds, default 60).
     * @return Generator<int, ServerSentEvent, mixed, void>
     *
     * @throws RewloyException While iterating: what a reconnection cannot fix (401, 403, 404…).
     * @throws InvalidArgumentException An unknown operation, one that is not a stream, or an
     *                                  argument it does not take.
     */
    public function stream(string $operation, array $args = []): Generator
    {
        return $this->open($operation, $args);
    }

    /**
     * The generated methods call this.
     *
     * @internal
     *
     * @param array<string, mixed> $args
     */
    protected function call(string $operation, array $args): mixed
    {
        $op = $this->operation($operation);
        if ($op['stream']) {
            throw new InvalidArgumentException(sprintf("Rewloy: %s is a stream; use stream('%s')", $operation, $operation));
        }
        $res = $this->send($operation, $op, $args);
        [$data, $meta] = $this->read($operation, $op, $res);
        return $op['paged'] ? ['data' => $data, 'meta' => $meta] : $data;
    }

    /**
     * The generated stream methods call this.
     *
     * @internal
     *
     * @param array<string, mixed> $args
     * @return Generator<int, ServerSentEvent, mixed, void>
     */
    protected function open(string $operation, array $args): Generator
    {
        $op = $this->operation($operation);
        if (!$op['stream']) {
            throw new InvalidArgumentException(sprintf("Rewloy: %s is not a stream; use request('%s')", $operation, $operation));
        }
        $this->check($operation, $op, $args);
        $this->url($operation, $op, $args); // a missing parameter fails here, not at the first iteration
        return $this->events($operation, $op, $args);
    }

    /** @return array<string, mixed> What var_dump() and print_r() show: everything but the credential. */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'maxRetries' => $this->maxRetries,
            'credential' => $this->credential,
            'merchant' => $this->merchant,
            'transport' => $this->transport::class,
        ];
    }

    /**
     * @param OperationMeta $op
     * @param array<string, mixed> $args
     * @return Generator<int, array<string, mixed>, mixed, void>
     */
    private function pages(string $id, array $op, array $args): Generator
    {
        $query = $args['query'] ?? [];
        if (!is_array($query)) {
            throw new InvalidArgumentException(sprintf('Rewloy: %s: `query` must be an array', $id));
        }
        $page = $query['page'] ?? 1;
        $page = is_int($page) ? $page : (int) self::scalar($id, 'query.page', $page);
        for (;;) {
            [$data, $meta] = $this->read($id, $op, $this->send($id, $op, ['query' => [...$query, 'page' => $page]] + $args));
            $items = is_array($data) ? array_values($data) : [];
            foreach ($items as $item) {
                /** @var array<string, mixed> $item */
                yield $item;
            }
            if ($meta === null || $items === [] || count($items) < $meta['pageSize'] || $meta['page'] * $meta['pageSize'] >= $meta['total']) {
                return;
            }
            $page = $meta['page'] + 1;
        }
    }

    /**
     * A stream, reconnected as a browser's `EventSource` would: after the
     * server's `retry:` delay, with `Last-Event-ID` once an event carried an
     * id, backing off while connections fail.
     *
     * @param OperationMeta $op
     * @param array<string, mixed> $args
     * @return Generator<int, ServerSentEvent, mixed, void>
     */
    private function events(string $id, array $op, array $args): Generator
    {
        $reconnect = $args['reconnect'] ?? true;
        if (!is_bool($reconnect)) {
            throw new InvalidArgumentException(sprintf('Rewloy: %s: `reconnect` must be a boolean', $id));
        }
        $idle = self::seconds($id, $args, 'idleTimeout') ?? self::DEFAULT_IDLE_TIMEOUT;
        $lastEventId = '';
        $wait = self::DEFAULT_RECONNECT;
        $failures = 0;
        // An exception kept in this generator's frame would keep its backtrace, whose arguments can hold
        // the generator itself: a cycle that delays the close on `break` until PHP's cycle collector runs.
        // So each one is let go as soon as it has been read.
        for (;;) {
            try {
                $conn = $this->connect($id, $op, $args, $lastEventId, $idle);
            } catch (RewloyException $e) {
                if (!$reconnect || !Retry::transientStatus($e->status)) {
                    throw $e;
                }
                unset($e);
                $failures++;
                ($this->sleep)(max($wait, min(self::MAX_RECONNECT, 2 ** min($failures, 8))));
                continue;
            }

            $parser = new SseParser($lastEventId);
            $dropped = null;
            try {
                foreach ($conn->body as $chunk) {
                    foreach ($parser->push($chunk) as $event) {
                        $lastEventId = $event->id;
                        $failures = 0;
                        yield $event;
                    }
                    $lastEventId = $parser->lastEventId;
                    if ($parser->retry !== null) {
                        $wait = $parser->retry / 1000;
                    }
                }
                $parser->end();
            } catch (TransportException $e) {
                $requestId = $conn->header('x-request-id');
                $dropped = $e->timeout
                    ? new TimeoutException($e->getMessage(), $id, $requestId, $e)
                    : new ConnectionException($e->getMessage(), $id, $requestId, $e);
                unset($e);
            } finally {
                // Also when the caller leaves the loop: the generator is destroyed and this runs.
                $conn->close();
            }

            if (!$reconnect) {
                if ($dropped !== null) {
                    throw $dropped;
                }
                return;
            }
            $delay = $wait;
            if ($dropped !== null) {
                $failures++;
                $delay = max($wait, min(self::MAX_RECONNECT, 2 ** min($failures, 8)));
                $dropped = null;
            }
            ($this->sleep)($delay);
        }
    }

    /**
     * @param OperationMeta $op
     * @param array<string, mixed> $args
     *
     * @throws RewloyException
     */
    private function send(string $id, array $op, array $args): HttpResponse
    {
        $this->check($id, $op, $args);
        $res = $this->exchange($id, $op, $args, null, 0.0);
        if (!$res instanceof HttpResponse) {
            throw new \LogicException('Rewloy: a stream answer to an ordinary call');
        }
        return $res;
    }

    /**
     * @param OperationMeta $op
     * @param array<string, mixed> $args
     *
     * @throws RewloyException
     */
    private function connect(string $id, array $op, array $args, string $lastEventId, float $idle): HttpStream
    {
        $conn = $this->exchange($id, $op, $args, $lastEventId, $idle);
        if (!$conn instanceof HttpStream) {
            throw new \LogicException('Rewloy: an ordinary answer to a stream');
        }
        return $conn;
    }

    /**
     * One call: attempts until an answer settles it. For a stream it returns
     * once the headers are in, the body unread; otherwise with the body read.
     *
     * @param OperationMeta $op
     * @param array<string, mixed> $args
     *
     * @throws RewloyException
     */
    private function exchange(string $id, array $op, array $args, ?string $lastEventId, float $idle): HttpResponse|HttpStream
    {
        $stream = $op['stream'];
        $timeout = self::seconds($id, $args, 'timeout') ?? $this->timeout;
        $request = new HttpRequest(
            $op['method'],
            $this->url($id, $op, $args),
            $this->headers($id, $op, $args, $lastEventId),
            $op['body'] ? self::json($id, $args['body'] ?? null) : null,
            $timeout,
            $stream ? $idle : 0.0,
        );
        $retryable = in_array($op['method'], self::IDEMPOTENT_METHODS, true) || $request->header('idempotency-key') !== null;
        $maxRetries = $args['maxRetries'] ?? $this->maxRetries;
        if (!is_int($maxRetries) || $maxRetries < 0) {
            throw new InvalidArgumentException(sprintf('Rewloy: %s: `maxRetries` must be an integer, 0 or more', $id));
        }

        for ($attempt = 0; ; $attempt++) {
            $wait = null;
            try {
                if ($stream) {
                    $conn = $this->transport->stream($request);
                    $this->notice($id, $op, $conn->headers);
                    if ($conn->status >= 200 && $conn->status < 300) {
                        return $conn;
                    }
                    $res = new HttpResponse($conn->status, $conn->headers, $conn->readAll(), $conn->reason);
                } else {
                    $res = $this->transport->send($request);
                    $this->notice($id, $op, $res->headers);
                    if ($res->status >= 200 && $res->status < 300) {
                        return $res;
                    }
                }
                $failure = $this->failure($id, $res);
                if (!$retryable || $attempt >= $maxRetries || !Retry::retryableStatus($res->status, $failure->errorCode)) {
                    throw $failure;
                }
                $wait = Retry::parseRetryAfter($res->header('retry-after'));
            } catch (TransportException $e) {
                $failure = $e->timeout
                    ? new TimeoutException($timeout > 0 ? sprintf('no answer within %s s', self::format($timeout)) : $e->getMessage(), $id, null, $e)
                    : new ConnectionException($e->getMessage(), $id, null, $e);
                if (!$retryable || $attempt >= $maxRetries) {
                    throw $failure;
                }
            }
            $delay = $wait ?? Retry::backoff($attempt);
            if ($delay > Retry::MAX_RETRY_AFTER) {
                throw $failure;
            }
            ($this->sleep)($delay);
        }
    }

    /**
     * Reads a successful answer: `[data, meta]`.
     *
     * @param OperationMeta $op
     * @return array{mixed, array{page: int, pageSize: int, total: int}|null}
     *
     * @throws RewloyException
     */
    private function read(string $id, array $op, HttpResponse $res): array
    {
        if ($op['response'] === 'none' || $res->status === 204) {
            return [null, null];
        }
        if ($op['response'] === 'blob') {
            return [$res->body, null];
        }
        try {
            $parsed = json_decode($res->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->invalid($id, $res, $res->body);
        }
        if ($op['response'] === 'raw-json') {
            return [$parsed, null];
        }
        if (!is_array($parsed) || !array_key_exists('data', $parsed)) {
            throw $this->invalid($id, $res, $parsed);
        }
        return [$parsed['data'], self::meta($parsed['meta'] ?? null)];
    }

    private function invalid(string $id, HttpResponse $res, mixed $body): RewloyException
    {
        return new RewloyException(
            $res->status,
            'INVALID_RESPONSE',
            sprintf('the answer is not the JSON the API documents (%s)', $res->header('content-type') ?? 'no content type'),
            requestId: $res->header('x-request-id'),
            body: $body,
            headers: $res->headers,
            operation: $id,
        );
    }

    private function failure(string $id, HttpResponse $res): RewloyException
    {
        $parsed = null;
        if ($res->body !== '') {
            try {
                $parsed = json_decode($res->body, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $parsed = $res->body; // not JSON: a proxy's page
            }
        }
        $e = is_array($parsed) && is_array($parsed['error'] ?? null) ? $parsed['error'] : [];
        $code = is_string($e['code'] ?? null) ? $e['code'] : 'HTTP_' . $res->status;
        $detail = is_string($e['message'] ?? null) ? $e['message'] : ($res->reason !== '' ? $res->reason : 'HTTP ' . $res->status);
        $details = $e['details'] ?? null;
        $docs = is_string($e['docs'] ?? null) ? $e['docs'] : null;
        $requestId = $res->header('x-request-id') ?? (is_string($e['requestId'] ?? null) ? $e['requestId'] : null);
        $title = ErrorCode::title($code);
        if ($res->status === 429) {
            $header = Retry::parseRetryAfter($res->header('retry-after'));
            $fromBody = is_array($details) && (is_int($details['retryAfterSec'] ?? null) || is_float($details['retryAfterSec'] ?? null))
                ? (int) ceil($details['retryAfterSec'])
                : null;
            return new RateLimitException(
                $header !== null ? (int) ceil($header) : $fromBody,
                $detail,
                $title,
                $details,
                $docs,
                $requestId,
                $parsed,
                $res->headers,
                $id,
                errorCode: $code,
            );
        }
        return new RewloyException($res->status, $code, $detail, $title, $details, $docs, $requestId, $parsed, $res->headers, $id);
    }

    /**
     * One E_USER_DEPRECATED per operation per process, naming `Sunset` and `Link`.
     *
     * @param OperationMeta $op
     * @param array<string, list<string>> $headers
     */
    private function notice(string $id, array $op, array $headers): void
    {
        if (!isset($headers['deprecation']) || isset(self::$warned[$id])) {
            return;
        }
        self::$warned[$id] = true;
        $sunset = isset($headers['sunset']) ? implode(', ', $headers['sunset']) : null;
        $link = self::deprecationLink(isset($headers['link']) ? implode(', ', $headers['link']) : null);
        $message = sprintf('Rewloy API operation %s (%s %s) is deprecated.', $id, $op['method'], $op['path'])
            . ($sunset !== null ? ' Sunset: ' . $sunset . '.' : '')
            . ($link !== null ? ' See ' . $link : '');
        try {
            trigger_error($message, E_USER_DEPRECATED);
        } catch (Throwable) {
            // An error handler that throws on deprecations must not turn an answer into a failure:
            // the call has happened. The notice goes to the error log instead.
            error_log('PHP Deprecated:  ' . $message);
        }
    }

    /**
     * @return OperationMeta
     */
    private function operation(string $id): array
    {
        return Operations::get($id) ?? throw new InvalidArgumentException(sprintf('Rewloy: unknown operation "%s"', $id));
    }

    /**
     * Refuses a key the operation does not take: a typo would otherwise pass
     * unnoticed (a misspelt `idempotencyKey` would send a generated key).
     *
     * @param OperationMeta $op
     * @param array<string, mixed> $args
     */
    private function check(string $id, array $op, array $args): void
    {
        $allowed = [];
        if (str_contains($op['path'], '{')) {
            $allowed[] = 'params';
        }
        if ($op['query']) {
            $allowed[] = 'query';
        }
        if ($op['body']) {
            $allowed[] = 'body';
        }
        if ($op['idempotency'] !== null) {
            $allowed[] = 'idempotencyKey';
        }
        if ($op['merchant']) {
            $allowed[] = 'merchant';
        }
        if ($op['headers'] !== []) {
            $allowed[] = 'headers';
        }
        array_push($allowed, 'timeout', 'maxRetries');
        if ($op['stream']) {
            array_push($allowed, 'reconnect', 'idleTimeout');
        }
        foreach (array_keys($args) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidArgumentException(sprintf('Rewloy: %s does not take `%s`; it takes %s', $id, $key, implode(', ', $allowed)));
            }
        }
    }

    /**
     * @param OperationMeta $op
     * @param array<string, mixed> $args
     */
    private function url(string $id, array $op, array $args): string
    {
        $params = $args['params'] ?? [];
        if (!is_array($params)) {
            throw new InvalidArgumentException(sprintf('Rewloy: %s: `params` must be an array', $id));
        }
        $path = preg_replace_callback('/\{([^}]+)\}/', static function (array $m) use ($id, $params): string {
            $value = $params[$m[1]] ?? null;
            if ($value === null || $value === '') {
                throw new InvalidArgumentException(sprintf('Rewloy: %s needs params.%s', $id, $m[1]));
            }
            return rawurlencode(self::scalar($id, 'params.' . $m[1], $value));
        }, $op['path']);
        $query = $args['query'] ?? [];
        if (!is_array($query)) {
            throw new InvalidArgumentException(sprintf('Rewloy: %s: `query` must be an array', $id));
        }
        $pairs = [];
        foreach ($query as $key => $value) {
            foreach (is_array($value) ? $value : [$value] as $one) {
                if ($one !== null) {
                    $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode(self::scalar($id, 'query.' . $key, $one));
                }
            }
        }
        return $this->baseUrl . $path . ($pairs !== [] ? '?' . implode('&', $pairs) : '');
    }

    /**
     * @param OperationMeta $op
     * @param array<string, mixed> $args
     * @return array<string, string>
     */
    private function headers(string $id, array $op, array $args, ?string $lastEventId): array
    {
        $h = [
            'accept' => $op['stream'] ? 'text/event-stream' : (in_array($op['response'], ['json', 'raw-json'], true) ? 'application/json' : '*/*'),
            'user-agent' => $this->userAgent,
        ];
        // An operation that does not take this kind of credential but works without one is called
        // without it: the API refuses a credential an operation does not accept (CREDENTIAL_NOT_ALLOWED).
        if ($this->token !== null && $this->credential !== null
            && (in_array($this->credential, $op['auth'], true) || !in_array('public', $op['auth'], true))) {
            $h['authorization'] = 'Bearer ' . $this->token;
        }
        $merchant = $args['merchant'] ?? ($this->credential === 'staff' ? $this->merchant : null);
        if ($op['merchant'] && $merchant !== null) {
            $h['rewloy-merchant'] = self::scalar($id, 'merchant', $merchant);
        }
        if ($op['idempotency'] !== null) {
            $key = $args['idempotencyKey'] ?? null;
            if ($key === null && $op['idempotency'] === 'required') {
                throw new InvalidArgumentException(sprintf(
                    'Rewloy: %s needs idempotencyKey: Idempotency-Key gerekli, kütüphane uydurmaz (8–64 ASCII karakter) / the Idempotency-Key is required and is never generated for you (8–64 printable ASCII characters)',
                    $id,
                ));
            }
            $h['idempotency-key'] = $key === null ? self::uuid() : self::idempotencyKey(self::scalar($id, 'idempotencyKey', $key));
        }
        if ($op['body']) {
            $h['content-type'] = 'application/json';
        }
        if ($lastEventId !== null && $lastEventId !== '') {
            $h['last-event-id'] = $lastEventId;
        }
        $extra = $args['headers'] ?? [];
        if (!is_array($extra)) {
            throw new InvalidArgumentException(sprintf('Rewloy: %s: `headers` must be an array', $id));
        }
        foreach ($extra as $name => $value) {
            if ($value !== null) {
                $name = strtolower((string) $name);
                $value = self::scalar($id, 'headers.' . $name, $value);
                $h[$name] = $name === 'idempotency-key' ? self::idempotencyKey($value) : $value;
            }
        }
        return $h;
    }

    /**
     * The base URL without trailing slashes and without a trailing `/v1`: the operations' paths
     * carry `/v1` themselves, and the documentation shows the address both ways.
     */
    public static function normalizeBaseUrl(string $url): string
    {
        $url = rtrim($url, '/');
        if (str_ends_with($url, '/v1')) {
            $url = rtrim(substr($url, 0, -3), '/');
        }
        return $url;
    }

    /**
     * An `Idempotency-Key` is 8–64 printable ASCII characters (0x21–0x7E): a header value cannot
     * carry anything else.
     */
    private static function idempotencyKey(string $key): string
    {
        if (preg_match('/\A[\x21-\x7e]{8,64}\z/', $key) !== 1) {
            throw new InvalidArgumentException('Rewloy: Idempotency-Key yalnız ASCII karakterler içerebilir (görünür karakterler, 8–64) / the Idempotency-Key must be printable ASCII (0x21–0x7E), 8–64 characters');
        }
        return $key;
    }

    /**
     * A value for a URL or a header.
     */
    private static function scalar(string $id, string $what, mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof Stringable => (string) $value,
            default => throw new InvalidArgumentException(sprintf('Rewloy: %s: %s must be a string, a number or a boolean', $id, $what)),
        };
    }

    /**
     * The JSON body. Left out or empty, it is `{}`: the API validates a body
     * on the operations that take one.
     */
    private static function json(string $id, mixed $body): string
    {
        if ($body === null || $body === []) {
            return '{}';
        }
        try {
            return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(sprintf('Rewloy: %s: the body cannot be sent as JSON: %s', $id, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function seconds(string $id, array $args, string $key): ?float
    {
        $value = $args[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if ((!is_int($value) && !is_float($value)) || $value < 0) {
            throw new InvalidArgumentException(sprintf('Rewloy: %s: `%s` must be 0 (none) or more seconds', $id, $key));
        }
        return (float) $value;
    }

    /**
     * @return array{page: int, pageSize: int, total: int}|null
     */
    private static function meta(mixed $meta): ?array
    {
        if (!is_array($meta)) {
            return null;
        }
        $page = $meta['page'] ?? null;
        $pageSize = $meta['pageSize'] ?? null;
        $total = $meta['total'] ?? null;
        if (!is_int($page) || !is_int($pageSize) || !is_int($total)) {
            return null;
        }
        return ['page' => $page, 'pageSize' => $pageSize, 'total' => $total];
    }

    /** The URL a `Link` header gives for `rel="deprecation"`, else its first. */
    private static function deprecationLink(?string $link): ?string
    {
        if ($link === null || preg_match_all('/<([^>]*)>([^,]*)/', $link, $matches, PREG_SET_ORDER) === false) {
            return null;
        }
        $first = null;
        foreach ($matches as $m) {
            $first ??= $m[1];
            if (preg_match('/\brel\s*=\s*"?[^";]*\bdeprecation\b/i', $m[2]) === 1) {
                return $m[1];
            }
        }
        return $first;
    }

    /** A random UUID (version 4): the `Idempotency-Key` of a call that was given none. */
    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    private static function format(float $seconds): string
    {
        return rtrim(rtrim(sprintf('%.3f', $seconds), '0'), '.');
    }
}

# Decisions

Choices made while building v0.1 without the owner (4 Oct 2026). The Node
library's decisions (`Rewloy/rewloy-node`, docs/DECISIONS.md, 24 of them)
hold here unless one below replaces it. Each can be revisited; most are a
line to change.

**Reused as they are** (Node's numbers): own emitter (1), sunset dates read
from the platform's sentence (3), generation refuses what it does not
understand (5), English library text with the API's Turkish descriptions and
a bilingual README (8), a credential is left out where an operation does not
take its kind but works without one (13), construction is checked (14), the
retry rules and their numbers (15), timeouts per attempt, for a stream only
until its headers (16), idempotency keys (17: required where the API's
document says so and never made up for those, printable ASCII of 8–64
characters checked before sending, a UUID v4 only where optional), streams
reconnect by default (21), webhooks accept
any `v1` and any of several secrets with a ±300 s tolerance (22), and the
regeneration workflow (24).

## Generation

1. **PHPDoc array shapes, not DTOs.** An answer is the decoded JSON array,
   typed by an array shape in the method's `@return`.
   - **Forward compatible:** the API adds fields without notice. An array
     carries a new field at once; a DTO would drop it until regenerated.
   - **Size:** the document has about 800 inline object schemas. As classes,
     that is hundreds of files for what `json_decode` already gives.
   - **Same data as Node,** whose methods return plain objects.
   - **Tools:** PHPStan checks shapes fully, and PhpStorm and Intelephense
     complete their keys.
2. **Shapes inline, not `@phpstan-type` aliases.** PhpStorm reads aliases only
   from 2025.1, and other editors not at all; an inline shape works
   everywhere.
   - **The cost:** there are no named types like Node's `PassActionBody`.
   - **Twice per method:** each result shape is written twice, in `@return`
     and in an inline `@var` that types the client's untyped answer for
     PHPStan. The generated file is 13,164 lines, as long as Node's types.
3. **What is generated** (`src/Generated/`):
   - `Methods`: a trait the client uses, one method per operationId;
   - `Operations`: the metadata table (`Operations::ALL`, `Operations::get()`)
     with its row type `OperationMeta`, and `API_VERSION`;
   - `ErrorCode`: a constant per code, `ALL`, `TITLES` and `title()`.
     Constants, not an enum: new codes may come without notice, and
     `$e->errorCode === ErrorCode::X` needs no `->value`.
4. **What PHPDoc cannot say exactly:**
   - an object with known keys **and** other keys (`additionalProperties`) is
     `array<string, mixed>`: two request fields today, the passkey `response`;
   - `number` is `int|float`, as `json_decode` gives either;
   - `format: binary` is a string;
   - a `$ref` is written inline (only `Error` and `PageMeta` exist); a cycle
     and `allOf` (unused) are refused.
5. **Null leaves an option out.** Optional query and header parameters, and
   `idempotencyKey`, `merchant`, `timeout`, `maxRetries`, `reconnect` and
   `idleTimeout`, accept `null`. PHP builds these arrays from nullable
   variables, and the client already leaves `null` out. Body fields stay
   strict: there `null` is a JSON value the API reads.
6. **Per-field text.** A shape cannot carry a description per key. Each
   method's docblock lists instead:
   - the descriptions of its arguments: path, query and header parameters,
     and the body's top-level fields;
   - the answer's fields marked for removal (`deprecated: true`; five today,
     all until 5 April 2027), by path, e.g. `data[].email`.
7. **More refusals than Node's:**
   - two operationIds that differ only in case (PHP method names ignore
     case);
   - `call` and `open`, the client's own, beside `request`, `paginate` and
     `stream`;
   - an enum value with a control character, which no PHPDoc literal can
     carry;
   - an error code that cannot be a constant name.
8. **The snapshot** is byte for byte what the Node library writes: two-space
   JSON from PHP's encoder, objects kept as objects (`{}` stays `{}`). The two
   repositories' snapshots can be compared with `cmp`.

## Language and package

9. **PHP 8.2 and later**, as the brief says.
   - **Dev tools:** PHPUnit 11.5 (the last major that runs on 8.2) and
     PHPStan 2 at `max`, plus `checkUninitializedProperties`,
     `checkBenevolentUnionTypes`, `reportAnyTypeWideningInVarTag` and the
     two `reportPossiblyNonexistent…Offset` checks, on `src`, `scripts` and
     `tests`.
   - **Size:** `vendor/` is 59 MB, 48 of them PHPStan's phar.
   - **No runtime dependencies:** only `ext-curl`, `ext-json` and `ext-hash`.
   - **No `composer.lock`:** it is git-ignored, as for most libraries; CI
     resolves the dev tools for each PHP version.
10. **Installed from GitHub until Packagist.** Through a Composer VCS
    repository, as `dev-main`.
    - **No `version` in composer.json:** the tags will carry it.
      `Client::VERSION` is kept equal to CHANGELOG.md's latest heading by a
      test.
    - **`.gitattributes` keeps installs small:** it keeps the tests, scripts,
      CI files, docs and the 3.5 MB snapshot out of what Composer downloads.

## Client

11. **Built with named arguments:**
    `new Client(apiKey: …)`, `new Client(staffSession: …, merchant: …)`,
    `new Client(holderSession: …)`. The options are `baseUrl`, `timeout`,
    `maxRetries`, `transport`, `userAgent` and `sleep`.
    - **Seconds, not milliseconds:** `timeout`, `idleTimeout`, the retry
      waits and the sleeper's argument, as PHP clients count (Guzzle,
      Symfony HttpClient).
    - **The credential stays out of sight:** the credential parameters are
      `#[SensitiveParameter]` (left out of stack traces), and `__debugInfo()`
      leaves the token out of `var_dump()`.
    - **`User-Agent`:** `rewloy-php/0.1.0 PHP/8.4.15`, plus the `userAgent`
      suffix.
12. **Arguments are checked at run time.**
    - **Unknown keys:** a top-level key the operation does not take, such as
      `idempotency_key`, throws `InvalidArgumentException`. Most PHP callers
      have no static analysis, and a misspelt `idempotencyKey` would
      otherwise go unnoticed (where the key is optional, with a generated
      one sent instead).
    - **Wrong values:** a missing path parameter or a wrong value type is
      refused the same way.
    - **The exception class:** programming errors are
      `InvalidArgumentException`, where Node throws `TypeError`.
13. **On the wire:**
    - **The body:** a missing or empty body is sent as `{}` (PHP's `[]`
      would encode as a JSON list). A nested empty object needs
      `new \stdClass()`.
    - **The query:** RFC 3986 encoding (`%20`), booleans as `true`/`false`,
      a list repeats its key, and `null` leaves a parameter out.
    - **Values:** `BackedEnum` and `Stringable` values are accepted wherever
      a scalar is.
14. **Results:**
    - JSON as arrays;
    - files as a string of bytes;
    - 204 as `void` (`request()`'s `data` is `null`);
    - the OpenAPI document as an array;
    - paged lists as `['data' => …, 'meta' => …]`.
15. **Errors.** `RewloyException` extends `RuntimeException`.
    - **The code is `errorCode`:** PHP's `Exception::$code` is an integer
      and `getCode()` is final, so the API's code cannot be `code`.
      `getCode()` returns the HTTP status, as Guzzle and Symfony HttpClient
      do.
    - **The class names are the brief's:** `RateLimitException`,
      `ConnectionException` and `TimeoutException`, which extends
      `ConnectionException`, as in Node.
    - **Webhook refusals:** `WebhookSignatureException`, apart from
      `RewloyException`, as in Node.
16. **The transport.** `Rewloy\Http\Transport` has `send()` and `stream()`.
    The default `CurlTransport`:
    - **Keep-alive:** it keeps one handle for ordinary calls, so connections
      and TLS sessions are reused;
    - **Streams:** it drives each stream with `curl_multi`, so the body
      arrives piece by piece while the caller iterates;
    - **Redirects:** it follows none. Node's `fetch` follows them, but the
      API does not redirect, and following one could carry the token
      elsewhere; a 3xx answer is an error (`HTTP_301`…);
    - **Protocols:** it speaks only http and https;
    - **Headers:** it sends no `Expect: 100-continue`. A body-less POST, PUT
      or PATCH sends `Content-Length: 0` without curl's form type. A header
      value with a line break is refused;
    - **Compression:** it accepts compressed answers;
    - **Options:** it takes extra curl options, such as a proxy or a CA
      bundle.
17. **Deprecations.** `trigger_error(…, E_USER_DEPRECATED)`, once per
    operation per process: per request under PHP-FPM, per worker under queue
    workers or Octane.
    - **Not silenced with `@`:** production php.ini logs `E_USER_DEPRECATED`
      (it excludes only `E_DEPRECATED`). Laravel writes it to the
      `LOG_DEPRECATIONS_CHANNEL` channel, and Symfony to its own log.
      `error_reporting(E_ALL & ~E_USER_DEPRECATED)` turns it off.
    - **An error handler that throws:** a handler that turns deprecations
      into exceptions does not make an answered call fail. That exception is
      dropped and the notice goes to `error_log()`: the server has already
      acted, and losing the answer is worse than losing the notice. This is
      Node's decision 19, which defers the throw.
18. **Streams are plain generators,** as the brief asks.
    - **Stopping:** leaving the loop destroys the generator, and its
      `finally` closes the connection. A variable that still holds the
      generator keeps the connection open until it is unset.
    - **Exceptions are let go at once:** an exception kept in the
      generator's frame would keep its backtrace, which can hold the
      generator itself. That reference cycle delays the close until PHP's
      cycle collector runs, so each exception is released once read. A test
      found this.
    - **Silence:** the idle check is the transport's (`idleTimeout`, 60 s by
      default).
    - **Events:** `ServerSentEvent::json()` parses `data`.
    - **No `requestId` or `mode` on a stream:** a generator has no room for
      them. `request()` has them.
19. **Webhooks.**
    - **The signature is the brief's:** `Webhook::verify(string $payload,
      ?string $header, string|array $secret, int $tolerance = 300, ?int $now
      = null): array`.
    - **The result's type:** a shape with `type: string`, so that a
      `default` branch stays reachable when new event types come.
    - **The payload:** a signed body that is not a JSON object is refused
      (`payload`).
    - **The secret:** an empty one is an `InvalidArgumentException`, a
      missing setting rather than a bad delivery.
    - **The header:** one string; frameworks join repeated headers.

## CI

20. **`ci.yml`:** `actions/checkout@v7` and `shivammathur/setup-php@v2`, on
    PHP 8.2, 8.3 and 8.4. It runs `composer validate --strict`, installs with
    `--no-plugins`, then PHPStan and PHPUnit.
    - **No dependency cache:** it would need `actions/cache`.
    - **The curl transport's tests** use PHP's built-in server on 127.0.0.1,
      one process per test, stopped by its own handle. They use no workers:
      a worker outlives its stopped master.
21. **`regenerate.yml`** is Node's decision 24, on PHP 8.4, at 05:41 UTC
    (Node's runs at 05:23).

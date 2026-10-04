# Değişiklik günlüğü / Changelog

Bu kütüphanenin sürümleri. API'nin kendi değişiklikleri:
https://rewloy.com/gelistiriciler/degisiklikler

This library's releases. The API's own changes are listed at the link above.

## Unreleased

- Regenerated from the API as of 4 Oct 2026: 237 operations.
  - Test environment endpoints.
  - The shop connect flow (`createShopConnectToken`, `connectShop`).
  - `issuePass` takes `Idempotency-Key`, `orderId` and `shopId`.
  - Shop health fields.
- The retry tests use `createSegment` as their POST without an idempotency key: `issuePass` now takes one.

## 0.1.0 (2026-10-04)

İlk önizleme. Rewloy API 1.0.0'a göre üretildi: 189 yol, 229 işlem.

First preview, generated from Rewloy API 1.0.0 (189 paths, 229 operations):

- **Client.** `new Rewloy\Client(apiKey: …)`, `(staffSession: …, merchant: …)`
  or `(holderSession: …)`, with `baseUrl`, `timeout` (seconds), `maxRetries`,
  `transport` and `userAgent`.
- **Methods.** One method per operation, named by its operationId, its
  arguments and answer typed with PHPDoc array shapes generated from the
  OpenAPI document. `request()` returns the whole answer
  (`Rewloy\Response`: `status`, `requestId`, `mode`, `replayed`).
- **Retries** on network errors, timeouts, 429, 502–504 and Cloudflare's
  520–524, with exponential backoff, jitter and `Retry-After`. Only safe
  requests are retried.
- **`Idempotency-Key`** for till actions and campaigns: generated when
  omitted, reused across retries; `409 IDEMPOTENCY_IN_PROGRESS` is waited out.
- **Pagination** with `paginate()`, a generator over every page's items.
- **Server-sent events** with `stream()`, `liveFeed()` and
  `holderCardEvents()`: generators that reconnect with `Last-Event-ID` and
  close the connection when the loop ends.
- **Webhooks:** `Rewloy\Webhook::verify()` and `Webhook::sign()`.
- **Errors:** `RewloyException`, `RateLimitException`, `ConnectionException`
  and `TimeoutException`; `Rewloy\Generated\ErrorCode` holds every code and
  its title.
- **Deprecations:** one `E_USER_DEPRECATED` per deprecated operation, and
  `@deprecated` in the PHPDoc.
- **Transport:** `Rewloy\Http\Transport`, with `CurlTransport` as the default.
- **Regeneration:** `composer generate`, plus a daily workflow that opens a
  pull request when the live document changes.

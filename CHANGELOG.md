# Değişiklik günlüğü / Changelog

Bu kütüphanenin sürümleri. API'nin kendi değişiklikleri:
https://rewloy.com/gelistiriciler/degisiklikler

This library's releases. The API's own changes are listed at the link above.

## 0.2.1 (2026-10-05)

Dışarıdan geliştiricilerin bulduğu üç sorun düzeltildi.

Three problems found by outside developers, fixed.

- **`Idempotency-Key` is checked before sending.** The client now refuses a key
  that is not printable ASCII (0x21–0x7E), 8–64 characters, with an
  `InvalidArgumentException` ("Idempotency-Key yalnız ASCII karakterler
  içerebilir …"), and sends nothing. The API will also answer `400 VALIDATION`
  for such a key in its next release.
- **`baseUrl` takes the address with or without `/v1`.** The documentation and
  the OpenAPI document show `https://app.rewloy.com/v1`, but the client wanted
  the origin only: a base of `…/v1` produced `/v1/v1/…` and a 404. Now both work;
  a trailing `/v1` or `/v1/` and trailing slashes are stripped (`$client->baseUrl`
  is the origin).
- **`idempotencyKey` is required where the API requires it.** For `recordSale`,
  `passAction`, `sendCampaign` and `refundShopRedemption` the OpenAPI document
  marks the header required, but the client made up a random UUID when it was
  missing, which does not survive a restart of your app. The key is now a
  required argument of those methods (`idempotencyKey: string` in the array
  shape; an `InvalidArgumentException` before sending if missing or `null`).
  Where the header is optional (`issuePass`, …) a UUID is still generated and
  reused on every retry. **Breaking for callers that relied on the generated
  key** (a small break, taken in a patch release because the old behaviour
  could write a sale twice).

## 0.2.0 (2026-10-05)

Rewloy API 1.0.5'e göre yeniden üretildi: 255 işlem (0.1.0'da 229). Kasa için
`recordSale` ve `reverseSale`; README'de yeni bir kasa örneği, test modu ve
`baseUrl`.

Regenerated from Rewloy API 1.0.5: 255 operations (229 in 0.1.0).

- **New operations.**
  - *Till:* `recordSale` (`POST /v1/passes/{serial}/sale`: write a completed
    sale to a card; the card type decides what is written) and `reverseSale`
    (`POST /v1/passes/{serial}/sale/reverse`: take a refunded sale back).
  - *Checkout codes and shop connections:* `quoteCheckoutCode`,
    `holdCheckoutCode`, `captureCheckoutOrder`, `releaseCheckoutOrder`,
    `refundCheckoutOrder`, `listOrderRedemptions`, `listShopRedemptions`,
    `releaseShopRedemption`, `refundShopRedemption`, `setShopSettings`,
    `setShopCeiling`, `setShopPluginAbilities`, and for the card holder
    `holderCheckoutCodes`, `mintHolderCheckoutCode`, `cancelHolderCheckoutCode`.
  - `getMeta` (`GET /v1/meta`): the API's version.
  - Since 0.1.0 also: the test environment endpoints and the shop connect flow
    (`createShopConnectToken`, `connectShop`).
- **`getPass`** now also returns `programName`, `currency`, `stamps`
  (`count`, `max`), `points`, `money` (`amountMinor`, `currency`), `customer`
  (with `customers.read`), `actions` and `sale`.
- **Webhooks.** `webhooks.manage` API keys manage webhooks (`createWebhook`,
  `listWebhooks`, `getWebhook`, `setWebhookStatus`, `testWebhook`,
  `listWebhookDeliveries`, `webhookEvents`); a webhook reports `createdByKey`.
- **Other fields.** `issuePass` takes `Idempotency-Key`, `orderId` and
  `shopId` and returns `created`; business lists and `me` carry `currency`;
  programs carry `sale`; batches `onlineValue`; shops `accepts`, `settings`,
  `shopName`, `unbacked` and the plugin key's `abilities`.
- The retry tests use `createSegment` as their POST without an idempotency key:
  `issuePass` now takes one.
- **README.**
  - A till example with `recordSale`, the structured fields of `getPass` and
    a refund with `reverseSale`.
  - `Idempotency-Key`: a key is unique for good per credential. The
    receipt number alone is not a key (fiscal receipt numbers restart after
    the Z report): use register + Z number + receipt number, or a UUID
    stored with the sale. The receipt number goes in `reference`.
  - Test mode exists: `rwk_test_` keys and a test business. The "being
    prepared" wording is gone.
  - How to set a custom base URL (staging), and a link to the developer
    docs, https://rewloy.com/gelistiriciler.

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

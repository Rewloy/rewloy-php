# Değişiklik günlüğü / Changelog

Bu kütüphanenin sürümleri. API'nin kendi değişiklikleri:
https://rewloy.com/gelistiriciler/degisiklikler

This library's releases. The API's own changes are listed at the link above.

## 0.3.0 (2026-10-07)

Rewloy API 1.3.2'yi izler (çekirdek v1.3.2) (API sürümü, `info.version`): 298 işlem (0.2.4'te 260),
hiçbiri kaldırılmadı; yeni alanlar, olaylar ve hata kodları da yalnız eklenti.
Fiş satırları ve kazanım kuralları, şube QR'ı, şube dondurma ve kod kartlarının
yeni alanları. Ayrıca `tests/Live` ve `composer live`: kütüphaneyi çalışan bir dev
Rewloy'una karşı sınayan canlı testler (README, "Canlı testler"); 1.3.0 işlemlerini de
kapsar. Beş kütüphane 0.3.0'da aynı sürüme gelir.

Follows Rewloy API 1.3.2 (core v1.3.2; the product version in `info.version`): 298
operations (260 in 0.2.4), none removed; the new fields, events and error codes
are additions too. All five client libraries are 0.3.0. Additive: no call that
worked with 0.2.4 changes (see "Compatibility" below for the few places where a
type got wider).

- **Receipt lines on a sale.** `recordSale` takes optional `lines` (up to 500:
  `lineId`, `name`, `sku`, `category` as a string or a path, `quantity` with three
  decimals, `unit`, `unitPriceMinor`, `discountMinor`, `totalMinor`, `kind`, `tags`)
  and `receiptDiscountMinor`. The answer then carries `earn`: each line's `status`
  (`earned`, `no_rule`, `excluded`, `below_unit_price`, `refunded`…), its groups
  and rules, each rule's sentence and the total step by step (caps, promotion,
  `credited`). New `reason`s on a sale that wrote nothing: `no_earning_lines`,
  `no_lines`, `daily_cap_reached`, `monthly_cap_reached`. Refusals only with lines:
  `422 LINE_AMOUNT_INVALID`, `422 LINES_TOTAL_MISMATCH`, `422 TOO_MANY_LINES`.
  A programme without rules earns as in 0.2.4.
- **New `previewSale`** (`POST /v1/passes/{serial}/sale/preview`): `recordSale`'s
  answer now, with `preview: true`, and nothing written (takes no
  `Idempotency-Key`). **New `previewEarn`**
  (`POST /v1/programs/{id}/earn-rules/preview`): what a receipt would earn, with
  no card, optionally against a draft `ruleSet` and a card's day and month
  (`context`).
- **Line refunds.** `reverseSale` takes `lines: [['lineId' => …, 'quantity' => …,
  'amountMinor' => …]]`: the sale is judged again without them and only the
  difference is taken back. The answer carries `earn` and `linesLeft`. New refusals
  only with lines: `404 LINE_NOT_FOUND`, `409 LINE_ALREADY_REFUNDED`.
  `passAction` `spend` takes `billMinor` for a cashback card whose rules cap the
  share of a bill (`422 BILL_REQUIRED`, `409 SPEND_SHARE_EXCEEDED`).
- **Product groups and earn rules.** `listEarnGroups`, `createEarnGroup`,
  `getEarnGroup`, `updateEarnGroup`, `deleteEarnGroup` (`409 GROUP_IN_USE`),
  `listSeenLines`, `listEarnSources`, `ignoreSeenLine`, `unignoreSeenLine`;
  `getEarnRules`, `putEarnRules`, `deleteEarnRules`, `createEarnRule`,
  `updateEarnRule`, `deleteEarnRule`, `listEarnRuleRevisions` (paged),
  `listEarnTemplates`. Every save is a revision, compare-and-set on `revision`
  (`409 REVISION_CONFLICT`); `422 RULE_KIND_NOT_FOR_TYPE`,
  `404 EARN_RULE_NOT_FOUND`, `404 EARN_RULES_NOT_FOUND`. The rule kinds and
  settings are typed (`stamp.perUnit`, `points.rate`, `cashback.groupRate`,
  `vip.visit`…). A programme's `sale` carries `rules` and `text`.
- **Branch QR.** Every branch has a permanent QR: `qr` (`code`, `url`, `state`) and
  `stats.qrCards30` on every branch. New `publicBranch` (no credential),
  `holderBranch` and `joinHolderBranch` (a Rewloy Cüzdan session), the QR as
  `locationQrSvg` / `locationQrPng` (`size` 512–4096) and the printed sheets
  `locationQrSheetPdf` / `locationQrSheetSvg` (`form` `a4`, `a6`, `sticker`), the
  list the business arranges (`getLocationQrItems`, `putLocationQrItems`,
  `addQrItems`, `previewLocationQr`; `409 QR_LIST_CHANGED`), `qrListFrom` and
  `programIds` on `createLocation`, `branchCode` and `format` on the join QR.
  Errors `BRANCH_NOT_FOUND`, `BRANCH_GONE`, `ITEM_NOT_OFFERED`, `PROOF_REQUIRED`,
  `QR_ITEM_INVALID`, `NOT_VALID_HERE`. The files come back as a string of bytes.
- **Code cards.** `copyProgram` (`POST /v1/programs/{id}/copy`: only a gift card,
  coupon or discount card; a loyalty card is `422 NOT_AN_INSTRUMENT`),
  `extendProgramCards`, `updateBatch`. On programmes `giftValueMinor`,
  `offerValueMinor`, `usage`, `usageLimit`, `validity`, `terms`, `joinWindow`; on
  codes `channels`, `qrLocationIds`, `claimFrom`, `claimUntil`, `terms`,
  `proofRequired` and the `scheduled` state (`listAllBatches` takes
  `status: 'scheduled'`). Errors `BATCH_NOT_OPEN`, `BATCH_CAP_REQUIRED`,
  `BATCH_PER_PERSON_REQUIRED`, `CLAIM_AFTER_CARD_END`, `CAPACITY_BELOW_CLAIMED`.
- **Branch freeze.** `freezeLocation` (a team session and the person's password:
  an API key is refused), `updateLocationFreeze`, `cancelLocationFreeze`,
  `unfreezeLocation` (these three also with a key that holds `locations.freeze`),
  `listLocationFreezes` (with the free days left). `frozen` on every branch and
  on the till view. A frozen branch's till answers `409 LOCATION_FROZEN`, and a
  business whose every branch is frozen `409 BUSINESS_FROZEN`; also
  `ALREADY_FROZEN`, `NOT_FROZEN`, `LOCATION_ARCHIVED`, `FREEZE_LIMIT`,
  `FREEZE_STARTED`. `getPlan` carries `billing.days`.
- **Webhooks.** New events `pass.extended` (`reason`, `from`, `to`),
  `location.frozen`, `location.unfrozen`, `business.paused`, `business.resumed`
  (not about a card: `card` and `customer_id` are null); `via` on `pass.issued`.
  `createWebhook` accepts them. A shop link may be of the new platform `rewloy`;
  a shop's `lastDelivery.result` may be `refund_lines`.
- **`getMeta`** now types `environment` (`'live'` or `'dev'`), which the API has
  returned since 1.2.1.
- **API 1.3.2** changes nothing in the 298 operations: six console-only error codes join `ErrorCode`
  (`DPA_DRAFT`, `SUMMARY_REQUIRED`, `PREVIEW_CHANGED`, `DAY_CHANGED`, `NOTHING_TO_SEND`,
  `NOTICE_TOO_LATE`; `/v1` never returns them) and the description of `signup` changed.
- **Live tests** (`tests/Live`, `composer live`) run the library against a running dev
  Rewloy (README, "Live tests") and now cover the 1.3.0 operations a test
  business can exercise: product groups and earn rules, receipt lines on
  `recordSale` and `previewSale` with the earn explanation, `previewEarn`, a line
  refund, the branch QR (public page, SVG, PNG, PDF sheets, the list), branch freeze
  (with a team session and `REWLOY_STAFF_PASSWORD`), `copyProgram` and its refusal.
  The library itself is unchanged by this.

Compatibility. Nothing was removed or renamed and no required argument was added to an
existing operation. In PHPStan and your editor, a few answer types got wider
(additions to unions: `webhook.events`, `listAllBatches` `state`, shop `platform`,
`recordSale` `reason`) and a few answers got new keys (`qr`, `frozen`,
`stats.qrCards30` on branches; `channels`, `claimFrom`… on codes): code that
`match`es exhaustively on one of those unions needs a default arm. The titles of
two error codes changed (`GROUP_NOT_FOUND` is now "Grup bulunamadı", `GROUP_IN_USE` "Grup kullanılıyor",
since earn groups share them with branch groups; the codes are the same).

## 0.2.4 (2026-10-06)

Rewloy API 1.2.0'ı izler (API sürümü, `info.version`): 260 işlem (0.2.2'de 256),
hiçbiri kaldırılmadı. Kasa yazımlarının yanıtında `card`, kartın işlem listesi,
webhook sırrını yenileme ve silme, POS anahtarları, test ortamını silmeden
sıfırlama, bütün kodların listesi. Webhook nesnesinde `pausedUntil` ve
`resumableUntil`; kod bağlantısı göndermede `BATCH_CLOSED`, `BATCH_EXPIRED`,
`BATCH_FULL` ve `PROGRAM_ARCHIVED` hataları. Ayrıca README'deki `rewardReady`
örneği `actions[].ready` okuyacak şekilde düzeltildi. 0.2.3 yalnız .NET ve
Kotlin'in paket sürümüydü; beş kütüphane 0.2.4'te aynı sürüme gelir.

Follows Rewloy API 1.2.0 (the product version in `info.version`): 260
operations (256 in 0.2.2), none removed. All five client libraries are 0.2.4.
Additive, except that `closed` in the test-reset answer is now always `null`
and `sendBatchLink` now refuses a code that issues no card (see below).

- **New operation `listPassOperations`** (`GET /v1/passes/{serial}/operations`,
  paged): a card's ledger operations and coupon / discount-card uses, newest
  first, for a till's "last operations" list. Each carries `kind`, signed
  `delta` and `unit`, `at` (and `occurredAt` for a sale written later),
  `reference`, `source`, `byCaller`, and what undoes it: `undoWith`
  (`sale/reverse` or `actions/reverse`), `reversible` and, when not,
  `reason`; for this credential's own operations `saleKey` / `actionKey` to pass
  straight to the reverse call; `reversedBy`, `reversedAt`, `reverses`.
  Needs `passes.read`.
- **`card` on write answers** (`recordSale`, `passAction`, `reverseSale`,
  `reverseAction`): the card after the write, the fields of `getPass` except
  `customer` (`programName`, `currency`, `stamps` / `points` / `money`,
  `rewardReady`, `actions`, `sale`…), read in the same transaction. On a replay
  (`duplicate: true`) it is the card's current state. It is `null` when the
  credential lacks `passes.read` in the card's programme (a till-only plugin
  key), so the type is nullable. No second `getPass` is needed to draw a receipt.
- **`reversed` on `recordSale` and `passAction` answers**: `true` only on a
  replay of a sale that was taken back since (`credited` is what the first
  request wrote, the card no longer carries it); send a new key to write the
  receipt again.
- **`occurredAt` errors**: a rejected `occurredAt` is a `400 VALIDATION` whose
  `details[0].reason` says which limit: `in_future`, `too_old` (over 72 hours),
  `before_issue` (the card did not exist then: resend without `occurredAt`),
  `invalid`. Treat an unknown reason as `invalid`. (Documented on the error
  details; the field stays optional.)
- **New operations `rotateWebhookSecret`** (`POST /v1/developers/webhooks/{id}/rotate-secret`)
  and **`deleteWebhook`** (`DELETE /v1/developers/webhooks/{id}`, `204`). A
  rotation returns the new `secret` once; the old one keeps signing for 24
  hours, so `Rewloy-Signature` carries two `v1` values and the delivery has
  `Rewloy-Signature-Rotating: 1`. `verifyWebhook` already tried every `v1` and
  several secrets: pass `[new, old]` while you switch. Deleting removes the
  delivery history too.
- **POS keys**: `createApiKey` takes a second body shape, `kind: "pos"` with
  `locationId` and optional `register` (the built-in till role, one branch, named
  "POS · branch · register"), and answers with `baseUrl`; `listApiKeys` and
  `getApiKey` rows carry `pos` (`{ locationId, register } | null`) and
  `requestsToday`, and `listApiKeys` filters with `kind` (`pos` | `standard`).
- **Test environment reset** (`resetTestEnvironment`) keeps the test business: the
  body takes `revokeKeys` (default `false`; `true` also revokes the keys, closes
  the webhooks and cancels open store-link codes), and the answer counts
  `deleted` (`customers`, `cards`, `codes`, `outbox`, `webhookDeliveries`), `kept`
  (`programs`, `keys`, `webhooks`), `created`, `keysRevoked` and
  `walletCardsVoided`; `closed` is now always `null`. New error code
  `TEST_RESET_BUSY` (`409`).
- **New operation `listAllBatches`** (`GET /v1/batches`, paged): every gift-card,
  coupon and discount code of the business, newest first; filters `programId`,
  `type`, `status` and `q`. Each row's `state` (and the `status` filter) takes
  **`archived`**: the code itself is open but its card (programme) is archived,
  so its link issues nothing; `status` on the row stays `open` | `closed`. New error
  code `PROGRAM_ARCHIVED` (`409`) on `createBatch` for an archived programme.
- **Programme rows** (`listPrograms`, `getProgram`, `createProgram`,
  `updateProgram`) carry `programName`, always equal to `name` (the field name
  that `createProgram` takes and `getPass` returns).
- **Webhook state: `pausedUntil` and `resumableUntil`** on every webhook object
  (the rows of `listWebhooks`, and the `webhook` of `createWebhook`, `getWebhook`,
  `setWebhookStatus` and `rotateWebhookSecret`). Both are always present, a
  date-time or `null`. `pausedUntil`: an open webhook is paused (its receiver
  failed twice in a row with a `5xx`, a `429`, a connection error or no answer):
  its deliveries wait until this moment and are retried on their own, 60
  seconds; `null` when it is not paused or the webhook is off.
  `resumableUntil`: the rules turned the webhook off and keep its pending
  deliveries; turned on before this moment (24 hours after it was closed, with
  `setWebhookStatus` `{ "active": true }`) it carries on where it stopped, the
  kept deliveries go at once and the events that happened meanwhile arrive too;
  `null` while it is on, when a person or a key turned it off, or once the time
  has passed.
- **`sendBatchLink` refusals** (`POST /v1/batches/{id}/send`): the link of a code
  is e-mailed only while the code issues a card. A stopped code answers
  `410 BATCH_CLOSED`, one past its date `410 BATCH_EXPIRED`, one whose cards
  are all given `410 BATCH_FULL`, and a code whose programme is archived
  `409 PROGRAM_ARCHIVED` (a new `409` on this operation); no mail goes. Before
  1.2.0 the last three were sent anyway. The error codes were already in the
  library's list of codes; the operation's description now names all four.
- Descriptions only: `earnRate` / `cashbackRate` round down on a sale
  (`floor(amountMinor / 100 × earnRate)`, `floor(amountMinor × cashbackRate / 100)`);
  `currencyLocked` also for an open amount-valued coupon; `actions30` on a key
  now counts reads; `rewardReady` means "reward ready" only on stamp and points
  cards (always `true` on VIP, any balance on cashback and gift cards): read
  `actions[].ready` to know what can be done now; `kvkkConsent` on `issuePass`;
  `me` → `key.abilities` is not the key's permissions (those are `permissions`).
- **Fixed in the README**: the first example read `rewardReady` as "ready to
  redeem". It now reads `actions[].ready` (see `getPass`).
- PHP: `Operations::ALL` is still the whole table, but it is generated in parts
  of 200 rows (`Operations::get()` looks in each): PHPStan keeps a constant
  array's exact shape only up to 256 entries and the API now has 260.
- PHP: the generated PHPDoc shapes of a webhook carry `pausedUntil: string|null`
  and `resumableUntil: string|null` (on `listWebhooks`, `createWebhook`,
  `getWebhook`, `setWebhookStatus` and `rotateWebhookSecret`); the
  `sendBatchLink` description names the four refusals
  (`ErrorCode::BATCH_CLOSED`, `BATCH_EXPIRED`, `BATCH_FULL`, `PROGRAM_ARCHIVED`).
- PHP: new tests in `tests/V120Test.php` (the four new operations, paging,
  `kind: "pos"`, `revokeKeys`, a replayed sale with `card: null`,
  `PROGRAM_ARCHIVED`, the webhook state fields, the four refusals of
  `sendBatchLink`); the generated PHPDoc shapes carry every new field and
  PHPStan (max) holds; `RewloyException::$details` documents `reason`.

## 0.2.2 (2026-10-05)

Rewloy 1.1.0'a (API sürümü) göre yeniden üretildi: 256 işlem (0.2.1'de 255). Kasa
için `reverseAction`, `recordSale`'de `occurredAt`, `passAction`'da `reference`;
yanıtlarda `RateLimit-*` başlıkları.

Regenerated from Rewloy 1.1.0 (the product version in `info.version`): 256
operations (255 in 0.2.1).

- **New operation: `reverseAction`** (`POST /v1/passes/{serial}/actions/reverse`).
  Voids a till action made with `passAction` (`spend`, `spend-points`,
  `redeem-stamps`, `redeem-reward`, `use`), found by its `actionKey` (the
  `Idempotency-Key` it was sent with) or its `reference`. It needs no
  `Idempotency-Key`: an action is voided once and a repeat answers
  `duplicate: true`. New error codes `ACTION_NOT_FOUND`, `ACTION_AMBIGUOUS`,
  `ACTION_NOT_REVERSIBLE` (constants of `ErrorCode`).
- **`recordSale` takes an optional `occurredAt`**: when the sale really happened
  (ISO 8601 with offset), for a till that queues sales while offline.
- **`passAction` takes an optional `reference`**, and its answer is documented as
  the union of two array shapes: the balance-card answer (`balance`, `detail`,
  `promotion`) or the coupon / discount-card answer (`status`, `uses`,
  `usesLeft`). Narrow with `isset($answer['uses'])`.
- **Rate limit headers.** `Response::rateLimit()` and
  `RewloyException::rateLimit()` (including `RateLimitException`) return a
  `Rewloy\RateLimit` (`limit`, `remaining`, `reset`, from `RateLimit-Limit`,
  `RateLimit-Remaining`, `RateLimit-Reset`) or null when the answer has none.
  Additive.
- Webhook-creation responses may carry `warnings` (a non-live installation whose
  URL production would refuse); the `Idempotency-Key` parameter documents its
  8–64 printable ASCII rule; the API's descriptions no longer contain internal
  `ADR n` references. README: the till example has a void step and a note on
  `occurredAt` for offline queues.

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

# Live tests: what is not covered yet

The live suite follows the 0.3.0 library (API 1.3.x). Covered since 0.3.0: receipt lines on
`recordSale` / `previewSale` with the `earn` explanation, `previewEarn`, line refunds, product groups
and earn rules (a stamp rule over a group, revisions, the revision conflict), the branch QR (public
page, SVG, PNG, PDF and SVG sheets, the list), branch freeze (with a team session and
`REWLOY_STAFF_PASSWORD`), `copyProgram` and `NOT_AN_INSTRUMENT`, the 1.3.0 webhook events, and
`getMeta`'s typed `environment`.

- [ ] **`BUSINESS_FROZEN`**: a business is paused only when every one of its branches is frozen,
      which means freezing the test business's own branch too. A branch may be frozen 4 times in 12
      months, so a suite that does this each run would use the allowance up; the unit tests
      (`tests/V130Test.php`) cover the error only against a stub.
- [ ] **Points, cashback, VIP earn rules**: only a stamp rule over a group is exercised
      (`stamp.perUnit`); the other rule kinds and the receipt / daily / monthly caps, the
      unit-price floor, `spendShareMaxPct` (`BILL_REQUIRED`, `SPEND_SHARE_EXCEEDED`).
- [ ] **Shop orders with lines** (WooCommerce, Shopify, the `rewloy` platform) need a shop link and
      a signed order from the shop.
- [ ] **Codes on a branch QR** (`batchId` in the QR list, `PROOF_REQUIRED`, `BATCH_NOT_OPEN`,
      `BATCH_CAP_REQUIRED`, `updateBatch`, `extendProgramCards`): the gift-card / coupon / discount
      batch flows with their claim windows.
- [ ] **Holder (Rewloy Cüzdan) operations**: `holderBranch` and `joinHolderBranch` need a holder
      session; only their refusal for an API key is tested.
- [ ] **Branch freeze beyond the basics**: `extendCards`, a freeze of seven days or more and the
      free-day counting, `FREEZE_LIMIT`, `LOCATION_ARCHIVED`, the `location.frozen` webhook delivery.
- [ ] **English API** (1.4): re-check the error `detail` and the field names the tests read.
- [ ] `BATCH_EXPIRED` and `BATCH_FULL` on `sendBatchLink`: a code cannot be made already
      expired, and filling one needs the public claim flow (`/c/CODE`). Cover them once the
      API has an operation for claiming a code.
- [ ] `PROGRAM_ARCHIVED` on `sendBatchLink`: archiving a program closes its codes first
      (`BATCH_CLOSED`), so only the creation refusal is reachable now.
- [ ] Points, cashback, discount, VIP and coupon programs: `recordSale` on each (only stamp and
      gift card are exercised).
- [ ] Staff-session operations beyond login, reset, logout and freezing a branch.
- [ ] Passkeys, phone sign-in and the live feed (`liveFeed`, a stream).
- [ ] Webhook delivery: a signed event reaching a public https endpoint needs an address the
      dev server can reach; today only the webhook's management is tested.

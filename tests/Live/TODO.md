# Live tests: what is not covered yet

The live suite follows the 0.2.4 library (API 1.2.x). For the 0.3.0 regeneration (API 1.3.0)
add:

- [ ] **Receipt lines on `recordSale`** (`lines`): a sale with lines and one without, the
      credited result, the reversal of a sale that had lines. (API 1.2.x has no `lines`; the
      sale tests cover the receipt `reference` only.)
- [ ] **Earn rules** (groups, the one line-item schema shared by POS, WooCommerce and Shopify):
      create, list, update, delete; a sale that matches a rule and one that does not.
- [ ] **Branch QR** (one QR per branch with curated and seasonal programmes): create, read,
      join through it, session reuse for a multi-join, the code card's single entry, the
      branch freeze.
- [ ] **English API** (1.4): re-check the error `detail` and the field names the tests read.
- [ ] `getMeta`'s array shape: the 0.2.4 PHPDoc lacks `environment`, which the API has returned
      since 1.2.1; the gate and `MetaTest` read it as data. After the regeneration read it as
      `$meta['environment']` and drop the `array_key_exists` in `Guard`.
- [ ] `BATCH_EXPIRED` and `BATCH_FULL` on `sendBatchLink`: a code cannot be made already
      expired, and filling one needs the public claim flow (`/c/CODE`). Cover them once the
      API has an operation for claiming a code.
- [ ] `PROGRAM_ARCHIVED` on `sendBatchLink`: archiving a program closes its codes first
      (`BATCH_CLOSED`), so only the creation refusal is reachable now.
- [ ] Points, cashback, discount, VIP and coupon programs: `recordSale` on each (only stamp and
      gift card are exercised).
- [ ] Holder (Rewloy Cüzdan) operations and the staff-session operations beyond login, reset and logout.
- [ ] Passkeys, phone sign-in and the live feed (`liveFeed`, a stream).
- [ ] Webhook delivery: a signed event reaching a public https endpoint needs an address the
      dev server can reach; today only the webhook's management is tested.

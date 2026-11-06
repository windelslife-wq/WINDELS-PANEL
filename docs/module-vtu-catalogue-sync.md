# Module — VTU catalogue sync for every adapter

## The report

> Sync failed: This VTU adapter cannot list products.

That is the flash message from **Admin → Providers → Sync services**. It came
from `ProviderSyncService::sync_vtu_catalogue()`, which bails out *before any
HTTP call* when the provider's adapter has no `variations()` method.

At the time `Provider_manager` registered three VTU api_types — `MOCK`,
`STANDARD_VTU` and `VTPASS` — but only `VtpassAdapter` implemented the catalogue contract
(`variations()` plus `catalogue_types()`). `VtuProviderInterface` never
required it, so the other two were perfectly good at *selling* airtime/data and
could not answer the one question the sync asks. An operator who added a
`STANDARD_VTU` provider (the generic "Nigerian VTU API" option in the create
form) could test the connection and buy, but the catalogue sync was a dead
button — and the hourly `provider_sync` cron recorded a FAILED log for it
forever, because `due_for_sync()` keeps selecting a provider with no
`last_successful_sync_at`.

## What changed

| File | Change |
| --- | --- |
| `application/libraries/StandardVtuAdapter.php` | New `variations()` and `catalogue_types()`. Reads the vendor's product list through `SecureHttpClient`, normalises the common response shapes into `{variation_code, name, amount, fixed_price}`, and returns `ok:false` with a vendor message when a network is not carried. |
| `application/libraries/MockVtuAdapter.php` | New deterministic `variations()` / `catalogue_types()` so an offline provider can be synced without a vendor. |
| `application/libraries/VtuProviderInterface.php` | Documents the catalogue capability and its return shape. It stays an optional contract so third-party adapters written against the original eight methods still load. |
| `application/libraries/ProviderSyncService.php` | The missing-capability guard now names the api_type, the missing method and the manual alternative instead of a dead-end sentence. `catalogue_types()` is called on the instance (works for static and instance declarations), validated against known service types, and malformed variation rows are skipped rather than fataling. |
| `application/views/admin/providers/detail.php` | Explains what a VTU sync imports (fixed-price DATA/CABLE/EXAM_PIN variations, inactive and priced at cost) and what it does not (variable-amount airtime/electricity). |
| `tests/unit/VtuCatalogueSyncTest.php` | 14 tests driving the real sync path against scripted HTTP doubles. |

`VtpassAdapter` is unchanged: it already answered both methods.

## Configuration (`STANDARD_VTU`)

Endpoint paths and field names come from `providers.retry_policy` under a
`vtu` key, exactly as the purchase paths already did:

```json
{
  "vtu": {
    "variations": "/service-variations",
    "variations_param": "serviceID",
    "variations_method": "GET",
    "service_ids": { "MTN-DATA": "mtn-data" },
    "balance": "/balance"
  }
}
```

Defaults: `GET /service-variations?serviceID=<network code>`, with the panel's
network code passed straight through (the same value purchases use as
`serviceID`). `service_ids` translates our codes for vendors with their own
identifiers; `variations_method: "POST"` sends the id as a form field instead
of a query parameter; `variations_param` renames the field/query key. Other
`retry_policy` keys (`maxRetries`, `backoffMs`) are untouched — only the `vtu`
block is read.

Accepted response shapes (first match wins):
`content.variations`, `content.varations` (the VTpass typo),
`contents.variations`, `data.variations`, `variations`, `products`, or a bare
list in `content`/`data`. Each row accepts `variation_code|code|product_code|id`,
`name|variation_name|title`, `variation_amount|amount|price|cost` and
`fixedPrice`. Rows without an identifier are ignored. An error reply (a
non-success `code`/`response_description` with no variation list) comes back as
`ok:false, error`, which the sync treats as a per-network skip.

`catalogue_types()` returns `DATA`, `CABLE` and `EXAM_PIN` for all four built-in
VTU adapters (`MOCK`, `STANDARD_VTU`, `VTPASS` and `BESTDATA`). Airtime and
electricity remain variable-amount products configured by the panel in
**Admin → Catalogue**, not by a vendor price list.

## Safety rules — inactive at cost, safe re-syncs

- **Inactive at cost on import:** new rows land with `is_active = 0` and
  `price = face_value = provider_cost = vendor cost`, so nothing becomes
  buyable until an admin reviews and activates it in **Admin → Catalogue**.
- **Unreviewed inactive rows track cost:** while an inactive row's `price`
  still equals its previous `provider_cost` (or is `NULL`), re-syncs move
  `price` with `provider_cost` so it stays at cost rather than drifting below
  cost before launch.
- **Admin prices, names and deactivations survive re-sync:** once an admin
  sets a custom `price`, edits `name` on an active row, or takes a row off
  sale, re-syncs update `provider_cost` without overwriting `price`, `name` or
  `is_active`. Identical re-syncs return `'unchanged'` with zero DB writes.
- **Margin guard on active rows:** if vendor `provider_cost` rises strictly
  above an active product's selling `price`, the sync switches `is_active` to
  `0` (reporting `paused`) instead of selling below cost or inventing a new
  customer price.
- **Cross-provider isolation:** if `(network_id, service_type, code)` already
  belongs to a different provider, a provider-scoped code (`CODE-P<id>`) is
  used so syncing a second VTU vendor never hijacks another vendor's product.
- One unsupported network is a skip; a sync that sees zero variations at all
  *and* collected errors is recorded `FAILED`.
- No decrypted key or vendor URL is ever returned in a sync error or log.

## Verification

`php tools/phpunit_lite.php VtuCatalogueSyncTest` — 14 tests, 82 assertions,
no network and no credentials. The full offline suite was run too; the only
failures are the two pre-existing `system/libraries/Email.php` ones in
environments without the CodeIgniter framework installed.

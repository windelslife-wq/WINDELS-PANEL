# Module — Bestdatanig / AdaData provider (the `HTTP 404` connection failure)

> `Connection failed: HTTP 404 (179 ms).`

An operator added `https://app.bestdatanig.com.ng/` as a provider API URL and
pressed **Admin → Providers → Test connection**. `STANDARD_VTU` is a
VTpass-shaped adapter: it appended `/balance` to whatever base URL it was
given and sent `GET https://app.bestdatanig.com.ng/balance`. That host is the
Next.js **marketing site**, not an API — every `/api/*` path on it answers the
site's 404 page. `SecureHttpClient` reports a 404 as an ordinary response with
no `error` key, so `StandardVtuAdapter::call()` turned it into the literal
string `HTTP 404`, and the controller prefixed it with `Connection failed:`.

Nothing was wrong with TLS, the key or the network. The URL and the protocol
were both wrong for this vendor.

## 1. Where the API documentation came from

`https://app.bestdatanig.com.ng/api-docs` redirects to
`/?auth=login&next=%2Fapi-docs` — the full docs are behind an account login,
and the vendor's older site says so explicitly ("Just login to your account to
access the api documentation"). The public material was assembled from four
sources instead:

| Source | What it gave us |
|---|---|
| `https://app.bestdatanig.com.ng/api` | Public developer sample: host shown as `api.adadata.com.ng`, `Authorization: Token YOUR_API_KEY`, `Content-Type: application/json`, `POST /api/data/` with `{"network":1,"mobile_number":"09095263835","plan":12,"Ported_number":false}` → `201 {"ident":"ADA-D4A81B","plan_name":"1GB","plan_network":"MTN","Status":"successful"}`. |
| `https://bestdatanig.com.ng/api/*` (live probes) | The actual API origin. Unauthenticated requests return DRF `401 {"detail":"Authentication credentials were not provided."}` and a browsable-API title/method list, which is enough to enumerate every endpoint and its allowed verbs. `api.adadata.com.ng` from the marketing sample does **not resolve**; `app.bestdatanig.com.ng` has no `/api/*` routes at all. |
| `https://documenter.getpostman.com/view/12429346/UVsHUoSR` | The vendor script's public Postman reference ("YUNIQTELECOMS API DOCUMENTATION", the same Django VTU codebase): the 17-endpoint list, `Authorization: Token <40-hex-key>`, and the per-endpoint URLs. |
| `Huzzy619/LegitData` (`nelly_api/urls.py`, `views.py`, `serializers.py`) and `Habeebllah/smartKonnect` (`app/templates/swagger-ui.html`) on GitHub | The open-source Django REST implementation behind this API family: exact URL patterns, serializer field names, response keys and the login-gated docs page. Its `nelly_api/urls.py` matches the live endpoint titles one for one. |

Confirmed live on `https://bestdatanig.com.ng` (all require authentication
except where noted):

| Verb | Path | Purpose / browsable-API title |
|---|---|---|
| GET | `/api/user/` | Account detail + wallet balance (`User Api`) |
| GET | `/api/get/network/` | Network names and numeric ids (`Get Network Api`) |
| GET | `/api/network/` | Data plans grouped `MTN_PLAN`, `GLO_PLAN`, `AIRTEL_PLAN`, `9MOBILE_PLAN` (`Network Api`) |
| GET | `/api/cable/` | `DSTVPLAN`, `GOTVPLAN`, `STARTIME`, `cablename` (`Cablename Api`) |
| GET | `/api/disco/` | Electricity discos (`Disco Api`) |
| GET | `/api/history/` | Last ten transactions per service (`Api History`) |
| GET, POST | `/api/data/` | Data transactions / buy data (`Data Api List`) |
| GET, POST | `/api/topup/` | Airtime transactions / buy airtime (`Airtime Topup Api List`) |
| GET, POST | `/api/cablesub/` | Cable subscriptions (`Cable Sub Api List`) |
| GET, POST | `/api/billpayment/` | Electricity payments (`Bill Payment Api List`) |
| GET, POST | `/api/epin/` | Result checker PINs (`Result Checker Pin Order Api List`) |
| GET | `/api/validatemeter/` | Meter lookup; answers `{invalid, name, address}` |
| GET | `/api/validateiuc/` | Smartcard lookup |
| GET, POST | `/api/rechargepin/`, `/api/sms/` | Recharge PINs, bulk SMS |

Other live paths: `/api/validateiuc/` (500 without the upstream configured),
`/api/rechargepin/`, `/api/sms/`. Python-side URL patterns also expose
`/api/data/<id>`, `/api/topup/<id>`, `/api/cablesub/<id>`,
`/api/billpayment/<id>`, `/api/epin/<id>`, `/api/pin/`, `/api/changepin/`,
`/api/kyc/`, `/api/transfer/`, `/api/couponpayment/`, `/api/users/` — the
adapter only uses the documented, stable subset above.

### Authentication and payloads

* Header: `Authorization: Token <key>` (public docs and Postman reference).
  The live Django deployment advertises `WWW-Authenticate: Bearer realm="api"`,
  so `BestdataAdapter` tries `Token` first and retries a `401` with `Bearer`.
  A `401` from DRF is raised before the view runs, so that retry cannot
  duplicate a purchase. Pin one with `retry_policy.bestdata.auth_scheme`.
* All POST bodies are **JSON** (`Content-Type: application/json`), not
  form-encoded. `SecureHttpClient` sends strings verbatim, so the adapter
  passes `json_encode($payload)`.
* Success is any 2xx (data purchases answer **201**, not 200), with
  `Status: "successful" | "processing" | "failed"` and an `ident` reference.
* Purchase fields (from the DRF serializers):
  * data — `network` (numeric network id), `mobile_number`, `plan` (numeric plan id), `Ported_number` (bool)
  * airtime — `network`, `mobile_number`, `amount`, `airtime_type` (`VTU`), `Ported_number`
  * cable — `cablename` (id), `cableplan` (id), `smart_card_number`
  * electricity — `disco_name` (id), `amount`, `Customer_Phone`, `meter_number`, `MeterType` (`Prepaid`/`Postpaid`)
  * exam pin — `exam_name` (`WAEC`/`NECO`), `quantity`
* Balance is `user.Account_Balance` (also `wallet_balance`, `bonus_balance`) in
  `GET /api/user/`. A 200 from that endpoint proves the token even when an
  install renamed the balance field, so a missing field does **not** fail the
  connection test.
* Price-list rows carry `id`, `plan`/`package` (name), `plan_amount`; `id` is
  the primary key used by the purchase endpoints, so it becomes our
  `variation_code`.

## 2. What changed

| File | Change |
|---|---|
| `application/libraries/BestdataAdapter.php` | New `VtuProviderInterface` adapter: normalises the website URL to the API origin, Token/Bearer auth, JSON bodies, 2xx handling, all eight interface methods, `catalogue_types()` (`DATA`, `CABLE`) and `variations()` for the grouped price lists. |
| `application/libraries/Provider_manager.php` | Registers `BESTDATA` under `FAMILY_VTU`. Legacy `STANDARD_VTU` rows whose URL is a `bestdatanig.com.ng` / `adadata.com.ng` host are routed to the new adapter, so an already-saved provider starts working without a database migration. |
| `application/libraries/StandardVtuAdapter.php` | Accepts every 2xx (not just 200), and replaces bare `HTTP 404` / `HTTP 401` with an actionable message naming the path and the likely mistake. |
| `application/libraries/VtuProviderInterface.php` | Capability docs now name the fourth built-in adapter. |
| `application/views/admin/providers/index.php` | `BESTDATA` help block: correct API URL, token, endpoints. |
| `application/views/admin/providers/detail.php` | Inline warning when a provider still points at `app.bestdatanig.com.ng`. |
| `tests/unit/BestdataAdapterTest.php` | Scripted-HTTP regression suite for the 404, auth, balance, catalogue and all five purchase payloads. |
| `tests/unit/VtuCatalogueSyncTest.php` | The "every registered VTU adapter can list products" assertion now covers `BESTDATA`. |

`ProviderSyncService::family()`, `supported_types()` and the create form are
all derived from the registry, so `BESTDATA` is automatically VTU, valid on
save and offered in the **Type** dropdown — no controller change and no new
permissions.

### Operator setup

1. **Admin → Providers → Add provider** (or *Update credentials* on the
   existing row): type `BESTDATA`, API URL `https://bestdatanig.com.ng`, API
   key = the token from the logged-in dashboard (`/api-docs` or `/documentation/`).
2. **Test connection** → `GET /api/user/` → `ONLINE` and the wallet balance.
3. **Sync services** → `GET /api/network/` and `GET /api/cable/` populate
   `vtu_products` (inactive, at cost, never overwrites an admin selling price).
4. Price the new products in **Admin → Catalogue** before selling them.

A row still stored as `STANDARD_VTU` with the website URL works immediately
because of the compatibility route, but its label is misleading — edit it to
`BESTDATA` and the API origin when convenient.

### Per-row overrides (`providers.retry_policy` JSON)

```json
{
  "bestdata": {
    "base_url": "https://bestdatanig.com.ng",
    "auth_scheme": "Token",
    "paths": { "user": "/api/user/", "data": "/api/data/" },
    "network_ids": { "MTN": 1, "GLO": 2, "9MOBILE": 3, "AIRTEL": 4 },
    "cable_ids": { "DSTV": 1, "GOTV": 2, "STARTIMES": 3 },
    "disco_ids": { "IKEDC": 1, "EKEDC": 2, "AEDC": 3, "PHED": 4 },
    "disco_names": { "IKEDC": "Ikeja Electric" },
    "airtime_type": "VTU",
    "ported_number": false,
    "currency": "NGN"
  }
}
```

Path values may be absolute URLs for a reverse proxy. Unknown network, cable
and disco codes are resolved from `GET /api/get/network/`, `GET /api/cable/`
and `GET /api/disco/`, which is also how nonstandard database ids are handled;
those documents are cached per adapter instance, so a catalogue sync makes
one request per document rather than one per network.

## 3. Verification

* `node /tmp/phpcheck/php.mjs tools/phpunit_lite.php BestdataAdapterTest` —
  scripted HTTP, no network and no real credentials.
* Full WASM suite: `node /tmp/phpcheck/php.mjs tools/phpunit_lite.php`.
* `node tools/php_syntax_check.mjs application tools/phpunit_lite.php`,
  `npm run test:js`, `bash tools/validate_deployment_zip.sh application-deployment.zip`.
* `application-deployment.zip` is refreshed with every changed/new
  `application/` file so `CpanelDeploymentTest::testTheCommittedPackageIsNotStale()`
  passes.

## 4. Still open

* The login-gated `/api-docs` page was not readable without an account. The
  endpoint set above was reconstructed from the public developer sample, the
  vendor's public Postman reference, the open-source implementation of the
  same Django script, and live unauthenticated probes — not from an
  authenticated OpenAPI export. Field names for a heavily customised reseller
  deployment may differ; `retry_policy.bestdata.paths` is the escape hatch.
* No real API token was available, so no authenticated 200/201 response from
  Bestdatanig itself was captured. The adapter's parsing is driven by the
  published sample response, the DRF serializers and scripted tests.
* The marketing sample's `api.adadata.com.ng` host does not resolve. The
  adapter maps it (and `app.bestdatanig.com.ng`) to the live Django origin;
  if the vendor later publishes a dedicated API host, set
  `retry_policy.bestdata.base_url` and no code change is needed.
* `/api/epin/` publishes transaction history rather than a price list, and
  this build's exam bodies are `WAEC`/`NECO`/`JAMB`. `BESTDATA` therefore
  declares only `DATA` and `CABLE` catalogue types; exam PIN products stay
  hand-configured in Admin → Catalogue.

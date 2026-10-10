# EXT-63 — React Native consumer integration validation

## Status and responsibility

**Status: backend contract validation complete; actual consumer validation pending.** The developer selected backend-only review for this EXT-63 run: React Native source, build and device evidence were not supplied. No React Native journey is marked passed without evidence from that real run. The group member maintains and runs the separate **React Native + Expo** project; this repository owns Laravel and the verified API contract. No mobile UI code belongs here.

Baseline: [API handoff](MOBILE_API_HANDOFF.md), [Postman collection](Battlefront_API_v1.postman_collection.json), and [environment placeholders](Battlefront_API_v1.postman_environment.json). The developer reported successful manual Postman Desktop testing and approved EXT-62; no dated run export or screenshots were supplied. This does not establish a React Native run.

### Original preparation evidence (historical)

| Check                          | Observed result                                                                              |
| ------------------------------ | -------------------------------------------------------------------------------------------- |
| Backend revision inspected     | `bc4ffb98de27a918ff7a5b34970adffeab5e09e8` (before these documentation changes)              |
| Versioned routes               | 22 `/api/v1` endpoints; unchanged by EXT-63 preparation                                      |
| Postman baseline               | 50 requests covering success/error/access scenarios; credentials remain local                |
| Local Herd health              | `GET http://battlefront-capstone.test/api/v1/health` -> HTTP 200, `{"data":{"status":"ok"}}` |
| Herd runtime                   | Running Nginx executable belongs to the Herd installation                                    |
| HTTP listener inspection       | Port 80 listens on `127.0.0.1`; no port 443 listener returned by the inspection              |
| Relevant Herd config           | `%USERPROFILE%\.config\herd\config\nginx\herd.conf`: `listen 127.0.0.1:80 default_server;`   |
| Config inclusion               | Herd's `nginx.conf` includes `herd.conf` and the configured site `.conf` files               |
| API regression run             | 275 passed, 1,732 assertions; command below                                                  |
| Consumer build/device/evidence | Not supplied; group member runs the app                                                      |
| Proven backend defects         | None established                                                                             |

The listener evidence proves the inspected HTTP listener is loopback-only. It does not prove firewall status, phone connectivity, or whether another LAN forwarding arrangement exists. No Herd, DNS, firewall, application environment, or system configuration was changed during preparation.

### Backend contract audit — 2026-10-08

Inspected Laravel revision `75d920dd39123827e91c0eac2bf39fea14cce78b` plus the EXT-63 working-tree tests/documentation. EXT-85–EXT-91 are complete in Linear. Current Laravel behavior is authoritative; the historical counts above describe the original preparation only.

| Check                                | Current result                                                                                                                                  |
| ------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| API inventory                        | 28 `/api/v1` endpoints; no endpoint changes                                                                                                     |
| Postman artifacts                    | 61 requests cover all 28 routes; 87 scripts compile, 29 saved examples parse, all 43 environment references resolve; request payloads unchanged |
| Full suite before edits              | 2,161 passed, 31,989 assertions; agent formatter reported two warnings without details, no failures                                             |
| New connected API journeys           | 5 passed, 229 assertions; wallet selection/snapshots, pickup cart CRUD, shipment/history/read state and session-bound push                      |
| Final regressions and quality checks | Results recorded in section 5                                                                                                                   |
| Backend behavior corrections         | None required by this audit                                                                                                                     |
| Consumer evidence                    | No source/device evidence; all RN scenarios below remain not run                                                                                |

Contract coverage includes 30-day customer bearer authentication without browser-session fallback; catalog search/category/brand/tag/effective-price filtering, sorting and pagination; owned cart CRUD; explicit selected-item checkout and stock revalidation; separate canonical destination and detailed address; authoritative decimal subtotal/fee/final total; selected-only profile/handling/preparation/ETA; pickup and wallet payment rules; private uploaded proofs/replacement; owned order history/detail and legacy nullable snapshots; manual shipment milestones/references/operational ETA; scoped notification history/count/read state; safe owned-order metadata; Expo registration/revocation and logout/expired/revoked-session push suppression.

The new journeys use real API authentication, cart and order endpoints, persisted order data, the existing administrator processing service, and isolated Expo HTTP/queue fakes. They assert expected amounts independently of presenters. Laravel feature uploads exercise parsed file/form inputs, including multiple string cart-item IDs; they do **not** prove React Native URI/MIME handling, repeated FormData encoding, PHP/proxy transport limits, actual worker operation, phone delivery or navigation. These require the physical-device run. No live Postman/customer data, provider credentials, server/firewall configuration or React Native code was changed.

## 1. Establish Herd LAN access

Preferred setup: the group member runs the Expo app on a **physical phone connected to the same Wi-Fi/LAN as the Laravel backend laptop**. Record whether the runtime is Expo Go, an Expo development build, or another Expo build, and separately whether the device is physical or an emulator. Do not infer backend connectivity from the fact that the Expo app starts successfully.

1. Keep **Herd as the primary server**. Confirm the Battlefront site works on the laptop using the health request above.
2. Resolve ENV-01 below: configure a LAN-accessible Herd listener and site routing for the selected laptop IP/hostname, or an existing local forwarding arrangement that reaches the correct Herd site. Inspect/backup the applicable local configuration before changing it; do not blindly replace all Herd listeners. The observed `herd.conf` is a default listener shared by local sites.
3. Use a hostname resolvable from the device and routed to this Laravel site, or explicitly configure IP/port routing to this site. A laptop IP does not by itself identify the Battlefront virtual host. Do not require a mobile-only custom Host header as a substitute for correct server routing.
4. Allow the chosen port on the development network through the laptop firewall. Confirm both devices can communicate; guest Wi-Fi/client isolation can prevent this. Record the selected interface/port and any forwarding without committing private network details.
5. Set the existing React Native development API setting to the laptop's reachable LAN IP/hostname plus any required port and `/api/v1`, with no trailing slash. Record the setting's actual name in the run record; none is assumed here. Postman's equivalent setting is `base_url`.
6. From the actual device, request `/api/v1/health` and expect the exact 200 JSON shape above. Then repeat from the React Native HTTP client. A browser success and native-client failure points toward client transport/configuration; record both.
7. Verify a returned product image and pagination link from the device. Investigate generated asset/storage URLs separately if they still reference a laptop-only hostname. `APP_URL` is the application root, not the API base; changing it does not make Nginx listen on LAN. Do not change URL configuration without reproducing the problem.
8. If Herd cannot be exposed over LAN for this setup, the approved **optional fallback** is `php artisan serve --host=0.0.0.0 --port=8000`. Use the laptop's reachable address on port 8000 in the client. `0.0.0.0` is a bind address, not a destination. Record fallback use explicitly; it does not prove Herd LAN access.

Phone localhost is the phone. Use the backend computer's LAN IP/hostname; do not use the desktop-only Herd `.test` hostname unless it is explicitly resolvable from the device and routes to the correct site. HTTPS requires device-trusted certificates; HTTP development allowances, where needed, belong to the mobile development configuration. Do not disable certificate verification globally. Native React Native networking does not need browser CORS changes simply because it uses another device. Treat Expo/device networking, firewall, hostname-resolution, and HTTP transport failures as environment issues, not API-contract defects.

Herd also documents public sharing through Expose, but that uses a public tunnel rather than the requested laptop LAN address; no tunnel is started by this plan. Reference: [Herd for Windows](https://herd.laravel.com/windows).

## 2. Run prerequisites and token handling

- Group member records app revision/build, Expo runtime/build type and version where available, platform, physical device/emulator and OS, tested journeys, and redacted request/response details or failure screenshots. Backend developer records the backend revision and running server configuration.
- Use disposable local accounts A and B, active products with known Sagay inventory, an unavailable/out-of-stock product, and confirmed branch data. Do not reset or seed the shared database merely to prepare evidence without coordinating existing test data.
- Prepare each order independently by refilling the cart. Use synthetic proof images containing no real banking information. Existing web administration prepares rejected wallet payment fixtures; mobile receives no admin endpoints.
- For administrator bearer checks, use an out-of-band local fixture supplied by the backend developer, never the mobile login endpoint. Record only the token role/status, not the value. If the app cannot exercise that state, keep it pending and distinguish supplemental API checks from UI journeys.
- Registration/login captures the returned Sanctum token and 30-day expiration. The mobile client stores it securely and attaches `Authorization: Bearer <token>` to customer requests. Fortify cookies do not authenticate the API.
- Guest chatbot/recommendations omit Authorization entirely. Invalid supplied credentials must return 401 rather than downgrade to guest. Valid administrator tokens receive 403 on customer-only and optional-auth features. Public catalog/branches remain public for all callers.
- Logout returns 204 with no JSON body and revokes only the current token. Clear client token and chat context; verify a second device token remains valid. Customer chatbot context is token/account-bound; public guest context supplies no identity.
- Follow all field/envelope/pagination rules in the handoff. Respect Retry-After; avoid unrelated requests masking a specific throttle check. Wallet uploads use a real FormData file with name/type/URI supplied by the mobile platform; let the HTTP client set the multipart boundary.

## 3. Consumer journey checklist

All rows start **not run**. Record each variant independently; a partial run does not pass the whole row. Date/tester/evidence belongs in the run record, not an assumed result here.

| ID    | Exercise in React Native                                                                                                                                       | Expected result / state                                                                                                                                                                                                                                                    | Status                                                                       |
| ----- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| RN-01 | Device health and native client health; image/link reachability                                                                                                | Correct Herd site; 200 health; resources reachable                                                                                                                                                                                                                         | Not run                                                                      |
| RN-02 | Register unique customer; invalid registration                                                                                                                 | 201 token/user; 422 field errors for invalid input                                                                                                                                                                                                                         | Not run                                                                      |
| RN-03 | Login; wrong credentials; administrator login                                                                                                                  | 200 customer token; 401 for wrong/admin credentials                                                                                                                                                                                                                        | Not run                                                                      |
| RN-04 | Own profile read/update                                                                                                                                        | Four approved fields; name/email required; address saved through profile update                                                                                                                                                                                            | Not run                                                                      |
| RN-05 | Public catalog/search/filter/page/detail, multiple categories, inclusive price bounds and both price sorts                                                     | 200 data/links/meta, 12/page, preserved filter links and correct nullable fields; invalid/conflicting filters 422; zero/missing-stock and inactive details 404; only available filter options; equality at reorder level is low stock; restocking restores active listings | Not run                                                                      |
| RN-06 | Public branches                                                                                                                                                | Unpaginated static data; unconfirmed values remain null                                                                                                                                                                                                                    | Not run                                                                      |
| RN-07 | Cart add/re-add/update/remove                                                                                                                                  | 200 refreshed cart; add accumulates, update replaces; correct decimal totals                                                                                                                                                                                               | Not run                                                                      |
| RN-08 | Quantity zero/excess stock/unavailable product                                                                                                                 | 422 field errors; no incorrect mutation/reservation                                                                                                                                                                                                                        | Not run                                                                      |
| RN-09 | Preview one/multiple/all selected owned cart items using repeated cart_item_ids[] query fields                                                                 | 200 selected snapshot and 12 delivery quotes/pickup quote; selected-only totals/profile/packing/ETA; missing/empty/malformed/duplicate selection 422 errors.cart_item_ids or dotted keys; stale/foreign/unavailable selected IDs 422 errors.cart                           | Not run                                                                      |
| RN-10 | Cash pickup and card-at-store pickup with partial/all selection                                                                                                | Each 201; pending order/payment; purchased rows removed, unselected rows retained; selected stock deducted once; zero fee/no shipment                                                                                                                                      | Not run                                                                      |
| RN-11 | GCash pickup and delivery                                                                                                                                      | Each 201 with proof; delivery requires canonical destination and separate address; pickup prohibits both, zero fee/no Shipment; delivery snapshots recalculated quote                                                                                                      | Not run                                                                      |
| RN-12 | Maya pickup and delivery                                                                                                                                       | Same shared wallet rules and state consistency                                                                                                                                                                                                                             | Not run                                                                      |
| RN-13 | Wallet proof failures; delivery cash/card; invalid destinations; pickup destination/address; missing/duplicate/foreign/stale selected IDs; forged quote values | Field-specific 422 or ignored forged pricing/ETA; no order/deduction/cart consumption/orphan proof on failed placement; forged quote cannot alter saved values                                                                                                             | Not run                                                                      |
| RN-14 | Own order history/detail, second page                                                                                                                          | 200; 10/page newest first; saved subtotal/fee/final total and relative delivery quote in detail; unchanged history shape; safe payment status/rejection                                                                                                                    | Not run                                                                      |
| RN-15 | Replace rejected GCash and Maya proof                                                                                                                          | 200 pending payment, rejection cleared, stock/order status unchanged; retry ineligible ->422                                                                                                                                                                               | Not run                                                                      |
| RN-16 | Guest chatbot product/store/FAQ, follow-up, order question                                                                                                     | 200 gemini/fallback; guest order asks for sign-in without private facts                                                                                                                                                                                                    | Not run                                                                      |
| RN-17 | Customer chatbot public topics, own/foreign order, follow-up                                                                                                   | Owned facts only; foreign/missing order same safe fallback; context reset on identity change                                                                                                                                                                               | Not run                                                                      |
| RN-18 | Guest/customer behavioral feeds and interactions                                                                                                               | Guest/popular and owned personalized feeds; pause/re-enable retention; current stock/reasons; prefetch suppression; anonymous feedback                                                                                                                                     | Mobile migration developer-confirmed; fresh device-run evidence not supplied |
| RN-19 | Missing/invalid/revoked bearer; optionally expired fixture                                                                                                     | Protected requests 401; optional-auth invalid supplied token 401; client handles state                                                                                                                                                                                     | Not run                                                                      |
| RN-20 | Customer A uses B's cart/order/notification/device IDs with otherwise valid input                                                                              | Cart mutation/order/proof/notification/device ownership failures 404; checkout selection failures 422 errors.cart; no foreign mutations/data exposure                                                                                                                      | Not run                                                                      |
| RN-21 | Valid admin token on profile/cart/orders/chatbot/recommendations                                                                                               | 403; no mobile administration capabilities; public reads stay public                                                                                                                                                                                                       | Not run                                                                      |
| RN-22 | Chatbot guest/customer and global rate limits                                                                                                                  | 429/Retry-After; correct 5/10/60 scopes; client waits appropriately                                                                                                                                                                                                        | Not run                                                                      |
| RN-23 | Logout/current-token replay/second-device token and queued push                                                                                                | 204 empty; revoked token 401; session-bound registrations disabled and queued sends suppressed; other valid session remains eligible; client clears account state                                                                                                          | Not run                                                                      |
| RN-24 | Leave fragile/bulky/unavailable items unselected; submit repeated cart_item_ids[] text fields with GCash/Maya payment_proof                                    | Selected-only subtotal/profile/surcharge/preparation/ETA; native upload succeeds; unselected lines/quantities remain; retry with purchased IDs 422 without buying remaining cart                                                                                           | Not run                                                                      |
| RN-25 | Delivery shipment through each administrator milestone, real reference correction/clear, cancellation and configuration changes                                | API matches saved fees/destination/operational ETA and chronological recorded timestamps; no fabricated reference/map; delivered completes one sale; cancellation terminates shipment and restores only purchased stock once                                               | Not run                                                                      |
| RN-26 | Pickup and legacy/pre-revision order history/detail                                                                                                            | Original totals preserved; unknown subtotal/quote/shipment fields remain null; historical notices/timestamps shown only when recorded                                                                                                                                      | Not run                                                                      |
| RN-27 | Notification history, pagination, count, individual retry/all-read and second-device/web comparison                                                            | Owned customer history 10/page; shared unread/read state; read retry preserves timestamp; no foreign/audience records exposed                                                                                                                                              | Not run                                                                      |
| RN-28 | Register two Expo installations; repeat/update/revoke; expiry/revocation and refreshed registration                                                            | Valid UUID/platform/project tokens; no raw tokens returned; active collisions 422; revocation 204; expired/revoked session cannot register or send; old feedback cannot disable refreshed registration                                                                     | Not run                                                                      |
| RN-29 | Real Expo push foreground/background/cold-start receipt/tap and account switch                                                                                 | Generic text and safe screen/order metadata; fetch owned order with current bearer token; foreign order 404; push receipt does not mark read or mutate order state; history remains usable without push                                                                    | Not run                                                                      |

Provider failure/timeout paths have existing deterministic backend coverage. Consumer fallback rendering can be demonstrated with supported safe fallback questions; do not claim a provider outage was exercised unless one was actually observed or deliberately reproduced in an isolated environment.

### Evidence record (copy for each scenario/variant)

```text
Scenario ID / variant:
Run date/time/timezone and tester:
Backend revision:
React Native revision/build:
Expo runtime: Expo Go / development build / other Expo build (specify):
Expo SDK/runtime/app version, where available:
Physical phone or emulator; model/platform, OS/version:
Same Wi-Fi/LAN as backend laptop: yes / no (explain):
Server: Herd / approved fallback; interface/port (keep private address local):
Mobile API config key and redacted value (preserve /api/v1):
Identity: guest / customer A / customer B / administrator; token valid/invalid/revoked/expired:
Fixture preconditions (synthetic IDs, stock, cart/payment state):
Steps:
HTTP method/path/query:
Headers (redact Authorization/cookies/context tokens):
Body (redact password/contact/address/private data):
Upload metadata if relevant: extension, MIME type, size; no real proof attached:
Expected status/body/state, handoff section:
Actual status/body/state (redacted):
Mobile display/behavior:
Screenshot/recording or redacted network evidence reference:
Backend/Postman reproduction (separate result, if needed):
Outcome: passed / failed / blocked / not run:
Mismatch ID, owner, next action:
Retest date/build/result/evidence:
```

Record complete returned JSON keys/types needed to diagnose a mismatch, not only a screenshot saying success. For checkout compare cart consumption, order/payment state, and inventory before/after through existing web administration. A response timeout alone does not prove placement failed; inspect order history before retrying. No new idempotency contract is assumed.

## 4. Mismatch register and triage

| ID      | Expected                                                                             | Observed / evidence                                                                              | Classification / owner                               | Resolution / retest                                                                                         |
| ------- | ------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------ | ---------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| ENV-01  | Herd accepts device requests at the chosen laptop LAN address and routes Battlefront | Inspected HTTP listener and herd.conf bind 127.0.0.1:80; no device reachability result supplied  | Environment / backend developer (local server setup) | Pending LAN routing/listener setup or confirmation of existing forwarding, firewall/device health check     |
| EVID-01 | Actual React Native + Expo evidence for approved journeys                            | Backend-only review selected; source, app build, Expo runtime, device details and traces pending | Evidence prerequisite / mobile group member          | RN-01 through RN-29 remain not run                                                                          |
| DOC-01  | Current checkout/device/shipment contract in validation checklist                    | Original checklist expected whole-cart clearing and omitted EXT-89–91 device scenarios           | Documentation drift / backend developer              | Corrected selected-item semantics, field-specific failures and pending shipment/notification/push scenarios |
| DOC-02  | Saved Postman order examples match OrderResource                                     | Three saved detail/placement examples omitted fulfillment.delivery_destination and shipment      | Documentation drift / backend developer              | Added canonical/null destination and authoritative nullable shipment shapes; requests/API unchanged         |

**No backend contract defect or React Native defect was established.** DOC-01/DOC-02 were corrected within EXT-63. No substantial mismatch requires a separate follow-up issue from this backend audit. EVID-01 remains the EXT-63 consumer acceptance gate, not a client bug. ENV-01 is historical environment evidence requiring a fresh device reachability check. The registration address and other limitations already documented in EXT-62 remain the baseline; do not label them new defects without a demonstrated conflict.

For each report:

1. Record expected client behavior, verified handoff behavior and actual request/response before editing anything.
2. Separate reachability/transport failure from an HTTP response. Check the app's full path, method, token state and body/upload metadata.
3. Replay the same request against equivalent disposable data via Postman/command line or a focused Laravel test; record this separately from the app result. Reusing an already-consumed cart is not an equivalent reproduction.
4. Classify: **backend** violates verified API/shared rules; **React Native** sends/parses/renders incorrectly; **environment** cannot route/transport correctly; **contract clarification** where implementation and handoff disagree.
5. Fix only proven Laravel defects here using existing shared actions/services. Add a regression test and cover affected web behavior. Do not alter a correct response to match an unsupported client expectation. Escalate genuine redesign/ambiguous contract decisions before changing behavior.
6. Send the reproducible client issue description back through the backend developer for the mobile group member; no direct messages to teammates are sent by the agent. Retest affected mobile journeys after either side's fix.

## 5. Verification and completion gate

Historical API regression command run during original preparation:

```powershell
php artisan test --compact tests/Feature/ApiFoundationTest.php tests/Feature/MobileAuthenticationTest.php tests/Feature/MobileProfileTest.php tests/Feature/MobileCatalogTest.php tests/Feature/MobileBranchTest.php tests/Feature/Api/V1
```

Historical result: **275 tests passed, 1,732 assertions**. These are prior preparation results, not this audit's current counts. Local Herd health also passed in that earlier run.

Current audit verification:

| Check                                                                                              | Result                                                       |
| -------------------------------------------------------------------------------------------------- | ------------------------------------------------------------ |
| New API journeys, after Pint                                                                       | 5 passed, 229 assertions                                     |
| Mobile/API and related auth/cart/checkout/order/payment/delivery/shipment/notification regressions | 818 passed, 6,946 assertions                                 |
| `php vendor/bin/pint --dirty --format agent`                                                       | Passed                                                       |
| `php vendor/bin/phpstan analyse --no-progress`                                                     | Passed, zero errors                                          |
| Final full suite                                                                                   | 2,166 passed, 32,218 assertions; exit code 0; 258.32 seconds |
| Postman structural review and `git diff --check`                                                   | Passed; no request payload changes                           |

The integration journeys can be rerun with `php artisan test --compact tests/Feature/Api/V1/MobileConsumerIntegrationTest.php`. Full validation uses `php artisan test --compact`; the final run additionally displays warning details. All tests use the configured SQLite in-memory database, not the shared MySQL data. Preserve shared CartService, OrderPlacementService, catalog queries, recommendation engine, and chatbot pipeline; no mobile-only business rules or admin endpoints.

The pre-edit agent-format baseline reported two warnings with an empty `warning_details` array. The final run used process-local `PAO_DISABLE=1` and `php artisan test --compact --display-warnings`; it displayed no warnings and all 2,166 tests passed. No related or unrelated test failures remain to report. The formatter setting changed only that command's output, not repository/application configuration.

Remaining manual gates:

- Confirm Herd LAN access and `/api/v1/health` from the preferred physical phone on the same Wi-Fi/LAN, then from the Expo app using the configured backend LAN base URL.
- Receive run identity and redacted evidence for each approved journey/variant.
- Reproduce/classify any reported mismatch; fix only backend defects here.
- Receive successful consumer retests for fixes and list unresolved client/environment issues.
- Obtain human review. Do not mark EXT-63 complete solely from documentation, automated tests, or Postman success. Do not commit from this workflow.

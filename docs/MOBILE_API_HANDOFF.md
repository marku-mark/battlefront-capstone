# React Native API handoff — EXT-62

This document describes the implemented customer REST API, including guest chatbot access. Laravel owns all catalog, cart, checkout, order, recommendation, and chatbot business rules. React Native + Expo is a separate client maintained and run by the mobile group member. Administration remains on the web.

## Customer parity additions

The Expo client now handles mandatory email OTP before normal verified login,
nationwide PSGC address selection, server wishlists, password changes, order returns
and refunds, and foreground hosted-payment recovery. Profile responses include
`appearance` (`light` or `dark`); `PATCH profile` accepts that optional field using
the existing per-user preference. Legacy `system` values read as `light`.

PC Builder uses the existing web services through these `/api/v1` JSON routes:

| Method         | Path                         | Result                                                  |
| -------------- | ---------------------------- | ------------------------------------------------------- |
| GET            | `pc-builder`                 | Component catalog, published templates, ruleset version |
| POST           | `pc-builder/validate`        | Shared compatibility findings for `components`          |
| GET            | `pc-builds/shared/{token}`   | Shared build; revoked links return 404                  |
| GET / POST     | `pc-builds`                  | List owned builds / create a build                      |
| PATCH / DELETE | `pc-builds/{uuid}`           | Update / delete an owned build                          |
| POST           | `pc-builds/{uuid}/duplicate` | Create an owned copy                                    |
| POST / DELETE  | `pc-builds/{uuid}/share`     | Issue / revoke a web-compatible share link              |
| POST           | `pc-builds/{uuid}/cart`      | Atomically add a complete validated build               |

All responses use a `data` envelope. Catalog, validation, and shared reads accept
guests or customer bearer tokens; a supplied invalid token returns 401 and an
administration token returns 403. Saved-build operations require a verified customer
bearer token and preserve ownership isolation. Web sessions alone do not authenticate
these routes. Existing v1 and corresponding web operation limits apply.

Save payloads retain `name`, `mode` (`simple` or `advanced`), optional `budget`, and
`components` entries with `key`, `product_id`, `quantity`, and optional
`configuration`. Updates require the returned `edit_version`; stale versions return
422 without overwriting a newer build. Compatibility is server-owned and unknown
specifications stay unknown. Existing schema and stored builds are reused.

Cart rows now expose nullable `pc_build_group: {uuid, name}`. Clients must identify
rows by the server cart-item ID, select all rows in a group together, and disallow
grouped quantity edits. Removing a grouped row removes its entire configuration.
Checkout and order presenters also retain group metadata. Build/cart/payment writes
have no new automatic retry policy; after an uncertain response, read saved builds
and cart/order state before an explicit retry.

Automated contract and bundle checks do not close the physical-device integration
gate. Actual uploads, payment return, push delivery, and iOS behavior remain subject
to the existing native validation procedure.

Companion files:

- [Postman collection](Battlefront_API_v1.postman_collection.json)
- [Postman environment](Battlefront_API_v1.postman_environment.json)

Examples below contain synthetic data, illustrative IDs and dates. They are shapes, not seeded records or credentials. Discover current IDs and options from the running application.

## 1. Connection and common contract

For EXT-63, prefer the Expo app on a physical phone connected to the same Wi-Fi/LAN as the backend laptop. Record Expo Go versus a development/other Expo build, and physical device versus emulator. Actual consumer LAN validation is still pending; the completed Postman run does not replace it.

Set Postman's `base_url` to the reachable backend root **including `/api/v1`**, without a trailing slash. Requests use `{{base_url}}/products`, etc. The checked local Herd health URL is `http://battlefront-capstone.test/api/v1/health`; that host may resolve only on the development computer.

Laravel Herd is the primary local server for LAN testing. Configure the mobile base URL with the laptop's reachable LAN IP/hostname, any required port, and `/api/v1`. Confirm that Herd accepts connections on that interface and routes the chosen hostname/address to this Laravel site; changing the client URL alone is insufficient. Allow the chosen development port through the laptop firewall for the test network. A phone's localhost refers to the phone, and Herd's local `.test` hostname may not resolve on it. Verify `/api/v1/health` from the actual device before exercising journeys.

Only if Herd cannot be exposed over LAN for this setup, use `php artisan serve --host=0.0.0.0 --port=8000` as an optional fallback and point the client at the laptop's reachable address on that port. `0.0.0.0` is a listening address, not the client destination. Do not hard-code LAN addresses in application code. Confirm that returned image and pagination URLs are also reachable from the device. Native mobile HTTP does not use browser CORS; browser-based tooling may have different requirements. Use HTTPS for deployed credentials. Network/firewall/hostname/device transport failures must be distinguished from API-contract failures; see [EXT-63 integration validation](MOBILE_INTEGRATION_VALIDATION.md) for the observed Herd prerequisite and evidence checklist.

Send `Accept: application/json`. JSON bodies use `Content-Type: application/json`; uploads use multipart with a client-generated boundary. API paths render JSON without needing the Accept header, but clients should send it.

- Resources: `{"data": {...}}`.
- Unpaginated lists: `{"data": [...]}`; an empty result is `{"data":[]}`.
- Paginated lists: `data`, `links`, `meta`.
- No additional success flag/message envelope.
- Money is a decimal **string in Philippine pesos**. Preserve precision; server totals are authoritative.
- IDs/counts/quantities are integers in responses. Nullable fields must be handled explicitly.
- Logout is the exception: HTTP `204`, **no body**. Do not parse JSON for it.

### Errors

| HTTP | Meaning / shape                                                                                                                                        |
| ---- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 401  | Missing/invalid required bearer auth: `{"message":"Unauthenticated."}`; invalid login (including administrators): `{"message":"Invalid credentials."}` |
| 403  | Valid authenticated identity is not allowed; JSON `message`                                                                                            |
| 404  | Unknown route/product or missing/foreign cart item/order; JSON `message`; do not rely on exact message text                                            |
| 422  | Request validation or business conflict; `message` plus `errors` mapping field names to arrays of messages                                             |
| 429  | Rate limit reached; JSON `message`, `Retry-After` header                                                                                               |
| 500  | With `APP_DEBUG=false`: `{"message":"Server Error"}`. Debug deployments can include diagnostic fields; these are not a client contract.                |

Representative quantity error:

```json
{
    "message": "Quantity must be at least 1.",
    "errors": {
        "quantity": ["Quantity must be at least 1."]
    }
}
```

Stock conflicts use **422, not 409**. Display field messages instead of parsing the summary string. Array validation can use dotted keys such as `tag_ids.0`. Reverse proxies/PHP upload limits may reject oversized requests before Laravel's field validation.

### Pagination

`GET /products?page=1`: fixed **12/page**; by default featured first, then name, then ID. Optional price sorting is described below.
`GET /orders?page=1`: fixed **10/page**; newest creation timestamp, then highest ID.
There is no configurable `per_page` parameter.

Both return `links.first/last/prev/next` and `meta.current_page/from/last_page/links/path/per_page/to/total`. Previous/next links can be null; `from/to` are null for an empty page. Catalog links preserve supported filters. Use `links.next` or page metadata; do not assume every page is full. Numbered metadata links include `url`, `label`, `page`, `active`; ellipsis entries may omit `page`.

Catalog validates `page` as an integer >=1; order history uses Laravel's paginator resolution without an equivalent FormRequest page rule. Do not assume invalid order pages yield catalog-style 422 errors.

### Rate limits

| Scope             | Limit                                                                            |
| ----------------- | -------------------------------------------------------------------------------- |
| API v1            | 60 requests/minute/IP across the version group                                   |
| Registration      | Additional 5/minute/IP shared with web signup; isolated from storefront activity |
| Login             | Additional 5/minute/lowercased email + IP, shared named login limiter            |
| OTP issuance      | 5/hour/account and 20/hour/IP across web/mobile; 60-second resend cooldown       |
| OTP verification  | 10/minute/account and 30/minute/IP; 5 attempts per issued code                   |
| OTP API endpoints | Additional 30/minute/IP for verification and resend                              |
| Chatbot guest     | Additional 5/minute/IP, shared with web guests                                   |
| Chatbot customer  | Additional 10/minute/customer, shared across tokens/devices and web              |

The chatbot-specific 429 message is `Too many questions. Please wait a minute and try again.` The general API limit can be reached first. Respect `Retry-After`. Limits count requests, not only failed attempts. Users behind one LAN can share the IP allowance.

## 2. Authentication and access

Bearer header: `Authorization: Bearer <token>`. No session login, CSRF-cookie request, or Fortify flow is needed for the mobile API. Fortify remains the web authentication flow.

Only login by an active, email-verified customer creates a Sanctum personal access token with a **30-day per-token expiration**. Registration and valid credentials for an unverified customer return a restricted email-verification context, never an authenticated session or token. OTP verification also issues no token; log in afterward. The verified login response includes `expires_at`; store the raw token securely on the device. There is no refresh endpoint or token listing endpoint. Login again after expiry. Multiple devices can have separate tokens; logout deletes only the token presented for that request.

### Complete endpoint inventory

All paths below are relative to `base_url`. GET routes also support HEAD through Laravel.

| Method | Path                                                     | Access          | Success |
| ------ | -------------------------------------------------------- | --------------- | ------- |
| GET    | /health                                                  | Public          | 200     |
| POST   | /auth/register                                           | Public          | 201     |
| POST   | /auth/login                                              | Public          | 200     |
| POST   | /auth/email/verify                                       | Pending context | 200     |
| POST   | /auth/email/resend                                       | Pending context | 200     |
| POST   | /auth/logout                                             | Customer        | 204     |
| GET    | /profile                                                 | Customer        | 200     |
| PATCH  | /profile                                                 | Customer        | 200     |
| GET    | /products                                                | Public          | 200     |
| GET    | /products/filters                                        | Public          | 200     |
| GET    | /products/{product}                                      | Public          | 200     |
| GET    | /branches                                                | Public          | 200     |
| GET    | /cart                                                    | Customer        | 200     |
| POST   | /cart/items                                              | Customer        | 200     |
| PATCH  | /cart/items/{cartItem}                                   | Customer        | 200     |
| DELETE | /cart/items/{cartItem}                                   | Customer        | 200     |
| GET    | /checkout                                                | Customer        | 200     |
| GET    | /delivery-locations                                      | Customer        | 200     |
| GET    | /checkout/delivery-quote                                 | Customer        | 200     |
| POST   | /orders                                                  | Customer        | 201     |
| GET    | /orders                                                  | Customer        | 200     |
| GET    | /orders/{order}                                          | Customer        | 200     |
| GET    | /orders/{order}/return-requests                          | Customer        | 200     |
| POST   | /orders/{order}/return-requests                          | Customer        | 201     |
| GET    | /orders/{order}/return-requests/{returnRequest}          | Customer        | 200     |
| POST   | /orders/{order}/return-requests/{returnRequest}/withdraw | Customer        | 200     |
| POST   | /orders/{order}/payment-proof                            | Customer        | 200     |
| GET    | /notifications                                           | Customer        | 200     |
| GET    | /notifications/unread-count                              | Customer        | 200     |
| PATCH  | /notifications/{notification}/read                       | Customer        | 200     |
| PATCH  | /notifications/read-all                                  | Customer        | 200     |
| PUT    | /push-devices/{device}                                   | Customer        | 200     |
| DELETE | /push-devices/{device}                                   | Customer        | 204     |
| POST   | /chatbot                                                 | Guest/customer  | 200     |
| GET    | /recommendations                                         | Guest/customer  | 200     |
| GET    | /recommendations/personalized                            | Customer        | 200     |
| POST   | /recommendations/interactions                            | Guest/customer  | 204     |

Public health/catalog/branches do not authenticate supplied credentials. Registration/login authenticate their submitted fields, not a bearer header.

For **chatbot and the public recommendation feed/interaction routes**: omit Authorization entirely for guests; valid customer tokens are accepted; valid administrator tokens get 403; invalid/expired/revoked/malformed supplied Authorization gets 401. An empty bearer header is not a guest request. Web sessions do not authenticate these API requests.

Customer-only routes require Sanctum, verified email ownership, and the customer role gate: missing/invalid/expired/revoked tokens and tokens belonging to unverified accounts get 401; a verified administrator token gets 403. Unverified tokens are also refused by optional-auth chatbot/recommendation routes and never enable authenticated catalog personalization. Foreign cart/order IDs are concealed with 404. Chatbot order ownership failures instead use the safe conversational fallback described below. Validation may run before resource lookup, so use otherwise valid input when testing ownership.

## 3. Registration, login, profile

### POST /auth/register

Required fields:

| Field                 | Validation                                                                 |
| --------------------- | -------------------------------------------------------------------------- |
| name                  | String, max 255                                                            |
| email                 | Valid email string, max 255, unique; lowercased by current Fortify setting |
| password              | String, confirmed by password_confirmation                                 |
| password_confirmation | Must match password                                                        |
| device_name           | String, max 255                                                            |

The password default outside production is minimum 8 characters. Production config requires at least 12, upper/lowercase, letters, numbers, symbols, and uncompromised-password validation. Use a suitable local test password rather than a committed example.

The shared request also validates optional `default_delivery_address` (nullable string, max 255), **but registration does not persist it**. Send it in the profile update instead. Submitted role/ownership fields do not create administrators.

Registration returns **201** with `message` and `data.status = verification_required`. It creates an unverified customer and sends a six-digit OTP through the existing configured Laravel mailer. No token or user resource is returned:

```json
{
    "message": "Verify your email address before signing in.",
    "data": {
        "status": "verification_required",
        "verification_token": "<restricted-context-issued-at-runtime>",
        "masked_email": "m***@example.test",
        "verification_expires_at": "<ISO timestamp>",
        "expires_at": "<OTP expiry ISO timestamp or null>",
        "resend_available_at": "<ISO timestamp or null>",
        "attempts_remaining": 5,
        "delivery_status": "sent",
        "retry_after": 60
    }
}
```

Keep `verification_token` only in temporary verification-screen memory. This random 256-bit token expires after thirty minutes and authorizes OTP verification/resend for its bound account/email; it cannot authenticate other API operations. Never put it in a URL, log it, or treat it as a Sanctum bearer token. The OTP is never returned by the application. Delivery failures leave the account unverified and report `delivery_status = failed`; registration still returns 201 because the account exists. Rate-limited issuance reports `rate_limited` and `retry_after`. Returning to login and supplying valid credentials safely establishes a new verification context for an existing unverified customer, invalidating any older context without resetting OTP attempts.

### POST /auth/email/verify and POST /auth/email/resend

Both endpoints are unauthenticated and require the restricted `verification_token`; do not send a bearer header. Verification additionally requires `code` as a **six-character numeric string**, preserving leading zeroes such as `"001234"`. A correct, current code returns `200` with `{"message":"Email verified. You can now sign in.","data":{"status":"verified"}}`. Clear the verification context/code and return to login; verification grants no session or token.

Codes expire after **five minutes** and permit **five attempts per issued code**. Verification is one-time; consumed, expired, incorrect, or unknown contexts return Laravel `422` field errors under `errors.code` without revealing account existence. A resend invalidates the previous code, resets its attempt allowance, and starts a new expiry. It returns `200` with `message` and updated verification metadata. Delivery failure returns `503` and `delivery_status = failed`; the account remains unverified. Display the server's message and preserve the safe context for retry.

The resend cooldown is **sixty seconds**, including when delivery fails. Show a countdown from `resend_available_at`; the server enforces it. Additional account/email and IP limits apply to issuance and verification across web and mobile. Verification and resend also have a 30-request/minute/IP endpoint limit, in addition to the common API limit. Throttling returns `429` with `Retry-After`; wait before retrying. Repeated login or switching channels does not reset an issued code's failed attempts. Remaining-attempt metadata is guidance; the server always decides eligibility.

### Manual Gmail delivery test — separate authorization required

Automated verification uses fake/mocked Laravel mail only. Actual Gmail delivery has not been verified by those tests. Preserve the existing Gmail SMTP/App Password configuration; do not copy secrets into source code, Postman, screenshots, or logs. OTP delivery uses the configured SMTP mailer synchronously, with a ten-second SMTP timeout; no queue worker or email-link callback is needed. Non-SMTP mailers are rejected for OTP delivery outside automated tests to prevent plaintext codes reaching a log transport. Gmail transport/account rejection may require checking the existing settings privately; never replace credentials automatically.

After the developer separately authorizes a real inbox test:

1. Apply the additive OTP migration to the intended database without resetting it, and build/start the normal application. Confirm the existing configured mailer is SMTP privately; do not print environment/configuration secrets.
2. Open Battlefront's existing registration page, register a unique address controlled by the authorized tester, and confirm the Verify Email page appears while authenticated features remain inaccessible.
3. Check that inbox and spam folder. Read the six-digit code from the Battlefront email and enter it within five minutes, keeping any leading zeroes. For a web customer, confirm verification automatically signs in and opens the dashboard or pending cart/intended page, both immediately after registration and when returning later through login with valid credentials. The returning login flow honors remember-me only after successful verification. Administrator verification still returns to login. Mobile verification remains separate from login as described in the next step.
4. For mobile/Postman, use the authorized email with `/auth/register`; confirm `201` and no bearer token. Set the locally scoped secret `verification_code` from the inbox, run Verify Email OTP, then run Login customer to receive the normal token. Never export the populated environment or code.
5. On a separate unverified test account, confirm an immediate resend is limited, wait sixty seconds, resend, and check that only the replacement code works. Confirm an expired/used code is rejected. Clear local OTP/context values after testing and record results without codes, tokens, credentials, or personal inbox content.

Trusted development seeders alone pre-verify the existing five demo identities and the thirty demonstration customers. Existing verified users need no OTP. New administrators created through web Team Management must verify their email inline before the account is created; the authorized creator remains signed in and the new administrator can subsequently log in normally. Existing unverified accounts, including administrators provisioned outside that form, still verify after valid web credentials. Verification never changes existing roles, account status, ownership, or stored business records. Password reset does not verify email ownership. Mobile endpoint contracts are unchanged.

Team Management manual delivery testing also requires separate authorization: sign in as an authorized Owner or IT administrator, enter an unused tester-controlled email, click Verify email, and have the recipient personally enter the received code on the Team page. Confirm no account exists before Create account, then create it and check that the creator remains signed in and the verified administrator can log in. Check email-change invalidation, resend cooldown, and proof expiry using separate authorized test accounts. Automated checks use faked mail only.

### POST /auth/login

Required: `email` (valid email string), `password` (string), `device_name` (string, max 255). No password_confirmation. Invalid credentials, inactive accounts, and administrator credentials all return the same 401 and issue no token. Valid credentials for an active unverified customer return 403 with the pending verification response below. Already verified customers receive the existing token response.

Verified login uses:

```json
{
    "data": {
        "user": {
            "id": 1,
            "name": "Example Customer",
            "email": "customer@example.test",
            "default_delivery_address": null
        },
        "token": "<issued-only-at-runtime>",
        "token_type": "Bearer",
        "expires_at": "2026-10-31T08:00:00+00:00"
    }
}
```

### POST /auth/logout

No body. Requires current customer token; 204 with an empty body. Remove the client token and clear conversation context. Subsequent use of the revoked token returns 401.

### GET /profile and PATCH /profile

GET returns:

```json
{
    "data": {
        "id": 1,
        "name": "Example Customer",
        "email": "customer@example.test",
        "default_delivery_address": null,
        "default_delivery_address_prefill": {
            "delivery_destination": "",
            "delivery_barangay_code": "",
            "delivery_address_details": ""
        },
        "default_delivery_contact_number": null,
        "search_recommendations_enabled": true,
        "product_view_recommendations_enabled": true,
        "personalized_recommendations_enabled": true
    }
}
```

PATCH requires `name` (string, max 255). Account email addresses are read-only: omit `email`, or send the exact current email for compatibility with existing clients. Any different value, including a case-only change, blank, null, or a non-string value, returns 422 with `errors.email`: `Your account email address cannot be changed.` A rejected request saves no profile changes. Existing request trimming still applies. `default_delivery_contact_number` is optional and nullable: omit to preserve, send null/blank to clear. Accept Philippine mobile numbers in `09XXXXXXXXX` or `+639XXXXXXXXX` form, with optional spaces/hyphens; responses and saved values use `+639XXXXXXXXX`. The number prefills checkout, where customers may override it per order. Legacy mobile `default_delivery_address` remains nullable/max 255: omit to preserve, send null/blank to clear. Existing v1 structured clients may send `default_delivery_destination`, `default_delivery_barangay_code`, and `default_delivery_address_details`. Nationwide clients also send `default_delivery_region_code`, nullable `default_delivery_province_code`, and `default_delivery_city_code`; the destination may be omitted and resolved from the city code. Complete parent membership is validated locally. Optional `default_delivery_address_action` explicitly selects `keep`, `save`, or `clear`; structured keys without an action imply `save`. Keep leaves the saved address untouched, save stores validated components plus a server-formatted string, and clear clears both. Structured saves ignore a supplied assembled address. Profile responses include `default_delivery_address_prefill` for structured form hydration. Checkout's `delivery_address_prefill` uses saved components directly. Legacy free-text updates clear stale components and populate new ones only when matching is unambiguous. No existing text is guessed or silently discarded by migration.

New customers have `search_recommendations_enabled` and `product_view_recommendations_enabled` enabled by default, so no profile setup is needed. PATCH can independently disable either preference; turning one off pauses its recording/ranking and retains unexpired history. The global `personalized_recommendations_enabled` switch pauses all personal ranking and new search/view recording; all three preferences default to true. Re-enabling uses retained unexpired history. Retained activity expires after 90 days. Ordinary profile updates never change the email or its verification timestamp. Email verification is mandatory before authenticated access, and a submitted `email_verified_at` cannot bypass it. Email remains present in GET and successful PATCH responses. Registration still requires a valid, unique email.

```json
{
    "name": "Example Customer",
    "email": "customer@example.test",
    "default_delivery_address": "Example delivery address",
    "default_delivery_contact_number": "+639171234567",
    "search_recommendations_enabled": true,
    "product_view_recommendations_enabled": true,
    "personalized_recommendations_enabled": true
}
```

Successful PATCH returns the same profile shape with all three recommendation preferences. No user ID is accepted to select a profile. No password, role, remember token, recovery code, or two-factor data is exposed.

## 4. Catalog and branches

### GET /products

Optional query parameters:

| Parameter    | Meaning                                                                                                     |
| ------------ | ----------------------------------------------------------------------------------------------------------- |
| q            | Nullable string <=255; substring search across product name, brand, description                             |
| category_id  | Nullable integer; existing active category                                                                  |
| category_ids | Nullable array of up to 50 distinct existing active category IDs; matches any selected category             |
| brand        | Nullable string <=255; catalog brand equality filter                                                        |
| tag_id       | Nullable integer; existing tag (single tag filter)                                                          |
| min_price    | Nullable numeric peso amount, 0..9999999999.99, at most 2 decimal places; inclusive effective-price minimum |
| max_price    | Same amount rules; inclusive effective-price maximum; must be >= min_price when both are supplied           |
| sort         | Nullable featured, price_asc, or price_desc; omitted/null/blank defaults to featured                        |
| page         | Nullable integer >=1                                                                                        |

Filters combine. Send multiple categories as indexed query parameters such as `category_ids[0]=1&category_ids[1]=2`, using real IDs from filter options; comma-separated values are not supported. Array keys are normalized to a list. A null or empty category list imposes no additional restriction. Do not send populated `category_id` and `category_ids` together: the API returns 422 with `errors.category_ids`. Invalid elements use dotted error keys such as `category_ids.0`.

Price filtering and ordering use `discount_price ?? price`, including a zero discount. Bounds are inclusive and either may be omitted; scientific notation and more than two decimal places are rejected. `price_asc` orders by effective price ascending, `price_desc` descending; ties use name ascending, then ID ascending. Featured status is the first sort only for the default/featured order. Pagination links retain supported filters. Ordering is deterministic for unchanged data; concurrent price or catalog changes can shift offset-based pages.

Multiple-category and price-range additions apply only to `/api/v1/products`. The Inertia web catalog also supports the same featured/price_asc/price_desc sorting values, preserving search, singular category/brand/tag filters, featured-first default ordering, and infinite scrolling. It continues to ignore mobile-only category_ids/min_price/max_price inputs. Featured products remain part of the main web grid with badges; no standalone Featured Products showcase is exposed. Product response fields and filter-option responses are unchanged. There is no stock, budget, or multi-tag catalog filter. Use `GET /products/filters` to populate selectors:

When a customer sends a valid Sanctum bearer token, page-one searches are retained unless `search_recommendations_enabled` is false. Guests and customers who disabled search tracking are not tracked. `GET /products/{product}` remains public; a valid customer bearer token records an eligible product view unless `product_view_recommendations_enabled` is false. Repeated views of the same product within 30 minutes are deduplicated. Both activity types expire after 90 days; pausing retains unexpired history. Global personalization must also be enabled. Requests carrying Purpose/Sec-Purpose/X-Moz prefetch headers do not record searches or views. Mobile callers must identify speculative requests as prefetch and issue a normal detail GET when the product is actually consumed. Dwell tracking currently belongs to the web detail page; no mobile dwell endpoint is added.

```json
{
    "data": {
        "categories": [
            {
                "id": 1,
                "name": "Peripherals"
            }
        ],
        "brands": [],
        "tags": [
            {
                "id": 1,
                "name": "Gaming"
            }
        ]
    }
}
```

List entries and `GET /products/{product}` share this product shape (detail wraps it in `data`):

```json
{
    "data": {
        "id": 1,
        "name": "Example Mouse",
        "description": null,
        "brand": null,
        "price": "100.00",
        "discount_price": null,
        "image_url": null,
        "is_featured": false,
        "category": {
            "id": 1,
            "name": "Peripherals"
        },
        "tags": [
            {
                "id": 1,
                "name": "Gaming"
            }
        ],
        "inventory": {
            "status": "in_stock"
        }
    }
}
```

Catalog products must be active, belong to an active category, and have existing Sagay inventory with `quantity > 0`. Zero-stock, missing-inventory, inactive products, and products in inactive categories are omitted from lists and return 404 on detail. Missing or nonnumeric product IDs also return 404. Restocking an active product in an active category makes it visible on the next request without changing either stored activation flag.

Catalog and recommendation `inventory.status` is `low_stock` when `quantity > 0 && quantity <= reorder_level`, or `in_stock` when `quantity > reorder_level`. Equal stock is low stock; positive stock with a zero reorder level is in stock. Zero/missing inventory remains classified internally as `out_of_stock`/`unavailable` but is excluded from these customer responses. Exact stock quantity is omitted. Use `discount_price ?? price` as effective price. Brand, description, image_url and discount_price may be null. Image URLs come from Laravel's configured asset/storage URLs.

Category options include only active categories containing an available product; brand/tag options also come only from available products. Existing active category IDs and existing tag IDs remain valid filters after their last product sells out and return empty results. A multiple-category selection continues matching the available products in its other selected categories. Inactive or nonexistent category IDs retain validation errors. Refresh options when revisiting the catalog; do not automatically clear a valid stale selection or reactivate records. Pagination totals count only currently available results; inventory changes between requests can shift offset-based pages, so refresh from page one to obtain a fresh listing.

A list wraps these entries in the pagination structure described above. No matches yields an empty paginated collection.

### GET /branches

Unpaginated `data` array. Returns current static directory rows, operational Sagay first then city alphabetically. No branch detail API is implemented. Current seeded directory includes five branches; do not hard-code record IDs/counts or invent unconfirmed details.

```json
{
    "data": [
        {
            "id": 1,
            "name": "Example Store",
            "address": null,
            "city": "Sagay City",
            "contact_number": null,
            "latitude": null,
            "longitude": null,
            "email": null,
            "operating_hours": "8:00 AM–6:00 PM",
            "operational_notice": "LBC COD Arrangement: Collection of both product payment and delivery fees is subject to confirmation with the LBC branch serving Battlefront's Sagay City operations. Current shipping rates and collection arrangements are configured for demonstration purposes and are not official LBC quotations.",
            "is_operational": true
        }
    ]
}
```

`id` integer; `name/city/operating_hours` strings; `is_operational` boolean; address/contact/email nullable strings; latitude/longitude nullable numbers. `operational_notice` is the Sagay-only COD confirmation notice and is null elsewhere. Other branches provide static reference information, not selectable live inventory pools.

## 5. Cart

All operations address the authenticated customer's cart. No cart ID/user ID is needed.

- GET /cart: empty or populated cart.
- POST /cart/items: required `product_id` (existing integer ID), `quantity` (integer 1..4294967295). Adds to existing line quantity.
- PATCH /cart/items/{cartItem}: required `quantity` with the same range. Replaces the quantity.
- DELETE /cart/items/{cartItem}: no body. Removes a line, including unavailable products.

All successful operations return **200 with the full refreshed cart**:

```json
{
    "data": {
        "items": [
            {
                "id": 1,
                "quantity": 2,
                "product": {
                    "id": 1,
                    "name": "Example Mouse",
                    "brand": null,
                    "image_url": null,
                    "category": "Peripherals",
                    "price": "100.00",
                    "discount_price": null
                },
                "unit_price": "100.00",
                "line_total": "200.00",
                "availability": {
                    "status": "available",
                    "available_quantity": 5
                }
            }
        ],
        "item_count": 1,
        "total_quantity": 2,
        "total": "200.00",
        "conflict_count": 0
    }
}
```

`item_count` counts lines; `total_quantity` sums units; `total` uses current effective prices and BCMath decimal arithmetic. The nested cart product's `category` is a string, unlike catalog's category object.

Cart availability statuses: `available`, `product_ineligible`, `inventory_unavailable`, `out_of_stock`, `insufficient_stock`. `available_quantity` is integer or null. `conflict_count` counts lines not marked available.

Cart mutation rechecks active product/category and current Sagay stock. Adding is cumulative and must fit stock. Zero quantity does not delete a line. Ineligible/missing inventory errors use `errors.product_id`; zero/insufficient stock use `errors.quantity`. Foreign/missing line IDs return 404. Supplied prices/ownership are ignored. Cart does not reserve/deduct inventory; checkout rechecks it.

Empty cart:

```json
{
    "data": {
        "items": [],
        "item_count": 0,
        "total_quantity": 0,
        "total": "0.00",
        "conflict_count": 0
    }
}
```

## 6. Checkout, orders, payment evidence

### GET /checkout

Requires an explicit nonempty `cart_item_ids` list of distinct positive integer IDs from the owning customer's cart. Send repeated query parameters, for example `GET /checkout?cart_item_ids[]=12&cart_item_ids[]=15`. Missing/empty/malformed/duplicate selections return 422 `errors.cart_item_ids` or `errors.cart_item_ids.<index>`; foreign/missing/removed IDs and unavailable selected items return 422 `errors.cart` without disclosing ownership. Unselected items are excluded from the snapshot and all quotes, including fragile/bulky handling and ETA, and may remain unavailable without blocking checkout. Returns a current snapshot, payment/fulfillment options, configured `paymongo_hosted_methods`, `pickup_quote`, and 12 `delivery_quotes`. No destination query parameter is needed: choose exactly one canonical `destination` from the returned quotes. Existing cart/payment fields are shown below; the new quote fields follow separately:

```json
{
    "data": {
        "cart": {
            "items": [
                {
                    "id": 1,
                    "quantity": 2,
                    "product": {
                        "id": 1,
                        "name": "Example Mouse",
                        "brand": null,
                        "image_url": null
                    },
                    "unit_price": "100.00",
                    "line_total": "200.00"
                }
            ],
            "item_count": 1,
            "total_quantity": 2,
            "total": "200.00"
        },
        "customer": {
            "name": "Example Customer",
            "default_delivery_address": null
        },
        "pickup_location": {
            "name": "Example Store — Sagay City",
            "address": null,
            "contact_number": null,
            "operating_hours": "8:00 AM–6:00 PM"
        },
        "fulfillment_methods": [
            {
                "value": "pickup",
                "label": "Pickup"
            },
            {
                "value": "delivery",
                "label": "Delivery"
            }
        ],
        "payment_methods": [
            {
                "value": "cash",
                "label": "Cash (Pay at Pickup)",
                "description": "Pay cash when collecting your products at the store. No advance payment or uploaded proof is required.",
                "requires_proof": false,
                "payment_account": null,
                "available_for": ["pickup"]
            },
            {
                "value": "card_at_store",
                "label": "Debit/Credit Card (Pay at Store)",
                "description": "Pay through a physical debit/credit card terminal during pickup at the store. This is not an online payment gateway; no advance payment or uploaded proof is required.",
                "requires_proof": false,
                "payment_account": null,
                "available_for": ["pickup"]
            },
            {
                "value": "gcash",
                "label": "GCash",
                "requires_proof": true,
                "payment_account": {
                    "account_name": "Example demo account",
                    "account_number": "EXAMPLE",
                    "is_demo": true
                },
                "available_for": ["pickup", "delivery"]
            },
            {
                "value": "maya",
                "label": "Maya",
                "requires_proof": true,
                "payment_account": {
                    "account_name": "Example demo account",
                    "account_number": "EXAMPLE",
                    "is_demo": true
                },
                "available_for": ["pickup", "delivery"]
            },
            {
                "value": "cod",
                "label": "Cash on Delivery (COD)",
                "requires_proof": false,
                "payment_account": null,
                "available_for": ["delivery"]
            }
        ]
    }
}
```

Checkout cart lines omit the ordinary cart's availability and category/price fields; use the shown snapshot shape. `payment_account` is null for cash/card/COD, or an object with account_name/account_number/is_demo for wallets. Display configured values from the response and honor demo labeling; the example account above is synthetic. No transfer is initiated by this API.

`pickup_quote` is `{"product_subtotal":"200.00","delivery_fee":"0.00","total":"200.00"}` for the example cart. Each `delivery_quotes` entry contains the complete server-derived quote for that cart and one destination. Example entry for standard-profile Sagay delivery:

```json
{
    "origin_city": "Sagay City",
    "destination": "Sagay City",
    "is_demo": true,
    "assumption_label": "Battlefront demonstration estimates. New Negros Occidental rates use OSM administrative-boundary centroids as straight-line proxies; they are not official LBC rates or verified production shipping charges.",
    "shipping_profile": "standard",
    "base_fee": "80.00",
    "handling_surcharge": "0.00",
    "delivery_fee": "80.00",
    "preparation_days": 1,
    "transit_min_days": 0,
    "transit_max_days": 1,
    "eta_min_days": 1,
    "eta_max_days": 2,
    "carrier": "lbc",
    "packing_expectation": "Standard packing",
    "product_subtotal": "200.00",
    "total": "280.00",
    "eta_anchor_date": "2026-10-08",
    "eta_timezone": "UTC",
    "estimated_delivery_start": "2026-10-09",
    "estimated_delivery_end": "2026-10-10",
    "notice": "Battlefront demonstration estimates, not official LBC rates or verified production shipping charges. New Negros Occidental rates use straight-line OSM boundary-centroid proxies, not storefront or road distances. Delivery dates are provisional; prepaid orders require payment verification. This does not book or approve an LBC shipment."
}
```

Display the selected entry's monetary values; `total` is product subtotal plus delivery fee. Mixed carts use their highest persisted shipping profile (`standard < fragile < bulky`) once, regardless of quantities/line count. Fees and dates are Battlefront-configured demo estimates, not official LBC rates or production-verified charges. Display carrier `lbc` as **LBC**, the packing expectation, estimate notice and demo assumption label. New Negros Occidental values are straight-line proxies from OSM administrative-boundary centroids; do not present them as storefront positions or road distances.

Calendar dates are checkout-only presentation. One server quote-generation date in `config('app.timezone')` (currently UTC) anchors the 12 legacy preview windows in a response; a live quote for a selected nationwide city code is anchored by its own server response. Minimum/maximum total days are preparation plus transit, added as calendar days without weekend/holiday adjustment. Zero transit days means same-day transit once preparation is ready; persisted historical snapshots keep their original ranges. Treat date strings as civil dates in the returned timezone; do not shift them through the device timezone. Arrival is provisional; prepaid orders require payment verification, while COD is collected upon delivery. Refresh checkout after cart changes and before paying; placement independently recalculates current prices, profiles and configured fees/relative ETA. Neither preview quotes nor calendar windows are trusted placement inputs.

### PSGC delivery dropdown data

Checkout preview exposes compatible `data.delivery_locations` and `data.delivery_address_prefill` fields from the bundled Q1 2026 PSGC dataset. These remain bounded to the original 12 destinations. Updated nationwide clients use the additional local lookup and quote endpoints below; do not expect all geography in preview.

Each location contains `destination` (the existing canonical delivery key), full string `code`, official `name`, `type`, `city_class`, nullable `province`, and `barangays: [{code, name}]`. Only the existing 12 supported destinations and their 300 barangays are returned. Bacolod is a highly urbanized city with no administrative province parent. Use readable names for labels and codes for barangay values; never derive codes from names or assume barangay numbers are codes.

The prefill contains `delivery_destination`, `delivery_barangay_code`, and `delivery_address_details`, using empty strings for unknown values. It conservatively extracts recognizable parts of the existing free-text profile address. Keep ambiguous saved text as a reference instead of copying the entire address into street details. Clear the selected barangay whenever the destination changes.

### Nationwide local lookup and selected-cart quote (additive v1 contract)

All requests use the existing customer bearer token. `GET /delivery-locations` returns `{data:{version:"2026-03-31.v2",locations:[...]}}`. Lazily load:

| Query                                    | Result                                                   |
| ---------------------------------------- | -------------------------------------------------------- |
| `level=regions`                          | 18 regions: code/name                                    |
| `level=provinces&parent_code=1800000000` | Provinces in Negros Island Region: code/name/region_code |
| `level=cities&parent_code=1804500000`    | Cities/municipalities belonging to Negros Occidental     |
| `level=cities&parent_code=1300000000`    | NCR cities/municipalities with no province               |
| `level=city&parent_code=1804523000`      | One city for saved-address hydration                     |
| `level=barangays&parent_code=1804523000` | Sagay barangays: code/name/city_code                     |

City records include code/name/region_code/nullable province_code/destination/type/city_class. Use null province for independent localities, including HUCs, NCR, Isabela and SGA; null does not itself imply HUC. Manila district metadata belongs to City of Manila. Keep every code as a string. Reset descendants after parent changes and discard stale responses. Invalid parents return 422 `errors.parent_code`. No external PSGC API is needed.

`GET /checkout/delivery-quote?delivery_city_code=1804523000&cart_item_ids[]=12` reviews only the customer's selected cart and returns `{data:{available:true,quote:{...}}}`. The quote adds `pricing_configured`, `pricing_status`, `delivery_arrangement_required`, `order_placement_allowed`, `destination_label`, `payment_methods` and `zone` (rule ID/version and geographic codes). Its `destination` is the selected city code. Render the label and monetary strings; choose payment only from `payment_methods`. Do not calculate shipping locally. The 20 new Negros Occidental rates are available through this city-code endpoint and use the same structured address/barangay validation as other nationwide locations. Valid destinations in mapped regional groups return a Battlefront estimate included in the order total, without an ETA, carrier, or automatic Shipment; an administrator must confirm the arrangement manually. Destinations without any approved estimate retain the `pricing_status="not_configured"` `0.00` placeholder and no carrier/ETA. This is not free delivery. Explicitly disabled/malformed rules return `available=false`; invalid city or cart selection returns 422. Foreign cart IDs never reveal their owner.

Thirty-two configured routes (the original 12 compatibility aliases plus 20 Negros Occidental code-based rules) support GCash, Maya and COD demonstrations. The preview's `delivery_locations` and initial `delivery_quotes` remain bounded to the original 12 for v1 compatibility; the new 20 use their validated nationwide city codes with the quote endpoint above. New delivery quotes include Battlefront's package-weight estimate and the highest handling surcharge once per order. Regional estimates are included in new order totals; an administrator confirms those arrangements manually. Historic `not_configured` orders retain their zero-fee subtotal-only snapshots. COD remains unpaid through delivery: the assigned handler records collection and separately reconciles full remittance with its reference before payment verification, completion, or sale recording. Those are manual Battlefront records, not proof of LBC carrier settlement. Historical orders preserve their payment and delivery snapshots.

New mobile delivery submissions use the following fields (wallets use multipart with a proof file):

```json
{
    "cart_item_ids": [12],
    "recipient_name": "Example Customer",
    "contact_number": "EXAMPLE",
    "fulfillment_method": "delivery",
    "payment_method": "cod",
    "delivery_region_code": "1800000000",
    "delivery_province_code": "1804500000",
    "delivery_city_code": "1804523000",
    "delivery_barangay_code": "1804523018",
    "delivery_address_details": "12 Mabini Street"
}
```

`delivery_destination` may be omitted and resolved from `delivery_city_code`; if supplied it must match that code or its compatibility alias. Any nationwide parent field or code destination opts into complete hierarchy validation. Province is nullable only where the selected city has no administrative province. Incorrect parents return field-specific 422 errors. Server composition ignores client address/fees and placement recomputes eligibility and snapshots accepted facts. Existing alias/free-text and original barangay/details requests remain supported as documented below. Historical order addresses and snapshots are never rewritten. Customer defaults use equivalent `default_delivery_` fields through the existing web settings flow or mobile `PATCH /profile`.

Order detail `delivery_quote` adds nullable `metadata` containing accepted geographic/rule/dataset facts, payment assumptions and structured address components. Historical metadata remains null. Existing `PATCH /profile` accepts the equivalent `default_delivery_` nationwide fields with `default_delivery_address_action=save`; its response shape remains compatible. Branch responses add nullable `operational_notice`, populated only for Sagay; show it in branch information. See [all configured rates, classification decisions and rollout instructions](PSGC_DELIVERY_DATA.md).

### POST /orders

Required fields:

| Field                    | Rule                                                                                                                                                                                                  |
| ------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| cart_item_ids            | Required nonempty list of distinct positive integer cart-item IDs owned by the customer                                                                                                               |
| recipient_name           | String <=255                                                                                                                                                                                          |
| contact_number           | String <=20; preserve formatting as text                                                                                                                                                              |
| fulfillment_method       | pickup or delivery                                                                                                                                                                                    |
| payment_method           | cash, card_at_store, gcash, maya, cod                                                                                                                                                                 |
| delivery_address         | Legacy mobile delivery: string <=255, required when both structured fields are absent; prohibited when nonempty for pickup                                                                            |
| delivery_barangay_code   | Structured delivery: required 10-digit string belonging to the selected destination; prohibited when nonempty for pickup                                                                              |
| delivery_address_details | Structured delivery: required nonblank string; composed address must fit 255 characters; prohibited when nonempty for pickup                                                                          |
| delivery_destination     | Exactly one destination for delivery: an original canonical alias from `delivery_quotes` or the selected full city code for structured nationwide address fields; prohibited when nonempty for pickup |
| payment_proof            | Required for gcash/maya; prohibited for cash/card_at_store/cod                                                                                                                                        |

Pickup accepts cash, card_at_store, gcash, and maya. Delivery accepts gcash, maya, and cod. COD is delivery-only and requires no uploaded proof or advance payment. Submit JSON for cash/card pickup:

```json
{
    "cart_item_ids": [12, 15],
    "recipient_name": "Example Customer",
    "contact_number": "EXAMPLE",
    "fulfillment_method": "pickup",
    "payment_method": "cash"
}
```

For COD delivery, submit JSON without `payment_proof`:

```json
{
    "cart_item_ids": [12, 15],
    "recipient_name": "Example Customer",
    "contact_number": "EXAMPLE",
    "fulfillment_method": "delivery",
    "payment_method": "cod",
    "delivery_destination": "Sagay City",
    "delivery_barangay_code": "1804523018",
    "delivery_address_details": "12 Mabini Street"
}
```

Display product subtotal, configured demo delivery fee, and the returned `total` as the total COD amount payable upon delivery. No advance payment, receiving account, or proof is required. This selection does not book or approve COD with LBC. Existing supported destinations and shipping calculations apply without a new COD surcharge.

For wallet orders submit the fields above using multipart form data with repeated `cart_item_ids[]` text fields (one ID per field), including an actual file part named `payment_proof`. JPEG/JPG, PNG, WebP only; contents must be an image, filename extension must match allowed extensions; max **5120 KB (5 MB)**. Do not send a filesystem path or base64 string as the proof, and do not manually specify a multipart boundary/Content-Type in Postman or React Native FormData.

For delivery, include `delivery_destination` (for example `Sagay City`). Existing mobile clients can retain their separate `delivery_address` by omitting both structured fields. Updated clients send `delivery_barangay_code` and `delivery_address_details` instead; sending either key, even null or empty, requires both to pass structured validation. For example, submit `delivery_barangay_code=1804523018` and `delivery_address_details=12 Mabini Street` with `delivery_destination=Sagay City`. Laravel saves `12 Mabini Street, Poblacion I, Sagay City, Negros Occidental`; client-supplied `delivery_address` is ignored in structured mode. Mismatched barangays return 422 `errors.delivery_barangay_code`, and invalid/oversized details return 422 `errors.delivery_address_details`. Web delivery always requires the structured fields. See [PSGC data and update instructions](PSGC_DELIVERY_DATA.md).

For pickup, omit all delivery fields. Supported canonical names remain Sagay City, Escalante City, Cadiz City, Toboso, Manapla, Calatrava, Victorias City, E.B. Magalona, San Carlos City, Silay City, Talisay City, and Bacolod City. Do not infer a destination from the address. Missing, unsupported, wrong-case and array destination values receive 422 `errors.delivery_destination`.

The server re-resolves every selected ID through shared transactional order placement, locks/rechecks ownership, quantities, eligibility and stock, snapshots current prices/recipient/fulfillment, and deducts selected inventory atomically. Only purchased cart items are removed; unselected items remain. The cart container is removed only when empty. Missing or empty selection never falls back to full-cart checkout. To purchase all items, explicitly submit all IDs from GET /cart. Selection is temporary client/checkout intent, with no permanent selection flag. New PayMongo online orders use the selected configured Hosted Checkout method; new GCash/Maya manual proof submissions are unavailable while PayMongo is enabled. Payment confirmation is provider-driven for PayMongo and manual for historical wallet payments. Failed stock validation uses 422 `errors.cart` and rolls back the operation.

Do not send client totals, item prices, inventory adjustments or a user ID. There is no idempotency-key contract: a repeated request with already-purchased IDs fails even when unselected items remain; it never purchases the remaining cart. On an uncertain network outcome, inspect history before attempting a new placement; do not assume retries return the original order.

Client fee/base fee/surcharge/profile/preparation/transit/ETA/subtotal/total values are ignored, including nested quotes. Delivery uses the same strict internal placement transaction as web checkout: configured routes create one `awaiting_preparation` Shipment, while regional and historical unconfigured routes wait for manual arrangement confirmation. Pickup saves zero delivery fee and creates no Shipment.

201 response, also the shape for GET /orders/{order} and successful proof replacement:

```json
{
    "data": {
        "id": 1,
        "reference": "BF-000001",
        "created_at": "2026-10-01T08:00:00+00:00",
        "status": {
            "value": "pending",
            "label": "Pending"
        },
        "recipient": {
            "name": "Example Customer",
            "contact_number": "EXAMPLE"
        },
        "fulfillment": {
            "value": "pickup",
            "label": "Pickup",
            "delivery_address": null,
            "delivery_destination": null
        },
        "payment": {
            "method": {
                "value": "cash",
                "label": "Cash (Pay at Pickup)",
                "description": "Pay cash when collecting your products at the store. No advance payment or uploaded proof is required."
            },
            "status": {
                "value": "pending",
                "label": "Pending"
            },
            "proof_submitted": false,
            "notice": "Pay cash when collecting your products at the store. No advance payment or uploaded proof is required. Battlefront confirms payment and product handover together to complete pickup.",
            "rejection": null,
            "can_resubmit_proof": false
        },
        "items": [
            {
                "id": 1,
                "product": {
                    "id": 1,
                    "name": "Example Mouse",
                    "brand": null,
                    "image_url": null
                },
                "quantity": 2,
                "unit_price": "100.00",
                "line_total": "200.00"
            }
        ],
        "item_count": 1,
        "total_quantity": 2,
        "product_subtotal": "200.00",
        "delivery_fee": "0.00",
        "delivery_quote": null,
        "shipment": null,
        "total": "200.00"
    }
}
```

Order item prices/quantities are persisted snapshots; displayed product names/brand/image come from current related product records. `payment.rejection` is null or `{"reason":"customer-facing explanation","note":null}` (note may be string). Use returned `can_resubmit_proof`; no proof path or download URL is exposed.

Detail `fulfillment.delivery_destination` contains the separately selected canonical city/municipality saved on the order. Display it alongside `fulfillment.delivery_address` in submitted details. It remains null for pickup and legacy orders without a saved destination; never infer it from the free-text address or current configuration. Web and API expose the same value, including when shipment data is unavailable. History summaries retain their existing shape.

Detail responses add saved `product_subtotal`, `delivery_fee`, `delivery_quote` and nullable `shipment`; existing `total` remains the final total. Quoted delivery `delivery_quote` contains the quote-entry fields above **except** `eta_anchor_date`, `eta_timezone`, `estimated_delivery_start`, and `estimated_delivery_end`. Its fees, destination, profile, carrier, assumptions and relative ETA come from saved Order/Shipment snapshots, never current configuration. Pickup/legacy unquoted delivery has `delivery_quote: null` and `shipment: null`; unknown legacy `product_subtotal` stays null and original totals remain unchanged. History summaries retain their existing shape. EXT-89's shipment detail contract follows; EXT-90 supplies shared notifications as documented below.

### Manual shipment detail — EXT-89

GET order detail, successful placement and payment-proof replacement share the same nullable `shipment` object. For example, a standard-profile Bacolod shipment whose preparation started on October 8, 2026 in UTC:

```json
{
    "carrier": "lbc",
    "status": { "value": "preparing", "label": "Preparing for shipment" },
    "tracking_reference": null,
    "eta": {
        "anchor_date": "2026-10-08",
        "timezone": "UTC",
        "estimated_delivery_start": "2026-10-10",
        "estimated_delivery_end": "2026-10-11",
        "notice": "Battlefront estimate from the start of preparation; arrival is not guaranteed."
    },
    "timeline": [
        {
            "status": "awaiting_preparation",
            "label": "Awaiting preparation",
            "occurred_at": "2026-10-08T08:00:00+00:00"
        },
        {
            "status": "preparing",
            "label": "Preparing for shipment",
            "occurred_at": "2026-10-08T09:00:00+00:00"
        }
    ],
    "notice": "Shipment status is manually maintained by Battlefront. This is not live LBC or GPS tracking.",
    "history_notice": null
}
```

Forward values are `awaiting_preparation`, `preparing`, `ready_for_dispatch`, `handed_to_lbc`, `in_transit`, `out_for_delivery`, `delivered`. `cancelled` is a separate terminal outcome from eligible order cancellation. Shipment status is independent of order/payment status: awaiting preparation means only a record exists. Prepaid progression requires payment verification and explicit order processing. COD can progress after explicit Processing while payment remains Pending. Delivered completes prepaid orders and records one sale; COD remains Processing with no sale until an administrator manually confirms LBC collection. Cancellation terminates shipment progression and restores eligible order quantities once.

Render the returned status label and `notice`. Read destination/fee components from `delivery_quote`, the detailed address from `fulfillment.delivery_address`, and operational dates from `shipment.eta`. These dates are persisted once at preparation using saved total ETA days; they do not drift when configuration changes or the client refreshes. Before preparation, all four ETA date/timezone fields are null: show the saved relative day range without inventing calendar dates. Treat civil dates in the supplied timezone without converting them through the device timezone. For cancelled shipments, label retained dates as the original estimate.

`tracking_reference` is null until a real value is manually supplied at handoff or later. Display it only when nonempty; Battlefront may correct or clear it without advancing status. Render `timeline` in its returned chronological order, containing only recorded timestamps. Creation is the awaiting-preparation milestone, never proof that packing began. Historical unavailable timestamps remain absent. Previously completed orders may include `history_notice` explaining that delivery milestones were not recorded; show this notice instead of reconstructing dates. Pickup and legacy orders without shipments expose no tracking section.

Only the owning customer can read shipment detail; foreign/missing orders remain 404. Customer bearer tokens cannot mutate shipment milestones, tracking references or payment verification. No shipment mutation endpoint exists under `/api/v1`. Updates occur in the existing web administration workflow. Do not fabricate references, carrier locations, maps or GPS data; there is no live LBC integration, courier booking or notification delivery in EXT-89. The React Native UI remains the separate mobile project's responsibility.

Order status values: pending, processing, completed, cancelled. Payment statuses: pending, verified, rejected. Render server labels: unpaid active COD displays `COD — Awaiting Collection`; delivered unpaid COD has order value `processing` and label `Delivered — awaiting COD confirmation`. Confirmation returns `completed` and `verified`. Proof submission and Delivered alone never verify COD payment.

### GET /orders and GET /orders/{order}

History is customer-owned, paginated at 10. Each `data` entry is:

```json
{
    "id": 1,
    "reference": "BF-000001",
    "created_at": "2026-10-01T08:00:00+00:00",
    "status": {
        "value": "pending",
        "label": "Pending"
    },
    "fulfillment": {
        "value": "pickup",
        "label": "Pickup"
    },
    "payment": {
        "method": {
            "value": "cash",
            "label": "Cash (Pay at Pickup)",
            "description": "Pay cash when collecting your products at the store. No advance payment or uploaded proof is required."
        },
        "status": {
            "value": "pending",
            "label": "Pending"
        }
    },
    "item_count": 1,
    "total_quantity": 2,
    "total": "200.00"
}
```

Detail is customer-owned and uses the full order shape above. Missing/foreign IDs get 404. Owning customers may use the existing cancellation endpoint only while `can_cancel` is true: Pending order/payment, no submitted wallet proof, and no shipment preparation. COD is eligible before Processing. Administrators may cancel only before LBC handoff. Cancellation restores stock once; reading an order or pending COD collection never restores inventory.

COD collection confirmation remains web-administrator-only after Delivered. The assigned handler records collection of the saved order total; this leaves payment pending. The handler separately reconciles full remittance and records its reference before payment becomes Verified, the order completes, and one sale is recorded. Only remittance verification publishes the existing `payment.verified` customer notification. These are manual Battlefront records, not verified LBC settlement. Customers cannot verify/reject COD or upload replacement proof. Failed delivery, uncollected payment, carrier returns, refunds, and return-to-sender are outside this workflow; no automatic paid/completed/cancelled/refunded/returned outcome is inferred.

Cash and Card-at-Store retain internal values `cash` and `card_at_store` and remain pickup-only. Render their returned labels and `description`; card payment uses a physical terminal during pickup, with no online gateway. Both may become Processing while payment remains Pending. Only the web administrator's **Confirm Payment & Complete Pickup** action, with explicit payment-received and product-handover confirmations, verifies payment, completes the pickup, and records one sale atomically. Separate payment verification/rejection and direct completion cannot bypass this action. Existing verified Processing pickups use the same handover action without another payment notification; completed history remains unchanged. Customer cancellation remains limited to eligible Pending orders, and GCash/Maya still require advance verification.

### POST /orders/{order}/cancellation-requests

Authenticated customer bearer endpoint for a Pending GCash/Maya order with submitted payment proof, before shipment preparation (no shipment or `awaiting_preparation`). Payment status may be pending, verified, or rejected. Submit `{}` or an optional `customer_note` string of at most 1,000 characters. Only one request is allowed per order. Foreign/missing orders return 404; ineligible or repeated requests return 422 with Laravel validation errors.

Success returns the existing `{ "data": <order detail> }` envelope. Order detail adds `can_request_cancellation` and nullable `cancellation`; history also includes a compact `cancellation` summary. Keep the existing `can_cancel`/direct-cancel contract unchanged. Do not automatically retry uncertain cancellation submissions: refresh order detail first.

`cancellation.status` contains `{ "value", "label" }`: `requested` / Cancellation requested, `rejected` / Cancellation request rejected, `cancelled` / Cancelled without refund, `refund_processing` / Refund processing, or `refunded` / Refund completed. Render these alongside the unchanged order/payment status values. A rejected request releases the processing hold but cannot be resubmitted. Requests cannot be withdrawn through this contract.

Detail includes `customer_note`, public `decision_note`, `requested_at`, nullable `reviewed_at`, nullable verified `amount_received`, and nullable `refund`. Refund fields are `id`, `approved_amount`, `confirmed_paid_amount`, `outstanding_amount`, `unrecorded_amount`, and confirmed `payments` (`id`, `amount`, `payment_method`, ISO `paid_at`, nullable `proof` with `id` and protected bearer `url`). Amounts are decimal PHP strings. These payments remain inside `cancellation.refund.payments`; existing physical-return `refund_payments` retains its meaning.

While review is pending, normal handling/payment decisions and replacement proof uploads are paused; `payment.can_resubmit_proof` is false. Administrators check actual wallet receipt, then cancel without refund when nothing was received, or owe the verified amount capped at the saved order total including delivery. Refunds are actual manual GCash/Maya transfers confirmed by the original approver with proof; approval alone does not complete payment. Existing `GET /orders/{order}/refund-payments/{payment}/proof/{evidence}` serves confirmed cancellation proof with ownership checks and private/no-store headers. No mobile administration, payment verification, or refund mutation endpoint is added.

### POST /orders/{order}/payment-proof

Multipart with one required `payment_proof` file; same image/extension/size rules as initial upload. This replaces evidence for an owned **GCash/Maya order with rejected payment**, provided the order is neither completed nor cancelled.

200 returns full order detail, resets payment status to pending and clears rejection feedback. Order status/inventory stay unchanged. Other payment states/methods/terminal orders return 422 `errors.payment_proof`; valid uploads against foreign/missing orders return 404.

There is no separate initial-proof upload endpoint: initial wallet proof belongs to POST /orders.

## Shared notifications and Expo push — EXT-90

Notification history/read state belongs to the authenticated account and is shared by web and mobile. All endpoints below require a valid customer bearer token. Missing/invalid/expired/revoked tokens return 401; administrator tokens return 403. Foreign notification/device IDs return 404. Browser sessions never authenticate these API endpoints.

The web bell separately refreshes its summary every 30 seconds while its tab is visible using session-only web routes; hidden tabs pause and requests cannot overlap. It updates only the notification summary prop without visiting/reloading the current page or refreshing order/form data. The mobile endpoints below are unchanged: mobile should fetch its own history/count when appropriate for its screen lifecycle and Expo events.

### History, unread count and read actions

`GET /notifications?page=1` returns 10 entries per page, newest creation time then UUID first, using Laravel's `data/links/meta` pagination envelope and `meta.unread_count`. IDs are notification UUID strings, not integers. One illustrative item is:

```json
{
    "id": "12345678-1234-4234-8234-123456789abc",
    "event": "shipment.in_transit",
    "title": "Shipment in transit",
    "body": "There is an update for order BF-000001. Open your order for details.",
    "occurred_at": "2026-10-08T10:00:00+00:00",
    "created_at": "2026-10-08T10:00:00+00:00",
    "read_at": null,
    "is_read": false,
    "order": {
        "id": 1,
        "reference": "BF-000001",
        "web_url": "<backend-origin>/orders/1",
        "api_url": "<backend-origin>/api/v1/orders/1",
        "deep_link": { "screen": "order_detail", "order_id": 1 }
    }
}
```

`order` is nullable when an owned order cannot be resolved. Do not render a destination from an absent order. Deep-link metadata is a screen identifier plus resource ID, not an application URI or authentication credential. The separate Expo client maps `order_detail` to its order screen and fetches `GET /orders/{order_id}` with its current bearer token; existing ownership checks still apply. Reset account-specific UI on logout/account change.

`GET /notifications/unread-count` returns `{"data":{"unread_count":2}}`.
`PATCH /notifications/{notification}/read` accepts no body and returns the updated item under `data`, with `meta.unread_count`.
`PATCH /notifications/read-all` accepts no body and returns `{"data":{"unread_count":0}}` (fresh concurrent events can increase that count). Read operations are idempotent; individual retries preserve the original read timestamp.

Customer events: `payment.verified`, `payment.rejected`, `order.cancelled`, and `shipment.preparing/ready_for_dispatch/handed_to_lbc/in_transit/out_for_delivery/delivered`. Proof paths, payment rejection notes and personal addresses are absent; read the authorized order detail for available feedback. Initial shipment creation and reference corrections generate no notice, and current ETA cannot be revised. Administrator operational notifications remain web-only.

### Device registration and revocation

Generate and retain a device-installation UUID in the mobile app; use it as `{device}`. `PUT /push-devices/{device}` accepts:

```json
{
    "expo_push_token": "ExpoPushToken[client_obtained_token]",
    "platform": "android",
    "notification_channel": "order-updates-v2"
}
```

`platform` is required and is `android` or `ios`. `notification_channel` is optional; updated Android clients send `order-updates-v2` after creating that channel. Older clients omit it and continue using the default channel. Tokens must use a nonempty `ExpoPushToken[...]` or `ExponentPushToken[...]` format and be at most 255 characters. Use Expo's actual project/device token; do not substitute an FCM/APNs token or bearer token. No provider credentials are passed by the client. Invalid fields return 422; malformed device UUID routes return 404.

Registration returns `{"data":{"id":1,"device_id":"<installation-uuid>","platform":"android","is_active":true,"updated_at":"<ISO timestamp>"}}`. Account/session ownership comes from bearer authentication, never submitted IDs. Raw push tokens and session identifiers are not returned. Repeated identical registration is idempotent; changed token/session creates a new registration version.

Multiple devices are supported. An active token already registered to a different account/device returns 422; do not silently transfer it. Revoke the previous registration or sign out before switching accounts. Ineligible expired/revoked registrations can be deactivated by server cleanup when encountered, permitting a fresh authenticated registration.

`DELETE /push-devices/{device}` deactivates the caller's registration and clears its stored token; repeated revocation returns 204 without a body. Unknown/foreign registrations return 404. Mobile logout disables registrations belonging to the presented session and revokes that session only. Other valid device sessions remain eligible. Expiry/revocation is also rechecked before queued sending. Sign in and PUT the device registration again to resume push; tokens never authenticate API requests.

### Push behavior, backend setup and limitations

Set `EXPO_PUSH_ENABLED=true` only after the separate Expo project has working platform credentials and physical-device push setup. `EXPO_PUSH_ACCESS_TOKEN` is optional server-side configuration for Expo enhanced push security; keep it outside source control and client responses. Run:

```shell
php artisan migrate --no-interaction
php artisan queue:work database --queue=notifications,default --timeout=30 --tries=3
```

The migrations add Laravel history, device registrations and delivery records; there is no historical backfill. Database history persists immediately after business commit without waiting for the worker. Push and persistence retries explicitly use the asynchronous database connection even when the application's default queue is sync. Workers must process delayed jobs. Rebuild deployment event caches and restart workers after deploying notification classes.

Push text is generic: “An update is available. Open Battlefront to view your order.” Its `data` contains `notification_id`, `order_id` and `deep_link`; it contains no proof URLs, addresses, amounts or personal/payment details. Expo's `channelId` is sent only to Android registrations that advertised the new channel. Client receipt/tapping a push must not mutate order/payment/shipment business state. Refresh history/count when opening the app and after read actions; push availability does not establish read state.

The adapter checks send tickets and receipts about 15 minutes later; absent receipts are rechecked within 24 hours of acceptance. Confirmed `DeviceNotRegistered` deactivates the matching registration; old feedback cannot disable a refreshed device. Connection/429/5xx/malformed-response failures use bounded retries. Other provider errors retain tokens unless confirmed unusable. Inspect sanitized notification diagnostics and failed queue jobs for recovery; do not log raw credentials or provider bodies.

Push is best effort: provider acceptance is not device delivery, and uncertain network/process failures can cause missing/duplicate push. An in-flight push cannot be recalled by logout. Enqueue failure can leave delivery/receipt work pending. After-commit persistence retries have no outbox: a crash between commit/history persistence, or simultaneous persistence/queue failure, can leave a history entry missing. These failures never roll back or fail successful business actions.

No browser Web Push, email/SMS, live LBC API, direct Firebase server integration or React Native UI is included. Real Expo/native-client validation remains pending.

Postman acceptance: list/count, mark one/all read and compare web state; verify foreign IDs and invalid/admin sessions; register two devices, revoke one, and verify session-specific logout. Trigger payment/shipment changes through web administration and refresh customer history. Provider errors/receipt timing require the focused automated fakes or a separately configured real Expo device, not synthetic Postman token examples.

## 7. Chatbot (guest and customer)

POST /chatbot JSON:

```json
{
    "message": "Where is the Sagay store?",
    "context_token": null
}
```

`message`: required nonblank string <=1000. `context_token`: optional nullable string <=16384.

```json
{
    "data": {
        "message": "Please sign in with a customer account to check order status.",
        "source": "fallback",
        "context_token": "<opaque-context-returned-at-runtime>"
    }
}
```

The example response illustrates a guest order question. Actual text depends on the question and authoritative data.

- Public product/store/FAQ questions work without Authorization.
- Personal order questions require a customer bearer token. A guest gets 200/fallback with `Please sign in with a customer account to check order status.`
- Customer questions resolve only owned orders; foreign/missing order references both return `I couldn't find a matching order in your account.`
- `source` is gemini or fallback. Provider failure/timeout/empty output returns the shared safe fallback with **200**.
- Unsupported/open-domain/recommendation questions do not become AI recommendations. Recommendations use their own deterministic endpoint.
- `context_token` is a string or null. Pass the latest one for follow-ups; it expires after 15 minutes. Invalid/tampered/expired context is ignored, not an authentication failure.
- Customer context is bound to the account and bearer token; guest context is scoped to the public API guest flow, not to an individual device/IP. Possession can continue public context but never supplies a customer identity. Guest/customer/web scopes cannot substitute for each other.
- Keep context in current-chat memory and reset it on New chat, login/logout, account or bearer-token change. Never treat it as the auth token.

## 8. Recommendations

### Behavioral feeds (current web/mobile architecture)

`GET /recommendations` is the shared feed for guests and signed-in customers. With no bearer token it returns currently available popular or featured products. With a valid customer bearer token it returns that customer's behavior-driven feed. Invalid supplied credentials return 401; administrator tokens return 403; a browser session does not authenticate an API request.

`GET /recommendations/personalized` is the authenticated-customer form of the same feed. It requires `auth:sanctum` and the customer role. Both endpoints use the same `data` item shape:

```json
{
    "data": [
        {
            "product": {
                "id": 1,
                "name": "Example Graphics Card",
                "description": null,
                "brand": "Example",
                "price": "10000.00",
                "discount_price": null,
                "image_url": null,
                "is_featured": false,
                "category": { "id": 1, "name": "Graphics Cards" },
                "tags": [],
                "inventory": { "status": "in_stock" }
            },
            "effective_price": "10000.00",
            "reasons": [
                {
                    "code": "matched_recent_searches",
                    "value": "Matches a recent catalog search"
                }
            ]
        }
    ]
}
```

Reason codes identify the signal actually used: `matched_recent_searches`, `similar_to_viewed_product`, `bought_with_cart_products`, `bought_with_viewed_products`, `bought_with_purchase_history`, `bought_by_similar_customers`, `spent_time_viewing_product`, `popular_with_customers`, and `featured_fallback`. Reasons are explanatory labels, not a compatibility guarantee. The response checks active product/category state and current positive Sagay inventory each time it is requested.

Search and product-view personalization are enabled by default for authenticated customers. Customers may independently disable these signals through `PATCH /profile`; disabling a signal pauses recording/ranking and retains unexpired history. The global personalization switch pauses all behavioral ranking and recording; re-enabling restores eligible retained signals. Search and view events expire after 90 days. Guests receive only general popular/featured suggestions in the first release.

Catalog search is recorded when the customer requests the first results page; product detail requests record a view; the next feed request uses the current cart and completed purchases. Request the feed again after search, product detail, or cart changes to get fresh results. No batch recomputation is required. Mobile impressions, clicks, dismissals, and wrong reports may use `POST /recommendations/interactions`; events are anonymous and expire after 90 days.

The developer confirms the React Native consumer has already migrated to these behavioral endpoints. Preserve the server order and refresh feeds after deliberate browsing/cart changes. The feeds are unpaginated and return at most twelve unique products; an empty result is `data: []`. Disabling personalization returns general popular/featured suggestions. Completed purchases, cart products and recent view anchors are excluded from personalized results.

`POST /recommendations/interactions` accepts `event_id` (UUID), `product_id`, `event_type` (`impression`, `click`, `dismiss`, `report_wrong`), `placement` (`home`, `product`, `cart`, `recommendations`), `position` (1?12) and nullable supported `reason_code`. A successful submission returns 204; invalid fields return 422, inactive-category/missing catalog identities return 404, invalid credentials return 401 and administrators receive 403. Reusing a UUID does not alter the original event. These are anonymous client-reported events, grouped in administrator reports by primary displayed reason; they do not identify customers, train the engine or prove sales conversion. The shared 60/minute/IP API limiter applies.

## 9. Postman setup and manual acceptance run

The collection uses the [Postman v2.1 JSON format](https://schema.postman.com/) and [environment scripting](https://learning.postman.com/docs/use/send-requests/variables/environment-variables). No generator package is required.

### Import and variables

1. Import both companion JSON files into the Postman app.
2. Select **Battlefront API v1 — local placeholders** as the active environment.
3. Set local values. Do not export/commit populated secrets or personal data. Sensitive variables are marked secret, but that is not a substitute for keeping exports clean.
4. Select individual requests or a prepared subset. **Do not blindly run every folder**: registration, login, logout, alternative order placements, uploads and negative checks have different prerequisites.

| Variables                                                 | Configuration                                                                                                                                        |
| --------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| base_url                                                  | Reachable API root, ending /api/v1; blank in export                                                                                                  |
| email, password, name, device_name                        | Your disposable local customer; credentials blank in export                                                                                          |
| token                                                     | Automatically captured only after verified login; protected requests inherit it                                                                      |
| verification_token                                        | Automatically captured after registration or an unverified valid login; restricted, temporary, never a bearer token                                  |
| verification_code                                         | Manually entered from an authorized test inbox, kept local and cleared after verification; never export populated values                             |
| product_id, category_id, tag_id, brand                    | Select actual values from catalog/detail/filter responses                                                                                            |
| category_id_2                                             | Another real active category ID for the optional category_ids[1] query entry; leave singular category_id disabled when enabling category_ids entries |
| min_price, max_price, sort                                | Optional catalog bounds and featured/price_asc/price_desc ordering; bounds start blank and query entries are disabled                                |
| cart_item_id                                              | Captured after Add product; manually selectable from own cart                                                                                        |
| order_id, order_reference                                 | Captured after placement; manually selectable from history                                                                                           |
| rejected_order_id                                         | Owned wallet order prepared with rejected payment in web administration                                                                              |
| quantity, page, search                                    | Representative nonsecret defaults where useful; optional catalog filters initially disabled                                                          |
| recipient_name, contact_number, delivery_address          | Locally supplied test checkout/profile values                                                                                                        |
| delivery_destination                                      | Configured destination alias or full structured city code; defaults to Sagay City for disposable delivery tests                                      |
| chatbot_message, follow_up_message                        | Representative public questions provided                                                                                                             |
| context_token, guest_context_token                        | Separate customer/guest continuation values captured by chatbot scripts                                                                              |
| other_cart_item_id, other_order_id, other_order_reference | Another disposable customer's resources, for ownership checks                                                                                        |
| admin_token                                               | Local administrator test token supplied out of band; mobile login cannot issue it                                                                    |
| invalid_token                                             | Enter any deliberately invalid string locally                                                                                                        |
| revoked_token                                             | Captured locally at successful logout for a revocation check                                                                                         |
| expired_token                                             | A genuinely expired local test token supplied out of band                                                                                            |

No file path is committed. Select a file in each multipart request after import. IDs start blank so the collection cannot silently assume database IDs. Raw JSON scripts serialize environment values to preserve quotes/backslashes in names/passwords; edit the script template if changing the represented body. Requests never log tokens.

Verified login replaces `token`, clears verification/conversation state and clears selected cart/order IDs. Registration or an unverified valid login clears the bearer token and captures only `verification_token`; enter the inbox code locally, verify, and log in afterward. Logout captures the old token in `revoked_token` for the negative check, then clears `token` and both context values. Remove `revoked_token` after checking. Guest and OTP requests have explicit No Auth, even when a customer token is populated.

### Required manual sequence

Use a disposable development database/account with an active product, live Sagay stock, and seeded branch/reference data.

1. **Connectivity/public reads:** Health (200), product filters/list/detail and branches (200). Enable catalog filters individually, then combine search/brand/tag with multiple categories and price bounds. Disable singular `category_id` when using `category_ids` entries. Check discount-aware price sorting in both directions and follow `links.next` to confirm filters persist. Try conflicting category inputs, invalid category IDs, reversed bounds, and an invalid sort (422); restore valid params afterward. Check nullable fields and compare the existing web catalog behavior separately.
2. **Access:** Profile without token (401). Guest behavioral feed (200); authenticated personalized feed without a token (401). Guest chatbot public question/follow-up (200). Guest order question returns sign-in fallback. Pace calls under guest limits.
3. **Authentication/profile:** With separate authorization for real email delivery, register a unique customer (201), confirm no bearer token, manually enter the inbox code in `verification_code`, verify (200), and log in (200) to capture a token. Alternatively, log in with a trusted already verified demo account without sending email. Valid credentials for an existing unverified account return 403 plus verification context; invalid credentials return 401. Verify resend cooldown (429), replacement code, invalid/expired code (422), and one-time verification. Read/update profile (200), verify only approved fields. Duplicate/invalid registration gives 422. Never run this SMTP sequence automatically or against an actual inbox without authorization.
4. **Cart:** Add stock-eligible product (200), verify captured cart_item_id; update quantity and totals; remove (200). Add again before checkout. Try quantity zero, quantity above stock, and an unavailable product (422); failed operations must not corrupt the cart.
5. **Checkout/orders:** Select one, multiple, or all owned cart-item IDs and include them in both preview and placement. Preview checkout (200), verify the 12 legacy quotes, then request a live estimate for a Negros Occidental code such as City of Bago (`1804502000`) and validate its barangays. Check standard/fragile/bulky and mixed carts, subtotal/fee/final total, demo/proxy wording and provisional calendar windows. Verify an unconfigured destination quote has `pricing_configured=false`, `order_placement_allowed=true`, GCash/Maya/COD eligibility, no carrier/ETA, and permits product-subtotal-only placement without an automatic Shipment. Submit cash/card pickup (201, zero fee/no Shipment); omit address and destination. Refill before each wallet placement, select an image, and include a configured or valid unconfigured destination code plus detailed address for delivery. Try missing/unsupported/wrong-case/array destinations, pickup destination/address, and forged quote fields. Verify recalculated configured fees/relative ETA, unconfigured no-fee snapshots, pending order/payment, selected-item removal, unselected-item retention and selected inventory deduction through existing web administration. Confirm no selection, duplicates, foreign/missing/removed IDs and stale selected stock fail without changes. Leave bulky/fragile products unselected and verify their handling/ETA do not affect the order.
6. **Replacement proof:** Reject the test wallet payment through existing web administration. Set rejected_order_id, select a replacement image, submit (200). Verify pending payment, cleared rejection, unchanged order status/stock. Retry while payment is pending (422). Test unsupported file type and >5 MB (422 when Laravel handles it).
   **Shipment tracking:** Using a delivery test order, verify that pending payment/order offers no shipment progression. Manually verify payment, move the order to Processing, and advance each shipment milestone separately through web administration. After each action compare customer web detail and GET /orders/{order}: status, saved ETA/fees/destination and chronological timestamps must agree. Leave reference blank unless a real value is available. Confirm Delivered completes the order with one sale; use a second eligible order to verify cancellation terminates shipment and restores stock once. Verify pickup/legacy orders have no shipment UI and a second customer's token receives 404. EXT-90 now supplies customer milestone notifications; no map or live carrier data is expected.
7. **Customer chatbot/recommendations:** Ask about the captured own order reference. Check customer follow-up. Search the catalog, view a product, change the cart, and request the new feed after each action. Confirm personal reason codes, pause/history retention and re-enabling through `PATCH /profile`, current stock, and the shared response shape. Verify current reason codes, anonymous feedback, expired-history exclusion and prefetch suppression.
8. **Ownership/roles:** Prepare a second customer's cart/order and use their IDs under the first token: 404. Foreign order chatbot: safe 200 fallback. With an out-of-band valid administrator test token: profile/chatbot/recommendations 403. Invalid/expired/revoked credentials: 401. Web session alone is insufficient for personal API data.
9. **Rate limits:** After the window resets, send five guest unsupported chatbot questions (e.g. Tell me a joke.), then the dedicated sixth-request check expects 429/Retry-After. Customer limit is ten across web/devices. Registration/login have five-request limits; global limit is 60/IP. Perform these separately to avoid unrelated limits masking results.
10. **Logout last:** 204 empty body; confirm environment token cleared. Run Profile with revoked token (401). A separate device token remains valid.
11. Record date, environment, request names/statuses, assertions and any failures in your manual verification evidence. Automated tests and curl checks do **not** satisfy the manual Postman acceptance criterion.

Provider fallback and expiration scenarios are deterministically covered by existing tests. Do not trigger a live provider outage to manufacture evidence; mark manual cases requiring fixtures/configuration as pending if prerequisites are unavailable.

### Verification status

Historical EXT-62 verification: 275 existing API tests passed (1,732 assertions). At that time, both JSON files parsed, all 22 then-registered endpoints had collection coverage, all 33 environment references resolved, and request scripts compiled with JSON body scripts handling quotes/backslashes. Twelve local HTTP smoke checks passed, covering public reads, guest recommendations/chatbot, missing/invalid authentication, and catalog validation. No live customer/order data was mutated by those HTTP checks.

Historical PR verification reported 126 focused tests (1,051 assertions); it does not establish the corrected head's results. The 2026-10-08 EXT-63 backend audit inspected Laravel revision `75d920dd39123827e91c0eac2bf39fea14cce78b` and its working-tree tests/documentation: that audit snapshot had 28 endpoints and 61 Postman requests. Its saved order examples include `fulfillment.delivery_destination` and nullable `shipment`, matching Laravel. The corrected API registers 29 routes, including behavior-driven recommendation feeds/interactions and EXT-90 notification/device routes. Current checks and pending native/device scenarios are recorded in [integration validation](MOBILE_INTEGRATION_VALIDATION.md); historical test results above are not current test counts. Laravel feature upload tests exercise parsed files/form data, not the actual React Native FormData boundary/URI transport. No actual consumer run is claimed.

The developer subsequently reported completing manual tests in Postman Desktop with no API issues and approved EXT-62. The execution date, individual run results, and screenshots were not supplied to the coding agent. This is developer-reported manual verification, not an agent-executed Postman run. Keep the sequence above for repeat runs. JSON/structural checks are not full external-schema validation. No generator or validator dependency was installed. Actual React Native consumer validation is tracked separately in [EXT-63](MOBILE_INTEGRATION_VALIDATION.md).

## 10. Source mapping and known limitations

- Routing/access: `routes/api.php`, `AuthorizeApiChatbot`, `AuthorizeApiRecommendations`, `AppServiceProvider`, `FortifyServiceProvider`, `config/sanctum.php`, `bootstrap/app.php`.
- Fields/validation: `app/Http/Requests`, shared profile/password concerns, API AuthController.
- Response shapes: `app/Http/Resources/Api/V1`, `CatalogProductPresenter`, `CustomerOrderPresenter`, `ShipmentPresenter`, `BuildCartViewData`, `PrepareCheckout`.
- Domain rules: `CartService`, `OrderPlacementService`, `OrderProcessingService`, `ResubmitPaymentProof`, `ProductCatalogRepository`, `BehavioralRecommendationEngine`, shared chatbot services.
- Contract tests: `tests/Feature/ApiFoundationTest.php`, `MobileAuthenticationTest.php`, `MobileProfileTest.php`, `MobileCatalogTest.php`, `MobileBranchTest.php`, and `tests/Feature/Api/V1`.

Known implementation details are documented, not changed: registration's validated-but-unsaved address; different page validation between products/orders; stateless guest context is not per-device identity; private proof has no mobile download endpoint.

The behavior-driven recommendation update adds the public recommendation feed and anonymous aggregate interaction endpoint and retires the superseded input/options endpoints after developer-confirmed mobile migration. EXT-89 exposes manual shipment facts through existing order detail endpoints; EXT-90 adds notification history/read-state and Expo device registration/revocation endpoints. No mobile password reset/change, token refresh, admin operations, payment gateways, live courier/GPS tracking, or compatibility checking are introduced.

## 11. Customer return/refund requests

These endpoints share Laravel business rules and presentation with customer web order details. They require a valid customer Sanctum bearer token; browser sessions alone do not authenticate them. Administration and physical receipt remain web-only. Paths below are relative to `/api/v1`.

| Method | Path                                                       | Success                                       |
| ------ | ---------------------------------------------------------- | --------------------------------------------- |
| GET    | `/orders/{order}/return-requests`                          | 200 `{"data":[...]}`                          |
| POST   | `/orders/{order}/return-requests`                          | 201 `{"data":{...}}`                          |
| GET    | `/orders/{order}/return-requests/{returnRequest}`          | 200 `{"data":{...}}`                          |
| POST   | `/orders/{order}/return-requests/{returnRequest}/withdraw` | 200 updated `{"data":{...}}`; no request body |

Creation payload (one order item per request):

```json
{
    "order_item_id": 81,
    "quantity": 1,
    "reason_code": "defective",
    "reason": null
}
```

Use purchased **order item IDs**, not product IDs. `quantity` must be a positive integer within the server's remaining eligible purchased quantity. The order must belong to the customer, be completed, and have a recorded sale. A second open customer request for an item is blocked while awaiting receipt or review. Physical returns and outstanding requests both reserve purchased quantities. Withdrawn requests remain in history and release their reservation; received requests cannot be withdrawn or received twice.

Reason codes/labels are `wrong_item` (Wrong item), `defective` (Defective), `transit_damage` (Damaged in transit), `not_as_described` (Not as described), `changed_mind` (Changed mind), and `other` (Other). `reason` is the customer's additional notes: optional/null for predefined reasons, required for Other, and 5–2000 characters when supplied. Blank predefined notes normalize to null. No condition, refund amount, ownership, review status, or receipt fields are accepted as trusted customer input.

Representative create/detail response:

```json
{
    "data": {
        "id": 12,
        "order_id": 7,
        "order_reference": "BF-000007",
        "order_item_id": 81,
        "product": { "id": 42, "name": "Purchased monitor" },
        "quantity": 1,
        "reason_code": "defective",
        "reason_label": "Defective",
        "reason": null,
        "submitted_at": "2026-10-23T08:00:00+00:00",
        "withdrawn_at": null,
        "received_at": null,
        "status": {
            "value": "submitted",
            "label": "Request submitted / Awaiting item receipt"
        },
        "can_withdraw": true,
        "feedback": null,
        "refund": null
    }
}
```

An empty list returns `{"data":[]}`. Lists are unpaginated, newest request first. `GET /orders/{order}` adds `can_request_return`, `return_reason_options` (code-to-label object), and `return_requests` containing the same resources. Each purchased item adds `remaining_returnable` and `can_request`; respect both because an open request can block new submission even with remaining units. Existing order/payment status values remain unchanged.

| `status.value`      | Label / meaning                                                                   |
| ------------------- | --------------------------------------------------------------------------------- |
| `submitted`         | Request submitted / Awaiting item receipt; no physical return or inventory change |
| `withdrawn`         | Withdrawn; history retained                                                       |
| `under_review`      | Item received / Under review; Staff has recorded actual receipt                   |
| `approved`          | Approved; no positive approved refund exists                                      |
| `rejected`          | Rejected; safe feedback distinguishes awaiting collection from handed back        |
| `refund_processing` | Refund processing; positive refund approved, not fully confirmed                  |
| `refunded`          | Refund completed; confirmed payment records cover the positive approved refund    |

For positive approved refunds, `refund` has decimal-string peso amounts. Example partial progress:

```json
{
    "status": { "value": "refund_processing", "label": "Refund processing" },
    "can_withdraw": false,
    "feedback": "Your return has been approved.",
    "refund": {
        "approved_amount": "100.00",
        "verified_paid_amount": "40.00",
        "remaining_amount": "60.00"
    }
}
```

On full payment confirmation the status becomes `{"value":"refunded","label":"Refund completed"}` and `remaining_amount` becomes `"0.00"`. New payments are confirmed by the Manager or Owner who approved the return; a second administrator is not required. Historical unreviewed, disputed, or voided payments do not establish completion. Approval alone does not pay a refund. Internal notes, employee identities, storage paths, audit records, and payment references are excluded.

Errors retain native Laravel envelopes: missing/invalid bearer token **401**, administrator identity **403**, foreign/missing orders/items/requests **404**, invalid input or lifecycle/quantity conflict **422**, existing API rate limit **429**. For example, a repeated request returns:

```json
{
    "message": "This item already has an open return request.",
    "errors": {
        "order_item_id": ["This item already has an open return request."]
    }
}
```

Render field errors instead of parsing the summary message. Repeated withdrawal/receipt conflicts use `errors.return_request`. This API has no idempotency-key contract: after an uncertain response, retrieve request history before retrying.

The separate Expo repository must add the completed-order action, single-item form, quantity bounds, optional/Other notes, confirmation before submission, history/status, and pre-receipt withdrawal confirmation. Disable repeated taps while requests are in flight and refresh order details after mutations. Display manual-review notices and the server-supplied proposed/approved eligibility metadata described below, without adding approval promises, pickup scheduling, or refund guarantees. Native UI and device/LAN integration were not implemented in this Laravel repository.

### Return workflow refinements

The endpoints and creation payload above remain unchanged. Order detail now additionally supplies `return_eligibility`:

```json
{
    "received_at": "2026-10-23T06:30:00+00:00",
    "receipt_source": "delivery",
    "deadline_at": "2026-10-30T06:30:00+00:00",
    "window_days": 7,
    "window_approved": false,
    "policy_status": "proposed",
    "deadline_passed": false,
    "remaining_seconds": 604800,
    "timezone": "Asia/Manila",
    "reasons": {
        "changed_mind": { "can_request": true, "manual_review": true },
        "defective": { "can_request": true, "manual_review": true }
    }
}
```

The example shows two reason entries; the actual map includes all existing reason codes. Eligibility still requires the owning customer's completed order with a recorded sale, remaining units, and no open request. Each item additionally supplies `purchased_quantity`, `returned_quantity` (physically received/accepted or awaiting rejected-item handback), and `pending_request_quantity`; `remaining_returnable` retains its quantity-balance meaning.

`received_at` here means the customer's **original purchase receipt**: recorded delivery or actual pickup handover. A missing receipt yields null receipt/deadline/remaining seconds and requires manual confirmation. Display times in the supplied business timezone. The proposed seven-day duration means exactly 168 elapsed hours; equality at the deadline is allowed.

The configurable policy remains disabled until Battlefront approval (`BATTLEFRONT_RETURN_WINDOW_APPROVED=false`; duration `BATTLEFRONT_RETURN_WINDOW_DAYS=7`). While proposed, deadlines are informational and must be labeled proposed. Once approved, only `changed_mind` expires automatically; other existing reasons, including defect or warranty concerns explained through Other, use manual assessment without guarantees of entitlement. An expired Changed mind submission returns native 422 `errors.reason_code`. Do not turn this into a blanket rejection of defect/remedy concerns.

Requests snapshot `eligibility_path` (`discretionary`, `manual_review`, or null for older records), `eligibility_receipt_at`, and `eligibility_deadline_at`. A request submitted legitimately remains valid for receipt/review after expiry; later requests use the original purchase receipt/deadline. Withdrawal remains available only before physical return receipt.

Request resources now add `return_decision` (`pending`, `accepted`, `rejected`) and nullable `resolution`. Existing top-level status values are preserved; `approved` is labeled **Return accepted**. A repair example:

```json
{
    "return_decision": "accepted",
    "status": { "value": "approved", "label": "Return accepted" },
    "resolution": {
        "type": "repair",
        "status": "pending",
        "summary": "Repair and return the original hardware",
        "completed_at": null
    },
    "refund": null
}
```

Resolution types are `refund`, `replacement`, `repair`, `other`, or `unknown` for an accepted legacy outcome without an authoritative resolution/refund. Nonmonetary resolutions remain pending until authorized actual fulfillment; completion returns `status=completed` and its recorded timestamp. Their private references and completion notes are excluded. Legacy unknown outcomes are not reconstructed or marked fulfilled.

A monetary resolution stays pending until sufficient confirmed payments; its completion timestamp is null because completion is derived from payment reviews. Existing positive legacy refund records remain authoritative without creating new historical resolution records. `refund` retains approved/verified/remaining amounts and `payment_recorded` (boolean), and adds `confirmed_paid_amount` and `payments` as described below. Display **Return accepted**, **Refund approved**, payment progress, and completion distinctly. The overall refund status stays `refund_processing` until confirmed payments cover the approved amount. Customer feedback comes from explicitly public review feedback or a generated safe explanation, never internal inspection notes.

### Confirmed refund payments and customer proof

The original approving Manager OR Owner confirms each new actual payment; no second administrator is required. Noncash payments require one image snapshot, while cash requires confirmation of actual handover and no snapshot. These changes are administration web-only. Historical payment reviews remain unchanged; old pending payments are not automatically confirmed.

`GET /orders/{order}` adds `data.refund_payments`, including confirmed payments for Staff-assisted returns without a customer request. Each request's `refund.payments` contains the same entries for that return. `refund.confirmed_paid_amount` is the confirmed total; `verified_paid_amount` remains a compatibility alias for that total. Example:

```json
{
    "confirmed_paid_amount": "40.00",
    "verified_paid_amount": "40.00",
    "payments": [
        {
            "id": 23,
            "amount": "40.00",
            "payment_method": "gcash",
            "paid_at": "2026-10-23T08:30:00+00:00",
            "confirmation_method": "approver",
            "proof": {
                "id": 41,
                "url": "/api/v1/orders/7/refund-payments/23/proof/41"
            }
        }
    ]
}
```

`confirmation_method` is `approver` for new confirmed payments or `legacy_review` for historical verified payments. `proof` is null for cash, legacy/private attachments, or an unavailable file. Never infer that a missing proof means another payment is due.

`GET /api/v1/orders/{order}/refund-payments/{payment}/proof/{evidence}` streams JPEG/PNG/WebP image bytes, not a JSON envelope. Send the customer's Sanctum bearer token in the Authorization header; never put tokens in URLs. The response is private with `Cache-Control: private, no-store`. Missing authentication returns 401; foreign, unavailable, or unshareable proof returns 404. Only explicitly shared new payment proof is accessible; inspection photos and historical private evidence remain private.

When confirmed payments cover the approved refund, the existing notification feed receives `refund.completed` with an order-details link. The separate Expo client needs a confirmed-payment list and authenticated image viewing on that order page. Retain the existing partial-payment status until the remaining balance is zero.

Existing request `received_at` continues to mean **Battlefront received the returned item**, distinct from the original purchase receipt in `return_eligibility`. Native clients should use the added metadata for reason availability, remaining quantity, proposed/active deadline wording, and nonmonetary resolution progress. Administration, evidence, review, actual fulfillment, and payment verification remain web-only.

### Return instructions and Staff receipt photos

`GET /orders/{order}` additionally returns `data.return_instructions`; customer web receives the same object as `order.return_instructions`. Request creation, listing, status and withdrawal payloads/endpoints remain unchanged.

```json
{
    "return_instructions": {
        "label": "Return instructions",
        "branch": {
            "name": "Battlefront Computer Trading",
            "city": "Sagay City",
            "address": "A, E Marañon St., Brgy. Poblacion II, Sagay City, Negros Occidental (beside LBC Sagay City), Sagay, Philippines 6122",
            "contact_number": "0938 647 6046"
        },
        "message": "Pack securely, including opened items, and include your order/request number. Use original packaging if available. Contact us before courier shipping (e.g. LBC) to confirm delivery and charges. Returns require inspection and approval."
    }
}
```

The destination uses the current published operational branch record; do not hardcode this example in Expo. `branch` is null when no published operational branch with an address is available. Display **Contact Battlefront to confirm the return address.** in that case. A missing phone is null and should be hidden. The destination is Battlefront's store; “beside LBC” is its landmark. These instructions do not assign courier charges, guarantee approval, or make original packaging mandatory.

Display the compact notice in the return form and once in the order's return section while a request awaits physical receipt. Staff now supplies mandatory product photos when recording actual receipt through the web application. Customers do not upload these Staff inspection photos, and the mobile API does not receive or expose their private paths. Manager/Owner views product evidence read-only; refund-payment proof retains its separate recording and verification workflow. Expo should render this added metadata; the native interface remains work for the separate repository.

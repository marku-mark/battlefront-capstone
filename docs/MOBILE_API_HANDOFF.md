# React Native API handoff — EXT-62

This document describes the implemented customer REST API, including guest chatbot access. Laravel owns all catalog, cart, checkout, order, recommendation, and chatbot business rules. React Native + Expo is a separate client maintained and run by the mobile group member. Administration remains on the web.

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

| Scope            | Limit                                                                 |
| ---------------- | --------------------------------------------------------------------- |
| API v1           | 60 requests/minute/IP across the version group                        |
| Registration     | Additional 5/minute/IP                                                |
| Login            | Additional 5/minute/lowercased email + IP, shared named login limiter |
| Chatbot guest    | Additional 5/minute/IP, shared with web guests                        |
| Chatbot customer | Additional 10/minute/customer, shared across tokens/devices and web   |

The chatbot-specific 429 message is `Too many questions. Please wait a minute and try again.` The general API limit can be reached first. Respect `Retry-After`. Limits count requests, not only failed attempts. Users behind one LAN can share the IP allowance.

## 2. Authentication and access

Bearer header: `Authorization: Bearer <token>`. No session login, CSRF-cookie request, or Fortify flow is needed for the mobile API. Fortify remains the web authentication flow.

Registration/login create a new Sanctum personal access token with a **30-day per-token expiration**. The response includes `expires_at`; store the raw token securely on the device. Tokens are only returned at creation/login. There is no refresh endpoint or token listing endpoint. Login again after expiry. Multiple devices can have separate tokens; logout deletes only the token presented for that request.

### Complete endpoint inventory

All paths below are relative to `base_url`. GET routes also support HEAD through Laravel.

| Method | Path                               | Access         | Success |
| ------ | ---------------------------------- | -------------- | ------- |
| GET    | /health                            | Public         | 200     |
| POST   | /auth/register                     | Public         | 201     |
| POST   | /auth/login                        | Public         | 200     |
| POST   | /auth/logout                       | Customer       | 204     |
| GET    | /profile                           | Customer       | 200     |
| PATCH  | /profile                           | Customer       | 200     |
| GET    | /products                          | Public         | 200     |
| GET    | /products/filters                  | Public         | 200     |
| GET    | /products/{product}                | Public         | 200     |
| GET    | /branches                          | Public         | 200     |
| GET    | /cart                              | Customer       | 200     |
| POST   | /cart/items                        | Customer       | 200     |
| PATCH  | /cart/items/{cartItem}             | Customer       | 200     |
| DELETE | /cart/items/{cartItem}             | Customer       | 200     |
| GET    | /checkout                          | Customer       | 200     |
| POST   | /orders                            | Customer       | 201     |
| GET    | /orders                            | Customer       | 200     |
| GET    | /orders/{order}                    | Customer       | 200     |
| POST   | /orders/{order}/payment-proof      | Customer       | 200     |
| GET    | /notifications                     | Customer       | 200     |
| GET    | /notifications/unread-count        | Customer       | 200     |
| PATCH  | /notifications/{notification}/read | Customer       | 200     |
| PATCH  | /notifications/read-all            | Customer       | 200     |
| PUT    | /push-devices/{device}             | Customer       | 200     |
| DELETE | /push-devices/{device}             | Customer       | 204     |
| POST   | /chatbot                           | Guest/customer | 200     |
| GET    | /recommendations                   | Guest/customer | 200     |
| GET    | /recommendations/personalized      | Customer       | 200     |
| POST   | /recommendations/interactions      | Guest/customer | 204     |

Public health/catalog/branches do not authenticate supplied credentials. Registration/login authenticate their submitted fields, not a bearer header.

For **chatbot and the public recommendation feed/interaction routes**: omit Authorization entirely for guests; valid customer tokens are accepted; valid administrator tokens get 403; invalid/expired/revoked/malformed supplied Authorization gets 401. An empty bearer header is not a guest request. Web sessions do not authenticate these API requests.

Customer-only routes require Sanctum plus the customer role gate: missing/invalid/expired/revoked token gets 401; administrator token gets 403. Foreign cart/order IDs are concealed with 404. Chatbot order ownership failures instead use the safe conversational fallback described below. Validation may run before resource lookup, so use otherwise valid input when testing ownership.

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

### POST /auth/login

Required: `email` (valid email string), `password` (string), `device_name` (string, max 255). No password_confirmation. Invalid credentials and administrator credentials both return the same 401 and issue no token.

Both authentication success responses use:

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
        "search_recommendations_enabled": true,
        "product_view_recommendations_enabled": true,
        "personalized_recommendations_enabled": true
    }
}
```

PATCH requires `name` and `email` (same length/email/uniqueness rules, excluding the current user). Optional `default_delivery_address` is nullable/max 255: omit to preserve, send null/blank to clear. New customers have `search_recommendations_enabled` and `product_view_recommendations_enabled` enabled by default, so no profile setup is needed. PATCH can independently disable either preference; turning one off pauses its recording/ranking and retains unexpired history. The global `personalized_recommendations_enabled` switch pauses all personal ranking and new search/view recording; all three preferences default to true. Re-enabling uses retained unexpired history. Retained activity expires after 90 days. Email changes clear the stored email verification timestamp; verification is currently optional/disabled.

```json
{
    "name": "Example Customer",
    "email": "customer@example.test",
    "default_delivery_address": "Example delivery address",
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

These additions apply only to `/api/v1/products`. The Inertia web catalog retains its existing search, singular category/brand/tag filters and default ordering; it ignores these mobile-only parameters. Product response fields and filter-option responses are unchanged. There is no stock, budget, or multi-tag catalog filter. Use `GET /products/filters` to populate selectors:

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
            "is_operational": true
        }
    ]
}
```

`id` integer; `name/city/operating_hours` strings; `is_operational` boolean; address/contact/email nullable strings; latitude/longitude nullable numbers. Other branches provide static reference information, not selectable live inventory pools.

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

Requires an explicit nonempty `cart_item_ids` list of distinct positive integer IDs from the owning customer's cart. Send repeated query parameters, for example `GET /checkout?cart_item_ids[]=12&cart_item_ids[]=15`. Missing/empty/malformed/duplicate selections return 422 `errors.cart_item_ids` or `errors.cart_item_ids.<index>`; foreign/missing/removed IDs and unavailable selected items return 422 `errors.cart` without disclosing ownership. Unselected items are excluded from the snapshot and all quotes, including fragile/bulky handling and ETA, and may remain unavailable without blocking checkout. Returns a current snapshot, payment/fulfillment options, `pickup_quote`, and 12 `delivery_quotes`. No destination query parameter is needed: choose exactly one canonical `destination` from the returned quotes. Existing cart/payment fields are shown below; the new quote fields follow separately:

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
                "label": "Cash",
                "requires_proof": false,
                "payment_account": null,
                "available_for": ["pickup"]
            },
            {
                "value": "card_at_store",
                "label": "Card at store",
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
            }
        ]
    }
}
```

Checkout cart lines omit the ordinary cart's availability and category/price fields; use the shown snapshot shape. `payment_account` is null for cash/card, or an object with account_name/account_number/is_demo for wallets. Display configured values from the response and honor demo labeling; the example account above is synthetic. No transfer is initiated by this API.

`pickup_quote` is `{"product_subtotal":"200.00","delivery_fee":"0.00","total":"200.00"}` for the example cart. Each `delivery_quotes` entry contains the complete server-derived quote for that cart and one destination. Example entry for standard-profile Sagay delivery:

```json
{
    "origin_city": "Sagay City",
    "destination": "Sagay City",
    "is_demo": true,
    "assumption_label": "Battlefront-configured demo delivery assumptions; not official LBC rates.",
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
    "notice": "Battlefront estimates, not live LBC quotations or tracking. Delivery dates are provisional and subject to payment verification."
}
```

Display the selected entry's monetary values; `total` is product subtotal plus delivery fee. Mixed carts use their highest persisted shipping profile (`standard < fragile < bulky`) once, regardless of quantities/line count. Fees and dates are Battlefront-configured demo estimates, not live LBC quotations or tracking. Display carrier `lbc` as **LBC**, the packing expectation, estimate notice and demo assumption label.

Calendar dates are checkout-only presentation. One server quote-generation date in `config('app.timezone')` (currently UTC) anchors all 12 windows in a response. Minimum/maximum total days are preparation plus transit, added as calendar days without weekend/holiday adjustment. Zero transit days means same-day transit once preparation is ready; persisted historical snapshots keep their original ranges. Treat date strings as civil dates in the returned timezone; do not shift them through the device timezone. Arrival is provisional and subject to payment verification. Refresh checkout after cart changes and before paying; placement independently recalculates current prices, profiles and configured fees/relative ETA. Neither preview quotes nor calendar windows are trusted placement inputs.

### POST /orders

Required fields:

| Field                | Rule                                                                                                                 |
| -------------------- | -------------------------------------------------------------------------------------------------------------------- |
| cart_item_ids        | Required nonempty list of distinct positive integer cart-item IDs owned by the customer                              |
| recipient_name       | String <=255                                                                                                         |
| contact_number       | String <=20; preserve formatting as text                                                                             |
| fulfillment_method   | pickup or delivery                                                                                                   |
| payment_method       | cash, card_at_store, gcash, maya                                                                                     |
| delivery_address     | String <=255, required for delivery; prohibited when nonempty for pickup                                             |
| delivery_destination | Exactly one canonical destination from `delivery_quotes`, required for delivery; prohibited when nonempty for pickup |
| payment_proof        | Required for gcash/maya; prohibited for cash/card_at_store                                                           |

Pickup accepts all four payment methods. Delivery accepts only gcash/maya. Submit JSON for cash/card pickup:

```json
{
    "cart_item_ids": [12, 15],
    "recipient_name": "Example Customer",
    "contact_number": "EXAMPLE",
    "fulfillment_method": "pickup",
    "payment_method": "cash"
}
```

For wallet orders submit the fields above using multipart form data with repeated `cart_item_ids[]` text fields (one ID per field), including an actual file part named `payment_proof`. JPEG/JPG, PNG, WebP only; contents must be an image, filename extension must match allowed extensions; max **5120 KB (5 MB)**. Do not send a filesystem path or base64 string as the proof, and do not manually specify a multipart boundary/Content-Type in Postman or React Native FormData.

For delivery, include `delivery_destination` (for example `Sagay City`) and a separate detailed `delivery_address`. For pickup, omit both fields. Supported canonical names are Sagay City, Escalante City, Cadiz City, Toboso, Manapla, Calatrava, Victorias City, E.B. Magalona, San Carlos City, Silay City, Talisay City, and Bacolod City. Do not infer a destination from the address. Missing, unsupported, wrong-case and array destination values receive 422 `errors.delivery_destination`.

The server re-resolves every selected ID through shared transactional order placement, locks/rechecks ownership, quantities, eligibility and stock, snapshots current prices/recipient/fulfillment, and deducts selected inventory atomically. Only purchased cart items are removed; unselected items remain. The cart container is removed only when empty. Missing or empty selection never falls back to full-cart checkout. To purchase all items, explicitly submit all IDs from GET /cart. Selection is temporary client/checkout intent, with no permanent selection flag. Order and payment initially remain pending; payment verification is manual. Failed stock validation uses 422 `errors.cart` and rolls back the operation.

Do not send client totals, item prices, inventory adjustments or a user ID. There is no idempotency-key contract: a repeated request with already-purchased IDs fails even when unselected items remain; it never purchases the remaining cart. On an uncertain network outcome, inspect history before attempting a new placement; do not assume retries return the original order.

Client fee/base fee/surcharge/profile/preparation/transit/ETA/subtotal/total values are ignored, including nested quotes. Delivery uses the same strict internal placement transaction as web checkout: the accepted quote is recalculated and saved with exactly one `awaiting_preparation` Shipment. That initial record does not imply packing or dispatch. Pickup saves zero delivery fee and creates no Shipment.

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
                "label": "Cash"
            },
            "status": {
                "value": "pending",
                "label": "Pending"
            },
            "proof_submitted": false,
            "notice": "Payment will be handled when you collect your order.",
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

Forward values are `awaiting_preparation`, `preparing`, `ready_for_dispatch`, `handed_to_lbc`, `in_transit`, `out_for_delivery`, `delivered`. `cancelled` is a separate terminal outcome from eligible administrator order cancellation. Shipment status is independent of order/payment status: awaiting preparation means only a record exists. Progression starts only after payment verification and explicit order processing. Delivered atomically completes the order and records one sale; cancellation terminates shipment progression and restores eligible order quantities once.

Render the returned status label and `notice`. Read destination/fee components from `delivery_quote`, the detailed address from `fulfillment.delivery_address`, and operational dates from `shipment.eta`. These dates are persisted once at preparation using saved total ETA days; they do not drift when configuration changes or the client refreshes. Before preparation, all four ETA date/timezone fields are null: show the saved relative day range without inventing calendar dates. Treat civil dates in the supplied timezone without converting them through the device timezone. For cancelled shipments, label retained dates as the original estimate.

`tracking_reference` is null until a real value is manually supplied at handoff or later. Display it only when nonempty; Battlefront may correct or clear it without advancing status. Render `timeline` in its returned chronological order, containing only recorded timestamps. Creation is the awaiting-preparation milestone, never proof that packing began. Historical unavailable timestamps remain absent. Previously completed orders may include `history_notice` explaining that delivery milestones were not recorded; show this notice instead of reconstructing dates. Pickup and legacy orders without shipments expose no tracking section.

Only the owning customer can read shipment detail; foreign/missing orders remain 404. Customer bearer tokens cannot mutate shipment milestones, tracking references or payment verification. No shipment mutation endpoint exists under `/api/v1`. Updates occur in the existing web administration workflow. Do not fabricate references, carrier locations, maps or GPS data; there is no live LBC integration, courier booking or notification delivery in EXT-89. The React Native UI remains the separate mobile project's responsibility.

Order status values: pending, processing, completed, cancelled. Labels reflect fulfillment (e.g. Preparing for pickup / Preparing for delivery). Payment statuses: pending, verified, rejected. They are separate state machines; proof submission is not payment verification.

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
            "label": "Cash"
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

Detail is customer-owned and uses the full order shape above. Missing/foreign IDs get 404. No customer cancellation, status mutation, payment verification or inventory restoration endpoint is exposed. Existing administrator cancellation logic owns restoration; reading an order does not restore inventory.

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
    "platform": "android"
}
```

`platform` is required and is `android` or `ios`. Tokens must use a nonempty `ExpoPushToken[...]` or `ExponentPushToken[...]` format and be at most 255 characters. Use Expo's actual project/device token; do not substitute an FCM/APNs token or bearer token. No provider credentials are passed by the client. Invalid fields return 422; malformed device UUID routes return 404.

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

Push text is generic: “An update is available. Open Battlefront to view your order.” Its `data` contains `notification_id`, `order_id` and `deep_link`; it contains no proof URLs, addresses, amounts or personal/payment details. Client receipt/tapping a push must not mutate order/payment/shipment business state. Refresh history/count when opening the app and after read actions; push availability does not establish read state.

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
| token                                                     | Automatically captured after successful register/login; protected requests inherit it                                                                |
| product_id, category_id, tag_id, brand                    | Select actual values from catalog/detail/filter responses                                                                                            |
| category_id_2                                             | Another real active category ID for the optional category_ids[1] query entry; leave singular category_id disabled when enabling category_ids entries |
| min_price, max_price, sort                                | Optional catalog bounds and featured/price_asc/price_desc ordering; bounds start blank and query entries are disabled                                |
| cart_item_id                                              | Captured after Add product; manually selectable from own cart                                                                                        |
| order_id, order_reference                                 | Captured after placement; manually selectable from history                                                                                           |
| rejected_order_id                                         | Owned wallet order prepared with rejected payment in web administration                                                                              |
| quantity, page, search                                    | Representative nonsecret defaults where useful; optional catalog filters initially disabled                                                          |
| recipient_name, contact_number, delivery_address          | Locally supplied test checkout/profile values                                                                                                        |
| delivery_destination                                      | Canonical configured destination; defaults to Sagay City for disposable delivery tests                                                               |
| chatbot_message, follow_up_message                        | Representative public questions provided                                                                                                             |
| context_token, guest_context_token                        | Separate customer/guest continuation values captured by chatbot scripts                                                                              |
| other_cart_item_id, other_order_id, other_order_reference | Another disposable customer's resources, for ownership checks                                                                                        |
| admin_token                                               | Local administrator test token supplied out of band; mobile login cannot issue it                                                                    |
| invalid_token                                             | Enter any deliberately invalid string locally                                                                                                        |
| revoked_token                                             | Captured locally at successful logout for a revocation check                                                                                         |
| expired_token                                             | A genuinely expired local test token supplied out of band                                                                                            |

No file path is committed. Select a file in each multipart request after import. IDs start blank so the collection cannot silently assume database IDs. Raw JSON scripts serialize environment values to preserve quotes/backslashes in names/passwords; edit the script template if changing the represented body. Requests never log tokens.

Successful login/registration replace `token`, clear conversation state and clear selected cart/order IDs. Logout captures the old token in `revoked_token` for the negative check, then clears `token` and both context values. Remove `revoked_token` after checking. Guest requests have explicit No Auth, even when a customer token is populated.

### Required manual sequence

Use a disposable development database/account with an active product, live Sagay stock, and seeded branch/reference data.

1. **Connectivity/public reads:** Health (200), product filters/list/detail and branches (200). Enable catalog filters individually, then combine search/brand/tag with multiple categories and price bounds. Disable singular `category_id` when using `category_ids` entries. Check discount-aware price sorting in both directions and follow `links.next` to confirm filters persist. Try conflicting category inputs, invalid category IDs, reversed bounds, and an invalid sort (422); restore valid params afterward. Check nullable fields and compare the existing web catalog behavior separately.
2. **Access:** Profile without token (401). Guest behavioral feed (200); authenticated personalized feed without a token (401). Guest chatbot public question/follow-up (200). Guest order question returns sign-in fallback. Pace calls under guest limits.
3. **Authentication/profile:** Register a unique customer (201) OR log in (200); confirm token capture. Read/update profile (200), verify only approved fields. Invalid login gives 401; duplicate/invalid registration gives 422.
4. **Cart:** Add stock-eligible product (200), verify captured cart_item_id; update quantity and totals; remove (200). Add again before checkout. Try quantity zero, quantity above stock, and an unavailable product (422); failed operations must not corrupt the cart.
5. **Checkout/orders:** Select one, multiple, or all owned cart-item IDs and include them in both preview and placement. Preview checkout (200), verify all 12 server quotes and select a destination. Check standard/fragile/bulky and mixed carts, subtotal/fee/final total, LBC/demo wording and provisional calendar windows. Submit cash/card pickup (201, zero fee/no Shipment); omit address and destination. Refill before each wallet placement, select an image, and include canonical destination plus detailed address for delivery. Try missing/unsupported/wrong-case/array destinations, pickup destination/address, and forged quote fields. Verify recalculated saved fees/relative ETA, pending order/payment, selected-item removal, unselected-item retention and selected inventory deduction through existing web administration. Confirm no selection, duplicates, foreign/missing/removed IDs and stale selected stock fail without changes. Leave bulky/fragile products unselected and verify their handling/ETA do not affect the order.
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

The behavior-driven recommendation update adds the public recommendation feed and anonymous aggregate interaction endpoint and retires the superseded input/options endpoints after developer-confirmed mobile migration. EXT-89 exposes manual shipment facts through existing order detail endpoints; EXT-90 adds notification history/read-state and Expo device registration/revocation endpoints. No mobile password reset/change, token refresh, customer order cancellation, admin operations, payment gateways, live courier/GPS tracking, or compatibility checking are introduced.

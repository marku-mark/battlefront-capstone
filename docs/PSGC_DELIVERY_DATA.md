# Local PSGC delivery locations

Battlefront bundles nationwide geographic reference data in `resources/data/psgc-nationwide-2026-03-31.v2.json` and 18 regional `psgc-barangays-2026-03-31.v2-*.json` partitions. The registry contains **18 regions, 82 provinces and 1,642 cities/municipalities**; the partitions contain **42,010 barangays**. `PsgcDeliveryLocations` validates addresses locally; `DeliveryRules` alone determines delivery coverage, fees, shipping profiles, payment eligibility and estimates. Nationwide address selection does not enable nationwide automatic checkout.

The original `psgc-delivery-2026-03-31.v1.json` remains a bounded compatibility payload for existing consumers and conservative legacy address matching. Geography requires no tables or runtime network access. The shipping-review/quote-metadata migration is required for the enhanced product and order persistence.

## Current fee policy: nationwide-demo-v7

All new quotes use regional base fees: Visayas/NIR PHP 150, Luzon PHP 200, Mindanao PHP 250, plus existing weight and handling charges. City/province/region rules determine eligibility, payment methods and transit, not base prices. The 32 configured local routes retain their ETAs and Shipment creation; fallback routes still require manual arrangements without an automatic Shipment or ETA. Missing regional pricing fails closed; pickup remains free of delivery charges. Saved orders retain their original fees and versions. These are Battlefront estimates, not official LBC quotations. Old city and distance-based fee tables below are historical pre-v7 references only.

## Source and version

- Publisher: Philippine Statistics Authority (PSA), [Philippine Standard Geographic Code](https://psa.gov.ph/classification/psgc/).
- Data release: **Q1 2026, as of 31 March 2026**. Nationwide local schema version: **2**; retained compatibility schema: **1**.
- Prepared on **13 October 2026** from the [PSA-derived psgc-rb data](https://github.com/alkevintan/psgc-rb/tree/279ffe1918c2fd28206c2153f6c5c2d8280fb9f3/data).
- Pinned revision: `279ffe1918c2fd28206c2153f6c5c2d8280fb9f3`.
- Source file Git blob hashes: regions `4b7efc1bc611f7a365027fb2b359c2a4eb36d03e`; provinces `41452fd6dff02c71a2f840ad78bd9944c2bd372f`; cities/municipalities `250aba0b930310650a0816fffd31a103ffb2bef8`; barangays `49f9fa82420187596178ee958855ec7fd4cf4710`.
- The official Q2 2026 workbook was located, but its direct download returned a Cloudflare 403 in the preparation environment. This bundle deliberately identifies the obtainable Q1 release; it is not labeled as the latest release.

Official string codes and names are retained nationwide. The following **12 configured delivery routes** and their **300 barangays** also remain available in the compatibility payload. Destination aliases remain Battlefront's exact configured strings; for example, `E.B. Magalona` maps to official `Enrique B. Magalona`.

| Destination key | Full locality code | Barangays |
| --------------- | ------------------ | --------- |
| Sagay City      | 1804523000         | 25        |
| Escalante City  | 1804509000         | 21        |
| Cadiz City      | 1804504000         | 22        |
| Toboso          | 1804529000         | 9         |
| Manapla         | 1804518000         | 12        |
| Calatrava       | 1804505000         | 40        |
| Victorias City  | 1804531000         | 26        |
| E.B. Magalona   | 1804508000         | 23        |
| San Carlos City | 1804524000         | 18        |
| Silay City      | 1804526000         | 16        |
| Talisay City    | 1804528000         | 27        |
| Bacolod City    | 1830200000         | 61        |

### Normalization and administrative identity

The mirror's `city_municipality_code` truncates barangay parent codes to six digits, which groups different localities together; its `province_code` is also truncated. Neither field was imported. Membership was rebuilt using the first **seven** digits of the full 10-digit locality/barangay codes and confirmed against [PSA locality listings](https://psa.gov.ph/classification/psgc/citimuni/1804500000). The service checks these relationships and unique string codes when loading JSON.

Bacolod is a [highly urbanized city](https://psa.gov.ph/classification/psgc/hucs), code `1830200000`, in Negros Island Region. It has no administrative province parent in the normalized data. The other supported cities/municipalities have Negros Occidental, code `1804500000`, as their province. Fixed delivery coverage still includes Bacolod.

The mirror includes 14 Manila districts among its locality rows. These are retained as district metadata under the actual City of Manila (`1380600000`), with 897 barangays, rather than presented as separate cities. NCR has 17 city/municipality records and no province selector requirement. HUCs, Isabela City and the BARMM Special Geographic Area localities retain null administrative province parents; a null province does not by itself mean HUC. Negros Island Region (`1800000000`) contains Negros Occidental, Negros Oriental and Siquijor; Bacolod remains independent of an administrative province.

Barangays are sorted naturally by official name, then code. Population, income and coordinates are omitted. Codes, region/province/city membership, attribution and release metadata are retained. No runtime external PSGC API is used.

## Checkout and compatibility

Inertia checkout and customer profile settings use cascading Region → Province/Administrative Area (when applicable) → City/Municipality → Barangay → Street/House/Landmark fields. Independent localities appear in a no-province administrative area option. Changing a parent clears descendants; stale asynchronous responses cannot restore old choices. Failed lookups expose retry guidance.

Existing checkout preview `deliveryLocations`/`data.delivery_locations` and prefill fields retain their bounded v1 contracts. Updated clients lazily request authenticated local `GET /delivery-locations` (web) or `GET /api/v1/delivery-locations` (bearer customer). Send `level=regions`, `provinces&parent_code=<region>`, `cities&parent_code=<province>` or `<region>` for independent localities, `city&parent_code=<city>` for hydration, and `barangays&parent_code=<city>`. Responses are `{data:{version,locations}}`. Codes are strings; unknown or invalid parents return 422. The complete dataset is never sent to checkout.

New structured submissions include `delivery_region_code`, nullable `delivery_province_code`, `delivery_city_code`, `delivery_barangay_code` and `delivery_address_details`. The city code can be used as `delivery_destination`, or that field can be omitted and resolved from the city code. Parent-child membership and any supplied destination alias are checked server-side. Profile fields use the `default_delivery_` prefix. Valid unpriced nationwide addresses may be saved as customer defaults.

Structured delivery sends the existing canonical `delivery_destination`, a `delivery_barangay_code` string, and `delivery_address_details`. Laravel verifies coverage, barangay membership, and nonblank details, then composes the order's existing `delivery_address` snapshot. The assembled string must fit 255 Unicode characters; it is never truncated. Supplied assembled addresses are ignored in structured mode.

Examples:

- Sagay: `12 Mabini Street, Poblacion I, Sagay City, Negros Occidental`.
- Bacolod: `12 Lizares Street, Barangay 13, Bacolod City`.

Web delivery uses nationwide structured fields. Existing mobile clients may continue sending an original configured destination alias and `delivery_address` when structured fields are absent, or the original v1 barangay/details contract. Sending any nationwide parent field or a code destination opts into complete nationwide hierarchy validation. Pickup requires no address and prohibits nonempty delivery fields.

Customer Profile Settings uses the same shared address fields as checkout. A nullable `users.default_delivery_address_components` JSON column saves the canonical destination, server-resolved full city code, barangay code, and detailed street text. The existing `default_delivery_address` string remains the readable compatibility representation. Profile saves require all three valid fields, and explicit clearing clears both representations. Checkout reads valid saved components directly and never updates either profile field.

Existing free-text addresses remain untouched by the additive migration. Conservative prefill recognizes exact comma-delimited canonical/official city suffixes and known barangay names, accepting case differences and explicit `Brgy.`/`Barangay` prefixes. It removes a trailing `Negros Occidental` label when parsing older addresses, including Bacolod. It never guesses a barangay. A confidently matched city may prefill independently; barangay/details prefill only when both the barangay and remaining detail text are unambiguous. Uncertain saved text remains visible as an escaped reference. Unrelated profile saves use `default_delivery_address_action=keep`, retaining even partially recognizable legacy defaults. Customer edits use `save`; explicit removal uses `clear`. Legacy mobile free-text updates remain supported and refresh or clear stale components using the same conservative matcher.

Orders retain their purchase-time human-readable address and all previous commercial/Shipment snapshots. Nullable `orders.delivery_quote_metadata` adds accepted codes, dataset/rule versions, payment eligibility/assumption and structured address components for new quotes; historical rows remain null and are never repriced. Apply the profile-components and shipping-review/quote-metadata migrations before deployment; rollback refuses to discard populated metadata.

## Configured demonstration coverage and product handling

Sagay City is the single origin. `battlefront.delivery.zones` resolves city rules before province rules before region rules. Specific disabled or incomplete rules stop resolution and reject delivery; they do not fall through to broader coverage. Each enabled rule requires nonnegative integer `transit_min_days`/`transit_max_days`, and explicitly allowed `payment_methods` from `gcash`, `maya`, `cod`. The original 12 named routes remain the bounded v1 compatibility list; the 20 new Negros Occidental localities are priced through their full PSGC city codes and the shared live quote endpoint. Province/region configuration remains empty.

### Negros Occidental coverage count and preservation

The bundled Q1 2026 PSGC registry contains **31 cities/municipalities with province code `1804500000`**. Eleven already had configured Negros Occidental city rates, leaving **exactly 20 uncovered provincial localities**. Bacolod City (`1830200000`) is a separate highly urbanized city with a null province parent; its existing route remains supported and is not counted in the 31. The 20 newly covered localities contain **362 barangays**. Their city codes, official names, and barangays are validated by the same nationwide PSGC service.

All amounts and day ranges below are **Battlefront demonstration estimates, not official LBC quotations or transit guarantees**. The listed base amounts are historical pre-v7 references. New quotes use the regional fee table above; transit estimates are preserved. Saved order amounts are never recalculated from current pricing.

| Routes                      | Base fee (PHP) | Transit days |
| --------------------------- | -------------- | ------------ |
| Sagay City                  | 80.00          | 0–1          |
| Escalante City              | 100.00         | 1            |
| Cadiz City                  | 120.00         | 1            |
| Toboso                      | 140.00         | 1            |
| Manapla                     | 160.00         | 1            |
| Calatrava, Victorias City   | 180.00         | 1            |
| E.B. Magalona               | 200.00         | 1–2          |
| San Carlos City, Silay City | 220.00         | 1–2          |
| Talisay City                | 240.00         | 1–2          |
| Bacolod City                | 250.00         | 1–2          |

### New Negros Occidental distance estimates

The method uses the Haversine great-circle distance on verified OSM administrative-boundary centroids. It uses Sagay City's OSM boundary centroid (relation `11491048`, `10.8960681, 123.4154617`) as the fixed calculation origin; it does **not** claim a Battlefront storefront pin. For reproducibility, use Earth radius `6,371 km`, round the resulting distance to the nearest `0.1 km`, then assign the interval `(minimum, maximum]` below. The retained transit bands are held in `battlefront.delivery.negros_occidental_distance_bands`; each city-code rule selects a band, while the reviewed coordinate, relation ID, rounded distance, and band are recorded in `battlefront.delivery.negros_occidental_distance_proxies`.

These are administrative-boundary centroids used only as geographic proxies. They are **not actual storefront locations, town centers, road distances, travel times, or official LBC pricing inputs**. OSM relation centroids can move when mapped boundaries change. OSM data is © OpenStreetMap contributors and licensed under ODbL 1.0. The OSM/Nominatim relation lookup pattern is [Nominatim lookup](https://nominatim.openstreetmap.org/lookup?osm_ids=R11491048&format=jsonv2); substitute the relation ID in the table for each locality. OSM attribution does not imply LBC endorsement.

Historical pre-v7 pricing rationale (superseded for new quotes): The bands were reviewed against the unchanged configured provincial references with OSM boundary centroids: Sagay 0.0 km / PHP 80, Escalante 11.0 km / PHP 100, Cadiz 13.7 km / PHP 120, Toboso 22.6 km / PHP 140, Manapla 32.6 km / PHP 160, Calatrava 34.2 km / PHP 180, Victorias 37.6 km / PHP 180, San Carlos 45.6 km / PHP 220, E.B. Magalona 47.4 km / PHP 200, Silay 49.2 km / PHP 220, and Talisay 52.0 km / PHP 240. These reference fees are intentionally not normalized or recalculated. Bacolod's PHP 250 / 1–2-day HUC route is also unchanged; it is excluded from spatial calibration because the available point was not a verified OSM boundary centroid. New bands use modest fixed increments, not a fit claimed to model actual courier costs.

| Distance interval from Sagay proxy (km) | Base estimate (PHP) | Demo transit days | Calibration / use                                            |
| --------------------------------------- | ------------------: | ----------------- | ------------------------------------------------------------ |
| (30, 45]                                |              180.00 | 1–2               | Around the unchanged PHP 160–180 references at 32.6–37.6 km  |
| (45, 60]                                |              220.00 | 1–2               | Around the unchanged PHP 200–240 references at 45.6–52.0 km  |
| (60, 100]                               |              280.00 | 2–3               | First conservative outward step beyond configured references |
| (100, 125]                              |              320.00 | 2–3               | One further distance tier                                    |
| (125, 155]                              |              360.00 | 3–4               | One further distance tier                                    |
| (155, 180]                              |              400.00 | 3–4               | Longest reviewed proxy distances in the proposed coverage    |

| Destination (PSGC name) | Full locality code | Barangays | OSM relation | Boundary centroid (lat, lon) | Great-circle km | Band       | Base fee (PHP) | Transit days |
| ----------------------- | ------------------ | --------: | -----------: | ---------------------------- | --------------: | ---------- | -------------: | ------------ |
| Binalbagan              | 1804503000         |        16 |     11525661 | 10.1937474, 122.8587743      |            99.0 | (60, 100]  |         280.00 | 2–3          |
| Candoni                 | 1804506000         |         9 |      3740498 | 9.8306756, 122.6429611       |           145.5 | (125, 155] |         360.00 | 3–4          |
| Cauayan                 | 1804507000         |        25 |      3740499 | 9.9733632, 122.6260198       |           134.1 | (125, 155] |         360.00 | 3–4          |
| City of Bago            | 1804502000         |        24 |     11366393 | 10.5376299, 122.8352802      |            74.9 | (60, 100]  |         280.00 | 2–3          |
| City of Himamaylan      | 1804510000         |        19 |     11525214 | 10.0992841, 122.8705390      |           106.8 | (100, 125] |         320.00 | 2–3          |
| City of Kabankalan      | 1804515000         |        32 |      3740502 | 9.9888817, 122.8134501       |           120.5 | (100, 125] |         320.00 | 2–3          |
| City of La Carlota      | 1804516000         |        14 |     11984373 | 10.4268649, 122.9208074      |            75.1 | (60, 100]  |         280.00 | 2–3          |
| City of Sipalay         | 1804527000         |        17 |      3740505 | 9.7491266, 122.4040602       |           168.8 | (155, 180] |         400.00 | 3–4          |
| Hinigaran               | 1804511000         |        24 |     12599665 | 10.2713832, 122.8519909      |            92.8 | (60, 100]  |         280.00 | 2–3          |
| Hinoba-an               | 1804512000         |        13 |      3740500 | 9.6021766, 122.4670994       |           177.4 | (155, 180] |         400.00 | 3–4          |
| Ilog                    | 1804513000         |        15 |      3740501 | 10.0242067, 122.7691808      |           120.0 | (100, 125] |         320.00 | 2–3          |
| Isabela                 | 1804514000         |        30 |     18527747 | 10.2047969, 122.9892506      |            89.9 | (60, 100]  |         280.00 | 2–3          |
| La Castellana           | 1804517000         |        13 |     17749026 | 10.3230498, 123.0187418      |            77.1 | (60, 100]  |         280.00 | 2–3          |
| Moises Padilla          | 1804519000         |        15 |     18491788 | 10.2708302, 123.0733691      |            78.9 | (60, 100]  |         280.00 | 2–3          |
| Murcia                  | 1804520000         |        23 |     11366415 | 10.6066483, 123.0404676      |            52.1 | (45, 60]   |         220.00 | 1–2          |
| Pontevedra              | 1804521000         |        20 |     12716470 | 10.3751168, 122.8668210      |            83.4 | (60, 100]  |         280.00 | 2–3          |
| Pulupandan              | 1804522000         |        20 |     12245305 | 10.5192005, 122.8034563      |            78.9 | (60, 100]  |         280.00 | 2–3          |
| Salvador Benedicto      | 1804532000         |         7 |     12717917 | 10.5770770, 123.2203670      |            41.4 | (30, 45]   |         180.00 | 1–2          |
| San Enrique             | 1804525000         |        10 |     12599666 | 10.4196780, 122.8495462      |            81.4 | (60, 100]  |         280.00 | 2–3          |
| Valladolid              | 1804530000         |        16 |     12599678 | 10.4615480, 122.8234493      |            80.7 | (60, 100]  |         280.00 | 2–3          |

The nearest new locality is Salvador Benedicto at 41.4 proxy km; the farthest is Hinoba-an at 177.4 proxy km. Nearby cases were reviewed: Isabela/Hinigaran (89.9/92.8 km) share one band; Ilog/Kabankalan (120.0/120.5 km) also share one band, avoiding a sharp fee change for a half-kilometer proxy difference. No new locality exceeds the documented 180 km maximum. The resulting rates are suitable only for reproducible capstone demonstrations, not as verified production charges.

All 32 configured routes (the original 12 plus the 20 new city-code rules) explicitly support GCash, Maya and COD demonstrations. Other valid destinations in mapped regional groups receive a Battlefront regional fee estimate plus package-weight and highest-profile handling estimates. The saved estimate is included in new order totals, but no ETA or Shipment is created until an administrator confirms the delivery arrangement. These are not official LBC rates. Missing or invalid regional pricing rejects new delivery quotes; historical `not_configured` orders retain their saved zero-fee placeholders. Existing orders and saved amounts/metadata are never repriced.

The quote endpoint is `GET /checkout/delivery-quote` (web) or `GET /api/v1/checkout/delivery-quote` (mobile), with owned selected `cart_item_ids[]` and `delivery_city_code`. Configured routes return a server-calculated fee and ETA. A regional estimate returns `pricing_status="regional_estimate"`, an included estimate, no carrier or ETA, and requires later manual arrangement confirmation. Historical orders may retain `pricing_status="not_configured"`, `pricing_configured=false`, and the `0.00` placeholder; new quotes with missing regional pricing fail closed. Placement independently recalculates from current products/configuration under its existing transaction; previews never authorize a client-submitted fee.

| Profile  | Once-per-order surcharge (PHP) | Preparation days |
| -------- | ------------------------------ | ---------------- |
| Standard | 0.00                           | 1                |
| Fragile  | 50.00                          | 2                |
| Bulky    | 100.00                         | 3                |

The highest profile in the selected cart applies once. Delivery fee = route estimate + package-weight estimate + that handling surcharge; final total = product subtotal + delivery fee. Package weight uses the greater of actual and volumetric weight rounded upward to the configured billing increment. These assumptions are not LBC tariffs. No valuation, insurance, or live carrier pricing is implemented.

`catalog-shipping-profiles.v1.json` reviews all 654 imported identities: 93 clear Standard accessories, 93 Fragile items (including actual displays, motherboards, graphics cards and explicit laptops), 46 Bulky items (including cases, clear desktop systems, printers and chairs), and 422 uncertain identities requiring administrator review. Uncertain items retain their existing profile rather than receiving an invented assignment. Accessory brackets are not classified as fragile displays. Mapping application requires an exact code/name/category match, an imported product, current Standard and no review source. Existing non-Standard/manual/source-tagged assignments are preserved.

`php artisan catalog:classify-shipping --no-interaction` previews the mapping; `--apply` applies eligible reviewed rows in locked transactions. New catalog inserts receive eligible initial classifications; reimports preserve existing profiles. Administrators can change or explicitly confirm each profile in the existing product form; `shipping_profile_source=manual` protects that choice, including a confirmed Standard assignment. Apply the migration before running the command.

Prepaid delivery methods require verified payment before Processing and manual arrangement confirmation. COD remains pending through delivery; an assigned handler records the saved-total collection, then separately reconciles full remittance and its reference. Only remittance verification marks payment verified, completes the order, and records one sale. These are Battlefront manual records, not verified LBC carrier settlement. Regional estimates are included in new order totals; historical `not_configured` orders retain their original zero-fee subtotal-only snapshots. Pickup cash/card workflows retain zero delivery fees. Sales revenue remains product subtotal only. No carrier API or automated settlement module is implemented.

## Updating the bundle

1. Find a verified PSA quarterly publication or a properly licensed mirror that explicitly identifies its PSA release. Retrieve the source during development/setup, never during checkout.
2. Pin the mirror revision or official publication URL and retain source hashes. Download `regions.json`, `provinces.json`, `cities_municipalities.json` and `barangays.json` into a temporary working directory only. For this release, use the raw URLs under the pinned revision above. GitHub's blob endpoint can retrieve large files.
3. Reproduce the current bundle with `node database/PrepareNationwidePsgc.mjs <source-directory>`. The transformer checks all four pinned Git blob hashes and expected counts before writing registry/partitions. Different source bytes require a reviewed source/version update; never bypass hash verification.
4. Review full-code parents, NCR/HUC/Manila/SGA/NIR exceptions and all 12 aliases. Never import truncated mirror parent fields or guess replacement identities. Validate unique string codes and every barangay parent.
5. For a new release, update source/license metadata and versioned filenames/reader references together. Review geographic changes separately from approved delivery configuration; a data refresh cannot silently enable routes or rewrite historical addresses.
6. Run the focused PSGC service, checkout, mobile compatibility, and frontend address tests. Existing orders must remain readable without looking up current geography.
7. Retain applicable notices and attribution below. Deploy validated JSON with application code; geographic refreshes do not require tables or runtime downloads.

Do not update this bundle automatically on a schedule. Geographic updates are reviewed source changes and cannot expand delivery eligibility.

## Attribution and licenses

PSA data is available under [CC BY 4.0 / PSA Terms of Use](https://psa.gov.ph/terms-of-use). Credit PSA, link to the terms, retain relevant technical explanations, indicate modifications, and do not imply PSA endorsement. This document and the JSON source metadata identify our filtering, parent normalization, classification metadata, and sorting.

The mirror uses MIT. Retain its notice when distributing this derived dataset:

> MIT License
>
> Copyright (c) 2026 Al Kevin Tan
>
> Permission is hereby granted, free of charge, to any person obtaining a copy
> of this software and associated documentation files (the "Software"), to deal
> in the Software without restriction, including without limitation the rights
> to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
> copies of the Software, and to permit persons to whom the Software is
> furnished to do so, subject to the following conditions:
>
> The above copyright notice and this permission notice shall be included in all
> copies or substantial portions of the Software.
>
> THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
> IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
> FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
> AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
> LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
> OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
> SOFTWARE.

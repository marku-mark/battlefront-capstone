# Battlefront Capstone Development Context

## Project Title

**Integrated Web and Mobile Business Management System with Product Recommendation, Predictive Analytics, and Integrated Chatbot for Battlefront Computer Trading**

This document records the current Battlefront Computer Trading repository implementation, completed work, pending work, and development boundaries. The repository snapshot was reviewed on **2026-10-08**; Linear status references below remain the historical **2026-10-01** snapshot unless explicitly rechecked.

For current implementation facts, use the repository first, then Linear issue state and approved scope, existing handoff/context documents, and existing Postman/mobile API documentation. Planned capabilities are explicitly distinguished from implemented behavior.

This is a development reference. `docs/capstone_manuscript.docx` remains academically sensitive: the developer explicitly authorized narrow forecasting/predictive-analytics text synchronization for the **2026-10-05** approved revision, preserving formatting and unrelated content. Other manuscript revisions remain developer-owned. This authorization does not extend to implementation changes or unrelated academic edits.

**Recommendation scope approval recorded:** on **2026-10-08**, the developer confirmed adviser approval for behavioral recommendations based on search, product-view, cart, and purchase signals, including temporary guest browsing history. The developer also confirms migration of the mobile consumer. Guest history transfers only when the visitor creates a new account; it is discarded when an existing account signs in. This approved amendment governs current implementation and evaluation planning; synchronization of the formal manuscript remains a developer follow-up.

---

# 1. Project Overview

Battlefront Computer Trading is a computer hardware and peripherals retailer serving walk-in and online customers.

Existing business operations rely partly on manual or semi-manual processes such as handwritten records and basic spreadsheets for sales and inventory management. Customer inquiries are also handled manually through channels such as social media and phone communication.

These processes can contribute to:

- delayed inventory updates;
- stock mismatches;
- recording errors;
- slower customer inquiry handling;
- fragmented business information.

The Laravel system integrates Battlefront's major business processes and provides a shared backend for the web application and a separately maintained mobile client.

The system supports:

- customer and administrator accounts;
- product and category management;
- inventory monitoring and low-stock awareness;
- customer e-commerce activities;
- cart and order processing;
- sales monitoring;
- business reports;
- product recommendations;
- predictive sales/demand analytics (monthly additive Holt–Winters implemented);
- chatbot-assisted customer inquiries;
- branch/store information.

The intelligent features are intended to support customer and managerial decisions, not replace human judgment.

Core catalog/inventory, customer commerce, administrator order/payment processing, sales reports, chatbot, and behavioral recommendations are implemented. The behavior-driven recommendation implementation is complete for the current web/backend paths; real React Native + Expo consumer validation, final predictive-analytics acceptance, and remaining integration/release work are pending; see Section 14.

---

# 2. Primary Study Location and Branch Scope

The primary operational branch is:

**Battlefront Computer Trading — Sagay City, Negros Occidental**

The Sagay City branch is the primary location for:

- requirements gathering;
- business-process observation;
- operational data;
- system testing;
- deployment;
- evaluation.

The repository also includes branch reference records for:

- Escalante City;
- San Carlos City;
- Guihulngan City;
- Bacolod City.

These additional branches are mainly represented through static store information such as location, address, contact information, and operating information supplied by the business.

All five seeded branches use the configured customer-facing operating-hours value **8:00 AM–6:00 PM**. This is static reference information in `config/battlefront.php`; there is no `operating_hours` column. Bacolod's inclusion reflects the current seeder/configuration and does not introduce another operational inventory branch.

## Current Branch Reference Data

| Branch          | Contact number                                   | Email                                | Location                                                                                                             |
| --------------- | ------------------------------------------------ | ------------------------------------ | -------------------------------------------------------------------------------------------------------------------- |
| Sagay City      | 0938 647 6046                                    | battlefrontcomputertrading@gmail.com | A, E Marañon St., Brgy. Poblacion II, Sagay City, Negros Occidental (beside LBC Sagay City), Sagay, Philippines 6122 |
| Escalante City  | Not yet confirmed                                | Not yet confirmed                    | Not yet confirmed; no official branch page is currently available                                                    |
| San Carlos City | Not listed on the available official branch page | Not yet confirmed                    | Carmona St., Brgy. V, San Carlos City, Negros Occidental, San Carlos City, Philippines 6127                          |
| Guihulngan City | 0947 946 5723                                    | battlefrontcomputertrading@gmail.com | L&E Arcade, Larena St., Brgy. Poblacion, Guihulngan City, Guihulngan, Philippines 6214                               |
| Bacolod City    | 0961 176 4608                                    | battlefrontbacolod@gmail.com         | Downtown, Along SKG Shopping Center, Beside Ukay-Ukayan 58 Lizares St. Brgy. 13, Bacolod CIty, Philippines, 6100     |

These reference values come from `database/seeders/BranchSeeder.php` and `config/battlefront.php`. Sagay City and Guihulngan City share the configured email address; Bacolod has a separate configured email. Unconfirmed values must remain null or undisplayed instead of being copied to other branches as verified facts.

Full operational detail such as live stock and order information is centered on the Sagay City branch.

The approved system is not a multi-branch operational synchronization platform.

---

# 3. Development Responsibility

## Primary Development Responsibility

The primary development responsibility for this project includes:

- Laravel backend;
- Vue 3 + Inertia.js web application;
- shared MySQL database;
- RESTful JSON API endpoints and contracts required by the React Native application;
- backend business logic shared by web and mobile clients.

## React Native + Expo Responsibility

Another group member is primarily responsible for:

- React Native UI;
- the separate React Native + Expo application implementation and runtime;
- mobile-side HTTP/API consumption.

The React Native + Expo application consumes Laravel's `/api/v1` JSON API and shares its MySQL-backed business data. Mobile UI code belongs in that separate project, not this repository. API completion does not establish that the real app has passed LAN integration testing.

**Laravel remains the shared source of backend business logic and persistent data for both web and mobile applications.**

---

# 4. Current System Architecture

The shared Laravel backend and Inertia web application follow a **monolithic deployment model** organized using a **three-tier logical architecture**. The mobile client is a separate consumer:

1. Presentation Layer
2. Application Layer
3. Data Layer

An external AI service is integrated specifically for chatbot response generation.

## 4.1 Presentation Layer — Web Application

Current web technologies:

- Laravel 13
- Inertia.js 3
- Vue 3
- Tailwind CSS 4
- Vite 8 through Vite+

The web application uses Inertia's server-driven architecture.

Normal request flow:

```text
Browser
    ↓
Laravel Web Route
    ↓
Laravel Controller
    ↓
Inertia Response
    ↓
Vue Page
```

The web application is **NOT a separate REST SPA**.

Normal Inertia web functionality should therefore use:

- Laravel web routes;
- Laravel controllers;
- Inertia responses;
- page props;
- Inertia forms;
- Wayfinder
- Inertia navigation/router features.

A separate REST endpoint or Axios-based frontend architecture should not be assumed for ordinary Inertia web features.

REST APIs remain appropriate where there is an actual API consumer or architectural requirement, particularly the React Native application.

## 4.2 Presentation Layer — Mobile Application

Separate mobile client technology:

- React Native + Expo

React Native communicates with Laravel through the implemented **RESTful JSON API at `/api/v1`**, using Sanctum bearer tokens for customer-specific operations.

Normal mobile request flow:

```text
React Native + Expo Application
    ↓ HTTP / JSON
Laravel /api/v1 Route
    ↓
Laravel Backend
    ↓
MySQL
```

The mobile application and Inertia web application use different communication mechanisms while sharing the same Laravel application and persistent business data.

```text
Web
Vue 3
   ↕ Inertia
Laravel
   ↕
MySQL

Mobile
React Native + Expo
   ↕ /api/v1 REST JSON API
Laravel
   ↕
MySQL
```

## 4.3 Application Layer

Laravel is responsible for the system's main application and business logic, including:

- authentication and users;
- products and categories;
- inventory;
- carts and checkout;
- orders;
- sales;
- reporting;
- product recommendation;
- predictive analytics (monthly additive Holt–Winters; see Section 9);
- chatbot orchestration;
- REST API behavior required by the mobile application.

Business rules shared between web and mobile belong to the shared Laravel backend rather than being independently reimplemented by each client.

## 4.4 Data Layer

The approved relational database management system is **MySQL**.

The implemented database stores users, products/categories/tags, inventory, customer carts and orders, sales, product forecasts, branch reference information, chatbot knowledge, and framework infrastructure including personal access tokens. Forecast persistence writes additive_holt_winters results; database constraints also accept historical moving_average and linear_trend rows without adding columns.

Current migrations and models establish the implemented schema. MySQL is the application backend; `phpunit.xml` uses SQLite in memory for automated tests. The starter `.env.example` still defaults to SQLite, so it does not describe the configured MySQL development backend.

## 4.5 External AI Integration

The chatbot agent is configured for **Google Gemini 3.5 Flash-Lite** (`gemini-3.5-flash-lite`) in `app/Ai/Agents/ChatbotResponseAgent.php`.

Laravel communicates with Gemini through the **Laravel AI SDK**, behind `ChatbotAiAdapter`. The configured timeout defaults to 20 seconds and accepts 5–30 seconds. Provider failures use the shared safe fallback; there is no automatic retry or alternate provider.

Gemini is used for generating the final natural-language chatbot response after the application has already categorized the inquiry and retrieved relevant system data.

Gemini is not the authoritative source for:

- stock quantities;
- product prices;
- order status;
- branch information;
- product recommendations.

System/database data remains authoritative for those facts.

---

# 5. Current Development Technologies and Tools

## Application Stack

- Laravel 13 / PHP (installed Laravel: 13.30.1 at this review)
- Vue 3 Composition API with plain JavaScript application components
- Inertia.js 3 (installed Laravel adapter: 3.3.2)
- Tailwind CSS 4
- Shadcn Vue
- Vite 8 / Vite+ (`vp` scripts)
- MySQL
- React Native + Expo (separate client project)
- Google Gemini 3.5 Flash-Lite, as configured in the chatbot agent
- Laravel AI SDK 0.11.2
- Fortify web authentication, Sanctum 4 mobile tokens, and Wayfinder route helpers

## Development and Testing Tools

- Laravel Herd — primary local Laravel/PHP server
- Visual Studio Code — primary IDE
- Git — version control
- GitHub — repository collaboration
- Composer — PHP dependency management
- npm — frontend dependency management
- Postman — manual REST API testing
- Pest/PHPUnit — Laravel automated unit and feature testing

Herd serves the Laravel application; `npm run dev` runs the Vite+ frontend development process and `npm run build` builds assets. Vite is not the mobile API server. `composer run dev` delegates to the project's Artisan development command.

Real mobile validation requires the correct Herd site to be reachable from the device on the same Wi-Fi/LAN. The existing EXT-63 preparation recorded a loopback-only Herd listener; actual device reachability remains unverified. Follow `MOBILE_INTEGRATION_VALIDATION.md` for LAN routing, device health checks, and the documented optional Artisan-server fallback. Do not treat a desktop-only `.test` hostname or phone localhost as a working mobile backend address.

---

# 6. Primary System Actors

The system has two primary actors:

1. Customer
2. Administrator

## Customer

Customers can:

- register, authenticate, and manage their profile;
- browse, search, filter, and view product details;
- receive behavior-driven product recommendations in the current product direction;
- manage a shopping cart;
- place orders and select an available payment method;
- monitor order status and view order history;
- use chatbot assistance;
- view Battlefront branch/store information.

## Administrator

Administrators can:

- manage products and categories;
- manage inventory and monitor stock levels;
- process and manage customer orders;
- monitor sales and revenue;
- view sales reports and dashboard summaries;
- view customer accounts and their order information;
- manage chatbot knowledge;
- view branch reference information;
- monitor sales, orders, and inventory through the administrative dashboard.

The product-only monthly Holt–Winters forecasting controller/service/requests/page are implemented. Administrators select a product, not a forecasting method or parameters. Current customer administration exposes index/detail viewing, not account editing. Branch reference data is maintained through seeders/configuration; there is no branch-management CRUD interface.

## Authorization Boundaries

- Public registration creates customers; request input cannot grant administrator privileges.
- Fortify handles web session authentication. Mandatory email verification is disabled; the retained `email_verified_at` field does not imply a verification gate.
- Administrator web routes require authentication and `access-administration`. Customer cart, checkout, and order routes require the customer role and enforce resource ownership.
- Guests can browse products/branches and use browser recommendations personalized from temporary search and product-view history, plus the storefront chatbot. The mobile guest recommendation feed remains popular/featured. Administrators cannot use customer commerce, recommendations, or the storefront chatbot.
- The mobile API is customer-facing only. Protected operations require Sanctum bearer authentication plus the customer gate; browser sessions do not authenticate these API calls. Tokens expire after 30 days, and logout revokes only the presented token.
- Optional-auth chatbot/recommendation endpoints accept guests and customers, reject invalid supplied credentials with 401, and reject valid administrator tokens with 403. Foreign customer cart/order resources are not exposed.
- Administrative inventory, payment verification, reports, and knowledge management remain web-only; there are no mobile administrator endpoints.

---

# 7. Core Functional Areas

## Core Business Management

- Authentication and customer accounts
- Product management
- Category management
- Inventory management
- Low-stock monitoring
- Customer profile management and administrator customer viewing
- Branch/store information

## E-Commerce

- Product catalog
- Product search and filtering
- Shopping cart
- Checkout
- Order placement
- Order status management
- Order history
- Payment-method selection

## Business Monitoring

- Sales recording and monitoring
- Revenue information
- Business reports
- Administrative dashboard

## Intelligent Modules

- Product Recommendation — adviser-approved behavioral scope implemented for the web/backend; mobile consumer validation and evaluation evidence pending
- Predictive Analytics — monthly additive Holt–Winters production workflow implemented; evaluation uses Moving Average and Seasonal Naive baselines
- Integrated Chatbot — complete

## Integration

- Inertia-based web application
- React Native REST API — complete; actual consumer validation pending
- Gemini chatbot integration

## Current Catalog and Inventory Rules

- Customer-visible products must be active, belong to an active category, and have an inventory record with positive quantity. Customer category, brand, and tag options require at least one available product. Deactivation retains history and supports reactivation; stock changes never alter product/category activation flags.
- Products have required unique product codes, nullable brands, tags, regular/optional discount prices, and managed image paths. Existing catalog import/image tools support catalog preparation; they are not historical-sales import or forecasting features.
- Live inventory is Sagay-only, with one inventory record per product rather than per-branch stock. Missing or zero stock hides customer catalog/search results and recommendations and returns 404 on web/mobile product details. Active products automatically reappear after restocking on the next request; administrators retain access to unavailable and inactive records. Explicit named-product chatbot inquiries and existing product follow-ups may still report unavailable stock, while broader chatbot discovery lists only available products.
- Quantities cannot be negative. Low stock means `quantity > 0 && quantity <= reorder_level`; in stock means `quantity > reorder_level`. Zero stock is a separate out-of-stock state, including when `reorder_level=0`; missing inventory is unavailable. Customer API catalog responses expose availability status rather than exact quantities. Existing active filter selections with no available matches return empty results and remain clearable; option lists hide them until stock returns.
- Cart operations use current server prices and stock eligibility. Adding to a cart does not reserve or deduct stock; checkout revalidates only explicitly selected owned cart items (EXT-91).

## Current Order, Payment, and Sales Rules

- Pickup accepts `cash`, `card_at_store`, `gcash`, and `maya`. Delivery accepts only GCash/Maya and requires one supported canonical destination plus a separate detailed delivery address; pickup prohibits nonempty destination/address values, saves zero delivery fee and creates no Shipment.
- GCash/Maya require an uploaded JPEG/JPG, PNG, or WebP proof image of at most 5 MB. Cash/card-at-store prohibit proof uploads. Proof is private and accessible through authorized administrator routes, never public storage URLs or mobile proof downloads.
- `OrderPlacementService` locks and rechecks customer/cart, catalog, and stock data. It snapshots recipient/contact/fulfillment, item quantities and current effective prices, creates a pending order/payment, deducts selected stock, and removes only purchased cart items in one transaction. Unselected items remain; the cart container is removed only when empty. Failed placement rolls back database changes and cleans up newly stored proof.
- Client totals and prices are not authoritative. Later payment verification or processing must not deduct stock again. The API has no idempotency-key contract; after an uncertain placement response, inspect order history before retrying.
- Administrator transitions are `pending -> processing/cancelled` and `processing -> completed/cancelled`; completed/cancelled are terminal. Both processing and completion require verified payment.
- Payment decisions are manual: pending becomes verified or rejected. Wallet verification requires accessible evidence and the administrator's payment-account/platform cross-check; uploading proof alone never verifies payment. Configured demo payment accounts must remain clearly identified as demo data.
- Rejected wallet proof can be replaced by the owning customer while the order is nonterminal. Replacement resets payment to pending and clears rejection feedback without changing order status or stock.
- Explicit administrator cancellation restores purchased quantities atomically with the eligible status transition, without double-restocking on retries. Payment rejection alone neither cancels an order nor restores stock. Customers have no cancellation/status/payment-verification endpoint.
- Completing an order records its sale once using the order total and completion date. Existing dashboards/reports summarize recorded sales; they are not forecasts. Order item prices/quantities remain snapshots, while displayed product names/brands/images come from current product records.

Shared actions/services implement these rules for both Inertia and mobile API controllers.

---

# 8. Product Recommendation Module

Behavioral recommendations are authoritative for both web and mobile under the adviser-approved amendment confirmed by the developer on 2026-10-08. The formal manuscript has not yet been synchronized; that academic update remains a developer follow-up.

**Current approved architecture for web and mobile:** behavior-driven suggestions. Signed-in customers' catalog searches and product views are recorded by default unless disabled in their profile; the engine also considers current cart items and completed purchases. Guests receive a temporary browser profile through an opaque, hashed-token cookie; their searches and product views use the same recommendation engine and expire after 90 days of inactivity. When a guest creates a new account, eligible guest history is merged transactionally into that account, respecting each recommendation preference. When an existing customer signs in, the guest profile is discarded and recommendations use only that account's saved activity and shopping history. Guests and customers without usable personal signals receive clearly labeled popular or featured products.

The deterministic engine combines scores from the five latest unexpired searches, five recent viewed products, current cart, completed purchases, overlapping customer purchases, and popularity. Fresh search contributions start at 55 and decay with age. Cart co-purchases start at 38; viewed/purchased co-purchases start at 18; similar-customer contributions start at 24. Category, brand and tag similarity uses an effective-price ratio of 0.5?2, with repeated-view and dwell boosts producing at most 56 points. Popularity adds at most five points; available featured fallback contributes 0.1. Stable IDs break scoring/history/cohort ties. Category diversity is a soft cap with backfill. This is rule-based scoring with purchase-overlap heuristics and no learned model.

Search and view personalization are enabled by default for signed-in customers, so recommendations respond without a profile setup step. Turning off personalized recommendations pauses all behavioral ranking and new search/view recording and hides dashboard/catalog recommendation sections for that customer; product-detail/cart sections and mobile feeds retain non-personalized popular/featured fallback. Turning it back on restores eligible recommendations from retained history. Guests and enabled customers without usable signals still receive fallback suggestions. Customers can also independently pause either signal. Unexpired search and view history is retained for up to 90 days and becomes usable again when its setting is re-enabled. Recommendation feedback is reported in aggregate; no individual search text is exposed in administrator reports.

Prefetch requests never record search/view/dwell activity. Mounted web detail navigation records an explicit view, including cached prefetch consumption; mobile detail GETs record views only without prefetch headers. Views deduplicate for 30 minutes. Dwell records the longest visible duration for the latest owned view, capped at 3,600 seconds. Browser feedback hides suggestions locally for at most 90 days, scoped to the customer or temporary guest profile. Anonymous interaction events are client-reported and carry only the primary displayed reason; they do not change backend scores or establish sales conversion.

A forward migration enforces exactly one customer/guest activity owner and adds chronological view indexes without changing valid history. It refuses rollback so operational deployments use forward fixes. Daily expiry pruning and registration transfer use bounded batches.

Every response rechecks active product/category eligibility and positive live Sagay stock. Recommendations use current effective prices, exclude items already purchased or in the cart from personalized candidates, avoid duplicate products, mix categories when enough relevant candidates exist, and include a reason tied to the signal used. The engine does not claim technical PC-part compatibility.

Customer dashboard, browsing catalog, product detail, and cart sections and both mobile GET feeds use the behavioral engine. The public homepage has no recommendation section, and the dedicated web recommendations destination is removed. Catalog recommendations remain separate from ordinary results and are hidden during searches or category/brand/tag filtering. Personalization controls remain in Profile settings. The developer confirms the React Native consumer has migrated to these endpoints; the superseded input/options endpoints are retired. Mobile guests continue to receive popular/featured products. This confirmation establishes consumer migration, not a new device-validation run.

The module operates using Battlefront's internal catalog, completed orders, cart, signed-in customer search/view activity, and temporary browser guest search/view activity. Signed-in customers can disable either personal activity signal in profile settings; disabled guest signals are discarded during account-creation merge. Guest history is never merged into an existing account at login. The chatbot does not receive recommendation history or make recommendation decisions.

The **Product Recommendation Module**, not the chatbot, owns recommendation logic.

---

# 9. Predictive Analytics Module

**Approved architecture, latest 2026-10-05 revision:** production uses one product-level **additive Holt–Winters exponential-smoothing method over monthly recorded sales**. This developer-approved decision supersedes both the original moving-average/linear-trend scope and the later trailing four-quarter moving-average production decision. The calculation models level, undamped trend, and recurring annual monthly seasonality. It remains deterministic, lightweight, internal-sales-only, and implementable in the existing PHP/Laravel stack without Python or a heavyweight dependency.

**Current implementation:** EXT-40/42/43/45/46 are complete. The checkout contains 48-month synthetic development fixtures, trusted monthly coverage metadata, readiness-gated 36-month preparation, additive Holt–Winters calculation and paired evaluation, compatible persistence, the product-only administrator workflow, and focused tests. New production forecasts use additive_holt_winters only. Four-quarter Moving Average and monthly Seasonal Naive are evaluation baselines only; Linear Trend/Regression is retired.

**Retirement status:** EXT-44 removes obsolete production paths after the completed Holt–Winters migration. EXT-36 remains the parent acceptance issue. Historical moving_average and linear_trend Forecast rows remain readable and preserved.

## Monthly History Contract and Fixed Window

Let T be the current calendar-quarter start in the application timezone, config('app.timezone'). Use exactly the latest **36 completed calendar months** in [T − 36 months, T), and estimate the three months of the full target quarter [T, T + 3 months). For October 5, 2026, the source is October 2023–September 2026 and the target is October–December 2026. Mid-quarter reruns use the same cutoff; observations anywhere in the target quarter are excluded. This is a full-quarter estimate, not remaining-quarter demand.

Each observation uses a half-open first-day-midnight calendar-month interval and includes year, month, start, end_exclusive, and nonnegative integer quantity_sold. The prepared envelope identifies one product, timezone, source period, target quarter, and 36 ordered consecutive months. Quantities derive through Sale → Order → OrderItem using **Sale.sale_date** and **OrderItem.quantity**, not order creation dates. Revenue, current prices, customer identity, and inventory are not predictors.

The current repository application timezone is **UTC**. Follow the configured timezone consistently; this migration does not silently change global application time to Asia/Manila. Preserve tests for other configured timezones, month/quarter/year boundaries and leap months.

QuarterlySalesAggregationService/QuarterlySalesRepository remain useful for generic product/category reporting and the evaluation baseline. Monthly forecasting uses its own query boundary; generic zero buckets never establish forecasting completeness.

## Trusted Monthly Coverage and Eligibility

Reuse the existing small configuration/import declaration and development manifest keyed by exact product code. Monthly declarations contain:

- granularity=month;
- month-aligned start and end_exclusive;
- optional unavailable_months, represented by month-start dates;
- timezone matching application timezone;
- source_kind distinguishing operational_prepared from synthetic_development;
- sales_scope distinguishing all_sagay_sales, captured_system_transactions, and development_fixture_transactions.

EXT-40 owns this contract; EXT-42 consumes it. The development manifest moves from version 1 to **version 2** and must be explicitly republished. Do not silently reinterpret earlier quarterly declarations as this new contract. No new coverage table or administrator coverage-management UI is approved. Operational declarations are unavailable by default.

Coverage is a setup/import/data-preparation declaration that records are complete for the named product, interval, and sales scope. It does not prove full-store coverage if the declared scope is only captured system transactions. Never infer completeness from earliest Sale, Product.created_at, or generated zero buckets. Setup/import/quarter-close preparation explicitly advances coverage; the clock alone does not.

| Condition for the required window                                                                                               | Outcome                            |
| ------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------- |
| Trusted complete 36-month history satisfying model eligibility                                                                  | ready                              |
| Valid complete gap-free declaration reaching T, with only 0–35 required months covered                                          | insufficient_history; save nothing |
| Missing/malformed/uncertain metadata, stale end before T, or an unavailable month in the covered portion of the required window | history_unavailable; save nothing  |
| Complete non-all-zero history failing the sparse-demand policy below                                                            | history_unsuitable; save nothing   |

Coverage failures take precedence over short-history/model eligibility checks. Ignore unavailable months outside the selected source window. For missing/unavailable/short coverage, do not construct a supposedly forecast-ready zero series. After coverage is established, empty covered months are valid zero observations.

**Minimum/window policy: 36 months.** Two annual cycles initialize components and a third supplies deterministic parameter-fitting observations. This is the approved project policy, not a universal statistical minimum or a guarantee of accuracy.

**Sparse policy:** divide the 36-month source into three chronological 12-month blocks. An all-zero covered history is ready with a valid zero estimate. For every other history, each block must contain at least **six positive-sales months**; otherwise return history_unsuitable. Eligible mixed-zero histories retain every zero. This conservative project rule may exclude legitimate products selling only during a few seasonal months; it is not a universal statistical test or proof that annual seasonality exists. No Croston, fallback method, or hidden second production algorithm is introduced.

Synthetic records/declarations work only in development/testing and cannot establish operational readiness for a real product. Real client history remains unconfirmed. Later operational preparation must validate mapping and completeness without silently mixing synthetic and operational records.

## Exact Additive Holt–Winters Calculation

EXT-43 owns one pure PHP action consuming prepared monthly quantities without database queries, zero-filling, completeness inference, or runtime-clock decisions. Seasonal period is 12; trend is additive and undamped, with additive seasonal adjustments.

Number observations y1 through y36. Define A=mean(y1..y12) and B=mean(y13..y24). Initialize at month 24:

    b24 = (B − A) / 12
    l24 = B + 5.5 × b24

For seasonal position j=1..12:

    s_j = [(y_j − A − (j − 6.5) × b24)
           + (y_(12+j) − B − (j − 6.5) × b24)] / 2

Center these 12 adjustments by subtracting their mean, and assign them to s13..s24 by position. This fixed initialization detrends the two annual cycles rather than treating ordinary within-year growth entirely as seasonality. Independently worked examples must verify this convention.

For each t=25..36, predict before updating with that observation:

    p_t = l_(t−1) + b_(t−1) + s_(t−12)
    l_t = alpha × (y_t − s_(t−12))
          + (1 − alpha) × (l_(t−1) + b_(t−1))
    b_t = beta × (l_t − l_(t−1))
          + (1 − beta) × b_(t−1)
    s_t = gamma × (y_t − l_t) + (1 − gamma) × s_(t−12)

Gamma uses the updated-level formulation, often written gamma-star; do not mix it with the alternative unadjusted parameterization.

### Deterministic Smoothing-Parameter Selection

Use the fixed Cartesian grid:

- alpha: 0.1, 0.2, …, 0.9;
- beta: 0.0, 0.1, …, 0.9;
- gamma: 0.0, 0.1, …, 0.9.

Evaluate exactly **900 candidates**, each from identical initialization. Minimize the sum of squared raw one-step errors, sum((y_t − p_t)^2), over t=25..36. Predict before updating; break equal scores by the smallest numeric (alpha, beta, gamma) tuple in ascending lexicographic order. Use the winning candidate's month-36 states.

This bounded coarse search is practical and reproducible; it does not claim a global optimum or best real-world accuracy. Unexplained fixed parameters were rejected because one arbitrary setting need not suit each product. No randomness, continuous optimizer, or administrator parameter input. The month-25..36 scores are fitting/tuning scores, not independent test accuracy.

Use the existing **BCMath** dependency with explicit **scale 12** for intermediate operations, truncation and score comparisons. Do not rely on global decimal scale, floating-point arithmetic, or intermediate two-decimal rounding.

### Three Monthly Outputs and Quarterly Rounding

For h=1,2,3:

    raw_h = l36 + h × b36 + s_(24+h)
    usable_h = max(0, raw_h)

Do not feed predicted values back as fabricated observations. Retain raw/clamp diagnostics in generation-time calculation context. Clamp each month separately, so negative projections cannot cancel another month's positive demand.

Sum usable_1 + usable_2 + usable_3 at internal precision; round the quarterly total **once to two decimals, half-up**. Monthly values displayed to two decimals are presentation only and never feed the total. Explain: “Monthly estimates are rounded; the quarterly total is calculated before rounding.” Displayed monthly figures may therefore differ from the displayed total by a cent.

Fully covered all-zero history initializes level/trend/seasonality to zero and uses the first tuple (0.1, 0.0, 0.0) without a parameter search. It produces three zero outputs and quarterly 0.00; it does not bypass coverage or the 36-month minimum. Invalid/oversized finalized output saves nothing and never overwrites a previous estimate.

The pure successful output identifies method=additive_holt_winters, product, timezone, source/target periods, selected parameters, three ordered monthly forecasts and diagnostics, and finalized quarterly forecast_quantity. Production callers always supply the approved fixed contract.

## Synthetic Development Strategy and Evaluation

DevelopmentHistoricalSalesSeeder generates **48 completed months ending at T** for full-history scenarios. Production uses the latest 36; the extra year supports four rolling quarterly evaluation targets. Environment guards, reserved identities, ownership checks, repeatability, publication-failure behavior, inactive synthetic products and unrelated operational records remain protected.

Scenarios include stable non-seasonal demand, recurring annual monthly seasonality, seasonality with growth, decline, eligible mixed zeros, sparse/intermittent demand, covered all-zero history, fewer than 36 covered months, absent/stale coverage, and an internal unavailable month. Include irregular/abrupt-change behavior where Holt–Winters performs poorly or should be unsuitable. Calendar-month profiles must not shift with seed-loop position. Keep older fixtures only where genuinely useful for unrelated reporting/evaluation regression tests.

For lightweight evaluation, compare production Holt–Winters with four-quarter moving average and monthly seasonal naïve summed to the same quarterly horizon. Using 48 complete months, evaluate four successive target quarters in the final 12 months. At every origin, fit/select parameters only from its preceding 36 months; do not use that target quarter. Earlier observed test quarters can enter later rolling training windows.

Report **quarterly mean absolute error (MAE) in units per product**, using finalized quarterly predictions, and report excluded/unavailable/unsuitable cases. Evaluation is separate from normal administration and does not persist baselines as new production results.

Synthetic data establishes implementation correctness and controlled behavior only. It does not establish accuracy for Battlefront, prove improvement over moving average, or validate claims based on seasonal social-media campaigns. Real operational accuracy requires held-out evaluation on real covered product history.

Method references: [additive Holt–Winters equations](https://otexts.com/fpp3/holt-winters.html), [short-history limitations](https://otexts.com/fpp3/long-short-ts.html), and [rolling forecast evaluation](https://otexts.com/fpp3/tscv.html). The fixed window, sparse threshold, initialization, finite grid and precision rules above are explicit project implementation policies.

## Sales Demand, Inventory, and Limitations

The estimate forecasts **recorded completed sales quantities**. Zero recorded sales does not prove zero customer demand, uninterrupted stock availability, or absence of lost sales. Current inventory/reorder level may appear as separate planning context but never modifies Holt–Winters.

The module supports human inventory decisions. It does not optimize reorder quantities, place purchases automatically, correct stockouts, know future discount schedules, or guarantee demand. Product turnover, irregular demand, changing promotion timing and limited history may reduce usefulness. ML, external economic/holiday datasets, Prophet, SARIMA, Python forecasting services and heavyweight libraries are outside this migration.

## Administrator Workflow and Product-Focused Persistence

    Select product → Generate forecast → Review estimated quarterly demand

The server checks trusted coverage/eligibility, aggregates monthly history, selects parameters, calculates three estimates, sums the quarterly result and persists it. Administrators supply only product_id: no method, alpha/beta/gamma, history start, seasonal period, horizon, target/window input or coverage checkbox. Administrator-only authorization remains.

The ForecastingService/controller/requests/page retain product search, independent pagination, loading/empty/error states and Chart.js/vue-chartjs. The generation view shows product, target quarter, three monthly estimates, quarterly demand, relevant historical monthly chart/table, generated time and concise decision-support wording. Synthetic data and declared sales scope remain identified. Ready/insufficient/unavailable/unsuitable messages preserve prior saved results on failure.

EXT-84 limits the administrator product selector, search results, and pagination totals to products whose current EXT-42 preparation status is ready. Covered all-zero and eligible mixed-zero histories qualify; inactive products qualify only when forecast-ready. Insufficient, unavailable, and unsuitable histories are excluded from the selector. Eligibility is evaluated in bounded batches using shared preparation rules before eligible results are paginated. Selector filtering is a convenience: generation always prepares fresh history and rechecks readiness server-side before calculation or persistence. Stale selections and direct product links retain readiness explanations and access to saved results.

Keep the Forecast entity **product-focused** and retain its columns: product_id, method, predicted_demand, forecast_quarter and generated_at. New writes use **additive_holt_winters**; predicted_demand is the finalized quarterly sum and forecast_quarter remains YYYY-Qn. Reruns replace only on (product_id, method, forecast_quarter).

The completed forward migration extends the MySQL forecasts_method_valid check and SQLite insert/update triggers to accept additive_holt_winters alongside moving_average and linear_trend for compatibility. Application forecast writes accept only additive_holt_winters. Preserve original applied migrations, strict formats/case/year, nonnegative demand, DECIMAL(12,2) capacity, product foreign key, uniqueness and every legacy row. No new Forecast columns, snapshot tables or ERD relationship changes are approved; rollback must not delete new-method records.

**Monthly breakdown is generation-time context only.** Original monthly predictions, source observations, parameters and clamp diagnostics are not saved. Saved review reads the stored quarterly total and states that the original breakdown is not retained after the generation view is lost/refreshed. Do not recreate original monthly values using corrected current history. Fixed method source dates may be explicitly inferred, but original quantities/provenance are not reconstructed. Label existing moving_average and linear_trend rows as legacy without generation controls.

## Issue Ownership and Current Implementation

| Issue  | Approved responsibility                                                         | Implementation           |
| ------ | ------------------------------------------------------------------------------- | ------------------------ |
| EXT-36 | Parent tracking and final acceptance                                            | Pending human acceptance |
| EXT-40 | 48-month development history and monthly trusted coverage                       | Implemented              |
| EXT-42 | Trusted 36-month preparation, monthly aggregation and sparse eligibility        | Implemented              |
| EXT-43 | Additive Holt–Winters calculation, bounded parameter fitting and evaluation     | Implemented              |
| EXT-45 | New production output validation and compatible method-constraint migration     | Implemented              |
| EXT-46 | Existing simple workflow adapted to monthly history and three estimates         | Implemented              |
| EXT-44 | Final retirement of obsolete production paths; retain used evaluation baselines | Cleanup in review        |

The implementation order was **EXT-40 → EXT-42 → EXT-43 → EXT-45 → EXT-46 → EXT-44**. EXT-44 removes unused trend calculation and quarterly production preparation while retaining the Moving Average evaluation baseline. Existing parent external dependencies are preserved.

Do not delete historical forecasts. The database retains both legacy method identifiers; saved-review behavior continues to label legacy rows without offering those methods for new generation. Human review remains required before closing EXT-44 or EXT-36.

---

# 10. Chatbot Module

The chatbot uses a **hybrid deterministic + generative architecture**.

**Status: complete.** EXT-47 and its knowledge-management, routing, resolver, provider, orchestration, interface, and testing issues are Done. EXT-61 provides the completed mobile endpoint. Web and mobile share the same backend pipeline.

```text
Customer Query
        ↓
Deterministic Query Categorization
        ↓
Retrieve Relevant System / Database Context
        ↓
Build Context for the Query
        ↓
Configured Gemini 3.5 Flash-Lite
        ↓
Natural-Language Response
```

## Supported Query Categories

- product;
- order;
- store;
- FAQ.

### Deterministic Stage

The application first identifies the query category using predefined rules.

Relevant information is then retrieved from system data or chatbot knowledge.

### Generative Stage

The retrieved information is combined with the customer's query and provided as context to Gemini.

Gemini produces the final natural-language response.

```text
Categorization = deterministic
Data retrieval = application/database controlled
Final wording = generative when safe context/provider are available; otherwise grounded fallback
```

Unsupported inquiries use predefined fallback behavior.

## Implemented Privacy and Scope Controls

- Normalize and route deterministically, clarify ambiguous/multiple topics, and retrieve only the selected category's authoritative context before calling the AI adapter.
- Public product/store/FAQ inquiries work for guests. Personal order inquiries require a customer identity and recheck ownership; foreign/missing orders share a safe fallback. Only Sagay live inventory is available.
- Product, price, inventory, order, and branch facts come from live application data. Active approved knowledge supplies FAQ content and must not override live facts. Sensitive input and absent/unsupported context are handled before a provider call.
- Provider timeout/failure/empty output uses existing resolver facts and approved fallback wording without inventing business facts. No automatic retries or alternate AI provider are enabled.
- Follow-ups use an encrypted 15-minute context token holding references/clarification state, not a persistent chat transcript. Web context is session/user-bound; mobile customer context is account/bearer-token-bound. Guest API context continues public topics only and supplies no identity. Reset current-chat memory on New chat or identity/token changes, and requery live facts each turn.
- Rate limits are five guest questions per minute by IP and ten customer questions per minute by customer identity; the API also has its global limiter.
- Open-domain chat, complex complaint resolution, compatibility advice, and chatbot-driven recommendations are outside scope. Do not invent warranty/refund/repair or other business policies absent approved context.

The chatbot does not perform the Product Recommendation Module's recommendation logic.

---

# 11. Current Database Baseline and Forecast Persistence

Current migrations/models implement the following core entities:

- Users
- Branches
- Categories
- Products
- Tags
- Product Tag
- Inventory
- Carts
- Cart Items
- Orders
- Order Items
- Shipments (EXT-87 persistence and EXT-89 manual workflow)
- Sales
- Forecasts
- Chatbot Knowledge

Framework infrastructure includes sessions, password reset tokens, cache/jobs, and Sanctum personal access tokens. **Forecasts are implemented** with product, method, predicted demand, target quarter, generation time, and uniqueness on product/method/quarter. New application writes require additive_holt_winters; method constraints retain both legacy identifiers.

## Relationship Baseline

Implemented relationships include:

- Categories and Products;
- Products and Tags through Product Tag;
- Products and Inventory;
- Users and Carts;
- Users and Orders;
- Carts and Cart Items;
- Cart Items and Products;
- Orders and Order Items;
- Order Items and Products;
- Orders and Shipments through one unique `shipments.order_id` for quoted delivery orders;
- Orders and Sales;
- Products and Forecasts.

Inventory is unique per product, carts are unique per customer, and sales are unique per order. Branches are reference records: `2026_09_26_151046_remove_branch_id_from_users_table.php` removes the former user/branch foreign key. Do not infer current user-branch membership or per-branch stock from older context.

Migrations and model definitions establish the actual foreign keys, columns, constraints, and relationships. Do not infer additional relationships merely because they are common in e-commerce applications. The implemented Product/Forecast relationship remains product-focused under the approved migration.

## Retained Decisions and Subsequent Implementation

EXT-6 is Done. Its recorded baseline decisions remain useful historical context, but subsequent migrations and completed features supersede stale statements about the current schema or deferred work. This section describes implementation without claiming to revise or synchronize the manuscript:

- use Laravel-compatible unsigned big integer primary and foreign keys rather than interpreting the ERD's generic `int` labels as a required physical storage size;
- retain Laravel authentication infrastructure fields and tables required by the installed authentication features, including `email_verified_at`, `remember_token`, conventional timestamps, password reset tokens, and sessions;
- retain the implemented `forecasts.method` column for compatibility/history; `additive_holt_winters` is the production method, while the completed forward constraint migration preserves legacy records;
- treat Sagay City as the sole operational branch for live sales and inventory, selected through application configuration; the current schema has no `branches.is_primary`;
- retain the implemented `is_active` boolean with a default value of `true` on categories, products, and chatbot knowledge; use neither a lifecycle status enum nor Laravel soft deletes for these records;
- use `DECIMAL(12,2)` for monetary values, matching unsigned integer types for quantities, and explicit foreign-key, uniqueness, and non-negative-value constraints where required by the approved relationships and business rules;
- use `customer` and `administrator` roles; `pending`, `processing`, `completed`, and `cancelled` order states; `pending`, `verified`, and `rejected` payment states; and `product`, `order`, `store`, and `faq` knowledge categories. Chatbot routing also supports an unsupported outcome. `additive_holt_winters` is the sole production forecast method. Current constraints also support legacy `moving_average`/`linear_trend` for historical compatibility.
- expose **8:00 AM–6:00 PM** for the seeded reference branches through application configuration, without an `operating_hours` column.
- seed confirmed branch addresses and contact numbers into the existing reference fields; keep unavailable values null and do not invent placeholders.
- keep branch email reference data in application configuration; the current schema has no `branches.email` column.
- keep deactivated categories, products, and chatbot knowledge in the database for historical and administrative reference, allow administrators to reactivate them, and prohibit physical deletion when historically referenced;
- exclude inactive products from the customer catalog, cart eligibility, and recommendation results; exclude inactive categories and their products from customer browsing; and exclude inactive chatbot knowledge from chatbot retrieval;
- keep recommendations behavioral and rule-based using retained searches/views/dwell, cart context and completed purchases; preserve current active catalog/Sagay stock eligibility and exclude explicit PC-part compatibility/configurator behavior.

Subsequent implemented schema/commerce decisions include:

- orders snapshot required recipient name, contact number, and fulfillment method; delivery address is nullable in persistence, remains null for pickup, and is required by delivery checkout validation;
- fulfillment methods are `pickup` and `delivery`;
- manual payment methods are `cash`, `card_at_store`, `gcash`, and `maya`, stored in the string-backed `orders.payment_method` column;
- the proof column is nullable for non-wallet orders, while checkout requires private proof for GCash/Maya and prohibits it for cash/card-at-store;
- rejected wallet payments carry customer-facing rejection feedback and allow eligible proof replacement;
- users have profile delivery-address and appearance fields; products have unique product codes, nullable brands, import tracking, and `image_path` rather than the former image URL field;
- EXT-86 adds the enum-cast `products.shipping_profile` string field (`standard`, `fragile`, `bulky`), defaulting existing and new products to `standard`. This persisted field is the sole assignment source; category changes do not infer handling. Server-side catalog preparation must explicitly assign fragile/bulky products. Current import and admin forms preserve assignments but do not offer profile editing. Rolling back the profile migration removes those assignments;
- checkout validation, transactional placement, manual payment decisions, initial stock deduction, explicit cancellation restoration, and completed-order sales recording are implemented, as described in Section 7.
- EXT-87 adds immutable commercial snapshots on Order and a separate Shipment fulfillment snapshot. Historical order totals remain unchanged; historical subtotal/quote fields stay nullable and delivery fee defaults to zero. No historical shipment or quote is reconstructed. See the delivery persistence contract below.

---

# 12. Approved Scope Boundaries

## Branch Operations

Outside scope:

- multi-branch inventory synchronization;
- branch-specific operational customization.

Sagay City remains the primary operational branch.

Escalante City, San Carlos City, Guihulngan City, and Bacolod City are customer-reference locations containing static branch/store information in the repository.

The customer-facing operating-hours value for each listed branch is **8:00 AM–6:00 PM** and remains reference information rather than branch-specific operational customization.

## Payments

The system supports:

- payment-method selection;
- cash, card-at-store, GCash, and Maya as manually processed payment methods;
- private payment-proof evidence for GCash and Maya;
- manual payment verification procedures determined by Battlefront.

Outside scope:

- real-time online payment gateway integration;
- automated payment verification;
- credit-card gateway processing;
- third-party e-wallet gateway integration.

## Delivery and Logistics

The system supports:

- order placement;
- order monitoring;
- order status tracking.
- pickup and delivery fulfillment, with recipient and contact snapshots on the order.

Actual delivery continues through Battlefront's existing business processes.

### Configured Delivery Rules — EXT-86

The reusable Laravel `DeliveryRules` service reads `battlefront.delivery` configuration. These are **Battlefront-configured capstone/demo assumptions, not official LBC rates**. Sagay City is the fixed operational origin for the approved table; runtime does not calculate distance or use coordinates, Google Maps, geocoding, routing, or courier APIs.

| Destination     | Base fee (PHP) | Transit days |
| --------------- | -------------- | ------------ |
| Sagay City      | 80.00          | 0–1          |
| Escalante City  | 100.00         | 1            |
| Cadiz City      | 120.00         | 1            |
| Toboso          | 140.00         | 1            |
| Manapla         | 160.00         | 1            |
| Calatrava       | 180.00         | 1            |
| Victorias City  | 180.00         | 1            |
| E.B. Magalona   | 200.00         | 1–2          |
| San Carlos City | 220.00         | 1–2          |
| Silay City      | 220.00         | 1–2          |
| Talisay City    | 240.00         | 1–2          |
| Bacolod City    | 250.00         | 1–2          |

| Shipping profile | Handling surcharge (PHP) | Preparation days |
| ---------------- | ------------------------ | ---------------- |
| standard         | 0.00                     | 1                |
| fragile          | 50.00                    | 2                |
| bulky            | 100.00                   | 3                |

Priority is explicitly `standard < fragile < bulky`. Delivery fee is the destination base fee plus the highest applicable surcharge **once**, regardless of cart line count or quantity. Money remains two-decimal strings, added with BCMath at scale 2. Relative ETA minimum/maximum is the selected profile's preparation days plus the destination transit minimum/maximum; no date anchor, holiday policy, or guaranteed courier arrival is implied. Zero transit days means same-day transit once preparation is ready. Revised transit assumptions apply to new quotes only; persisted order/shipment snapshots retain their original values. A forward compatibility migration allows non-negative transit minima while preserving the other shipment snapshot constraints and existing rows.

`DeliveryRules::destinations()` lists canonical names and rules; `destination()` rejects unsupported names with `DomainException`. `handling()` accepts a `ShippingProfile`, and `highestProfile()` reads an iterable of server-loaded Products. `quote()` accepts a `FulfillmentMethod`, optional canonical destination name, and those Products; callers cannot supply fee, preparation, surcharge, or ETA values. Quotes include origin, demo identification, selected profile, fee components, and day ranges. Destination lookup is exact and never parses a free-text address. Empty delivery product lists or products lacking a loaded profile raise `InvalidArgumentException`. Pickup returns no delivery quote before destination or product evaluation.

EXT-86 implements the rule layer and product assignment field only. EXT-87 supplies quote snapshot/shipment persistence, and EXT-88 applies the rules through shared web/mobile checkout and strict delivery placement. EXT-89 adds manual shipment progression and customer tracking. EXT-90 now supplies shared database notifications and optional customer Expo push; live courier/GPS tracking remains outside scope.

### Delivery Snapshot and Shipment Persistence — EXT-87

`OrderPlacementService::executeWithDeliveryQuote()` accepts validated checkout data plus a supported canonical destination, and requires delivery fulfillment. It generates one authoritative EXT-86 quote from the locked current products and persists the order, items, shipment, initial stock deduction, and cart consumption in the existing retried transaction. Shipment creation failure rolls back all of those database changes. No caller-supplied fees or estimates are accepted, and no external mapping/courier API is called.

Order owns `delivery_destination`, `delivery_base_fee`, `shipping_profile`, `handling_surcharge`, `delivery_fee`, and `product_subtotal`; existing `total_amount` is the final total, calculated as subtotal plus delivery fee using BCMath at scale 2. All money columns use DECIMAL(12,2) and decimal-string casts. Order also stores `delivery_origin_city`, `delivery_is_demo`, and `delivery_assumption_label` so historical demo assumptions retain their original context. Snapshot values are not re-read from current configuration or product profiles.

Shipment owns the configured manual carrier (`battlefront.delivery.carrier`, initially `lbc`), its separate enum-cast status, and `preparation_days`, `transit_min_days`, `transit_max_days`, `eta_min_days`, and `eta_max_days`. It reads the immutable handling profile through its Order relationship rather than copying that profile into a second column. The unique order foreign key permits one Shipment per quoted delivery order and restricts deletion of its owning order. Shipment model persistence rejects pickup and unquoted legacy orders and protects ownership, carrier, and relative ETA context from subsequent edits.

Initial status is **`awaiting_preparation`** while order/payment are pending. It means only that the shipment record exists; it does not imply packing or dispatch. Payment decisions, order processing and proof resubmission do not advance shipments. EXT-89 now coordinates shipment delivery/order completion and cancellation through the existing service workflow. Sales continue using the persisted final `Order.total_amount`.

`tracking_reference`, `handed_to_carrier_at`, and `delivered_at` remain nullable until the manual workflow records the relevant data. EXT-87 itself introduced no calendar anchor or timeline; EXT-89's operational estimates and milestones are described below. Shipment creation/update timestamps never substitute for preparation or delivery dates. There are no GPS/location, status-note, or notification fields.

Pickup placement saves its product subtotal and zero delivery fee and creates no Shipment. EXT-88 now routes all web/mobile delivery submissions through `OrderPlacementService::executeWithDeliveryQuote()` using the validated canonical destination. The original `execute()` compatibility entrypoint remains available internally and serves pickup; it is no longer the customer delivery HTTP path. A free-text address is never parsed or treated as a configured zone. Existing pre-revision orders remain readable with their original totals and nullable unknown snapshot fields.

Eloquent update guards protect placed commercial snapshots; database constraints validate quote completeness, non-negative amounts, monetary consistency, allowed profile/status values, and relative ETA consistency. Snapshot mutation must not bypass model guards through query-builder updates. Migration rollback refuses to discard saved delivery quotes or shipments; preserve historical data and use a forward migration instead. Opt-in order snapshot and shipment factories use DeliveryRules without changing existing fixture defaults or seeding operational shipments.

EXT-88 implements shared checkout/API quoting and wiring; EXT-89 implements shipment controls, transitions, operational shipment date anchoring, and customer tracking views; EXT-90 owns notifications. None of those behaviors is implemented by EXT-87 itself.

### Shared Delivery Checkout — EXT-88

`PrepareCheckout` obtains quotes from EXT-86 for the authenticated customer's current ready selected cart items and all 12 supported destinations. Existing GET checkout endpoints need no destination query parameter. Web props add `deliveryQuotes` and `pickupQuote`; API checkout exposes the equivalent `delivery_quotes` and `pickup_quote` inside the existing `data` envelope. Clients select exactly one quote's canonical destination without computing fees, profiles, ETA or totals. The pickup quote shows product subtotal, zero delivery fee and unchanged final total.

Delivery quotes include origin/destination, profile, base fee, handling surcharge, delivery fee, preparation/transit/total day ranges, configured carrier, packing expectation, demo assumptions, product subtotal, final `total`, and estimate notice. Highest-profile surcharge applies once per selected checkout irrespective of quantity/line count. Subtotal and final total remain two-decimal BCMath values. The web checkout preserves the recipient/fulfillment/payment flow, requires explicit destination selection alongside the separate address, and shows the selected server quote before placement/payment.

**Checkout calendar anchor:** one server quote-generation date in `config('app.timezone')` (currently UTC), taken once for all quotes in a response. Add EXT-86 minimum/maximum total days as calendar days; apply no weekend/holiday adjustment. Expose `eta_anchor_date`, `eta_timezone`, `estimated_delivery_start` and `estimated_delivery_end` as civil dates/timezone. These windows are checkout-only provisional presentation, not persisted shipment dates. Display LBC as the configured/manual carrier and state: “Battlefront estimates, not live LBC quotations or tracking. Delivery dates are provisional and subject to payment verification.” Demo assumptions remain identified.

`ValidateCheckoutRequest` requires a scalar configured `delivery_destination` for delivery and prohibits nonempty destination/address values for pickup. Missing, unsupported, noncanonical/wrong-case and array inputs fail with destination validation errors. The explicit checkout input allowlist ignores supplied prices, surcharge, profile, relative ETA, calendar dates, subtotal/final total and nested quotes. `PlaceCustomerOrder` calls EXT-87's strict entrypoint for delivery and its existing pickup entrypoint otherwise. Placement recalculates fees and relative ETA from locked current products/configuration and retains atomic order/items/Shipment/stock/cart behavior and private proof cleanup. Quote previews never reserve stock or persist shipments.

Shared customer order detail adds saved `product_subtotal`, `delivery_fee` and nullable `delivery_quote`; existing `total` remains the final total and history summaries retain their contract. The detail quote reads immutable Order/Shipment commercial/carrier/relative-ETA facts rather than current rules. It omits checkout calendar windows; legacy unknown subtotal/quote remains null. EXT-89 adds a separate nullable shipment payload and tracking panel; its persisted operational dates are distinct from EXT-88's provisional checkout windows.

### Selective Cart Checkout — EXT-91

The cart starts with all items selected on a fresh visit and offers per-item and Select all/deselect all controls. Selection stays in temporary Vue memory; no database flag or cross-session selection is added. Cart mutations retain surviving selected IDs. Empty selection or selected availability conflicts disable checkout; unselected unavailable items do not block checkout. The cart summary reactively displays selected line count, units and subtotal by aggregating server-provided quantities and line totals; no selection displays zero values. Checkout and placement independently recalculate authoritative selected totals.

Web/API GET checkout requires explicit `cart_item_ids[]` query parameters. POST orders requires the same nonempty list of distinct positive integer IDs in `cart_item_ids` (repeated `cart_item_ids[]` multipart fields for wallets). Missing, empty, malformed or duplicate selection fails validation; there is no full-cart fallback. Shared checkout and placement resolve every ID against the customer's current cart and reject foreign, missing, removed or unavailable selected items safely. Placement revalidates current quantities, eligibility, stock and effective prices under the existing locks.

Subtotal, highest shipping profile, surcharge, preparation, delivery fee, ETA and final total use selected products only. Unselected fragile/bulky items cannot influence the quote. Delivery retains one correctly snapshotted Shipment; pickup and all existing payment/proof rules remain. Purchased rows alone are removed atomically; unselected rows and quantities remain. Replaying purchased IDs cannot order the remaining cart. Cancellation restores only the saved purchased quantities.

### Manual Shipment Workflow and Customer Tracking — EXT-89

The dedicated shipment lifecycle is `awaiting_preparation → preparing → ready_for_dispatch → handed_to_lbc → in_transit → out_for_delivery → delivered`. Every transition requires a quoted delivery shipment, verified payment and an order explicitly in `processing`. Each administrator request advances exactly one milestone; skips, reversals, repeats and terminal transitions fail with validation errors. Payment verification and moving the order to Processing never advance a shipment implicitly.

`OrderProcessingService` locks the Order before its Shipment in a retried transaction. The shipment Delivered action atomically records `delivered_at`, completes the order and invokes the existing sale action once, using the saved final total and completion date. Direct order completion is blocked for orders with shipments. Pickup and legacy delivery orders without shipments keep their existing order workflow. Eligible order cancellation retains stock restoration and atomically marks the Shipment `cancelled`; this terminal status is available only through order cancellation, including after handoff. Repeated cancellation cannot restore stock twice. Payment rejection/proof replacement remain stock-neutral and do not advance milestones.

Shipment adds nullable `preparing_at`, `ready_for_dispatch_at`, `in_transit_at`, `out_for_delivery_at` and `cancelled_at`, retaining the handoff/delivery fields. At Preparing, one application-timezone calendar date anchors the saved `eta_min_days`/`eta_max_days`; `eta_anchor_date`, `eta_timezone`, `estimated_delivery_start` and `estimated_delivery_end` are persisted together and cannot subsequently change. Apply calendar days without weekend/holiday adjustments. Arrival remains an estimate, not a courier guarantee. Before preparation, expose the saved relative range and null calendar dates. Cancelling retains the original estimate as historical context.

Administrator-only web PATCH routes are `administration/orders/{order}/shipment/status` and `/shipment/reference`, protected by session authentication and `access-administration`. Status accepts the approved next status and an optional real reference at handoff; reference updates accept a nullable string of at most 255 characters. References can first be supplied at handoff, then corrected or cleared during transit or after delivery. Cancelled shipments are read-only. Submitted carrier, fees, dates or other operational fields are ignored. No customer/mobile shipment mutation endpoint exists, and no customer-safe shipment note is added.

Shared customer web/API order detail adds nullable `shipment`: saved carrier, labeled manual status, nullable real reference, persisted operational ETA, chronologically sorted recorded milestones, manual-tracking notice and nullable historical notice. Existing `delivery_quote`, fees and fulfillment address retain their contracts. Owning-customer queries conceal foreign orders with 404. The administrator detail reuses those facts and supplies only eligible shipment actions. Wording explicitly states that Battlefront maintains status manually and this is not live LBC/GPS tracking. No maps, invented references, external carrier calls or automatic booking are introduced.

The forward migration expands existing MySQL checks/SQLite triggers without changing quote constraints or historical commercial values. Existing shipments attached to cancelled orders become `cancelled` without invented cancellation dates. Previously completed orders remain readable and read-only without reconstructing delivered milestones. Orders without shipments retain null shipment data; no historical shipments are created. Rollback refuses to discard recorded workflow or ETA data. EXT-90 adds database/in-app and optional Expo push notifications after committed milestones; browser Web Push remains outside scope.

Outside scope:

- courier API integration;
- logistics management;
- route optimization;
- real-time delivery tracking.

## Recommendation Data

The recommendation engine uses internal catalog/inventory, completed-order aggregates and the owner's eligible search/view/dwell/cart history.

Outside scope:

- external supplier pricing;
- external supplier availability;
- competitor pricing;
- external market product availability;
- explicit PC-part compatibility checking or configurator behavior;
- a dedicated product-to-product compatibility data model.

## Forecasting Data

Forecasting uses covered historical Battlefront unit sales; current inventory remains separate planning context. Clearly identified quarterly synthetic history and coverage metadata are implemented; EXT-40 will revise them to monthly fixtures/declarations. Validated real client history should be imported later if supplied; synthetic history cannot establish operational readiness. Synthetic development data is not an external market dataset and must not be presented as operational client history.

Outside scope:

- external forecasting datasets;
- external market trends;
- external economic indicators;
- supplier-disruption inputs;
- other external market intelligence.

## Chatbot Behavior

Supported:

- product inquiries;
- order inquiries/status;
- store information;
- FAQs.

Outside scope:

- unrestricted open-domain conversation;
- automated complex complaint resolution;
- chatbot-driven product recommendation.

Product recommendations remain the responsibility of the Product Recommendation Module.

---

### Shared Order and Shipment Notifications — EXT-90

Laravel database notifications are the authoritative account-bound history for customer web/mobile and administrator web. Existing order placement, payment review, proof replacement and shipment services publish events only after successful commit. Notification persistence runs immediately afterward; failed persistence is retried on the existing database queue. Notification or push failure cannot change a committed business outcome or trigger checkout proof cleanup. Event/recipient notification UUIDs and per-device delivery uniqueness prevent duplicate history on replay; subsequent payment-review cycles remain separate events.

Customer events are payment verified/rejected, order cancelled, and shipment preparing, ready for dispatch, handed to LBC, in transit, out for delivery and delivered. All administrators receive new-order and initial/replacement wallet-proof notices. Shipment creation, reference corrections and insignificant updates are silent. Operational ETA remains immutable, so there is no ETA-revision notification. Existing shipment delivery/completion/sale consistency and cancellation restoration remain authoritative.

Authenticated web headers expose a notification bell, unread count, five recent entries and a paginated history page. Individual/all-read actions update the same database state used by mobile. Customer and administrator routes retain their existing role gates, recipient/audience scoping and ownership checks on order destinations. No browser Web Push, email/SMS or courier API is introduced.

The web bell polls every 30 seconds while visible through dedicated session-authenticated `notifications/summary` and `administration/notifications/summary` GET routes. Inertia's standalone `useHttp` client retrieves only unread count/five recent entries, then `replaceProp` updates only `notificationSummary`, preserving the current page, scroll and form state. This avoids re-running the current page's checkout/reporting/forecasting controller. Hidden tabs pause, slow requests cannot overlap, navigation/read actions invalidate stale responses, and identity changes/unmount cancel pending work. Unauthorized sessions stop polling. Response metadata identifies the current server user/audience; a cross-tab account mismatch clears the old summary and stops polling until the page/account is refreshed. The response is private/no-store and uses three bounded notification/owned-order queries; no page-data polling, extra mobile endpoint or dependency is added.

Customer-only Sanctum endpoints add history/count/read actions and device registration/revocation under `/api/v1`. Resources expose only safe notification fields and nullable owned-order metadata, including `{screen: "order_detail", order_id}`. See the mobile API handoff and Postman collection for exact routes, examples and failure responses.

Push devices are account/device scoped, support multiple devices and bind to the registering Sanctum session. Logout, token revocation/expiry or explicit device revocation stops future sends; registration must be repeated after login. Raw Expo tokens are encrypted and omitted from responses/logs; active token collisions cannot transfer another account's registration. Invalid/unregistered provider feedback deactivates only the matching registration version.

Expo push is disabled by default, implemented through Laravel HTTP behind an adapter, and queued on the existing `database` connection's `notifications` queue. Persisted tickets receive delayed receipt checks; transient provider failures use bounded retries, while history stays available. Push text is generic and metadata cannot authorize order access. Accepted tickets/receipts establish provider acceptance, not phone delivery. Push can be missing or duplicated after uncertain network/process outcomes.

The developer selected after-commit persistence with queue retries without a transactional outbox. A crash between business commit and notification persistence, or simultaneous persistence/queue failure, can leave history missing. Queue enqueue failures can leave pending push/receipt work unsent; sanitized diagnostics and worker failures require operator review. No historical notifications are reconstructed. Actual Expo/native consumer validation remains separate from automated backend/UI verification.

# 13. Development Methodology

The study follows a **descriptive-developmental research design**.

The software development methodology is **Agile SDLC**, selected because the system contains multiple interacting components that benefit from iterative development, testing, stakeholder feedback, and refinement.

```text
Requirements
    ↓
Design
    ↓
Development
    ↓
Testing
    ↓
Deployment
    ↓
Review
```

Development is intended to be iterative and incremental rather than one large single-stage implementation.

## Current Development Workflow

Follow **Understand → Plan → Implement → Test → Explain → Human Review → Complete** for non-trivial development. Read applicable `AGENTS.md`, `.ai/rules`, relevant skills, current implementation, and the approved issue before changing behavior. Confirm installed package versions and use version-appropriate documentation when APIs matter.

Keep changes focused on the approved issue, reuse existing components/actions/services, and preserve shared web/API business rules. Vue application work uses Composition API and plain JavaScript, existing Inertia patterns, and Wayfinder routes. Do not introduce dependencies, architectural changes, additional frameworks, or a mobile UI in this repository without explicit approval.

Run the narrowest relevant automated checks for code changes; apply Pint to changed PHP and the applicable static-analysis/build checks. Keep frontend formatting focused and avoid cosmetic-only diffs. Explain behavior, validation, and unresolved limitations for human review. Passing tests do not constitute human approval or authorize marking a Linear issue Done. Do not modify Linear issues/comments or create commits without authorization.

Documentation-only updates require source/diff review rather than invented feature tests. Manuscript changes require explicit authorization and must preserve unrelated academic content. The 2026-10-05 migration authorizes only necessary forecasting text synchronization; it does not authorize general manuscript rewrites or implementation work.

---

# 14. Current Delivery Status and Remaining Work

General status snapshot verified against Linear on **2026-10-01**; the forecasting row reflects the implemented EXT-40/42/43/45/46 migration and EXT-44 cleanup:

| Area                                                                             | State                                                                 | Evidence / remaining boundary                                                                                                                                                                                                                             |
| -------------------------------------------------------------------------------- | --------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Core catalog, inventory, roles, and branch reference                             | Complete                                                              | Implemented routes/models/services and completed core issues                                                                                                                                                                                              |
| Cart, checkout, orders, manual payments, cancellation restoration, sales/reports | Complete                                                              | EXT-22–27, EXT-28–34, EXT-80–83 Done                                                                                                                                                                                                                      |
| Behavior-driven recommendation implementation                                    | Complete for current web/backend paths                                | Adviser approval confirmed by the developer on 2026-10-08; guest profiles, tracking, recommendations, and registration-only history merge implemented; mobile migration is developer-confirmed; device validation and evaluation evidence remain separate |
| Integrated chatbot                                                               | Complete                                                              | EXT-47–55 and EXT-61 Done                                                                                                                                                                                                                                 |
| Mobile API foundation/customer endpoints                                         | Complete                                                              | EXT-56–61 and EXT-64 Done                                                                                                                                                                                                                                 |
| Mobile handoff and Postman collection                                            | Complete                                                              | EXT-62 Done; developer-reported successful manual Postman Desktop verification                                                                                                                                                                            |
| Actual React Native + Expo integration                                           | Pending                                                               | EXT-63 Backlog; preparation complete, real LAN consumer journeys not run                                                                                                                                                                                  |
| Predictive analytics and historical development data                             | Monthly Holt–Winters production implemented; final acceptance pending | EXT-40/42/43/45/46 are implemented; EXT-44 retires obsolete production paths, and EXT-36 awaits human acceptance                                                                                                                                          |
| Cross-module integration and release-quality checks                              | Pending                                                               | EXT-65–72 Backlog                                                                                                                                                                                                                                         |
| Deployment, pilot evaluation, and release candidate                              | Pending                                                               | EXT-73–79 Backlog                                                                                                                                                                                                                                         |

## Completed Mobile API Scope

`routes/api.php` defines 29 `/api/v1` endpoints covering health, customer registration/login/logout, profile read/update, catalog search/filter/detail, branch information, cart operations, checkout preview, order placement/history/detail, rejected-proof replacement, chatbot, behavioral recommendation feeds/interactions, shared notification history/read state, and Expo device registration/revocation.

The mobile product list additionally supports multiple active categories (`category_ids`), inclusive effective-price bounds (`min_price`, `max_price`), and `featured`/`price_asc`/`price_desc` sorting. These parameters are enabled only for the named API product-list route; the existing Inertia web catalog keeps its singular category/brand/tag filters, search, default ordering, and scroll behavior. Both clients retain shared catalog eligibility and presentation. This extension adds no endpoints or schema changes; see the handoff for validation and pagination details. React Native consumer validation of the additions remains pending.

Controllers reuse shared business logic and safe resource presenters. Responses use the documented JSON envelopes, pagination, validation/access errors, and rate limits. The global API limit is 60 requests per minute per IP, with additional authentication/chatbot limits. No mobile administrator operations, token refresh, password-reset/change, customer cancellation, payment gateway, or courier endpoints are present.

Supporting artifacts:

- [Mobile API handoff](MOBILE_API_HANDOFF.md)
- [Postman collection](Battlefront_API_v1.postman_collection.json)
- [Postman environment placeholders](Battlefront_API_v1.postman_environment.json)
- [React Native + Expo validation plan and evidence gaps](MOBILE_INTEGRATION_VALIDATION.md)

Known contract limitations remain explicit: registration validates but does not persist the delivery address (profile update does); private proof has no mobile download endpoint; guest chat context is not device identity; order placement has no idempotency-key contract. This context update does not change those behaviors.

## EXT-62 and EXT-63 Completion Boundary

EXT-62 is complete. The developer reported successful manual Postman Desktop testing and approved it; the handoff does not contain a dated run export/screenshots. Existing preparation records 275 API tests passing with 1,732 assertions. These are recorded prior results, not a fresh test run performed for this context update.

EXT-63 remains pending real **React Native + Expo** validation with the responsible group member, preferably on a physical phone on the same Wi-Fi/LAN as the backend laptop. Confirm the correct Herd site is reachable, exercise device and native-client `/api/v1/health`, then run the documented customer journeys, image/link access, uploads, access failures, and logout behavior. Record app/backend revisions, Expo runtime, device, and redacted results. Current preparation records all RN-01–RN-23 journeys as not run and has established no API-contract defect.

Postman, desktop health, or automated backend success cannot close EXT-63. Classify observed mismatches as backend, client, environment, or contract clarification; keep fixes focused and Laravel-owned unless responsibility is explicitly reassigned. Consumer retest evidence and human review remain required.

## Remaining Integration, Testing, and Release Work

- EXT-65/66/68: verify the complete customer web journey, web/API rule consistency, and administrator operational journey, including forecasting when available.
- EXT-67/69/70: security/data-privacy review, database/query-performance review, compatibility/responsiveness and manual API testing.
- EXT-71/72: full release-quality verification and focused integration-defect resolution.
- EXT-73–76: production configuration/operations planning, controlled production data initialization, release evidence, and Sagay deployment/smoke testing.
- EXT-77–79: Sagay pilot UAT/capstone evaluation, findings triage, and an evaluated release candidate.

Completed feature tests do not mean these broader acceptance gates are complete. Follow issue dependencies and acceptance criteria; this roadmap does not authorize implementing multiple modules or expanding scope at once.

---

# 15. Testing and Evaluation Context

Testing is part of the approved Agile development process.

Laravel's built-in testing framework using **Pest/PHPUnit** is used for automated unit and feature testing of important application and business logic.

Relevant implementation areas include:

- authentication and authorization;
- product/category behavior;
- inventory management;
- cart and checkout behavior;
- order processing;
- sales behavior;
- recommendation rules;
- forecasting calculations, monthly aggregation/readiness, persistence and administrator workflow (Holt–Winters production tests, paired Moving Average and Seasonal Naive evaluation tests, and legacy compatibility tests);
- deterministic chatbot categorization;
- REST API behavior required by React Native.

Postman is used for manual REST API endpoint testing where appropriate.

Automated tests use SQLite in memory; MySQL remains the development backend and intended deployment database. Treat MySQL/database review, full integration testing, actual mobile consumer evidence, and release acceptance as separate checks. See Section 14 for existing verification evidence and pending gates.

## Capstone Evaluation

The following evaluation framework is retained from the existing project context. Formal evaluation remains pending; manuscript wording was not reverified during this update.

### McCall's Software Quality Model

Used to evaluate software quality, including the manuscript's identified quality criteria under:

- product operation;
- product revision;
- product transition.

### Computer System Usability Questionnaire (CSUQ)

Used for customer usability evaluation.

The manuscript also assigns usability evaluation through McCall's model for the appropriate IT-expert and Battlefront-personnel evaluators.

Implementation and testing should therefore preserve measurable correctness, usability, maintainability, and system-quality characteristics needed during formal evaluation.

---

# 16. Data Privacy Context

Customer and transaction information must be handled in accordance with the **Philippine Data Privacy Act of 2012**.

Relevant system areas include:

- customer accounts;
- transactions;
- orders;
- API responses;
- application data;
- chatbot context.

Only information required for the intended system function should be exposed to users, clients, or external services.

---

# 17. Project Decision Boundaries

### Approved Scope

Represented by the approved manuscript and explicit authorized study revisions. For forecasting, the latest 2026-10-05 developer-authorized monthly additive Holt–Winters revision supersedes both the original two-method scope and the later four-quarter moving-average production decision.

### Necessary Implementation Detail

A technical implementation decision required to deliver an approved capability without changing its intended scope or architecture.

### Optional Enhancement

A potentially useful improvement that is not necessary for the approved system.

### Scope Extension

A capability that adds behavior beyond the approved manuscript.

Features categorized as scope extensions should not be treated as existing project requirements.

---

# 18. Source of Truth

## Current Repository State

For maintaining this development context, use this source order:

1. Current repository implementation: routes, controllers, shared actions/services, models, migrations, configuration, dependency versions, and tests.
2. Linear issue state, explicit acceptance criteria, and approved scope/implementation decisions.
3. Existing handoff and context documents.
4. Existing Postman/mobile API documentation and artifacts.

Implementation establishes what currently exists; approved issue scope establishes what remains intended. Do not describe planned work as implemented, or treat a historical document's stale statement as authority over current code. Do not silently infer completion from tests or a prepared checklist.

## Academic Scope and Manuscript Handling

The approved Chapters 1–3 manuscript and authorized amendments remain academic scope references. For this forecasting migration, the developer explicitly approved the study revision and the following precedence:

1. The latest approved 2026-10-05 monthly additive Holt–Winters forecasting decision.
2. Revised Linear scope (EXT-36, EXT-40, EXT-42–46).
3. Current implementation after each migration issue is completed.
4. Revised CAPSTONE_CONTEXT.
5. Manuscript synchronized to the approved revision.

Existing legacy code does not override the approved destination; Section 9 distinguishes implemented foundations from pending changes. The forecasting text in `docs/capstone_manuscript.docx` is narrowly synchronized under explicit authorization, preserving unrelated content, formatting, and the product-focused Forecast ERD. Other manuscript amendments remain developer-owned. Surface unresolved diagram/text or scope conflicts rather than silently revising unrelated material.

The embedded Figure 8 architecture image still contains the older `Trend Analysis / Moving Average` label. Its revised explanatory paragraph explicitly supersedes that label with monthly additive Holt–Winters, three monthly estimates summed into quarterly demand. The raster itself remains an unresolved artwork follow-up rather than being silently treated as synchronized. The image bytes are preserved; updating only that label in the original diagram artwork remains a developer follow-up. The DFD can retain inventory as separate planning context, and the product-focused Forecast ERD remains unchanged; its earlier omission of the separately approved compatibility `method` column is not a new schema requirement.

## Evidence to Obtain Later

- Real React Native + Expo LAN run evidence and resolution of Herd/device reachability prerequisites (EXT-63).
- Implemented/tested monthly fixtures/readiness/aggregation, additive Holt–Winters and held-out evaluation, compatible persistence, updated simple workflow, accepted retirement cleanup, and validated real historical client data if later supplied (EXT-40/42/43/45/46/44).
- Integration, production readiness, pilot evaluation, and release acceptance evidence (EXT-65–79).

This context update does not supply that evidence or close those pending issues.

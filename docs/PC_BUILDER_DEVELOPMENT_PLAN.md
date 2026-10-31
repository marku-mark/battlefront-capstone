# Battlefront PC Builder Development Plan

**Status:** Planning proposal only. The PC Builder is not implemented or approved for application development by this document.

## 1. Existing system findings

Battlefront is a Laravel 13 monolith using Vue 3, Inertia 3, Tailwind 4, Wayfinder, and MySQL. Runtime inspection reported PHP 8.5; repository instructions state PHP 8.4. The mobile client is a separate React Native + Expo project consuming Laravel APIs. No mobile UI work is included in the first release proposal.

`Product` already owns catalog identity, effective prices, category/tags, eligibility, shipping profile/package dimensions, and a one-to-one inventory relationship. It has no normalized PC component specification catalog. Product package dimensions must not be reused as GPU/case/cooler fit dimensions. Wishlist items represent products and user ownership; a build needs its own composition and ownership semantics.

`CartService::add()` merges lines by cart and product. `OrderPlacementService::place()` owns key order behavior: locks relevant records, validates selected customer cart lines, calculates current prices, snapshots commercial values, decrements stock, creates shipment information, and consumes selected lines transactionally. Delivery quotation currently maps quantities by product ID. A builder integration must preserve build group identity and aggregate repeated product IDs before availability checks and delivery calculation.

PayMongo Hosted Checkout and payment flows already exist, along with order/payment state and submission identity. The builder should reuse those established paths. Existing shipping/fulfillment is configured for existing product/order assumptions; assembly services and assembled-PC packaging are outside this parts-bundle proposal. Gemini chatbot behavior follows its existing adapter boundary and is not a compatibility authority.

## 2. EasyPC reference comparison

Public pages reviewed on 2026-10-27:

| Reference                                                  | Publicly visible evidence                                                                                                                  | Limits                                                                                      |
| ---------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------- |
| [EasyPC homepage](https://easypc.com.ph/)                  | Build-a-PC navigation, hardware categories, products and cart controls.                                                                    | Checkout behavior was not tested.                                                           |
| [Build A PC](https://easypc.com.ph/pages/build-a-pc)       | Advertises 17 categories, real-time compatibility, comparisons, sharing, pricing and cart transfer.                                        | These are published claims; correctness and runtime behavior were not validated.            |
| [Build Your PC](https://easypc.com.ph/pages/build-your-pc) | Eight selectors, build load/save/share/clear controls, price/power/compatibility summary, Add All to Cart, search and out-of-stock toggle. | Controls observed but not exercised; persistence and compatibility accuracy remain unknown. |
| [Easybuild](https://easypc.com.ph/pages/easybuild)         | Priced prebuilt configurations with product links.                                                                                         | Not evidence of an interactive compatibility engine.                                        |

No Simple/Advanced mode was observed; these are proposed Battlefront modes. Do not copy competitor code, images, branding, or proprietary text.

## 3. Functional requirements

- Select eligible Battlefront products by component role and show current server-sourced price and availability.
- Provide a guided Simple mode and a filter-rich Advanced mode; both use the same server-side rules and result semantics.
- Show selected components, total price, incomplete requirements, per-rule compatibility outcomes, evidence explanations, and corrective guidance.
- Save, rename, load, duplicate, and delete customer-owned builds without reserving inventory.
- Transfer selected build components to cart while retaining build grouping and allowing repeated SKUs to be counted correctly.
- Support authorized administrator review of component-role mapping, specifications, source evidence, and verification status.
- Keep all prices, inventory availability, ownership, and compatibility decisions server-validated at cart/checkout boundaries.

## 4. Non-functional requirements

Keep compatibility deterministic, explainable, testable, and reproducible from versioned rules and source records. Preserve current web/API business boundaries, historical order data, existing customer/payment records, accessible responsive behavior, and application performance. Record evidence for specifications and distinguish unknown facts from verified facts.

## 5. Hardware specification model

Propose normalized facts linked to the existing product identity. Capture exact manufacturer/model/part number/revision, component role, typed values and units, source URL/section, verification date, reviewer/review status, and source revision where available. Keep source claims and normalized values traceable. Do not create duplicate catalog products or derive component fit from shipping parcel dimensions.

Minimum domains: CPU socket/chipset/BIOS support; motherboard CPU support, memory generation/capacity/population, slots and lane-sharing; RAM generation/form/capacity; GPU length/height/thickness, occupied slots and power connectors; PSU output/rails where sourced, connectors, and exact cable compatibility evidence; case clearances/configuration; cooler mount kits, height/radiator fit and interference; storage protocol/form factor/port sharing; expansion slots. Missing or contradictory values remain explicitly unknown.

## 6. Compatibility engine architecture

Propose a Laravel-owned deterministic evaluator that consumes exact selected product IDs, reviewed specifications, and a versioned rule set. Return per-rule status (`compatible`, `warning`, `incompatible`, `unknown`), rule/version, affected components, explanation, evidence, missing facts, and suggested resolution. An incomplete build is distinct from a compatible build; aggregate results must not hide individual failures or unknowns.

Examples: known socket mismatch is incompatible; matching socket without exact motherboard CPU/BIOS evidence remains unknown or conditional; a documented BIOS update requirement is a warning; verified memory generation/slot violations are incompatible; configuration-dependent GPU/radiator clearance requires exact setup evidence; missing PSU cable evidence remains unknown. Compatibility means only that evaluated, evidenced checks passed. It is not an installation or stability guarantee. Gemini may explain results but never set statuses.

## 7. Database design

Propose product-linked specification and provenance records, a controlled component-role mapping, saved-build ownership and composition records, and immutable purchase/build snapshots where commerce review establishes the necessary boundary. Define owner exclusivity, product/build uniqueness, component ordering/role cardinality, source/review state, delete behavior, and query indexes.

Before finalizing fields, indexes, or migration sequencing, inspect exact existing MySQL schema constraints through Laravel Boost. The audit confirmed model/service relationships but its schema output was truncated. Use forward-only data-preserving migrations, deploy compatible code/schema in a safe order, and define recovery that does not remove customer builds or order history. Never reset or rewrite existing customer, inventory, payment, or order data.

## 8. Customer interface

Build an Inertia/Vue customer flow consistent with existing catalog, cart and wishlist patterns. Simple mode guides customers through essential component roles with clear incomplete states. Advanced mode adds filters, technical details, and rule explanations. Both show current price, stock, selected parts, subtotal, compatibility state, and actions to save or add the group to cart.

Design loading, empty, stale-price/stock, source-unknown, invalid, and error states. Support keyboard operation, semantic headings/labels, visible focus, screen-reader announcements for rule changes, responsive layouts, and accessible contrast. Use named Wayfinder routes and existing shared UI conventions.

## 9. Admin functionality

Propose role-authorized product-to-component-role management and specification evidence review. Administrators need to inspect source, verification date/status, conflicts, and affected compatibility rules; corrections should preserve prior evidence/history where needed. Reuse current administration authorization and UI patterns. Do not assume unrestricted bulk import or unreviewed AI extraction.

## 10. Saved builds and wishlist

Saved builds belong to their customer (or an explicitly designed guest identity) and contain references to existing products plus a stable selection snapshot as required by later schema review. They do not become fake products or wishlist SKUs and do not reserve stock. Define behavior when a product is inactive, deleted, out of stock, repriced, or its specification changes. Current WishlistItem semantics remain intact; cross-linking builds into wishlist requires a separate decision.

## 11. Cart and checkout

Represent the build as a cart grouping/metadata boundary while retaining ordinary product lines and order item semantics where feasible. Prevent build parts from merging indistinguishably with loose purchases or separate builds. Aggregate all repeated product quantities per product before validation, locking, and delivery quoting. Revalidate customer ownership, active eligibility, price, stock, and required compatibility policy server-side when adding to cart and placing an order.

Show grouped contents and current totals through the existing checkout flow. A saved build alone creates no reservation and no checkout entitlement. Decide separately whether incompatible/warning builds can be purchased and whether warnings require acknowledgement; unknown compatibility must remain visibly unresolved.

## 12. Inventory integration

Use the existing product inventory source and current Sagay operational assumptions. Stock is checked at cart/placement boundaries and decremented only by the established atomic order placement. Account for aggregate demand when a SKU appears more than once. Do not add synthetic bundle stock, reserve stock during saving, or silently transfer stock across branches.

## 13. PayMongo reuse

Reuse the existing payment origin, hosted checkout/payment flow, attempt/operation records, idempotency, authenticated provider events, and current online methods. Do not create a parallel payment subsystem or treat a return redirect as proof of payment. Preserve current manual payment workflows, permissions, cancellation/payment reconciliation, and refund review. Builder work does not authorize new credentials, dashboard/webhook configuration, live transactions, or gateway scope changes.

## 14. Order management

Keep existing order placement as the single transactional boundary for commercial totals, stock reduction, and order snapshots. Make grouped-build identity and component composition visible to the customer and authorized administrators without allowing later saved-build edits or product changes to rewrite placed orders. Preserve existing cancellation, return, payment, reporting, and historical manual orders.

## 15. Shipping and LBC

Use existing delivery quote, destination snapshots, shipment creation, and fulfillment lifecycle for the selected components, subject to corrected aggregate quantities/weights. Validate whether current package weights and delivery rules represent a multi-item parts bundle before release. Assembly, custom packaging, assembled-PC insurance/warranty promises, and live LBC API operations are not part of this proposal. Any future real carrier adapter requires verified documented contracts and separate approval.

## 16. Gemini chatbot opportunities

Optional later enhancement: let the existing chatbot summarize user goals/budget and explain already-computed rule results using only application-prepared scalar/array context. Compatibility computation remains deterministic and server-owned. Do not pass Eloquent models, secrets, unreviewed specs, or unnecessary personal data. Treat generated suggestions as guidance, never as a compatibility result or automatic cart action.

## 17. Security and privacy

Authorize each saved build and every product selection on the server. Restrict specification administration to existing authorized roles. Validate request shape and ownership, apply rate limits to expensive search/evaluation paths, prevent mass assignment, and avoid exposing private notes or customer data. Bind orders to current product IDs/prices/stock and verified server-side rules. Protect webhook/payment invariants and log only sanitized diagnostics. Follow Philippine Data Privacy Act obligations and existing project privacy conventions.

## 18. Testing and acceptance

Proposed test matrix includes socket mismatch; matching socket with missing CPU/BIOS support; DDR mismatch; capacity/slot overflow; GPU clearance boundary with radiator configuration; missing cooler mount; unknown PSU connector/cable; SATA/M.2 sharing; missing/conflicting source revision; incomplete build; inactive/out-of-stock component; saved-build ownership; repeated SKU aggregation; group identity in cart; stale price/stock; transactional rollback; delivery quantity aggregation; PayMongo duplicate/out-of-order events and cancellation races; immutable order snapshots; and existing cart, wishlist, checkout, payment, shipment, and manual-payment regressions.

Acceptance requires reviewed source-backed specification fixtures, deterministic explainable results, inaccessible builds denied, aggregate stock validated atomically, no order/payment regression, accessible web flows, and human review of the plan and implementation. These tests are proposed, not executed.

## 19. Implementation phases

| Phase                  | Proposed work                                                                                                         | Exit evidence                                                                   |
| ---------------------- | --------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| Approval and discovery | Resolve scope extension, compatibility policy, admin/review ownership, schema details, and checkout warning policy.   | Explicit developer/adviser approvals where required; reviewed decision record.  |
| MVP foundation         | Product-linked reviewed specs, core deterministic rules, saved builds, Simple web selection, grouped cart handoff.    | Rule/source coverage, authorization, migration review, focused automated tests. |
| Commerce completion    | Aggregated inventory/delivery validation, checkout/order snapshots, admin visibility, regression and security checks. | Cart/checkout/payment/shipping regressions pass; human operational review.      |
| Enhancements           | Advanced filters, comparisons, controlled sharing, improved explanations and admin tools.                             | Usability/accessibility evidence and updated acceptance matrix.                 |
| Optional advanced      | Mobile consumer, Gemini explanations, recommendations, or assembly services if separately approved.                   | Separate scope, security, operations, and evaluation approval.                  |

## 20. Risks and approval decisions

The Builder conflicts with the existing explicitly excluded configurator/compatibility scope and needs a scope amendment before application implementation. Required decisions include who owns and verifies component specifications; minimum evidence for an overall compatible result; whether warnings/unknowns can proceed to checkout; guest build ownership and retention; whether build groups persist into order items/reports/returns; exact bundle shipping assumptions; and whether builds need wishlist integration.

Technical risks include incomplete or stale manufacturer data, variant mismatch, BIOS and physical-clearance edge cases, cart SKU merging, duplicate quantity undercount, oversold stock under concurrency, changing product records after order placement, authorization leaks, shipping estimates based on inadequate package data, and overreliance on unverified EasyPC claims. The existing database constraint details require focused inspection before migration design is finalized.

This document records a planning proposal only. Explicit developer approval is required before application code, schema changes, provider configuration/operations, production activity, deployment, or any scope expansion beyond the decisions above.

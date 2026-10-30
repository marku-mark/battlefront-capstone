---
name: lbc-shipping-domain
description: Design or review Battlefront flows against LBC's documented shipment lifecycle, including local mocks and future integration planning, while preserving existing order workflows.
---

# Battlefront shipping domain

Use `docs/integrations/lbc/README.md` as the entry point and follow its task-to-reference table. Read `docs/CAPSTONE_CONTEXT.md`, applicable `.ai/rules`, and PayMongo references when the design touches those boundaries. Use Graphify before source exploration as required by AGENTS.md. Treat current approved rules as authoritative and identify stale conflicts.

- Trace existing `Shipment`, `OrderProcessingService`, delivery quoting/address validation, presenters, and notification publisher before proposing another module.
- Start domain modeling from the selected, documented LBC product flow in `api-sources.md` and `architecture.md`. Separate public customer/merchant flows from the API contract and account entitlement; never force LBC stages into Battlefront's current state machine without an explicit mapping.
- Keep payment, order, and shipment states distinct. Prepaid fulfillment requires persisted verified payment; COD delivery may progress while payment is pending and requires explicit collection confirmation after delivery. Browser redirects, tracking events, and mock outcomes never establish payment.
- Preserve approved COD and pickup exceptions, historical manual payments, manual shipment registration, immutable quote snapshots, and existing cancellation/stock/sales behavior. COD eligibility for the proposed mock-booking action is unresolved and must be surfaced for developer decision. Delivery alone must not verify COD collection.
- Serialize mutations on Order before Shipment. Prevent duplicate booking and duplicate lifecycle effects, including replay and concurrent requests.
- Treat Battlefront mock results as synthetic local behavior, not official rates, serviceability, or provider acceptance. An approved internal mock does not require a merchant contract; provider-compatible behavior does. Canonical address validation does not establish carrier coverage. Map package data, handoff, identifiers, tracking, and collection/remittance only to available evidence.
- Keep mock identifiers clearly fake and prevent mock execution in production. Separate mock-booking cancellation from order cancellation, stock release, payment closure, and refunds. Current order rules prohibit cancellation at or after `handed_to_lbc`; do not repeat older EXT-89 wording that allowed it.
- For eligible PayMongo-origin cancellation, preserve latest-state reconciliation and confirmed supported flow closure before stock release. Keep Checkout Session and online-banking Payment Intent closure flow-specific; distinguish method expiry from the merchant cutoff. Unresolved/unsupported closure retains stock. Preserve historical manual GCash/Maya review. Do not reprice saved totals; cancellation is not a refund. State whether a safeguard is implemented, approved, or planned.
- Inspect schema before proposing persistence; prefer existing shipment structures. Present concrete migration and compatibility implications, and stop before major database, checkout, or payment changes until approved.

Deliver the existing-to-proposed mapping, transition ownership, failure behavior, approval boundaries, and unresolved future carrier requirements.

---
name: payment-architecture
description: Use when planning or reviewing PayMongo online methods (cards, wallets, QR, and supported online banking) against Battlefront checkout, orders, fulfillment, inventory, and legacy manual payment workflows.
---

# Payment architecture workflow

## Scope
Map payment use cases to the existing application architecture and produce a reviewable proposal. Do not implement or expand approved scope.

## Required references
- Read `docs/paymongo/11-system-aligned-setup.md` for verified local infrastructure, existing permissions/refund workflows, chosen operating settings, private test credential placement and the official PayMongo guide checklist. Store actual test keys only in the ignored root `.env`; these proposed environment names remain inactive until explicitly implemented. Never print secrets or assume account activation from their presence.
- Read `docs/CAPSTONE_CONTEXT.md`, all applicable `.ai/rules` entries, and `docs/paymongo/00-overview.md`, `03-architecture.md`, `05-payment-flow.md`, and `09-decisions-and-open-questions.md`.
- Inspect the current checkout/order Actions, Order model, payment enums, controllers, routes, migrations, and tests.

## Operations
Read `docs/paymongo/10-readiness-audit.md` for the shared implementation contracts and outstanding evidence gates. Use the audited creation/cancellation/state contracts. Cover customer/admin/scheduled cancellation, method-switch races, persistent manual/gateway origin, shared web/mobile recovery and compatibility with fulfillment/sales. Preparation-only tasks produce review artifacts, not application changes or external operations.

1. Trace current web and API order paths and identify shared business logic.
2. Use Hosted Checkout v2 for online card, GCash, Maya, and QR Ph; use the documented Payment Intent redirect flow for Direct Online Banking. Support eligible online methods for both delivery and pickup. Distinguish online card from physical card paid at pickup, cash at pickup, and COD.
3. Require provider-verified automatic payment confirmation; do not design customer receipt or screenshot uploads for PayMongo transactions.
4. Absorb provider fees by default. Require written PayMongo/acquirer permission and disclosure review before any pass-on fee. Preserve commercial order snapshots, stock behavior, fulfillment rules, legacy manual payment records, and sales recording semantics.
5. Use PayMongo's native method/action expiry and a separate 24-hour merchant order cutoff. Distinguish Checkout Session expiry from Payment Intent state. Reconcile and confirm provider closure before order cancellation or stock restoration; retain stock and escalate uncertainty.
6. Distinguish payment lifecycle from order and shipment lifecycle; describe transitions and failure/reconciliation paths.
7. Require latest provider-state reconciliation and confirmed applicable provider-flow closure before customer/admin/scheduled cancellation or stock release; plan retry only while the order/flow remains valid.
8. Treat late payment separately from order cancellation. Automated refunds are excluded from first release; require administrator review and separate authorization for refund requests.
9. Compare architectural options and record tradeoffs, migration implications, ownership boundaries, and open decisions. Suggested PHP class names are conceptual; map responsibilities to existing Laravel conventions first.

## Safety restrictions
- Do not modify application code or schema unless the developer explicitly approves implementation.
- Do not treat a proposed table, state machine, endpoint, or checkout behavior as approved scope.
- Preserve existing customer/order/payment data; never recommend destructive migration shortcuts.
- Provider events must be authenticated and processed idempotently; redirects are UX only.

## Expected output and completion
Deliver a component/sequence proposal, integration points, state-transition table, compatibility risks, migration outline, and unresolved decisions. Complete when the proposal maps to existing code and distinguishes facts from recommendations.

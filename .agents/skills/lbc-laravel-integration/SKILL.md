---
name: lbc-laravel-integration
description: Implement explicitly assigned local mock LBC shipping features in Battlefront Laravel, reusing shipment, authorization, order, and notification conventions. Excludes real LBC adapter activation.
---

# Local Laravel shipping integration

Use `docs/integrations/lbc/README.md` as the entry point and follow its task-to-reference table. For implementation, read the applicable mock, architecture, lifecycle, security, payment-coordination, and scope guidance; read PayMongo references when payment coordination is touched. Activate Laravel, testing, and frontend skills for changed paths. Follow AGENTS.md, Graphify, applicable `.ai/rules`, installed package versions, and Boost documentation for affected Laravel APIs. Treat current approved rules as authoritative and flag stale contradictions.

- Implement only the assigned and authorized scope. An internal Battlefront simulation may proceed without a merchant contract when its behavior is explicitly approved and clearly synthetic; do not claim LBC compatibility. Provider-compatible simulation and real adapters require verified endpoint evidence and separate authorization.
- Reuse an existing Shipment, shipping/order structures, and canonical destination validation. Do not create a Shipment or reinterpret an unconfigured zero-fee placeholder as free shipping within a mock-booking action. Keep the shipping provider boundary independent of undocumented LBC wire contracts.
- Treat public-flow documents as domain evidence, not API contracts. Internal mock behavior is a Battlefront contract; use provider semantics only when verified for the selected product and account.
- A local mock makes no outbound calls, needs no credentials, uses synthetic references, and fails closed outside explicitly permitted local/test environments. Do not silently fall back from a real adapter to a mock. This skill does not activate a real adapter.
- Apply authorization and payment checks at the business mutation boundary. Prepaid fulfillment requires persisted verified payment; preserve approved COD behavior. COD eligibility for a particular booking simulation remains a product decision. Lock Order before Shipment; make local mutations idempotent without overwriting manual or foreign-provider references.
- Preserve current order, payment, and shipment boundaries. Order cancellation is prohibited at or after `handed_to_lbc`. For eligible PayMongo-origin cancellation, preserve latest-state reconciliation and confirmed applicable-flow closure before stock restoration; carrier cancellation cannot satisfy that gate. Preserve historical manual GCash/Maya review. Carrier simulation cannot verify payment, restore stock, or record sales; cancellation is not a refund. Confirm a safeguard is implemented before reporting it as current behavior.
- Reuse after-commit notification mechanisms when authorized; local simulations must not send external delivery messages. Use controlled failure simulation without persisting partial success.
- Add relevant behavior tests and run Pint for changed PHP. Carrier-only mock tests must make no outbound requests. Payment-coordination tests may use HTTP fakes for required PayMongo reconciliation while preventing real traffic. Report commands, results, limitations, and files; do not commit or push.

Future sandbox/production adapters require documented contracts and separate approval under `docs/integrations/lbc/sandbox-checklist.md`.

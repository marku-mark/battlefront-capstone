---
name: lbc-security
description: Review Battlefront local mock shipping isolation, authorization, payment trust, replay defense, privacy, cancellation races, and future LBC adapter security boundaries.
---

# LBC shipping security review

Use `docs/integrations/lbc/README.md` as the entry point and follow its task-to-reference table. Inspect LBC security, mock, architecture, lifecycle, and payment-coordination guidance as applicable, plus actual mutation and presentation boundaries. Read PayMongo security/readiness references when the reviewed path touches gateway coordination. Check current `.ai/rules` and distinguish approved requirements from verified implementation.

- Verify explicit approval, local/test-only execution, synthetic identifiers, and zero outbound requests for carrier-only simulation. Environment configuration alone must not allow production mock fulfillment. Internal simulation need not have an LBC contract, but cannot claim provider compatibility.
- Check ownership and existing staff permissions at every exposed action. Reject forged references, foreign shipment IDs, status skipping, and attempts to overwrite historical/manual references.
- Verify persisted-payment trust by flow: prepaid fulfillment requires verified payment; approved COD progression remains unpaid through delivery and uses explicit collection confirmation. COD eligibility for a particular booking simulation remains undecided. Check Order-before-Shipment locking, duplicate prevention, rollback, and cancellation races. Carrier operations cannot bypass PayMongo reconciliation or change payment, refunds, inventory, or sales.
- Apply the current cutoff from `.ai/rules/controllers-js-pages-orders.md`, including customer wallet-proof eligibility; do not rely on stale after-handoff wording. Review carrier booking cancellation separately from order cancellation.
- Check notification isolation and data minimization. Do not expose addresses, contact details, payment proof paths, credentials, or full external payloads in logs/customer responses beyond authorized needs.
- No LBC or PayMongo API calls, credentials, webhook registration, tunnels, production setup, or live shipments are permitted in the local mock scope. Do not invent a webhook signature mechanism for an undocumented future carrier provider.
- For future adapters, require official authentication/signature/replay/error evidence and explicit sandbox approval; document unknowns rather than treating mocks as security validation of LBC.
- Compare integration claims and trust boundaries with the LBC source audit/capability matrix. Public user flows do not prove merchant API authentication, booking semantics, tracking authenticity, settlement, or cancellation behavior.
- Verify that PayMongo-origin order cancellation retains PayMongo's reconcile-and-confirm-closure gate, including separate Hosted Checkout and online-banking behavior, and that a booking cancellation cannot close payment, restore stock, or claim refund. Preserve historical manual GCash/Maya review. Keep external calls outside retried database transactions and preserve immutable amount/delivery snapshots.

Report prioritized findings with file/line evidence, realistic reproduction, and remaining limits. Tests and review do not replace human approval.

# Architecture baseline and agent roles

**Status:** implementation scope approved; detailed implementation choices follow this baseline. Current repository evidence reviewed 2026-10-23.

The detailed creation/cancellation/state contracts and responsibility matrix are in `10-readiness-audit.md`. They extend this architecture without adding a parallel application layer.

## Existing integration points

- Current environment, queue/job budgets, database isolation, role permissions, and existing customer refund/review integration points are mapped in `11-system-aligned-setup.md`; use these existing mechanisms rather than introducing parallel infrastructure or a second refund ledger.
- `routes/web.php`: authenticated customer checkout preview, order placement, order history and admin payment/order decisions.
- `routes/api.php`: versioned mobile checkout preview and order placement; maintain parity through shared business actions.
- `app/Actions/Checkout/PrepareCheckout.php`, `app/Actions/Order/PlaceCustomerOrder.php`, `app/Services/Order/OrderPlacementService.php`: checkout validation and atomic order workflow.
- `app/Models/Order.php`, `app/Enums/PaymentMethod.php`, `PaymentStatus.php`, order migration(s): single current payment state, existing manual methods, whitelisted storage constraints.
- `app/Actions/Order/StorePaymentProof.php`, `ResubmitPaymentProof.php` and admin payment review controllers: manual proof pathway.
- This manual pathway is the current behavior. At the approved cutover, new GCash/Maya online orders use PayMongo; preserve historical records and grandfather orders already placed. New PayMongo transactions never use receipt upload/review.
- Existing order completion actions record sales and coordinate inventory. Gateway confirmation must not bypass them.

## Candidate component flow

First-release customer payment methods are online card, GCash, Maya, QR Ph, and eligible Direct Online Banking for both delivery and pickup. Use Hosted Checkout v2 for card, GCash, Maya, and QR Ph; use PayMongo's documented Payment Intent redirect flow for Direct Online Banking, without assuming Hosted Checkout supports it. Enable only bank routes available in test mode and activated for the merchant. Absorb provider fees by default; enable pass-on fees only after written PayMongo/acquirer permission and disclosure review. Battlefront records payment only after authenticated provider confirmation; customers do not upload receipts. Request a provider email receipt only where the selected API supports it and a valid email is available; delivery is not guaranteed or payment evidence. Existing cash/card-at-pickup and COD remain separate workflows.

An unpaid order has a 24-hour validity window from placement. At the cutoff, reconcile provider state and close the provider payment path only through a documented supported action (expire the Checkout Session or resolve/cancel the Payment Intent only where supported). Cancel the order and release its already-deducted stock only after provider closure is confirmed and no attempt is paid, pending, processing, or uncertain. If closure is unsupported or its result is unclear, retain the order and stock and raise an operator reconciliation task.

Use PayMongo's native method expiry for payment actions and the separate 24-hour merchant order cutoff. A method/payment attempt may expire while its Checkout Session remains active and allows retry. Checkout Sessions have no automatic expiry. Reconcile and confirm provider closure before cancelling or restoring stock; unresolved outcomes retain the stock hold.

```text
Customer web/API -> existing checkout validation -> existing order placement
                                               -> local payment attempt
                                  -> Checkout Session v2 (card/GCash/Maya/QR Ph)
                                  -> Payment Intent redirect (eligible online banks)
Customer browser <- provider-hosted checkout/bank redirect
PayMongo -> public webhook endpoint -> signature verifier -> durable event inbox
                                           -> flow-specific event mapping -> order workflow
Customer return -> read-only status presentation; no payment mutation
```

Map these responsibilities to existing Laravel conventions. Keep controllers thin; use focused Actions and a Service only for a coordinated workflow, matching `.ai/rules/application-architecture.md`.

## State ownership (proposal)

- Provider state is external evidence; local payment attempt state is an auditable projection.
- Order, shipment, inventory, and sales statuses remain separate domains.
- A verified, matching successful provider payment sets the existing order payment state to `verified`; provider attempt failures/expiry remain on the separate payment-attempt record and must not be mislabeled as manual proof rejection.
- Before cancellation, reconcile the latest provider payment state. Never cancel/release stock when paid, pending, processing, or unknown; close the Checkout Session or resolve the Payment Intent using its documented flow and confirm terminal status first.
- Failed/expired attempts may be retried within the 24-hour order window through the same provider flow where supported. Do not create another session/intent while its predecessor could still succeed.
- Late, duplicate, contradictory, wrong-amount, wrong-currency, and wrong-mode events require explicit safe handling and operator visibility. A payment arriving after local cancellation stops automatic fulfillment and enters reconciliation.
- Automated refund state/operations are out of first release. Cancellation alone never means money was returned; route paid-order cancellation/refund requests to administrator review.

## Logical component responsibilities

These are responsibilities to place within the inspected Laravel Actions, Services, controllers, jobs, and existing order workflow. The names below are conceptual labels, not mandatory PHP filenames or new architectural layers.

| Responsibility        | Proposed boundary                                                                                                                                                                                                                     |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Provider API client   | Server-side PayMongo calls for Checkout Sessions v2 and Direct Online Banking Payment Intents; handles authentication, idempotency, retrieval/closure, and provider errors.                                                           |
| Payment lifecycle     | Tracks flow-specific attempts and reconciles provider state; keeps payment state independent from order state and prevents duplicate payable sessions/intents/charges.                                                                |
| Webhook ingress       | Public HTTPS route verifies the raw-body signature, records/deduplicates the event durably, returns a prompt 2xx, and hands safe processing to existing async mechanisms where appropriate.                                           |
| Order lifecycle       | Existing order Actions/Services remain authoritative for order transitions, stock, sales, cancellation, and fulfillment. At 24 hours, reconcile provider state and confirm provider closure before cancellation/restoration.          |
| Expiry/recovery       | A scheduled/queued process handles the 24-hour cutoff, reconciles Checkout Session or Payment Intent state, and escalates uncertainty without releasing stock. Select the mechanism after inspecting existing scheduling conventions. |
| Refund lifecycle      | Automated refund operation is excluded from first release; route requests to administrator review and record any externally completed refund for audit.                                                                               |
| Payment audit/logging | Records references, state changes, timestamps, and safe correlation data; excludes credentials and unnecessary personal/full payload data.                                                                                            |

## Specialized agent role specifications

### Architecture Agent

- **Purpose:** maintain alignment with project scope and existing Laravel boundaries.
- **Responsibilities:** trace web/API order workflows, keep order/payment lifecycles separate, assess migration impacts, plan online methods for delivery/pickup versus physical card/cash/COD at fulfillment, assess fee presentation, expiry, cancellation/recovery and refund handling, propose options and decision records.
- **Allowed:** read repository/docs, draft documentation, review proposed diffs after implementation is approved.
- **Prohibited:** implement payment code/schema, change scope, invoke provider APIs, approve own design.
- **Required skills:** `payment-architecture`, `paymongo-api`, `payment-database`.
- **Deliverables:** component/sequence proposal, transition ownership, compatibility and open-decision report.
- **Review:** developer approves scope and architecture before implementation.

### PayMongo Integration Agent

- **Purpose:** implement only an explicitly approved provider boundary.
- **Responsibilities:** verify current PayMongo docs, propose/implement Laravel integration for Hosted Checkout methods and the separate online banking Payment Intent flow, map API errors, method/order expiry, idempotent retries, fee absorption, and automatic provider confirmation. Automated refunds and pass-on fees remain out of first release unless separately approved.
- **Allowed:** after explicit approval, modify scoped application files and use local test fakes; use test credentials only when separately supplied/authorized.
- **Prohibited:** live credentials/transactions, dashboard/webhook registration, inventing API shapes, deciding schema alone, treating redirects as proof.
- **Required skills:** `paymongo-api`, `payment-architecture`, `payment-database`, `payment-security`, `payment-testing`.
- **Deliverables:** sourced client boundary, safe retry/idempotency handling, tests, changed-file summary.
- **Review:** independent security review and developer approval before merge/release.

### Payment Security Agent

- **Purpose:** independently assess threats and verify trusted payment state.
- **Responsibilities:** secrets, webhook signatures, authorization, replay/duplicate defense, amount/order binding, cancellation races, late payments, refund authorization boundaries, privacy/logging, and recovery.
- **Allowed:** read-only review and, after approved implementation, scoped remediation proposals or changes explicitly assigned.
- **Prohibited:** access/use live secrets, trigger transactions, weaken signature/authorization, self-approve findings.
- **Required skills:** `payment-security`, `paymongo-api`, `payment-architecture`.
- **Deliverables:** threat model, prioritized findings, evidence, remediation verification.
- **Review:** findings need developer disposition; critical/high findings need independent re-review.

### Payment QA Agent

- **Purpose:** prove intended payment lifecycle and failure behavior without production effects.
- **Responsibilities:** test matrix for each enabled method, automatic success confirmation, no-receipt behavior, fakes/feature tests, duplicate/out-of-order/retry/cancellation/refund/late-payment scenarios, test-mode evidence and gaps.
- **Allowed:** run approved local test suite; test mode only after explicit authorization and credential availability.
- **Prohibited:** live payment, register webhooks, edit production data, call undocumented APIs, claim sandbox proves settlement.
- **Required skills:** `payment-testing`, `payment-security`, `payment-architecture`, `testing-best-practices`.
- **Deliverables:** requirement-to-test matrix, run results, residual risks, manual verification needs.
- **Review:** developer reviews test scope/evidence; tests do not grant implementation or launch approval.

## Codex agent configuration decision

Current official Codex documentation supports project agents at `.codex/agents/*.toml` with required `name`, `description`, and `developer_instructions` fields, and multi-agent delegation with parent-inherited permissions. The local executable reports `codex-cli 0.163.0`; four role files are present. The CLI emitted a missing-home warning, so verify project agent discovery in the developer's normal CLI environment. Existing `.codex/config.toml` was reviewed and left unchanged.

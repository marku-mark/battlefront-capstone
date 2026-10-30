---
name: paymongo-api
description: Use when researching or implementing PayMongo API interactions, Checkout Sessions, payment methods, test mode, webhooks, API errors, retries, or production readiness. Requires current official documentation and project payment references.
---

# PayMongo API workflow

## Scope
Own verified PayMongo API behavior and the server-to-provider boundary. Do not decide project schema or authorize payment feature scope.

## Required references
- Read `docs/paymongo/11-system-aligned-setup.md` for verified local infrastructure, existing permissions/refund workflows, chosen operating settings, private test credential placement and the official PayMongo guide checklist. Store actual test keys only in the ignored root `.env`; these proposed environment names remain inactive until explicitly implemented. Never print secrets or assume account activation from their presence.
- Read `docs/paymongo/01-official-sources.md` and `02-requirements.md`.
- Re-open the linked official PayMongo pages before relying on version-sensitive details; record review date and uncertainty.
- Read applicable project `.ai/rules` and order/checkout implementation before proposing code.

## Operations
Use the Money Movement references in `11-system-aligned-setup.md` for wallet/settlement context. Customer bank payments use the Payment Acceptance contract; never infer checkout success, cancellation or webhook names from transfer/payout resources. Account test readiness reported by the developer is not live activation or endpoint test evidence.

Read `docs/paymongo/10-readiness-audit.md` for the shared implementation contracts and outstanding evidence gates. Verify endpoint-specific authentication/idempotency, the full bank create-method/attach flow, account limits, and closure evidence. Treat provider documentation contradictions and unknown outcomes as unresolved facts; do not invent cancellation or lookup capabilities. Preparation-only tasks produce review artifacts, not application changes or external operations.

1. Follow the approved product split: Hosted Checkout v2 for card, GCash, Maya, and QR Ph; the documented Payment Intent redirect flow for Direct Online Banking. Do not claim DOB/Brankas is supported by Hosted Checkout.
2. Verify authentication, request/response shape, method/bank availability, account activation, and test-mode behavior for the specific provider flow. Provider method codes do not prove merchant access.
3. Absorb fees by default. If pass-on fees are later requested, verify current Checkout Sessions v2 behavior, customer-visible total, and paid amount/fee/net fields, then require written PayMongo/acquirer permission and disclosure review. Do not hard-code fee schedules.
4. Verify event names, errors, retries, idempotency, and native method expiry in current official docs. Keep the separate 24-hour merchant order cutoff distinct from PayMongo method/action and session/Payment Intent lifecycles. Never invent expiry windows or events.
5. Automated refunds are out of first release. For future work, verify current method eligibility, event names, partial/full rules, and timing. Cancellation alone never refunds a payment.
6. Keep credentials server-side; use test mode for development; use documented API behavior only.
7. Separate confirmed API facts from application decisions and unresolved account eligibility/configuration.

## Safety restrictions
- Preparation does not authorize code changes, API calls, creating sessions, registering webhooks, dashboard changes, or live activity.
- Never infer successful payment from a browser redirect.
- A completed PayMongo transaction must be confirmed server-side from authenticated provider evidence; the customer does not submit a receipt or screenshot.
- Never invent endpoints, payload fields, payment status mappings, or retry semantics.
- Never expose credentials or paste secrets into logs, tests, docs, or source.

## Expected output and completion
Provide source URLs, access/review date, verified facts, assumptions, version-sensitive gaps, and integration implications. Complete only when every API claim is cited to an official source and unresolved facts are explicitly listed.

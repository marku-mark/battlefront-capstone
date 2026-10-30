---
name: payment-testing
description: Use when planning or validating PayMongo card, wallet, QR, or online banking checkout, automatic payment confirmation, webhook security, retries, recovery, and test-mode scenarios.
---

# Payment testing workflow

## Scope
Plan test coverage and, only after implementation is approved, execute authorized test-mode checks. Never create live transactions.

## Required references
- Read `docs/paymongo/11-system-aligned-setup.md` for verified local infrastructure, existing permissions/refund workflows, chosen operating settings, private test credential placement and the official PayMongo guide checklist. Store actual test keys only in the ignored root `.env`; these proposed environment names remain inactive until explicitly implemented. Never print secrets or assume account activation from their presence.
- Read `docs/paymongo/07-testing.md`, `02-requirements.md`, current official PayMongo test guidance, project testing rules, and relevant existing Pest tests.

## Operations
Use the worker/scheduler assessment in `11-system-aligned-setup.md`. Native Windows without PCNTL does not enforce Laravel daemon job timeouts; numeric timeout settings alone cannot prove safe concurrent worker recovery. Separate local functional evidence from enforced termination/concurrency acceptance.

Read `docs/paymongo/10-readiness-audit.md` for the shared implementation contracts and outstanding evidence gates. Map the G01-G12 scenarios to evidence: creation crash points, inbox/enqueue recovery, different events for one payment, cancellation races, key expiry, manual/gateway coexistence and web/mobile parity. Document existing notification delivery limits. Preparation-only tasks produce review artifacts, not application changes or external operations.

1. Cover card, GCash, Maya, and QR Ph through Hosted Checkout v2 and each enabled Direct Online Banking bank through its Payment Intent redirect flow, for both delivery and pickup. Verify order binding, server-derived amount/currency, success/failure/abandonment, redirect without webhook, signatures, duplicate/out-of-order events, retries/timeouts, and recovery.
2. Verify fee absorption by default. Pass-on fee tests are conditional on later approval and written method-specific permission. Test native method expiry separately from the 24-hour order cutoff; cover active-session retries, documented provider closure, expiry races, uncertain provider status, stock retention, and release rules. Do not invent events or expiries.
3. Prove successful payment is confirmed automatically from authenticated provider evidence and never from a customer receipt, screenshot, or redirect.
4. Test cancellation only after latest provider-state retrieval/reconciliation and confirmed applicable provider-flow closure; cover paid, pending, processing, unknown, and delayed states. Test late success after local cancellation without automatic fulfillment.
5. Test failed/expired payment recovery only while order/provider-flow validity permits retry. Assert duplicate requests/events cannot duplicate orders, charges, inventory, completion, sale, notification, or audit effects.
6. Verify the first release has no automated PayMongo refund action. Cover compatibility with existing manual refund recording, independent review and reporting when affected; only new refund behavior requires separate scope. Test that disabling new gateway starts preserves authenticated ingress and recovery for existing flows, following document 11.
7. Separate deterministic automated fakes from provider test-mode/manual sandbox checks; document what each proves. Use an approved HTTPS tunnel only for local webhook delivery checks, never exposing database/admin routes.
8. Use existing Pest conventions and narrow checks; record environment, evidence, and redaction.

## Safety restrictions
- Do not use live credentials, live cards, real payment transactions, or real webhook registrations.
- Do not log secret headers or unredacted provider/customer data.
- Do not claim a test proves dashboard activation, production delivery, or actual settlement.
- Preparation-only tasks specify coverage without adding/running application tests. During an assigned implementation task, follow repository test requirements; external test-mode operations still require their own authorization.

## Expected output and completion
Deliver a traceable test matrix with expected outcomes, fixtures/fakes, manual test prerequisites, and evidence gaps. Complete when critical payment, security, duplication, and recovery paths are covered without production side effects.

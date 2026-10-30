---
name: payment-database
description: Use when planning MySQL persistence for PayMongo card, wallet, QR, or online banking attempts, verified provider events, payment status, and reconciliation in this Laravel project.
---

# Payment database workflow

## Scope
Design data persistence aligned with current Laravel migrations and MySQL usage. Do not apply migrations or alter existing data during preparation.

## Required references
- Read `docs/paymongo/11-system-aligned-setup.md` for verified local infrastructure, existing permissions/refund workflows, chosen operating settings, private test credential placement and the official PayMongo guide checklist. Store actual test keys only in the ignored root `.env`; these proposed environment names remain inactive until explicitly implemented. Never print secrets or assume account activation from their presence.
- Read `docs/paymongo/04-database-design.md`, `03-architecture.md`, project schema rules, and current order/payment migrations and models.
- Inspect actual schema and database engine assumptions before any future implementation.

## Operations
Read `docs/paymongo/10-readiness-audit.md` for the shared implementation contracts and outstanding evidence gates. Separate payment flows from provider attempts, durable operations and inbox entries. Specify MySQL active-flow enforcement, stable payment/event uniqueness, request fingerprints, exact money conversion and worker claim recovery. Preparation-only tasks produce review artifacts, not application changes or external operations.

1. Map existing `orders` payment columns and constraints, status enums, manual proof path, sales, cancellation, and fulfillment rules.
2. Identify what requires a distinct payment attempt/event record versus an order snapshot; present alternatives before selecting a design.
3. Represent Hosted Checkout attempts for card, GCash, Maya, and QR Ph separately from Direct Online Banking Payment Intent attempts; do not model PayMongo success as customer-uploaded proof.
4. Define candidate keys, foreign keys, uniqueness, decimal/currency treatment, status/audit retention, and indexes. For pass-on fees, distinguish order base amount, provider gross paid amount, provider fee, and net settlement amount; never hard-code method fee schedules.
5. Keep order state, Checkout Session/Payment Intent state, payment attempts, webhook processing, and any later refund lifecycle distinct. Record provider references/timestamps and audit transitions without retaining unnecessary full payloads. Model the 24-hour order cutoff separately from provider-native expiry.
6. Plan idempotency/uniqueness against duplicate orders, payable sessions, provider payments, webhook events, and refunds; account for concurrent requests.
7. Plan a forward-only, backward-compatible migration and a data-preserving rollout/rollback strategy.

## Safety restrictions
- Do not modify schema or run migrations without explicit implementation approval.
- Do not delete, rewrite, or assume disposable existing order/payment data.
- Do not store full card details, credentials, or unnecessary webhook payload data.
- Treat the design document as a proposal until approved.

## Expected output and completion
Deliver an entity/relationship proposal, field rationale, constraints/indexes, migration and rollout notes, privacy/retention considerations, and unresolved decisions. Complete when existing schema and data compatibility are explicitly addressed.

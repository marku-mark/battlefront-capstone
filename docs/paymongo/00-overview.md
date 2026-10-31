# PayMongo preparation foundation

**Status:** PayMongo gateway scope and implementation approved by the developer; adviser approval and panel recommendation reported by the developer. Production credentials, live transactions, and deployment are not approved. **Reviewed:** 2026-10-23.

## Purpose

Prepare reusable Codex workflows and project-specific documentation for the developer-approved PayMongo gateway direction. No API calls, transactions, webhook registrations, dashboard changes, credentials, migrations, or application behavior were changed.

## Project facts inspected

- Laravel 13 application targeting PHP 8.4 compatibility, Vue 3/Inertia; Eloquent migrations and Pest tests. Current Boost runtime inspection reports Laravel 13.30.1 and PHP 8.5; deployment runtime must be checked separately. No dependencies were installed or changed.
- Cart/checkout preview is separate from order placement. Authenticated web checkout is in `routes/web.php`; mobile order placement is under `/api/v1` and shares order actions.
- `app/Actions/Order/PlaceCustomerOrder.php`, `app/Services/Order/OrderPlacementService.php`, `app/Models/Order.php` and payment enums implement current order/payment behavior.
- Existing methods include cash, card at store, GCash, Maya, and COD. GCash/Maya use private manual proof upload and administrator payment review; pickup cash/card and COD have distinct approved fulfillment confirmation rules.
- Orders store one payment status and method alongside proof path/rejection feedback. Database constraints whitelist values. No PayMongo integration, payment attempt entity, or webhook consumer was found.
- Test environment template defaults to SQLite; project context identifies MySQL as development/intended deployment database. Preserve existing data.

## Approved baseline

1. Use PayMongo Hosted Checkout v2 / Checkout Sessions for online card, GCash, Maya, and QR Ph. Support Direct Online Banking through PayMongo's documented Payment Intent redirect flow, since Hosted Checkout support is not established. Confirm test support and account activation for each method/bank before enabling it.
2. Offer eligible online methods for delivery and pickup. Default to absorbing provider fees; enable pass-on fees only after written PayMongo/acquirer confirmation and required disclosure approval. Customers do not upload receipts; PayMongo may send its payment confirmation receipt to the customer's checkout email.
3. Server creates checkout from a persisted order using server-calculated commercial snapshots; client cannot set amount or mark paid.
4. Browser return route displays status only. A correctly signed provider webhook is payment evidence.
5. Payment lifecycle, order fulfillment lifecycle, shipment state, and sales recording remain distinct. At approved cutover, PayMongo replaces manual GCash/Maya proof processing for new online orders; retain historical records and preserve separate cash pickup, physical card-at-store, and COD behavior.
6. Let PayMongo apply each method's native action expiry. Independently, close unpaid orders at 24 hours from order placement: reconcile the latest provider state, close the applicable provider flow only through a documented supported operation, and release stock/cancel only after terminal closure is confirmed with no payment paid, pending, processing, or uncertain. Payment Intent closure behavior for every enabled bank remains to be verified. If status is uncertain or closure is unsupported, retain the order/stock hold and escalate reconciliation.
7. Payment records/schema are a proposal; no migration should proceed until architecture and data migration are reviewed. Preserve order base amount, provider gross amount, fee, and net settlement separately.

## Deliverables index

Start with [11-system-aligned-setup.md](11-system-aligned-setup.md) for the verified local MySQL/queue/permission/refund baseline, resolved operating choices, official-guide checklist, and where to put test credentials. It supersedes earlier unresolved repository-environment questions; account/provider evidence remains explicit.

The preparation audit and evidence register are in [10-readiness-audit.md](10-readiness-audit.md). It identifies G01-G12 gaps, maps them to acceptance evidence, defines creation/webhook/cancellation recovery, and records agent responsibilities. Preparation completeness is distinct from account readiness, runtime agent discovery, and tested implementation readiness.

See `01-official-sources.md` through `09-decisions-and-open-questions.md` for researched facts, approved baseline, risks, test strategy, staged plan, and external readiness gates. Agent roles are in `03-architecture.md` and executable definitions are in `.codex/agents/`. The supplied local CLI reports version 0.163.0; its shell could not resolve a home directory, so project agent discovery remains to verify in the developer's normal CLI environment.

## Approval gate

The developer has approved PayMongo as the planned gateway and replacement for manual GCash/Maya proof processing for new online orders, including implementation within this scope, and reports that the adviser approved this scope and the panel recommended it. Decisions recorded in the preparation documents define the initial implementation baseline. Merchant-specific channel activation, credentials, public test webhook setup, live transactions, and deployment remain separate gates.

The approved payment-scope amendment is recorded in `docs/CAPSTONE_CONTEXT.md` and the payment scope paragraph in `docs/capstone_manuscript.docx`.

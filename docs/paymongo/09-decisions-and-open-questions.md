# Decisions and readiness gates

**Status:** PayMongo replacement and implementation scope approved by the developer; adviser approval and panel recommendation reported by the developer. The manuscript and `CAPSTONE_CONTEXT.md` were synchronized with that scope. Production credentials, live transactions, and deployment are excluded. Reviewed 2026-10-23.

## Approved implementation baseline

- Replace manual GCash/Maya proof upload and administrator review for new online orders with PayMongo. Preserve historical payment/proof records and continue administering existing orders under their original rules through completion.
- Support online card, GCash, Maya, and QR Ph through PayMongo Hosted Checkout v2. Support Direct Online Banking through the documented PayMongo Payment Intent redirect flow; do not represent DOB/Brankas as Hosted Checkout methods. Offer enabled online methods for pickup and delivery. Confirm method and bank availability in test mode before exposing each choice.
- Do not request payment receipts or screenshots from customers. Request PayMongo's customer receipt email after successful payment only where the selected API documents support and a valid checkout email is available. Email delivery is not guaranteed or payment evidence.
- Absorb PayMongo transaction fees by default. Keep `pass_on_fees` disabled unless written PayMongo/acquirer permission and disclosure requirements are confirmed for every method offered in a session.
- Let PayMongo apply the native action expiry for the selected method. Independently, an unpaid order has a 24-hour validity window from order placement. At the cutoff, reconcile and close the provider payment path using its documented operation. Cancel the order and restore stock only after provider closure is confirmed and no payment is paid, pending, processing, or uncertain. If uncertain, retain the order/stock hold and escalate reconciliation.
- Allow retries through the same active provider flow during the 24-hour window. Do not create a second session/intent while the prior outcome may still succeed. After confirmed closure and cancellation, the customer places a new order.
- Keep payment, order, shipment, and sales lifecycles separate. A verified online payment does not itself complete pickup handover or delivery.
- Automated refunds are out of first release. A paid-order cancellation/refund request requires administrator review and separate authorization under the provider's current method rules. Do not automatically fulfill a cancelled order after contradictory/late payment evidence; reconcile and route to support.
- At approved cutover, new GCash/Maya online orders use PayMongo. Cash pickup, physical card-at-store, COD, and existing historical orders remain separate.
- The developer has approved implementation within this baseline. Production credentials, live transactions, production webhook activation, and deployment need separate approval.

## Confirmed project integration facts

- Laravel web checkout and the versioned mobile API share order placement business logic.
- `Order` currently combines one payment method/status with optional proof path and rejection feedback. `PaymentStatus` has `pending`, `verified`, and `rejected`; payment methods are constrained in both application enums and database migrations.
- Current placement creates the pending order, decrements inventory, removes cart items, and may create a shipment within one transaction. A 24-hour unpaid interval therefore holds stock; cancellation must use the existing atomic stock restoration rules.
- Delivery currently permits manual GCash/Maya and COD; pickup permits cash/card-at-store and manual wallets. Online card and bank methods need explicit eligibility in both web and API flows.
- Manual proof upload/resubmission and admin verification are active for GCash/Maya. At cutover, preserve historical paths but stop requiring proof for new PayMongo orders.
- No PayMongo client, payment-attempt entity, webhook consumer, or PayMongo config exists.

## External readiness gates (not solvable from repository preparation)

Latest developer confirmation: test keys, webhook setup and development-only method availability are handled. This is user-reported readiness, not live approval or proof the unimplemented receiver has processed an event. The worker/scheduler inspection and local run procedure are now recorded in `11-system-aligned-setup.md`; Windows PCNTL absence remains an explicit runtime acceptance limitation. The concrete first proposed implementation issue is in `08-implementation-plan.md`.

The latest task is explicitly preparation only; no application implementation is started by earlier gateway approval. See `10-readiness-audit.md` for the resolved preparation gaps and their implementation acceptance criteria. The supplied AGENTS baseline retains fee absorption and a separate 24-hour merchant cutoff; neither is a universal provider/industry rule.

Documents 10 and 11 specify the operation/inbox contracts, money handling, local retry/timeout settings, history preservation and role boundaries. Implementation must demonstrate those choices, not treat them as missing decisions. Endpoint-specific authentication/idempotency, banking closure and effective worker recovery still require evidence at the affected gate. Failure to prove a bank route safe keeps that route disabled without removing banking from approved scope.

1. Developer reports payment methods ready for development only. Record the exact enabled methods/banks during test setup and expose only those verified available; do not repeat the account-readiness approval request.
2. Developer reports test credentials handled. Validate only redacted presence/mode during authorized setup; never print keys. No live key is in scope.
3. Developer reports webhook setup handled. Once the receiver exists, demonstrate signed delivery, mode/events and correct processing through the public test endpoint. Dashboard registration alone is not E2E evidence. No endpoint was registered by this preparation.
4. Local MySQL 8.0.46, REPEATABLE-READ and InnoDB are verified. Use the existing database queue and scheduler conventions described in `11-system-aligned-setup.md`. Deployment worker/scheduler health and configuration still need operational evidence. SQLite alone does not prove MySQL locking/constraint behavior.
5. Create or confirm the approved Linear issue with acceptance criteria before implementation work is tracked, as required by project workflow. Developer implementation approval is recorded here; no issue was supplied in this preparation.
6. Pass-on fees remain off unless written PayMongo/acquirer permission and required disclosures are received. This is a launch gate only if the business later elects to pass fees through.
7. Production channel activation, live credentials, monitoring, support rotation, launch/rollback, and real transactions require a separate production approval.

## Provider facts and important limits

- Hosted Checkout v2 is PayMongo's recommended new Checkout API. One session can contain sequential attempts but has at most one active Payment Intent and one successful payment; retries can reuse its active URL. Sessions do not expire automatically.
- Checkout success uses `checkout_session.payment.paid`; Direct Online Banking uses Payment Intent events such as `payment.paid` and `payment.failed`. Verify the correct signature and mode, then bind the event to the known local attempt/order and expected amount/currency.
- Checkout Session expiry has no webhook. An expiry request can fail for an already expired, paid, pending, or processing session. Retrieve/reconcile provider state and verify closure before cancellation or stock restoration.
- Method/action expiry and merchant order validity are different timers. The 24-hour merchant cutoff is a business rule, not a PayMongo or industry-wide standard.
- PayMongo's Direct Online Banking guide describes a separate Payment Intent flow. Hosted Checkout's documented examples do not establish DOB/Brankas support there.
- Official PayMongo pages currently differ on refund event naming (`refund.succeeded` versus `payment.refunded` / `payment.refund.updated`). Since refund automation is excluded, reverify the exact event before a future refund feature.
- PayMongo retries failed webhook deliveries, but the docs also warn that missed events may not be resent. Persist an authenticated event to a durable inbox before acknowledging and run reconciliation for missed/ambiguous state.
- Test mode does not move real money. QR Ph test responses include a test URL; do not scan a real QR during testing.
- PayMongo account API support does not prove merchant activation or fee pass-through permission.

## Codex capability verification

- Local executable supplied by the developer: `C:\Users\mark\AppData\Local\Programs\OpenAI\Codex\bin\codex.exe`; `--version` returned `codex-cli 0.163.0`. Command help was available.
- The executable emitted a warning that it could not find a home directory while creating PATH aliases. Version and help are verified; home-based runtime settings cannot be fully validated in this shell.
- Current official Codex docs support repository skills at `.agents/skills/<name>/SKILL.md` and project custom agents at `.codex/agents/<name>.toml`; required agent fields are `name`, `description`, and `developer_instructions`. Agents are enabled by default and inherit parent permissions. Skills and four matching project agent files are present.
- `.codex/config.toml` was reviewed and left unchanged. Custom agents do not grant production or external side-effect permissions. Verify agent discovery in the developer's normal CLI environment because the current shell has no home directory.

## Deferred/out of scope

Repository-based operating decisions, permissions, manual refund compatibility and private test-key setup are resolved in `11-system-aligned-setup.md`; its current inspection supersedes earlier uncertainty about those mechanisms. Remaining account/provider/deployment evidence is listed there with a safe disabled/pending outcome.

- Automated refund processing, payment disputes/chargebacks, split tender, partial payment at order checkout, recurring billing, saved payment methods, and other gateways.
- Production credentials, live channels/transactions, production webhook registration, deployment, and account/dashboard changes.

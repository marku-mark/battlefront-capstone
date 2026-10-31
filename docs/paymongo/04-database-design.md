# Database design proposal

**Status:** no schema changes made. The persistence shape remains for detailed design before migrations. Reviewed 2026-10-23.

## Current baseline

Read `11-system-aligned-setup.md`: local MySQL 8.0.46, REPEATABLE-READ and InnoDB order/queue storage are verified. Existing manual customer refund/payment/review records and reporting must be preserved and reused where their domain rules apply; automated PayMongo refund execution remains out of scope.

`orders` currently stores `payment_method`, `payment_status`, `payment_proof_path`, rejection reason/note, commercial totals and fulfillment details. Payment methods/status are constrained in database migrations and cast to enums by `Order`. `order_items` stores purchase-time price/quantity. Existing orders are protected historical records and must be preserved. No gateway payment table/event inbox exists.

Use the following logical entities rather than treating a Checkout Session as a single payment attempt. Exact table names and migrations remain for implementation review. The lifecycle and crash-recovery contracts are in `10-readiness-audit.md`.

| Logical record                       | Cardinality / essential evidence                                                                                                                                                                                                                                                   |
| ------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Payment origin and active-flow claim | Persist manual/gateway origin independently from method names; one order has at most one locally claimed unresolved payable flow. Historical `gcash`/`maya` must not be classified using today's flags or missing proof.                                                           |
| Payment flow                         | Order has flow history; each flow selects Hosted Checkout or direct banking, mode and expected amount/currency. Checkout Session ID is nullable for direct banking. Track open/closing/reconciliation state separately from provider status.                                       |
| Provider attempt/payment             | A flow can have multiple intents/attempts and payment references. Store stable provider identities and their parent association; a Checkout retry can replace its current intent without replacing the session. Record failed and successful outcomes without overwriting history. |
| Provider operation                   | Durable local operation ID, flow, operation kind, stable idempotency key where supported, canonical request fingerprint, dispatch/response timestamps, provider reference, uncertainty and retry state. Commit before network dispatch. Never persist authorization headers.       |
| Webhook inbox                        | Unique mode/provider/event identity, event type, provider-object reference, authenticated receipt time, minimal verified processing data, queued/processing/completed/quarantined outcome, retry/lease metadata and safe error category. Received is not the same as applied.      |
| Transition/audit                     | Order, flow/payment, source operation/event, previous/new state, actor, provider occurrence and local receipt/application times, reconciliation reason. Preserve financial anomalies and externally confirmed refund references without adding automated refunds.                  |

Do not impose one Payment Intent per Checkout Session. Enforce stable provider payment uniqueness and one accepted success application per order while preserving evidence if an unexpected second distinct successful payment is reported. A unique active-flow claim requires an actual MySQL design, such as a locked order pointer or claim record; a nullable column or ordinary status index alone does not enforce one active row.

Keep provider Checkout Session lifecycle separate from payment-attempt/payment-method lifecycle and from order status. A method action can expire and permit a new attempt while the Checkout Session remains active. Persist method type and provider-reported attempt status only when present and needed; do not infer method expiration from a timer or treat it as order/session expiry. Track the merchant 24-hour order cutoff independently.

Keep order lifecycle and payment lifecycle as separate state machines. Preserve provider references, status transitions/timestamps, event dedupe references, and cancellation/refund audit history needed to explain each change. Avoid retaining full webhook payloads unless a reviewed need and retention policy justify it.

Do not persist full card/payment credentials, webhook secrets, or raw payloads by default. Retain only identifiers, state, and minimal redacted event data needed for audit/reconciliation; align deletion with the existing order/audit retention policy and applicable privacy requirements.

## Constraints and integrity questions

- Default behavior absorbs fees; do not apply a customer surcharge. If pass-on fees are separately enabled after written permission, preserve base order amount, provider gross `amount`, provider `fee`, and provider `net_amount` separately and reconcile the exact Checkout v2 payload.
- Unique provider session/intent/payment identities prevent duplicate associations; event dedupe and guarded local transitions prevent repeat application. Scope uniqueness to environment/provider as needed. Two different event IDs can describe one payment.
- Base order amount, currency, pass-on-fee choice, provider-calculated fee, gross paid amount, and net settlement amount must be distinguishable and auditable; do not compare the fee-inclusive gross amount directly to the fee-exclusive order amount.
- Enforce duplicate protection across the local order, unresolved checkout session/payment attempt, provider identifiers, and webhook event IDs; coordinate database uniqueness with transactional application checks for concurrent requests.
- FK deletion behavior must preserve financial/audit history; avoid cascading historical payment evidence away with customer/order records.
- Index order ID, provider session/payment reference, status, event ID, and timestamps based on concrete reconciliation queries.
- Use integer minor units for PayMongo API amount if confirmed by current endpoint docs; retain existing Laravel decimal snapshots for commerce. Define exact conversion/rounding rules before coding.
- Choose transaction/locking strategy to serialize competing successful events and order transitions. Extend the inspected order-then-shipment lock order consistently; never run provider HTTP inside retried DB transactions. Add bounded work claims and stale-claim recovery for workers.

## Money and audit rules

Use exact decimal-string to integer-minor-unit conversion for documented PHP amounts; no binary float multiplication. Validate currency, positive supported amount, safe integer/storage bounds, rounding policy, and method-specific limits. Persist the immutable order payable amount including delivery; a provider fee must not reduce the amount accepted as payment of that order. Net settlement is not the customer-paid amount and must not replace merchandise revenue. Persist fee/net fields only when documented and available; absent is not zero. Retention duration and access roles remain an explicit developer decision in `10-readiness-audit.md`.

## Migration strategy (later, only with approval)

Use additive tables and data-preserving migrations; do not rewrite historical orders or infer gateway records from manual payment history. Validate MySQL and SQLite compatibility and rollback safety before making a method selectable. Never delete financial records for rollback convenience.

## Before migration design

Use the verified local MySQL 8.0.46/InnoDB/REPEATABLE-READ baseline; inspect actual table constraints and validate lock ordering before migrations. Finalize exact local status mapping and provider identifiers against current responses. Preserve the existing manual refund subsystem without adding automated PayMongo refund execution. Validate amount conversion and order/flow uniqueness against representative historical records before writing a migration. Deployment server settings require their own confirmation.

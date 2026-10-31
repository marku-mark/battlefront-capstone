# Requirements baseline

**Status:** implementation baseline approved by the developer within the PayMongo replacement scope; provider/account-specific activation and production launch remain gated. Derived from current code, project rules, and official sources reviewed 2026-10-23.

## Current project behavior (confirmed in repository)

- Checkout preview validates cart and fulfillment/payment eligibility; order placement is a later operation.
- Order commercial totals and item details are server-owned snapshots. Orders are persisted before fulfillment workflow and stock/sales effects follow existing rules.
- Manual GCash/Maya require private proof and administration review. Pickup cash/card require explicit payment and handover confirmation. COD requires separate delivery/collection behavior.
- The proposed PayMongo flow is an online payment path. Customers should not upload payment receipts or screenshots; successful payment should be confirmed automatically from authenticated PayMongo event/status evidence.
- Customer web requires authentication; mobile API has a separate route layer reusing shared business behavior.

## Approved first-release functional requirements

1. Use PayMongo Hosted Checkout v2 for card, GCash, Maya, and QR Ph. Integrate Direct Online Banking through its documented PayMongo Payment Intent redirect flow, not by assuming Hosted Checkout support. Offer these methods for delivery and pickup only when the method/bank is available in test mode and activated for the merchant.
2. Create the provider Checkout Session or Direct Online Banking Payment Intent only from a persisted, eligible order and server-calculated amount/currency.
3. Persist enough local provider references to associate attempts and events unambiguously with one order.
4. Redirect customer to the provider-hosted URL; return flow reports pending/success/failure from server state and never mutates payment state from query parameters.
5. Apply payment state only after verified provider evidence, with order/amount/currency/mode binding and idempotent event processing.
6. At an explicitly approved cutover, replace manual GCash/Maya proof upload and review for new online orders with PayMongo; preserve historical payment/proof records. Keep cash pickup, physical card-at-store, and COD workflows and their fulfillment rules separate and intact.
7. Provide support/reconciliation for uncertain outcomes, webhook outages, duplicate events, and late events.
8. Keep existing cash/card-at-pickup and COD workflows distinct from online card and bank-authorized payments.
9. Make PayMongo online payment methods available to customers choosing either delivery or pickup, subject to method support and merchant activation.
10. Absorb PayMongo fees by default. Enable Checkout Sessions v2 `pass_on_fees` only after written PayMongo/acquirer confirmation for every method in that session and approval of customer disclosure. If enabled later, preserve base order amount, provider gross, fee, and net settlement distinctly and show the provider-calculated total before authorization.
11. Let PayMongo apply native method/action expiry where documented for the selected flow. Separately, the merchant order validity cutoff is 24 hours from order placement. Reconcile, then close the applicable provider flow only through a documented supported operation; cancel the order and release stock only after terminal provider closure is confirmed and no payment is paid, pending, processing, or uncertain. If provider closure is unsupported or its result is uncertain, keep the order and stock held and escalate.
12. Keep order lifecycle and payment lifecycle separate. Update the order only through valid project transitions after the latest provider payment state is confirmed; never cancel an order solely because a local timer elapsed.
13. At the 24-hour cutoff, retrieve/reconcile the latest PayMongo state and close the applicable provider flow using its documented operation. Do not cancel or release stock while any attempt is paid, pending, processing, or uncertain. If closure is unsupported or unclear for an enabled Payment Intent flow, retain the order/stock hold, retry reconciliation, and escalate; do not release stock merely because 24 hours elapsed.
14. Prevent duplicate orders, payment attempts/charges, and webhook side effects through request idempotency, one unresolved provider flow per payable order, unique provider references/event IDs, and transactional state transitions. Do not create a replacement session or intent while its predecessor's state is unresolved.
15. Let customers retry failed or expired payment actions on the same active provider flow while the order remains within its 24-hour validity window, where the specific API supports retry. Do not create a replacement session or intent while the prior outcome is unresolved. A new order is required after confirmed closure/cancellation.
16. Do not automate refunds in the first release. A paid-order cancellation or refund request requires administrator review and separate authorization; follow provider method eligibility and record the external refund result. A cancellation never implies a refund. Late or contradictory payment evidence goes to reconciliation and is not fulfilled automatically.
17. Request PayMongo's customer receipt email after successful payment only where the selected provider API documents that capability and a valid customer email is available. This does not replace webhook/API verification and does not ask the customer to submit a receipt.

## Candidate nonfunctional requirements (proposed)

- Apply the creation, durable inbox, local state, cancellation and rollback contracts in `10-readiness-audit.md`. Persistent payment origin distinguishes historical manual wallets from PayMongo even when their method labels match.
- Customer, administrator, scheduler and method-switch actions share the same provider closure checks. Do not let existing local-only cancellation/manual-review predicates bypass verified gateway state.
- A duplicate-safe API result must be recoverable after browser/app closure, retried checkout submission, provider timeout and process crash; exact operation identity and database constraints belong in the reviewed design.
- No cardholder data is handled or stored by Battlefront; secrets are server-only.
- Preserve auditability, least privilege, data minimization, safe retries, and the existing customer/order history.
- Automated tests prove state transitions and duplicate safety; test-mode/manual checks are separately evidenced.

## Payment integration readiness checklist

The implementation baseline is approved. The following operational/provider checks remain before the relevant test or production activity.

| Checklist item         | Preparation status / remaining gate                                                                                                                                                                                                                                                                                                                     |
| ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Payment lifecycle      | Documented: order and payment states are separate. Approve exact state mapping and existing order workflow integration.                                                                                                                                                                                                                                 |
| Expiration rules       | Decision: use PayMongo method/action expiry plus a separate 24-hour merchant order cutoff. Keep Checkout Session and Payment Intent closure semantics distinct; verify supported Payment Intent closure for each enabled bank before implementation.                                                                                                    |
| Automatic cancellation | Decision: reconcile and close the provider flow at 24 hours where a documented closure operation exists; cancel/release only after confirmed terminal closure and no unresolved payment. If closure is unsupported or unclear, retain the order/stock hold and escalate. Scheduler implementation and failure alerting belong in implementation design. |
| Webhooks               | Baseline: verify signatures, persist/deduplicate durably before prompt acknowledgement, and process idempotently. Test endpoint registration/tunnel use remains a separately authorized test activity.                                                                                                                                                  |
| Duplicate prevention   | Documented: order/session/payment/event uniqueness and idempotency. Design transaction and concurrency controls before implementation.                                                                                                                                                                                                                  |
| Payment recovery       | Decision: retry in the same provider flow within 24 hours where supported; reconcile before any replacement order. Uncertain cases retain stock and escalate.                                                                                                                                                                                           |
| Refund handling        | Decision: no automated refunds in first release; administrator review and explicit authorization, with provider eligibility checked per method.                                                                                                                                                                                                         |
| Security               | Documented: server-only secrets, raw-body signature verification, least privilege, no redirect-as-proof. Review concrete implementation before test activity.                                                                                                                                                                                           |
| Database records       | Documented: persist provider references, statuses, timestamps, and minimized audit/dedupe data. Approve schema, retention, and MySQL concurrency design.                                                                                                                                                                                                |
| Testing                | Scenarios are in `07-testing.md`. Focused automated tests are part of implementation; test-mode end-to-end validation awaits credentials and account access.                                                                                                                                                                                            |

## Scope assumptions and open requirements

- Hosted Checkout v2 is selected for card, GCash, Maya, and QR Ph. Direct Online Banking remains in the approved payment scope through the documented Payment Intent redirect flow; do not represent it as a Hosted Checkout method. Enable only bank routes available in test mode and activated for the merchant. Bank availability may change.
- “Bank transfer” in this proposal means a customer authorizes a payment through PayMongo’s supported online banking flow. It does not mean manual transfer to Battlefront followed by receipt upload.
- Partial payment, split tender, recurring billing, saved payment methods, and mobile-native payment SDKs are out of scope. Refunds remain manual/admin-authorized and are not automated in the first release.
- PayMongo method action expiry is independent of the merchant's 24-hour order/session cutoff. Session expiry may fail for paid, pending, or processing attempts. Use reconciliation and retain stock when the result is uncertain.
- Pass-on fees remain disabled by default until PayMongo/acquirer permission and disclosure requirements are confirmed in writing. This preserves the preference to pass fees on later where permitted without applying an unverified surcharge.
- Reuse existing manual refund recording/review and business permissions where their domain rules apply, as documented in `11-system-aligned-setup.md`. No automated PayMongo refund or invented return case is allowed; unmatched paid-order exceptions go to Owner/permitted business review. Live dispute/provider actions remain separately authorized.
- Check the authoritative manuscript and approved amendments if a proposed flow changes approved project scope.

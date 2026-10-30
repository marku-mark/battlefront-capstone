---
name: pc-builder-commerce
description: Plan or implement an approved PC Builder integration with Battlefront cart, inventory, checkout, wishlist, payments, orders, and shipping.
---

# Purpose

Integrate build purchases through existing Battlefront product and commerce boundaries while preserving stock, payment, order, and fulfillment invariants.

## Preconditions

- Read `battlefront-system-audit/SKILL.md`, `docs/CAPSTONE_CONTEXT.md`, and cart/checkout/order/payment/shipping rules.
- Use payment architecture/security and LBC domain skills for relevant behavior; the existing project agents remain authoritative for their domains.
- Confirm explicit implementation approval before changing application code or data structures.

## Workflow

1. Trace existing cart identity, selected-line validation, pricing, inventory locks, checkout, payment, order snapshots, returns/cancellation, and shipment behavior.
2. Preserve existing product IDs and current server-side price/stock authority. A saved build does not reserve stock.
3. Define build group identity through cart and checkout without merging it into unrelated loose-product lines.
4. Aggregate duplicate SKU quantities before stock validation and delivery quotation; verify every selected line belongs to the customer and requested build.
5. Reuse the existing atomic order-placement boundary and immutable commercial snapshots.
6. Reuse existing PayMongo flows and authentication/idempotency/state rules. A customer redirect is not payment confirmation. Do not introduce a parallel gateway.
7. Reuse existing shipping/fulfillment assumptions; separately approve assembly packaging, fees, service promises, or live courier operations.
8. Plan transaction, race, retry, cancellation, payment, and regression scenarios.

## Expected output

Provide integration data flow, reused boundaries, required changes, transaction invariants, edge cases, regression tests, and unresolved decisions.

## Safety and validation

Never reserve stock merely by saving a build, trust browser prices, mutate orders after placement, release stock while payment is uncertain, trigger provider operations without explicit authorization, or add synthetic stock or service SKUs implicitly. Keep manual refunds and current role permissions intact.

## References

- `.agents/skills/payment-architecture/SKILL.md`
- `.agents/skills/payment-security/SKILL.md`
- `.agents/skills/lbc-shipping-domain/SKILL.md`
- `.agents/skills/lbc-security/SKILL.md`
- `.ai/rules/index.md` and applicable commerce rules

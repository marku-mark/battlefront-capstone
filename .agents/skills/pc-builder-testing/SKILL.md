---
name: pc-builder-testing
description: Design, review, or run approved PC Builder compatibility, commerce, authorization, security, and release tests.
---

# Purpose

Provide evidence that the approved builder rules and commerce integration work, including failure paths and existing-flow regression coverage.

## Preconditions

- Read `AGENTS.md`, `docs/CAPSTONE_CONTEXT.md`, `.ai/rules/index.md`, and applicable test, compatibility, commerce, payment, and security skills.
- Confirm the task authorizes test execution and the relevant environment is non-production.
- Read the installed Pest/PHPUnit conventions before authoring tests.

## Workflow

1. Build a rule matrix for socket/chipset/BIOS, RAM generation/capacity/slots, GPU and case clearances, PSU output/connectors, cooler mounting/clearance, storage interfaces/shared lanes, missing/conflicting sources, and incomplete builds.
2. Cover selection, search, pricing refresh, out-of-stock products, saved-build ownership, edits/deletion, and authorization.
3. Cover repeated SKUs, build group preservation, aggregated inventory demand, stale stock, checkout idempotency, and transactional rollback.
4. Cover existing online payment success/failure/expiry/retry/webhook duplicates/recovery and manual methods without treating redirects as payment evidence.
5. Cover order snapshots, cancellation/payment races, shipment assumptions, and existing cart/checkout/wishlist/order regressions.
6. Run only the authorized narrow test scope; report exact commands, results, environment, and untested limits.

## Expected output

Return requirement-to-test mapping, critical fixtures, observed results (if run), failures, regressions, security findings, and residual risks.

## Safety and validation

Do not claim tests passed if not run. Keep provider activity test-mode and separately authorized; no production calls, stock mutation, or destructive data operations. Never approve a design based solely on test counts. `unknown` compatibility must remain visible, and all order/payment boundaries require regression tests.

## References

- `.agents/skills/testing-best-practices/SKILL.md`
- `.agents/skills/payment-testing/SKILL.md`
- `.agents/skills/payment-security/SKILL.md`
- `.agents/skills/pc-compatibility-engine/SKILL.md`

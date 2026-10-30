---
name: payment-security
description: Use when reviewing PayMongo credentials, webhook authenticity, payment authorization, data exposure, replay/duplicate handling, logging, access control, or payment-related threat models.
---

# Payment security workflow

## Scope
Review payment integration security and privacy. This skill does not authorize security-sensitive changes or live operations.

## Required references
- Read `docs/paymongo/11-system-aligned-setup.md` for verified local infrastructure, existing permissions/refund workflows, chosen operating settings, private test credential placement and the official PayMongo guide checklist. Store actual test keys only in the ignored root `.env`; these proposed environment names remain inactive until explicitly implemented. Never print secrets or assume account activation from their presence.
- Read `docs/paymongo/01-official-sources.md`, `06-security.md`, and current official webhook/authentication guidance.
- Read applicable project rules and inspect the relevant Laravel routes, middleware, request parsing, logging, and order authorization.

## Operations
Read `docs/paymongo/10-readiness-audit.md` for the shared implementation contracts and outstanding evidence gates. Review authenticated durable ingress before acknowledgement, early/synthetic events, mode/amount/order binding, timestamp versus event time, every cancellation entry point, credential/redirect boundaries and operator access. Preparation-only tasks produce review artifacts, not application changes or external operations.

1. Trace secrets from configuration to HTTP client and ensure server-only handling.
2. Verify webhook signatures from the raw bytes using the environment-specific secret and constant-time comparison; define freshness/replay handling from current provider guidance.
3. Require authenticated event identity and an idempotent, transaction-safe application of verified payment state.
4. Ensure a completed transaction is confirmed from provider evidence; no receipt upload or customer assertion can authorize a paid state.
5. Review amount/currency/order binding, ownership, status transitions, logging, retention, and operational recovery. Before cancellation, require current provider-state reconciliation and confirmed applicable provider-flow closure; late payment after cancellation needs operator review.
6. Automated refunds are out of first release. Treat any later refund as a separate authorized provider transaction; cancellation is not a refund. Verify method eligibility and provider-confirmed outcomes before adding refund behavior.
7. For local webhook tests, use only an approved HTTPS tunnel to the webhook endpoint; do not expose database/admin/debug routes or secrets.
8. Report threats, evidence, severity, remediation options, and remaining uncertainty.

## Safety restrictions
- Never expose or request actual secret values; redact them if encountered.
- Never accept browser return parameters as payment evidence.
- Never process an unsigned/unverified event or trust client-supplied amount/order identifiers.
- Do not register endpoints, send transactions, alter dashboard settings, or use live credentials.

## Expected output and completion
Provide a concise threat model and actionable findings with source/code references. Complete when authenticity, authorization, replay/duplicate handling, data minimization, and operational failure paths have been reviewed.

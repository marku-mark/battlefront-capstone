---
name: lbc-api-docs
description: Research official LBC capabilities, merchant access, and documented API contracts for Battlefront shipping. Use for LBC source verification or future adapter planning, not ordinary order administration.
---

# LBC documentation research

Use `docs/integrations/lbc/README.md` as the entry point and follow its task-to-reference table. Read only the documents needed for the requested research; reread guidance if it changes during the task. Separate Battlefront decisions, public LBC service descriptions, documented API contracts, merchant entitlement, and tested behavior.

- Start with the evidence in `docs/integrations/lbc/api-sources.md`. Recheck official LBC sources when current evidence is needed. Return exact URLs, access date, claims, and limitations; update the source packet only when documentation work is assigned.
- Distinguish publicly advertised services, accessible API operations, account-specific entitlement, and unresolved questions. An empty catalogue or inaccessible page does not prove a capability is unsupported.
- Public booking, rates, and tracking interfaces are not API contracts. Never infer endpoints, authentication headers, payloads, webhooks, idempotency, cancellation, limits, or sandbox access from those interfaces.
- Research is read-only. Do not submit bookings, track real shipments programmatically, obtain credentials, create merchant accounts, or call operational LBC APIs. Sandbox operations require separate explicit authorization.
- An approved internal Battlefront mock may use explicitly designed synthetic behavior without an LBC contract, but must not claim LBC compatibility. Provider-compatible simulation or an adapter requires endpoint-specific evidence, entitlement, and separate authorization.

Return cited facts, explicit unknowns, and the evidence needed to resolve them. Never claim merchant access or production readiness without evidence.

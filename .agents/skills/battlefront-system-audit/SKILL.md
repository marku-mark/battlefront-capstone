---
name: battlefront-system-audit
description: Audit existing Battlefront architecture, database relationships, reusable modules, and integration risks before planning a feature.
---

# Purpose

Produce a concise, evidence-backed system map for feature planning. Existing implementation and approved scope are separate facts; never describe a proposal as implemented.

## Preconditions

- Read `AGENTS.md`, `.ai/rules/index.md`, matching rule files, and relevant keywords across `.ai/rules`.
- For scope, business rules, data, payment, order, or architecture work, read `docs/CAPSTONE_CONTEXT.md`. Consult the manuscript only when an exact requirement or unresolved conflict requires it.
- If `graphify-out/graph.json` exists, use the repository Graphify skill/query before the first source search or read.

## Workflow

1. Establish the task boundary, runtime/package versions, and current implementation status.
2. Map relevant routes, controllers, actions/services, models, migrations, UI, API contracts, and tests.
3. Inspect schema and constraints with Laravel Boost when available; distinguish verified relationships from assumptions.
4. Trace ownership, authorization, transaction, inventory, payment, fulfillment, and historical-data boundaries as relevant.
5. Identify reusable functionality, extension points, duplication, technical debt, risks, and unknowns.
6. Check findings against approved scope; surface conflicts without silently resolving them.

## Expected output

Report concise facts with paths/symbols, relevant data relationships, reusable behavior, risks by impact, unknowns, and recommendations. State what was not inspected or verified.

## Safety and validation

Use read-only inspection unless the task separately authorizes implementation. Do not mutate data, execute migrations, call providers, or expose secrets/PII. Verify each claim against code, schema, tests, or an identified authoritative document. This project currently excludes explicit compatibility checking and configurator behavior; label PC Builder work as a proposed scope extension unless the developer supplies an approved amendment.

## References

- `AGENTS.md`
- `.ai/rules/index.md` and matching rules
- `docs/CAPSTONE_CONTEXT.md`
- `.agents/skills/graphify/SKILL.md`

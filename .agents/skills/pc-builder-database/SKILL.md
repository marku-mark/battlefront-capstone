---
name: pc-builder-database
description: Plan PC Builder product specifications, saved-build relationships, constraints, indexes, migrations, and data-preserving recovery.
---

# Purpose

Design the smallest persistence extension that reuses Battlefront’s catalog and preserves existing commercial history.

## Preconditions

- Read `battlefront-system-audit/SKILL.md`, `AGENTS.md`, `docs/CAPSTONE_CONTEXT.md`, and database/model rules.
- Inspect current schema, models, factories, and migration conventions. Use Laravel Boost schema inspection where available.
- Treat Builder persistence as a proposed scope extension until explicitly approved.

## Workflow

1. Confirm actual product, inventory, cart, wishlist, user, order, and order-item relations and constraints.
2. Reuse product identities; propose normalized/versioned specifications with source provenance rather than a parallel product catalog.
3. Propose saved-build ownership and component composition with explicit uniqueness, ordering, deletion behavior, and authorization.
4. Define indexes and constraints from query and integrity needs. Preserve unknown facts and source review states.
5. Plan forward-only, data-preserving migrations, deployment ordering, backfill needs, and recovery/rollback that refuses to discard customer or order history.
6. Define immutable order snapshots so later build edits or product changes cannot rewrite a placed order.

## Expected output

Provide entity/relationship proposal, field purpose/types at planning level, constraints/indexes, migration sequence, data-preservation strategy, recovery limits, and open schema questions.

## Safety and validation

Do not edit schema, execute migrations, reset databases, invent existing constraints, or delete historical data. Verify proposed relationships against focused schema inspection and local conventions. Clearly label unverified assumptions.

## References

- `AGENTS.md`
- `.ai/rules/index.md` and applicable model/migration rules
- `docs/CAPSTONE_CONTEXT.md`
- Laravel Boost database-schema tool when configured

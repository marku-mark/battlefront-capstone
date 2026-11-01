---
name: qdrant-cloud-integration
description: Plan Qdrant Cloud semantic product retrieval, indexing and synchronization.
---

# Purpose and activation

Plan Qdrant Cloud semantic product retrieval, indexing and synchronization. Use for preparation and design, not authorization to implement.

## Procedure and expected output

Verify current official Qdrant documentation and free-tier limits before choosing collection settings. Specify embedding provider/model/version, dimension, metric, multilingual evaluation and migration strategy; never guess dimensions. Estimate vectors plus payload/index overhead and resource headroom. Use stable MySQL product IDs, public allowlisted payloads, idempotent after-commit indexing, stale-job handling, deletion/inactivation, reconciliation and rebuild plans. Rehydrate candidates from MySQL for current visibility, price and stock. Output collection proposal, sync lifecycle, capacity assumptions and failure fallback. Do not create collections or embeddings.

## Constraints

Preparation only. Do not implement integrations, edit application code or .env, install dependencies, run migrations, call Gemini, or mutate Qdrant/Neo4j. Require explicit developer approval for future implementation. Read AGENTS.md, .ai/rules/index.md and applicable rules; use graphify query before source exploration when graph exists. Keep MySQL authoritative and answers restricted to the Battlefront catalog. Preserve existing functionality and flag scope conflicts. Never expose credentials. Verify changing provider limits from official sources at planning time; do not claim multilingual quality without evaluation.


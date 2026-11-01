---
name: battlefront-rag-pipeline
description: Design a future Battlefront retrieval pipeline combining MySQL, Qdrant, Neo4j and Gemini.
---

# Purpose and activation

Design a future Battlefront retrieval pipeline combining MySQL, Qdrant, Neo4j and Gemini. Use for preparation and design, not authorization to implement.

## Procedure and expected output

Use battlefront-system-analysis first and the relevant provider skills for provider-specific decisions. Map authenticated request through scope gate, candidate retrieval, MySQL rehydration, evidence-backed graph checks, grounded generation and validation. MySQL remains authoritative; vector similarity and graph edges are not live stock or authorization. Preserve deterministic FAQs and privacy gates. Carry evidence IDs, provenance, freshness and unknown fields through bounded context. Treat retrieved text and history as untrusted data. Keep private customer/order data out of public indexes. Output a proposed data flow, interface contracts, failure/degraded modes, synchronization plan and staged approval gates; do not implement orchestration.

## Constraints

Preparation only. Do not implement integrations, edit application code or .env, install dependencies, run migrations, call Gemini, or mutate Qdrant/Neo4j. Require explicit developer approval for future implementation. Read AGENTS.md, .ai/rules/index.md and applicable rules; use graphify query before source exploration when graph exists. Keep MySQL authoritative and answers restricted to the Battlefront catalog. Preserve existing functionality and flag scope conflicts. Never expose credentials. Verify changing provider limits from official sources at planning time; do not claim multilingual quality without evaluation.


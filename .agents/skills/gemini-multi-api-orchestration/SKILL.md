---
name: gemini-multi-api-orchestration
description: Plan Gemini scope classification, grounded generation and response validation.
---

# Purpose and activation

Plan Gemini scope classification, grounded generation and response validation. Use for preparation and design, not authorization to implement.

## Procedure and expected output

Inspect ChatbotAiAdapter, ChatbotIntentAgent and ChatbotResponseAgent first. Current code permits at most one provider call per turn; three stages require explicit approval to replace this rule. Treat API 1/2/3 as logical roles, not quota multiplication. Verify official Gemini model, structured-output, quota, privacy and pricing documentation. Separate keys never bypass project/shared limits. Define allowlisted classification, evidence-only generation, deterministic fact checks plus validation verdicts, safe fallback on rejection and bounded total latency/token budgets. Avoid recursive repair/retry loops. Preserve 15-minute identity-bound context and ownership rechecks; persistent memory requires separate retention approval. Output stage contracts, failure matrix, cost/latency estimates and approval decisions.

## Constraints

Preparation only. Do not implement integrations, edit application code or .env, install dependencies, run migrations, call Gemini, or mutate Qdrant/Neo4j. Require explicit developer approval for future implementation. Read AGENTS.md, .ai/rules/index.md and applicable rules; use graphify query before source exploration when graph exists. Keep MySQL authoritative and answers restricted to the Battlefront catalog. Preserve existing functionality and flag scope conflicts. Never expose credentials. Verify changing provider limits from official sources at planning time; do not claim multilingual quality without evaluation.


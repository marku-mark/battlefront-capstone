---
name: battlefront-chatbot-evaluation
description: Plan chatbot multilingual, retrieval, compatibility, hallucination and memory evaluation.
---

# Purpose and activation

Plan chatbot multilingual, retrieval, compatibility, hallucination and memory evaluation. Use for preparation and design, not authorization to implement.

## Procedure and expected output

Read testing-best-practices before designing tests; reuse existing chatbot feature/unit/mobile/frontend suites. Plan English, Tagalog, Cebuano/Bisaya and Hiligaynon cases, code switching, typos, ambiguous regional vocabulary and other Visayan languages with qualified reviewers. Include unknown/inactive/deleted products, stock changes, unsupported recommendations, missing compatibility evidence, injection in catalog/history, unauthorized orders, token expiry/identity change, empty retrieval, timeout and invalid validation output. Use provider fakes, no live Gemini calls. Define labeled expected outcomes, retrieval recall, grounding accuracy, false compatibility rate, privacy failures and latency budgets with proposed thresholds for human approval. Output test matrix, fixtures strategy and regression/evaluation acceptance criteria.

## Constraints

Preparation only. Do not implement integrations, edit application code or .env, install dependencies, run migrations, call Gemini, or mutate Qdrant/Neo4j. Require explicit developer approval for future implementation. Read AGENTS.md, .ai/rules/index.md and applicable rules; use graphify query before source exploration when graph exists. Keep MySQL authoritative and answers restricted to the Battlefront catalog. Preserve existing functionality and flag scope conflicts. Never expose credentials. Verify changing provider limits from official sources at planning time; do not claim multilingual quality without evaluation.


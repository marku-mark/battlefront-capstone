---
name: battlefront-system-analysis
description: Analyze the existing chatbot and its Laravel data boundaries before AI integration planning.
---

# Purpose and activation

Analyze the existing chatbot and its Laravel data boundaries before AI integration planning. Use for preparation and design, not authorization to implement.

## Procedure and expected output

Reuse battlefront-system-audit rather than duplicate its general audit. Read that skill, project context and matching rules. Trace web/mobile controllers, ChatbotOrchestrationService, ChatbotAiAdapter, both app/Ai/Agents, context resolvers, conversation tokens and tests. Verify installed versions and Boost schema without customer rows. Map Product category, inventory, tags and componentProfile relationships; preserve Scout/Algolia. Distinguish existing config placeholders from working integrations. Output a path-backed architecture map, reusable boundaries, scope conflicts and unknowns.

## Constraints

Preparation only. Do not implement integrations, edit application code or .env, install dependencies, run migrations, call Gemini, or mutate Qdrant/Neo4j. Require explicit developer approval for future implementation. Read AGENTS.md, .ai/rules/index.md and applicable rules; use graphify query before source exploration when graph exists. Keep MySQL authoritative and answers restricted to the Battlefront catalog. Preserve existing functionality and flag scope conflicts. Never expose credentials. Verify changing provider limits from official sources at planning time; do not claim multilingual quality without evaluation.


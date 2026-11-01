---
name: ai-security-and-secrets
description: Review AI credential boundaries, prompt injection, privacy and safe logging.
---

# Purpose and activation

Review AI credential boundaries, prompt injection, privacy and safe logging. Use for preparation and design, not authorization to implement.

## Procedure and expected output

Inspect configuration references without opening or printing secret values. Server-only env/config credentials; never VITE variables, prompts, source, logs or public payloads. Plan least privilege, TLS, endpoint allowlisting, credential rotation and redacted diagnostics. Treat prompts, catalog text, graph results and conversation memory as untrusted; enforce authorization and query templates outside the model. Minimize provider data, exclude customer data from public indexes, verify current provider retention/free-tier terms. Validate structured output and safe rendering; model validation is not an authorization boundary. Output trust-boundary map, concrete threats/mitigations, logging allowlist and residual risks. Do not change .env, credentials or external accounts.

## Constraints

Preparation only. Do not implement integrations, edit application code or .env, install dependencies, run migrations, call Gemini, or mutate Qdrant/Neo4j. Require explicit developer approval for future implementation. Read AGENTS.md, .ai/rules/index.md and applicable rules; use graphify query before source exploration when graph exists. Keep MySQL authoritative and answers restricted to the Battlefront catalog. Preserve existing functionality and flag scope conflicts. Never expose credentials. Verify changing provider limits from official sources at planning time; do not claim multilingual quality without evaluation.


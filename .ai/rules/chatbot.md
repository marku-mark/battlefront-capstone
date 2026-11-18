---
paths:
    - 'app/{Ai,Services/Chatbot}/**'
---

# Chatbot

## Keep Gemini behind the chatbot adapter

Use ChatbotAiAdapter as the application boundary for Laravel AI SDK text generation. ChatbotResponseAgent is pinned to Gemini 3.5 Flash-Lite with a 20-second timeout because Google no longer makes 2.5 Flash available to new users; callers pass only application-prepared scalar/array context, never Eloquent models or provider credentials. Map provider failures to safe adapter results without logging prompts, context, secrets, or raw exception messages.

## Keep Gemini behind the chatbot adapter

Use ChatbotAiAdapter for Gemini 3.5 Flash-Lite text generation with application-prepared scalar/array context only. Its timeout now reads battlefront.chatbot_timeout_seconds (CHATBOT_TIMEOUT_SECONDS), defaults to 20 seconds, and accepts 5–30 seconds. Log only provider/model, timing, configured timeout, failure category, HTTP status and exception class; never prompts, context, secrets or raw exception messages. Distinguish typed transport timeouts from other connection failures.

## Approved retrieval and catalog advice extension
The developer approved implementing docs/ai-integration/IMPLEMENTATION_PLAN.md and reported adviser approval for chatbot recommendations and exact-part compatibility. Supersedes prior chatbot exclusion only within the current eligible catalog and reviewed evidence. Keep PHP CompatibilityEngine authoritative, cloud projections rebuildable, private support deterministic in the new path, and durable memory identity-bound with 15-minute context expiry. Provider integration, model-led understanding, and adaptive orchestration are disabled by default. The legacy route uses at most one call per turn; approved opt-in flows can use up to the configured shared three-call budget. Feature activation/live cloud evaluation remains separately controlled.

## Bound opt-in multipart public answers to one understanding call
The developer approved continuing the findings-driven chatbot repair. CHATBOT_MULTIPART_ENABLED is off by default and extends model-led public handling to at most three ordered needs using one structured understanding call and deterministic authoritative per-part replies. Screen every original clause for private support before provider use, clarify overflow, validate every part, and clear ambiguous product follow-up state. Do not multiply three-stage calls by part; retain the shared deadline and private-order authorization.

## Review multipart catalog drafts within the existing turn budget
The continued repair extends multipart catalog answers to one understanding call plus one combined generation and mandatory review, within the existing three-call ceiling. Namespace evidence by part and validate every ordered part against its own facts and price scope; reject missing parts and cross-part citations. Budget/review failures retain deterministic complete sections. FAQ/private/unresolved parts remain deterministic; this supersedes the earlier deterministic-only multipart catalog rule. Gemini 3.5 Flash-Lite is the developer-confirmed model; retain current configuration and disabled-by-default activation.

## Switch limited Gemini models without mixing embedding spaces
The developer explicitly requested model fallback and no guessed provider quotas. Local project caps default off; retain turn/deadline/review and concurrency safeguards. Text fallback switches only on provider rate-limit errors, stays with the role credential, honors cooldowns and counts retries within the shared three-call budget without consuming reserved review capacity. Embedding model fallback must use a separate matching Qdrant collection, current model-specific source hashes/revisions and matching dimensions; never send Embedding 2 vectors into an Embedding 001 index. Cloud provisioning/re-embedding remain separately activated.

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
The developer approved implementing docs/ai-integration/IMPLEMENTATION_PLAN.md and reported adviser approval for chatbot recommendations and exact-part compatibility. Supersedes prior chatbot exclusion only within current eligible catalog and reviewed evidence. Keep PHP CompatibilityEngine authoritative, cloud projections rebuildable, private support deterministic in the new path, and durable memory identity-bound with 15-minute context expiry. Additional Gemini roles share project quotas; default remains one generative call. Feature activation/live cloud evaluation remains separate from local implementation.

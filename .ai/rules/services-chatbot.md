---
paths:
    - 'app/Services/Chatbot/**'
---

# Services Chatbot

## Gate Gemini behind authoritative chatbot context

Chatbot orchestration must normalize and categorize first, resolve only the selected category's authoritative context second, and call ChatbotAiAdapter only when that context is non-empty. Unsupported, missing-data, order-privacy, timeout, and provider-failure paths return predefined fallbacks without inventing facts.

## Gate Gemini behind authoritative chatbot context

Normalize and route first, resolve only the selected category, and call ChatbotAiAdapter only after privacy and non-empty-context checks. On provider failure, RenderChatbotFallback formats existing resolver facts/approved templates; clarify ambiguous matches, preserve unavailable fields and demo disclaimers. Requery ownership and live facts on follow-ups. No automatic retries, alternate provider, or provider bypass is enabled.

## Return approved FAQs and application rule guidance directly

The developer-approved direct-answer refinement supersedes the prior provider-bypass prohibition for FAQ-category responses. After shared ownership, privacy, ambiguity and nonempty-context checks, return the one selected current active eligible FAQ answer or application payment/fulfillment guidance directly, preserving approved wording and conversation references. Gemini remains behind ChatbotAiAdapter for live product, branch and personal-order contexts. Preserve the existing gemini/fallback response-source contract; fallback denotes deterministic replies too.

## Bound multilingual intent classification to one provider call per turn

The approved multilingual refinement permits Gemini intent classification before fact retrieval only for screened, uncertain Battlefront inquiries, through ChatbotAiAdapter with validated allowlisted output. Never use it for FAQ approval/preview or authorization. Allow at most one Gemini call per turn: classification consumes the call and the response uses deterministic resolver wording. Keep approved FAQ/rule and fallback text in English until localized wording is reviewed; live-data generation may use en/hil/ceb/tl without claiming verified fluency. No live Gemini calls are authorized for automated tests or implementation verification.

## Approved three-stage multilingual chatbot refinement
The developer approved implementing the multilingual three-stage plan on 2026-11-02, superseding the older one-call limit and English-only fallback restriction. Enabled public AI turns use scope, grounded generation and review within one shared three-call budget; deterministic FAQs/private support remain zero-call. Explicit language preferences persist within identity-bound 15-minute context; English, Cebuano/Bisaya, Tagalog and Hiligaynon remain distinct. Preserve MySQL live facts, PHP compatibility verdicts and exact amounts; localized wording requires fluent-speaker review, and cloud activation/live evaluation remains separately controlled.

## Adaptive public chatbot performance policy
The developer approved adaptive Gemini stages: deterministic single-product facts skip Gemini, clear public catalog reasoning may skip scope, and free-form drafts still require review. CHATBOT_ADAPTIVE_PIPELINE gates these choices; retain legacy behavior when disabled. Reserve review capacity before adaptive generation, refund unused reservations, and keep MySQL/current approved specifications and PHP compatibility authoritative. Projection workers must consume chatbot-indexing; live cloud performance evaluation remains a separate release gate.

## Model-led public chatbot remains grounded
When CHATBOT_MODEL_LED_ENABLED and CHATBOT_AI_INTEGRATION_ENABLED are on, eligible public turns use one bounded understanding call, MySQL/approved context, grounded generation and review within a shared three-call budget. Never send private order data to the interpreter or treat model output as catalog authority. Preserve deterministic private-order authorization, PHP compatibility verdicts, current stock/price, and fallback on provider or review failure. Live activation and fluent-speaker evaluation remain separate release gates.

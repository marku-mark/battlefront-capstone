---
paths:
    - 'app/Services/Chatbot/**'
---

# Services Chatbot

## Keep privacy gates and authoritative context ahead of generation

Apply deterministic privacy and scope checks before every provider call. The legacy route categorizes and resolves authoritative context before generation. The approved model-led route may interpret a screened public catalog inquiry before retrieval, then resolves current facts before generation and review; never send personal order data to the intent model. Unsupported, missing-data, order-privacy, timeout, and provider-failure paths return predefined fallbacks without inventing facts.

## Gate Gemini behind authoritative chatbot context

In the legacy route, normalize and route first, resolve only the selected category, and call ChatbotAiAdapter only after privacy and non-empty-context checks. On provider failure, RenderChatbotFallback formats existing resolver facts/approved templates; clarify ambiguous matches, preserve unavailable fields and demo disclaimers. Requery ownership and live facts on follow-ups. No automatic retries or alternate provider is enabled.

## Return approved FAQs and application rule guidance directly

The developer-approved direct-answer refinement supersedes the prior provider-bypass prohibition for FAQ-category responses. After shared ownership, privacy, ambiguity and nonempty-context checks, return the one selected current active eligible FAQ answer or application payment/fulfillment guidance directly, preserving approved wording and conversation references. Gemini remains behind ChatbotAiAdapter for live product, branch and personal-order contexts. Preserve the existing gemini/fallback response-source contract; fallback denotes deterministic replies too.

## Bound the legacy intent route to one provider call per turn

When the model-led flag is disabled, Gemini intent classification may run before fact retrieval only for screened, uncertain Battlefront inquiries, through ChatbotAiAdapter with validated allowlisted output. Never use it for FAQ approval/preview or authorization. This legacy route allows at most one Gemini call per turn: classification consumes the call and the response uses deterministic resolver wording. When model-led orchestration is enabled, follow the approved shared three-call budget below. Keep unreviewed FAQ/rule and fallback translations in English; live-data generation may use en/hil/ceb/tl without claiming verified fluency. No live Gemini calls are authorized for automated tests or implementation verification.

## Approved three-stage multilingual chatbot refinement
The developer approved implementing the multilingual three-stage plan on 2026-11-02, superseding the legacy one-call limit and English-only fallback restriction when the model-led path is enabled. Eligible public AI turns may use scope, grounded generation, and review within one shared three-call budget; deterministic FAQs/private support remain zero-call. Explicit language preferences persist within identity-bound 15-minute context; English, Cebuano/Bisaya, Tagalog and Hiligaynon remain distinct. Preserve MySQL live facts, PHP compatibility verdicts and exact amounts; localized wording requires fluent-speaker review, and cloud activation/live evaluation remains separately controlled.

## Adaptive public chatbot performance policy
The developer approved adaptive Gemini stages: deterministic single-product facts skip Gemini, clear public catalog reasoning may skip scope, and free-form drafts still require review. CHATBOT_ADAPTIVE_PIPELINE gates these choices; retain legacy behavior when disabled. Reserve review capacity before adaptive generation, refund unused reservations, and keep MySQL/current approved specifications and PHP compatibility authoritative. Projection workers must consume chatbot-indexing; live cloud performance evaluation remains a separate release gate.

## Model-led public chatbot remains grounded
When CHATBOT_MODEL_LED_ENABLED and CHATBOT_AI_INTEGRATION_ENABLED are on, eligible public turns use one bounded understanding call, MySQL/approved context, grounded generation and review within a shared three-call budget. Never send private order data to the interpreter or treat model output as catalog authority. Preserve deterministic private-order authorization, PHP compatibility verdicts, current stock/price, and fallback on provider or review failure. Live activation and fluent-speaker evaluation remain separate release gates.

## Rank catalog products by intent and verify price scope
Use the shared chatbot catalog selection and ranking policy: main products lead broad/device searches; explicit accessories lead accessory searches. Calculate cheapest/most expensive over all eligible matching MySQL rows using discounted selling price before limits; cloud candidates alone cannot establish extrema. Persist only bounded structured constraints, never prior user questions in provider context. Qdrant/Neo4j family-role projections are derived and require current hashes; PHP retains compatibility authority.

## Keep failure review separate from customer memory and catalog knowledge
The developer-approved failure review stores only encrypted allowlisted public diagnostic samples; unknown/private scope gets metadata only and explicit unsupported scope is excluded. Successful deterministic fallback replies are not failures. Keep capture bounded and best effort, expire samples within seven days, and never send them upstream or use them in customer retrieval. Local CLI review proposes investigations; human resolution links a regression test and never automatically edits prompts, code or facts.

## Full catalog pipeline has one bounded deadline
The developer approved full public-catalog participation via CHATBOT_FULL_CATALOG_PIPELINE: understanding, Qdrant and Neo4j retrieval where valid inputs exist, grounded generation and mandatory review. FAQs/private support retain direct routing. Full mode overrides adaptive shortcuts, caps server work at 25 seconds and browser waiting at 30 seconds; missing review or provider failure returns authoritative local fallback. Automated tests must explicitly reset the full-mode flag independently of local activation.

## Keep branch product inquiries compound and inventory authoritative
Branch plus product constraints form one catalog request. Preserve branch/availability with existing brand/category/price constraints in bounded identity-bound follow-up state. Branch stock replies use published MySQL branch records and deterministic wording: only Sagay has live inventory; other branches are unknown, never inferred available or out of stock. Legacy intent fallback and model-led understanding share this boundary; never activate providers for verification.

## Preserve branch catalog constraints in deterministic fallbacks
Local branch requests and understanding-failure replies use ChatbotCatalogSelection with structured filters; never reconstruct a search sentence that drops sort or strict price bounds. Retain bounded branch/category/brand/price constraints through empty results and branch clarification, clear them for explicit new catalog topics, and requery live facts each turn within identity-bound expiring state.

## Prioritize model-led catalog replies with mandatory product format
Eligible enabled public catalog turns prioritize model-led understanding, grounded generation and mandatory review; keyword success and simple facts must not routinely bypass generation. Preserve ordered category sections and Product name, Price, Availability, Link (View Product) fields from verified system facts/system URLs through multipart, fallback and restored transcripts. All factual prose must use current authorized MySQL/approved evidence and PHP compatibility verdicts; never invent missing facts. Deterministic fallback is for provider/validation/budget/deadline failure; private-support gates and approved direct FAQs remain. Supersedes adaptive catalog shortcuts; preserve activation controls.

## Retain reviewed model wording beside authoritative catalog fields
Do not discard reviewed model wording while constructing product cards or multipart sections. Keep the validated explanation with system-controlled Product name, Price, Availability and Link (View Product), including single-section follow-ups and transcript restoration. Keep section-specific product IDs bounded and identity-bound; ordinal follow-ups requery current authorized facts rather than reusing saved prices or stock.

## Always keep a natural catalog opening above labeled products
Catalog sections must retain a concise natural opening above Product name, Price, Availability and Link. Prefer reviewed model wording; if provider or review fails, retain the localized factual fallback opening rather than clearing intro. Never label fallback wording as model-generated or weaken review to force a model response.

## Match catalog openings to the users language
Natural catalog openings and supporting prose follow the current detected user language (English, Tagalog, Cebuano/Bisaya or Hiligaynon) unless an explicit current/persisted language preference overrides it. Clear local language detection takes priority over a conflicting model language label. Localized factual fallback openings follow the same selection; preserve exact product names, amounts and stock facts.

## End compound refinements on new support topics and render catalog facts once
New branch/store, FAQ and private-order questions must escape pending compound product/section refinements; a newly named product family starts a fresh search. Preserve genuine section, ordinal and shared-filter follow-ups. Product presentation removes duplicate recognized labeled fields and fact-only listing paragraphs when authoritative cards already display them; preserve reviewed explanations, uncertainty and compatibility warnings. Apply the same cleanup to single and multipart replies without relying on provider compliance.

## Localize early clarifications and explain Sagay-only stock politely
Select the current detected language before compound/early fallback routing, honoring explicit saved language preferences. Translate application-owned Wi-Fi, section, budget and branch clarifications without model calls, preserving catalog names, amounts and technical identifiers. For other-branch stock questions, explain politely in en/tl/ceb/hil that this chat's stock information covers Sagay only and direct customers to the branch; never imply another branch has no stock. Keep existing privacy and stock-authority boundaries.

## Keep branch locations out of catalog search text
Branch query cleanup must handle English and regional location wording (including sa, diri sa, dinhi sa and dito sa), published city aliases, and branch suffixes. Apply shared branch scope to every requested product section without treating a city as a product term. Clean the already-resolved catalog query; never restore raw intent text containing budgets, exclusions or recommendation wording after structured constraints have been parsed. Retain MySQL stock, per-section filters and other-branch Sagay-only notices.

## Apply explicit catalog constraint updates before selection
The approved accuracy refinement uses ChatbotIntentState for new/refine/select/clarify/topic operations and set/clear/retain constraint updates. Persist resolved constraints, never replay per-turn updates in saved compound intents. Exact-product selection skips unused Qdrant/Neo4j candidate lookups while keeping grounded generation and mandatory review. Filter-removal choices are not clarification; retain the explicit presentation clarification flag.

## Keep evaluated intent state and benchmark traffic distinct
After empty catalog results, discovery/recommendation refinements search the matching catalog rather than selecting prior IDs. Generation and review use resolved PHP constraints, including retained/cleared price bounds, rather than raw model defaults. Explicit CLI evaluation isolates operational metrics and failure-review samples while preserving real provider budgets/cooldowns; review packets remain hash-bound and require fluent-speaker attestation. Full-mode review may use up to ten seconds of remaining time without exceeding the shared 25-second/three-call ceiling.

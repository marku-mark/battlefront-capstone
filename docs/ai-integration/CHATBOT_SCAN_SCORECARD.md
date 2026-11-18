# Chatbot scan scorecard and repair backlog

Audit date: 2026-11-13. This report implements the approved audit-and-planning task, not a provider activation or chatbot replacement.

## Latest implementation status (supersedes earlier progress notes)

### Approved cloud provisioning completed

The developer explicitly approved creation and population of the paired Embedding 2 indexes. The ignored root environment now names `battlefront_products_gem002_768_fallback_v1` and `battlefront_faq_gem002_768_fallback_v1`, with their readiness flags enabled only after the payload audit passed. Credentials, the primary model/index settings and source records were preserved. Semantic FAQ retrieval remains disabled; preparing its derived index did not activate that customer-facing feature.

`chatbot:initialize-catalog --apply --fallbacks-only` created only the paired Qdrant collections/indexes. The resumable inline backfill processed public source records directly, leaving the existing queued backlog untouched. It respected real provider rate limits and resumed after cooldown. The final read-only `chatbot:audit-embedding-fallbacks` reported **654 current product vectors, 9 current FAQ vectors, zero missing and zero stale source hashes/revisions**. Twenty-seven ineligible/unreviewed FAQ-language points were correctly absent. Both approved collections are now marked ready for fallback routing. This verifies vector provenance/freshness, not semantic relevance or language fluency.

Fallback maintenance remains independent of primary acknowledgments. FAQ source edits now queue derived maintenance even while semantic FAQ retrieval is off. Inline backfill supports bounded, disjoint ranges and precise resume checkpoints; it never drains unrelated queued work. A graceful queue restart signal was issued after configuration activation; no worker was launched to consume existing jobs.

Aggregate-only failure review found 49 retained cases representing 52 recorded occurrences: 20 scope-stage timeouts, 14 understanding failures, 7 no-matches, 6 overloads, 4 validation failures and 1 review timeout. No conversation sample was opened. Scope timeout failures averaged 4,823 ms per retained case, consistent with the prior four-second scope cutoff. Scope timeout is now configurable (default six seconds, bounded by the shared turn deadline); overloaded/5xx models also enter the bounded failover path. Privacy/validation rejection still never triggers a permissive retry. These development diagnostics are not a representative customer-quality or CSAT baseline.

This completion supersedes earlier notes that paired-index approval, provisioning and readiness were pending. Native-language review, representative live quality/recall evaluation, handoff staffing and release approval remain external gates; no such approval or quality result is inferred from successful cloud indexing.

Final recovery verification: runtime configuration and both readiness flags persisted after the tool-server restart; provisioning and backfill were not repeated. The consolidated affected suite passed **248 tests / 1,168 assertions**. Pint and whitespace checks passed. This is targeted verification, not a complete-suite or full production-release approval.

### Provider-limit fallback update

The developer explicitly requested automatic fallback for both text and embedding models and no guessed provider caps. Text now tries Gemini 3.5 Flash-Lite, then Gemini 3.1 Flash-Lite, then Gemini 2.5 Flash-Lite on HTTP 429 / typed rate-limit errors. It uses the same role credential, skips limited models during cooldown, honors numeric Retry-After, counts each attempt against the existing three-call turn budget and never spends review capacity on a generation retry. Invalid output, authorization errors and review rejection do not trigger model switching. Actual model names are retained in provider-usage logs. Local RPM/RPD/token enforcement is disabled by default for text and embeddings; the older explicit-cap mechanism remains opt-in for backwards compatibility. No quota figures are needed to implement or use this routing.

Google's [pricing reference](https://ai.google.dev/gemini-api/docs/pricing) lists these text models and both embedding models with free-tier availability. This does not change account billing settings or guarantee availability for a particular project.

Embedding 001 → Embedding 2 fallback is implemented for product and FAQ retrieval through separately configured Qdrant collections. Google's [embedding guide](https://ai.google.dev/gemini-api/docs/embeddings) states that their vector spaces are incompatible and requires re-embedding. `QDRANT_FALLBACK_COLLECTION` and `QDRANT_FALLBACK_FAQ_COLLECTION` therefore remain unset until the paired indexes can be provisioned and populated. Empty/missing, stale, wrong-model or wrong-dimension fallback data never becomes authoritative; existing local retrieval remains available. Embedding 2 uses the documented query/document task prefixes, without taskType, and isolated cache entries.

Existing initialization supports those configured collections only under its explicit `--apply` mode. `chatbot:reconcile-embedding-fallbacks` previews by default; its `--apply` mode queues independent derived-index jobs. Normal catalog/FAQ projection jobs also enqueue configured fallback projections. Workers reload current MySQL records, preserve model-specific source hashes and revisions, remove ineligible derived points, honor provider cooldowns and retain environment-default queue settings. Fallback jobs never acknowledge the primary index. No cloud initialization, re-embedding, activation or environment edits were performed during implementation.

Read-only runtime diagnostics confirmed Gemini 3.5 Flash-Lite, configured role credentials, text model fallback enabled, local project caps disabled, and zero configured fallback embedding indexes. The embedding reconciliation preview sent no provider requests and queued nothing. Thus text switching is operationally configured; embedding switching code is implemented but awaits paired-index provisioning. Cloud provisioning approval was requested separately, as required by the approved plan. No provider success is inferred from credential presence.

Verification of the model-fallback continuation: 135 affected regression tests passed (660 assertions), followed by 18 focused fallback tests (56 assertions) after cooldown recovery and backfill scheduling coverage were added. An additional diagnostics regression passed. Pint and whitespace checks passed. Tests used provider fakes throughout.

The developer clarified that the active model is **Gemini 3.5 Flash-Lite**, matching the existing default. No model migration is needed. Google's [model reference](https://ai.google.dev/gemini-api/docs/models/gemini-3.5-flash-lite) confirms the `gemini-3.5-flash-lite` model code and structured-output support. The [Gemini 3 guide](https://ai.google.dev/gemini-api/docs/gemini-3) recommends default temperature 1.0; this repair does not introduce a low-temperature override. Google's [rate-limit documentation](https://ai.google.dev/gemini-api/docs/rate-limits) establishes project-level limits and Pacific-midnight daily reset, but directs users to AI Studio for their actual model/project limits. Public documentation does not verify this account's limits, project identities or billing tier.

Multipart catalog answers now support the complete understanding → combined answer → mandatory review flow within the shared maximum three calls. Generation must return one structured answer for every part in order, with part-scoped evidence IDs. PHP rejects dropped/reordered parts, cross-part evidence, unsupported numbers/prices and existing compatibility violations before review. Rejected or unavailable review returns complete deterministic sections. FAQs/private support and unresolved parts retain local responses. The multipart feature remains disabled by default.

`chatbot:evaluate-retrieval --dataset=/path/to/public-snapshots.json` now compares Algolia, Qdrant and equal-weight RRF (k=60) offline. Dataset shape: `{"catalog_version":"fixture-v1","cases":[{"id":"query-1","relevant_product_ids":[1,3],"algolia":[1,2],"qdrant":[2,3,1]}]}`. Candidate lists must contain unique integer product IDs in rank order; labels must be nonempty. The report fingerprints its input and provides recall@10, MRR@10 and zero-result rates. This implements the comparison harness; it does not claim live relevance or activate RRF in customer retrieval. Source snapshots must come from the same frozen eligible catalog and human-reviewed relevance judgments.

Diagnostics now aggregate completed provider responses and input/output tokens per role, with bounded two-day cache retention and best-effort recording. These are application counters, not provider billing or project-level usage totals; existing error and duration metrics remain separate.

The following remain genuinely incomplete: sanitized real-log analysis; project-specific quota/billing verification; representative live candidate snapshots and relevance labels; fluent-speaker review; a staffed handoff contract; an approved live pilot and deployment. No real conversations, credentials, cloud collections or production settings were accessed or changed. These inputs cannot be obtained from Gemini documentation. CDC/read replicas, cross-encoder reranking, altered index settings, automatic failover and larger-model escalation remain conditional proposals without measured triggers, not silently completed features.

Verification of this continuation: the targeted generation/retrieval/budget/adapter/full-pipeline run passed 91 tests with 372 assertions. After adding unavailable-review coverage, all 16 multipart tests passed again with 116 assertions. The offline retrieval benchmark's six tests passed, including exact metrics, rank-ten boundaries and invalid-label rejection. Pint and whitespace checks passed. No live provider test or full-suite pass is claimed.

## Evidence and limits

Read-only source, configuration defaults, route inspection, database schema, corpus inspection and local provider-faked tests form the evidence. No customer conversation rows, secrets, live provider requests or cloud configuration exports were collected. Configuration defaults are not evidence of deployed activation. No approved Linear issue was supplied.

Verified direct dependencies: Laravel 13.30.1, Laravel AI 0.11.2, Scout 11.9.0, Inertia Laravel 3.3.2 and Algolia PHP client 4.51.0. package.json declares Vue ^3.5.13 and Inertia Vue ^3.0.0; these are declared ranges, not verified installed frontend versions.

MySQL schema inspection confirms products, inventories, orders, order items, chatbot knowledge, conversation and projection storage. Order and returns access already exists through application services; a new model-written SQL tool is unnecessary. Schema inspection does not establish grants, EXPLAIN plans, collation, replica availability or binlog configuration.

Ratings: green means adequate evidence for the stated local contract only; yellow means partial capability or a verified evaluation gap; red requires a demonstrated failure; unverified means evidence is unavailable. No red production finding is established by this scan.

## Architecture and trust boundaries

- Web and mobile expose POST chatbot endpoints, with shared application orchestration. See `ChatbotOrchestrationService`, `ModelLedChatbot` and `ChatbotConversation`.
- Local routing resolves approved FAQs and private support. `ResolveOrderContext` uses customer-scoped Eloquent ownership filtering, not model-generated queries. The model-led public path screens input before understanding.
- `ChatbotAiAdapter` isolates provider calls and validates structured understanding, generation and review. `ChatbotProviderBudget` caps enabled turns at three generation-role calls, reserves review capacity and bounds deadlines. Embedding reservations are accounted separately; a three-stage turn is not necessarily three total external requests.
- Algolia and live SQL participate through shared catalog selection; enabled semantic candidates are rehydrated from MySQL. `SemanticCatalogCandidates` verifies projection revision and content hashes before accepting IDs. Cloud stores cannot authorize orders or establish current price/stock.
- `CatalogProjectionRecorder`, `SyncCatalogProjection` and reconciliation already implement derived-store synchronization. Public documents exclude live price, inventory and private commercial fields. PHP remains the compatibility authority.
- Conversation state carries bounded references and constraints with identity binding and expiry. Browser continuity and optional encrypted durable storage are separate; transcripts are not provider context.

## Layer scorecard

| Layer | Rating | Evidence and next action |
| --- | --- | --- |
| Routing and structured understanding | Yellow | `ChatbotUnderstandingAgent` defines one intent and one constraint set. Preserve current routing; measure per-intent accuracy before changing it. |
| Long and independent multi-part inquiries | Yellow | Understanding explicitly clarifies mixed topics; no sub-question array exists. Request validation caps messages at 1,000 characters. Current corpus has no message above 81 characters. This is a capability/evaluation gap relative to the proposal, not a violation of current one-topic rules. |
| Algolia | Unverified for quality | Shared catalog search and SQL fallbacks exist. Live settings, recall, zero-result rate, synonyms and quota remain unverified; do not replace storefront semantics. |
| Qdrant | Unverified for quality | Bounded filtered requests and revision/hash checks exist. Configuration defaults to 768-dimensional embeddings and a versioned product collection. Live collection state, HNSW, recall and threshold calibration are unverified. |
| Neo4j | Unverified for quality | Client and projection integration exist; PHP verdicts retain authority. Live permissions, indexes, freshness and query profiles need operator evidence. |
| Fusion and reranking | Unverified | No RRF or cross-encoder implementation surfaced in the inspected chatbot services. Establish candidate-level baseline and provenance before proposing either dependency. Absence alone is not a defect. |
| Generation and review | Green, local contract | Adapter uses structured evidence, rejects malformed drafts, requires approval for natural drafts and falls back on failed/missing review. Live grounding and model suitability remain unverified. |
| Role keys and budgets | Yellow | Three role-key references fall back to the legacy key; one configured project budget is shared. This does not establish three distinct projects or per-project quota accuracy. No key values inspected. |
| MySQL ownership and live authority | Green, local contract | Owned order resolver, live catalog selection and projection rehydration are implemented and covered by targeted suites. Database load, grants and physical query performance remain unverified. |
| Synchronization | Green, local mechanism | Revisioned jobs and reconciliation exist with deletion/staleness coverage. Operational drift, queue health and actual store lag remain unverified. No evidence warrants replacing this with CDC. |
| Context and memory | Green, local contract | Bounded structured follow-ups, expiry and identity reset exist; browser continuity tests pass. No upstream raw-history extension is proposed. |
| Safety | Yellow overall | Screening, ownership and safe fallbacks have local coverage. No claim of comprehensive PII detection, deployed secret hygiene or live red-team success is justified. Keep private data local. |
| Handoff | Unverified | No external handoff adapter surfaced in this pass. Verify the staffed contact process before proposing a new system or promising agent availability. |
| Performance and cost | Unverified operationally | Budget and stage telemetry exist. Mocked duration is not provider latency; no operational p50/p95, spend, quota or concurrency measurements were collected. |
| UX | Green, targeted behavior | 25 frontend tests pass for continuity, safe cards, pending requests, timeout handling and late-response rejection. Human usefulness, CSAT and accessibility are not established by these tests. |
| Evaluation | Yellow | Existing offline evaluator and 200-case multilingual corpus are reusable, but do not establish cloud retrieval quality or language fluency. |

## Evaluation specification

Corpus inspection found 200 cases: 50 each in English, Cebuano, Tagalog and Hiligaynon; 100 multi-turn cases; zero messages over 500 characters; maximum message length 81; no `expected_category` labels. Existing substring expectations are not relevance judgments or part-coverage labels. Preserve the original corpus and use versioned additions.

Build a 300-case release corpus: retain the existing 200, add 60 synthetic messy public inquiries of 500–1,000 characters and 40 cases for typo/SKU ambiguity, hostile instructions, outages and ownership/privacy. Allocate 15 long and 10 additional edge cases per language. Have fluent reviewers approve meaning and expected outcomes. Do not fabricate real-user volume or language distribution from this synthetic corpus.

Each case must record intent, applicable route, expected clarification, required answer parts, permitted source IDs and forbidden claims. For retrieval comparisons, attach graded relevant product IDs against a frozen synthetic catalog fixture. Bind human reviews to exact answer hashes, as the existing evaluator already supports. Keep auth fixtures in feature tests, not real customer datasets.

The current evaluator accepts category and clarification labels but does not support arbitrary segment/retrieval judgments. Define those additive evaluation fields and backward compatibility in the implementation issue before editing its validator. Keep the existing web/mobile response interface unchanged during audit work.

Run deterministic offline cases once for correctness; repeat only to detect accidental state contamination. For separately approved live evaluation, freeze model ID, prompts, dataset and catalog version, record supported sampling settings and run each case three times. Do not promise deterministic provider output from a seed. Use a dedicated evaluation project with explicit request/token/spend limits and sanitized public data.

Calculate recall@10/MRR only from relevant-ID labels and candidate traces; calculate part coverage from required-part labels. Report malformed-output, routing, fallback, grounding and privacy outcomes separately. Business metrics, production failure frequency and revenue/CSAT impact remain unavailable pending instrumentation. Never rank these by invented frequency or money.

## Findings-triggered repair backlog

1. **P1: close evaluation blind spots.** Trigger: corpus length and labeling gaps. Extend existing evaluator/corpus rather than building a parallel harness. Acceptance: 300 unique cases, language balance above, explicit routing/part judgments, backwards-compatible original dataset, zero live calls in automated tests, answer-hash-bound human reviews. This enables defensible severity and model-role decisions.
2. **P1: reconcile model and project configuration.** Trigger: proposal requests 3.1 Flash-Lite while `config/ai_integration.php` defaults to `gemini-3.5-flash-lite`; project membership is unknown. Obtain sanitized model IDs, project identifiers, tier/quota limits and role mapping from the operator. Preserve aggregate turn limits and reserve review capacity. Do not rotate keys, switch models or infer project identity from key strings. Acceptance: documented effective role/model mapping, mocked quota exhaustion and no secrets in diagnostics. Any future per-project accounting must preserve the current conservative global ceiling.
3. **P2: evaluate multi-part support as a bounded extension.** Trigger: current one-topic behavior and new long-inquiry results. Existing rules require clarification of independent topics; obtain explicit agreement to supersede that behavior before implementation. Proposed default: at most three public parts, stable part IDs, one understanding call and one combined generation/review pair within the shared budget/deadline. Route private portions locally; never send their text upstream. Clarify overflow rather than silently omit parts. Keep the 1,000-character limit until a separately tested increase is justified. Acceptance: part-coverage tests, no dropped negations/constraints and safe behavior on invalid understanding output.
4. **P2: measure retrieval before tuning.** Trigger: missing empirical recall. Compare existing Algolia/SQL, semantic and combined paths on identical fixtures and then an approved cloud pilot. Record candidate provenance, freshness, recall@10, MRR and latency. Preserve MySQL filters, named unavailable-product handling and deterministic compatibility. Add fusion/reranking only if results justify it; dependencies require approval. No automatic HNSW, collection, index or synonym mutations.
5. **P2: establish operational evidence and handoff.** Trigger: unavailable live metrics and support process. Request sanitized aggregate volume, latency, errors, role costs, queue/projection lag and staffed support hours. Verify least privilege and endpoint/credential handling using redacted operator evidence. Define handoff reason, safe summary, subject binding and reference IDs only after identifying the destination; do not promise refunds or response times. No external messages or dashboard changes are authorized by this audit.

Skip rewrites of locally green mechanisms. Reopen them only on failing regressions or measured operational evidence. Roll out any later approved repair behind existing flags, with baseline comparison and human review. Roll back by disabling behavior flags while preserving MySQL history and additive projection/conversation tables.

## Model evidence

Google publishes a [Gemini 3.1 Flash-Lite model card](https://deepmind.google/models/model-cards/gemini-3-1-flash-lite/). Its [Cloud model reference](https://docs.cloud.google.com/gemini-enterprise-agent-platform/models/gemini/3-1-flash-lite) lists default temperature 1.0. Cloud documentation does not establish availability or quota for this application's Gemini Developer API projects. No sampling setting, model migration or larger-model escalation is justified without role-specific evaluation and endpoint-specific verification.

## Verification record

### Local repair progress

`chatbot:evaluate` now accepts optional `parts`: up to ten objects containing a unique per-case `id`, a 1-based existing `turn`, nonempty `contains` text assertions and optional `not_contains` assertions. It checks the specified reply, reports each part and per-language `part_coverage_rate`, and fails the run on missed parts. Unlabeled coverage remains null. This measures text coverage, not semantic correctness. Reports include a dataset fingerprint and the configured model; existing datasets and answer-bound human reviews remain compatible.

`chatbot:diagnostics` now reports model, role-key presence, configured quota limits and the shared accounting boundary. Project membership remains explicitly unverified. It does not display keys or budget identifiers or contact providers.

### Subsequent local stages

- `resources/data/chatbot-evaluation-extended.json` preserves the original 200 cases and adds 60 long inquiries (15 per language) and 40 edge/routing cases (10 per language). The added cases have routing labels and use code-switching and explicit language preferences. They are synthetic drafts, not fluent-speaker-approved translations or real customer samples. Original labels and messages are preserved. Product relevance judgments and fluent review are still required.
- `chatbot:evaluate --runs=3` repeats cases with fresh context, fingerprints the dataset and reports repeat consistency, unique-case counts, per-language part coverage and failed-check counts. Repeats cannot satisfy the minimum unique-case release gate. Offline reports do not claim cloud latency or grounding quality.
- `CHATBOT_MULTIPART_ENABLED` defaults off and requires the existing model-led/integration gates. It uses one bounded structured understanding request for up to three ordered public needs, then authoritative deterministic per-part responses. No extra generation/review requests are needed for these local replies. Every part is validated; overflow clarifies rather than silently dropping needs. Unsupported/private model parts never authorize order access. Original private clauses are screened before understanding. Multipart answers clear ambiguous product follow-up references and do not send history upstream. Single-part behavior remains compatible.
- Public multi-question HTTP replies are covered along with direct orchestration, overflow, invalid parts, one-call accounting and private-support exclusion. Live feature activation remains off and separate from this implementation.

### Disposition of remaining stages

| Stage | Disposition after local implementation |
| --- | --- |
| Evaluation coverage | Local corpus, reporting and repeated-run support implemented; human review and real log analysis remain external. |
| Model/project mapping | Safe diagnostics implemented. Actual project membership, tier, quota, model availability and spend need sanitized operator evidence; no credentials/model changed. |
| Multipart public support | Implemented behind a disabled flag, using existing authoritative resolvers. No provider activation performed. |
| Retrieval tuning/fusion | Deferred pending candidate relevance labels and approved live recall measurements. No evidence yet supports RRF, a cross-encoder, changed HNSW values or new dependencies. |
| Data foundation | Existing revision/hash synchronization retained. No measured drift or database load finding justifies CDC, replicas or migrations. |
| Generation/model escalation | Existing grounded generation/review retained. Live role-specific failures must justify prompt/settings changes or a larger model. |
| Handoff | Destination, staffing and operational contact contract remain unverified; no external ticketing integration or promise of transfer created. |
| Speed/cost/key failover | Existing budgets, deadlines, caching and metrics retained; actual project quotas/latency/spend must be supplied before changing retry or failover behavior. |
| Continuous evaluation/rollout | Local evaluation tools and regression coverage implemented. Fluent review, live pilot/A/B tests, production dashboards and rollout require external evidence and activation approval. |

All locally justified repairs above are reviewable. Remaining operational gates are not completed or represented as production readiness.

Final local verification for the continued implementation: 153 tests / 756 assertions passed across multipart/model-led orchestration, understanding refinement, budgets, memory, performance, language pipeline, retrieval and mobile suites. The evaluator suite passed another 17 tests / 94 assertions, including all 300 synthetic conversations offline. Total: 170 tests and 850 assertions passed. Pint and whitespace checks passed; `graphify update .` completed. This is targeted verification, not a complete-suite or live-provider pass.

Repair verification: `ChatbotEvaluationTest` passes 11 tests with 50 assertions; Pint was applied to dirty PHP files.

- `node --test resources/js/components/chatbot/CustomerChatAssistant.test.js resources/js/components/chatbot/chatContextStorage.test.js`: 25 passed, zero failed.
- `php artisan test --compact tests/Feature/ChatbotEvaluationTest.php`: 5 passed, 26 assertions.
- Targeted `AiRetrievalTest`, `ChatbotModelBudgetTest`, `ChatbotMemoryTest` and `Api/V1/MobileChatbotTest`: 89 passed, 454 assertions.
- Targeted `ResolveOrderContextTest` and `ChatbotReliabilityTest`: 29 passed, 67 assertions.
- `php artisan test --compact --filter="Chatbot|AiRetrieval|CatalogSearch"`: interrupted after an extended run without returned output. No pass/fail result is claimed for that broader selection. The completed targeted runs above establish 123 passing PHP tests (547 assertions); they do not establish a complete-suite pass.
- Source and schema inspection only for operational data. No production metric or live-provider result is claimed.

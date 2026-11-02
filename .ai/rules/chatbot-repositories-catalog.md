---
paths:
  - 'app/Actions/Chatbot/**,app/Repositories/Catalog/**'
---

# Chatbot Repositories Catalog

## Use shared catalog intent for chatbot product discovery
When Algolia is configured, natural chatbot category questions must use CatalogSearchIntent and ProductCatalogRepository::chatbotSearch, preserving literal longer accessory phrases ahead of weaker language aliases. Chatbot device discovery applies the shared primary-product predicates as filters before its limit, so printer machines are not replaced by ink/cables when machines are absent. Explicit consumable/accessory requests and named product follow-ups remain supported. Keep current eligibility, inventory/reorder thresholds and provider-outage fallback authoritative; do not alter storefront pagination or price sorting.

## Use dynamic chatbot retrieval independently of Algolia
Developer's dynamic-routing refinement supersedes the prior Algolia-only chatbot discovery and chatbot zero-hit stop rules. Use indexed candidates when usable, then live SQL matching, then existing enabled semantic retrieval when unresolved. Category intent works without Algolia; apply the same live category/subtype/brand and primary-device constraints to fallback candidates. Exact named unavailable products, identity-bound follow-ups, private support authorization and provider budgets retain their existing boundaries. Storefront Algolia zero-hit behavior remains unchanged; this fallback expansion is chatbot-only. Never activate providers to satisfy a request.

## Route reasoning requests beyond indexed discovery
Comparisons, recommendations, budgets, compatibility and explicit use-case questions must not terminate at the indexed search-card fast path. Preserve exact named-product resolution, use live constrained SQL context for typed recommendations, and allow existing enabled semantic retrieval to supplement unresolved/use-case facts. Keep requested category/subtype and device exclusions when rehydrating semantic IDs; the existing orchestration/compatibility engine remains responsible for the final answer and provider budget.

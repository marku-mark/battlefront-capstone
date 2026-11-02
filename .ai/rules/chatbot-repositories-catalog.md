---
paths:
  - 'app/Actions/Chatbot/**,app/Repositories/Catalog/**'
---

# Chatbot Repositories Catalog

## Use shared catalog intent for chatbot product discovery
When Algolia is configured, natural chatbot category questions must use CatalogSearchIntent and ProductCatalogRepository::chatbotSearch, preserving literal longer accessory phrases ahead of weaker language aliases. Chatbot device discovery applies the shared primary-product predicates as filters before its limit, so printer machines are not replaced by ink/cables when machines are absent. Explicit consumable/accessory requests and named product follow-ups remain supported. Keep current eligibility, inventory/reorder thresholds and provider-outage fallback authoritative; do not alter storefront pagination or price sorting.

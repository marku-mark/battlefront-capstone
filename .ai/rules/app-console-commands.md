---
paths:
  - app/Console/Commands/AuditEmbeddingFallbacks.php
---

# App Console Commands

## Establish fallback readiness from source coverage
Fallback payload readiness must include all current eligible MySQL products, including those created while synchronization was disabled and lacking CatalogProjection rows. Report eligible_products and missing_projections and fail readiness for missing projections; retain revision/hash/model/dimension and ineligible-point checks. A projection-only scan cannot establish complete coverage.

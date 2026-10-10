---
paths:
    - 'app/Models/Product.php,app/Repositories/Catalog/**'
---

# Models Repositories Catalog

## Separate customer availability from activation-only history lookups

Customer discovery and web/mobile details use customerAvailable: active product, active category, existing positive inventory. Stock changes never toggle activation. Keep customerEligible, recommendationInputs, and findEligibleOrFail activation-only for historical recommendation anchors and delayed dwell/feedback; direct customer details use findAvailableOrFail. Apply availability before pagination and derive category/brand/tag options from available products; valid stockless filters return empty results.

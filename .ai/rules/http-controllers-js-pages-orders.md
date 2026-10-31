---
paths:
    - 'app/Models/Shipment.php,app/Services/Order/**,app/Http/Controllers/**/*Order*,resources/js/pages/**/Orders/**'
---

# Http Controllers Js Pages Orders

## Shipment cancellation rules supersede legacy EXT-89 wording

Order cancellation is prohibited at or after handed_to_lbc. The older models-js-pages-orders.md statement allowing cancellation after handoff is historical and superseded by controllers-js-pages-orders.md; use current order-processing rules for customer and administrator eligibility.

---
paths:
    - 'app/Services/Order/**,app/Http/Controllers/**/*Order*,resources/js/pages/**/Orders/**'
---

# Controllers Js Pages Orders

## Restrict customer cancellation and forbid cancellation after carrier handoff

This supersedes EXT-89's earlier allowance to cancel after handoff. Shared OrderProcessingService must lock Order then Shipment and recheck ownership/eligibility: customers may cancel only their own Pending orders with Pending payment, no submitted wallet proof, and no shipment beyond awaiting_preparation. Admin Pending/Processing cancellation allows only awaiting_preparation/preparing/ready_for_dispatch (or no shipment), never handed_to_lbc or later. Restore saved quantities once atomically, preserve payment/snapshots/history, notify after commit, and forbid payment decisions on cancelled orders. Web and API share eligibility; cancelled payments are not automatically refunded.

## Current order cancellation cutoff supersedes historical shipment rule

Order cancellation is prohibited at or after handed_to_lbc. Customers may cancel only their own Pending order with Pending payment, no submitted wallet proof, and no shipment beyond awaiting_preparation; administrator Pending/Processing cancellation is limited to ready_for_dispatch. Historical EXT-89 wording allowing cancellation after handoff is superseded and must not guide current work.

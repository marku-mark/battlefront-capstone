---
paths:
    - 'app/Services/Order/**,app/Models/Shipment.php,app/Http/Controllers/Administration/Order*,resources/js/pages/Administration/Orders/**'
    - 'app/Services/Order/OrderProcessingService.php,app/Models/Shipment.php,app/Http/Controllers/Administration/Order*,resources/js/pages/Administration/Orders/**'
---

# Administration Js Pages Administration Orders

## Reconcile LBC COD collection and remittance separately

For configured LBC COD deliveries, delivered status does not verify payment. The assigned handler records carrier collection of the saved order total, then separately records the LBC remittance reference after reconciling that same total. Only remittance verification marks payment Verified, completes the order, and records the sale. Collection/remittance evidence is immutable; local booking simulations remain synthetic and make no provider calls.

## Supersede one-step Battlefront COD completion

This supersedes the earlier checkout-orders-administration-orders rule that completed COD upon a single collection confirmation. For LBC COD, record collection at delivery without changing order payment; only after the full saved order total is reconciled as remitted and the LBC remittance reference is recorded may the order become paid, completed, and recognized as a sale.

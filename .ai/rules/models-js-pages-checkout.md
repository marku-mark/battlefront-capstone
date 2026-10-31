---
paths:
    - 'app/Services/Order/**,app/Services/DeliveryQuotePresenter.php,app/Services/CustomerOrderPresenter.php,app/Models/Shipment.php,resources/js/pages/Checkout/**'
---

# Models Js Pages Checkout

## Keep regional fees separate from shipment readiness

Newly priced nationwide fallback routes use fixed Battlefront regional fee estimates plus the existing once-per-order handling surcharge. Keep pricing_status=regional_estimate distinct from configured_estimate: include the fee in checkout/order totals, but do not promise an ETA or create a shipment until the delivery arrangement is manually confirmed. Preserve existing COD eligibility and collection confirmation.

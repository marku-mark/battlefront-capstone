---
paths:
    - 'app/Services/Order/**,app/Actions/Checkout/**,app/Http/Requests/ValidateCheckoutRequest.php,config/battlefront.php,resources/js/pages/Checkout/**,docs/*API*,docs/PSGC_DELIVERY_DATA.md'
---

# Requests Js Pages Checkout

## Preserve historical zero-fee orders and separate pricing from fulfillment

Historical not_configured orders retain their saved zero-fee presentation and manual shipment handling. Every new delivery quote uses regional_estimates.fees plus package-weight and the highest handling surcharge once per order; missing or invalid pricing and explicitly disabled or malformed route rules reject delivery. Configured local routes retain their payment eligibility, transit estimates and Shipment workflow. Nationwide fallback routes snapshot pricing_status=regional_estimate, include the fee in order totals, and show no automatic ETA or Shipment until manual arrangement confirmation. Never reprice saved orders.

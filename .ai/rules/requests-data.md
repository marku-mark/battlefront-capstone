---
paths:
    - 'app/Services/Order/**,app/Actions/Checkout/**,app/Http/Requests/ValidateCheckoutRequest.php,config/battlefront.php,resources/data/psgc-*.json'
---

# Requests Data

## Use regional base fees independently of local fulfillment

Developer-approved nationwide-demo-v7 supersedes local city/distance-band price precedence. regional_estimates.fees is the only base-fee source for new quotes (Visayas/NIR 150, Luzon 200, Mindanao 250), plus existing weight and highest handling surcharge. Specific route precedence still controls exclusions, payments and transit; local Shipment creation and fallback manual arrangements remain unchanged. Preserve saved order fees/versions; missing regional pricing fails closed.

## Nationwide geography does not imply automatic delivery coverage

Keep pinned local PSA-derived full-string codes and lazy parent lookups, with NCR/HUC/Manila/NIR exceptions; never call external PSGC services at checkout. DeliveryRules resolves city before province before region for exclusions, payment eligibility and transit, never base-fee precedence. Use regional_estimates.fees for every new delivery base fee, plus package-weight and the highest Standard/Fragile/Bulky handling surcharge once per order. Missing or invalid regional pricing rejects new delivery; preserve immutable historical addresses, fees and versions.

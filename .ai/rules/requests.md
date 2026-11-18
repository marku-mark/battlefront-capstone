---
paths:
  - 'app/Services/Order/**,app/Actions/{Cart,Order}/**,app/Http/Requests/CheckoutPreviewRequest.php'
---

# Requests

## Keep Buy Now separate from persisted cart rows
The developer approved Buy Now checkout for one product/quantity through a bounded session selection. Validate the direct_purchase_key against that session and bind resumed intent to the customer; reuse live checkout review and atomic placement with transient line models. Never add, merge or remove persisted cart rows for direct purchases. Guests authenticate before resuming; cart count changes and guest checkout are excluded.

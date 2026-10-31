---
paths:
    - 'app/Actions/Checkout/**,app/Http/Requests/ValidateCheckoutRequest.php,resources/js/pages/Checkout/**,resources/js/pages/Orders/**'
---

# Checkout Http Requests Js Pages Checkout

## Route selected PayMongo methods through their approved payment experience

Developer decision 2026-10-23: new online orders offer configured QR Ph, card, GCash and Maya, defaulting to QR Ph without overriding explicit customer selection. All four use Hosted Checkout v2 restricted to the selected method. Payment method is fixed when the order is placed and cannot be changed afterward. Open PayMongo in a new tab after order submission and show a persistent Continue payment button on the saved order page whenever the checkout session remains open. Keep the payment tab's opener severed; when PayMongo returns to the owned order route, reconcile server-side and close the script-opened payment tab so the browser returns to the original order tab. The return redirect never proves payment. Do not add a manual Check payment status action; payment state continues to update through existing reconciliation and background refresh. Reject direct_online_banking/payment_bank at the shared web/API request boundary and expose no bank choices. Preserve historical direct QR and bank flows, provider references, and authenticated reconciliation; do not delete payment history or silently convert active attempts.

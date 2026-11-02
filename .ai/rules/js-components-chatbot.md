---
paths:
  - 'app/Services/Chatbot/**,resources/js/components/chatbot/**'
---

# Js Components Chatbot

## Retain same-tab chat independently of optional AI memory
The approved continuity refinement retains a bounded versioned transcript and opaque context token in sessionStorage for 15 minutes, using the server-shared session/customer identity even when durable AI memory is off. Navigation/restoration must not extend server context validity. Clear on expiry, New chat and identity changes. Internal links use Wayfinder/Inertia; application-generated action kinds and escaped structured presentation apply to normal and fallback replies. Interpretations and restored messages never authorize orders or establish live price/stock.

---
paths:
    - 'app/Actions/Chatbot/**,app/Services/Chatbot/**,resources/js/components/chatbot/**'
---

# Components Chatbot

## Keep chatbot follow-ups bounded and authoritative

Use RouteChatbotQuery and MatchChatbotKnowledge for customer routing and admin previews. Resolve one selected topic; clarify independent mixed topics. Current-chat context is a 15-minute encrypted token bound to session and user, held only in Vue memory and reset on identity change or New chat. Retain entity references and pending clarification only; requery live facts and order ownership each turn. Explicit entity names/references override previous context. Knowledge templates must not override live catalog, branch, or personal order facts.

## Keep support shortcuts grounded in shared rules and approved usage FAQs

The approved support refinement uses checkout's shared payment-option selector for PayMongo/manual-wallet guidance, preserves COD remittance and gateway cancellation boundaries, and retains owned order/return/refund status privacy. PC Builder navigation FAQs are allowed; exact-part compatibility advice and recommendations remain unsupported. Nine development FAQ presets replace the former four-preset count; seeding preserves edited/inactive records. Quick-question leaves use the existing API with fresh context and preserve composer drafts; menus alone make no request. FAQs and guidance remain deterministic and the chatbot performs no commerce actions.

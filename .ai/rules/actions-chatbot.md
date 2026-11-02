---
paths:
  - 'app/Actions/Chatbot/**'
---

# Actions Chatbot

## Bind FAQ translations and semantic retrieval to reviewed source wording
Custom FAQ translations are optional JSON on chatbot_knowledge for ceb/tl/hil. Runtime uses only reviewed entries whose source_question/source_answer exactly match the current English record; edits invalidate previous review. Semantic FAQs use a separate Qdrant collection, public minimized queries, current MySQL source hashes and ambiguity clarification. Admin validation/previews never invoke semantic providers. Keep the original FAQ eligibility/authorization gates and zero-generative-call FAQ answers.

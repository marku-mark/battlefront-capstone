---
paths:
  - 'app/{Services/Chatbot,Jobs}/**'
---

# Chatbot Jobs

## Keep fallback embedding projections independent of the primary index
Gemini Embedding 001 and Embedding 2 spaces are incompatible. Fallback profiles require separate product/FAQ collections, model/dimension tags and current source hashes/revisions. Existing projection jobs enqueue SyncEmbeddingFallback on the default queue; these jobs reload current MySQL state and never acknowledge primary qdrant_revision. Preview reconciliation is read-only; provisioning/backfill require explicit apply and cloud approval. A missing/unpopulated fallback collection must degrade to local retrieval, never query the primary index with alternate vectors.

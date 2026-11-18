---
paths:
  - 'app/Services/Chatbot/Retrieval/**'
---

# Retrieval

## Audit paired embedding collections before enabling readiness
Only mark QDRANT_FALLBACK_READY and QDRANT_FALLBACK_FAQ_READY after chatbot:audit-embedding-fallbacks confirms matching model/dimensions and current source hashes/revisions for all eligible records. Readiness gates retrieval, not projection preparation. FAQ fallback maintenance must continue while semantic FAQ replies are disabled. The approved fallback-only initializer and inline resumable backfill must not alter primary indexes, enable FAQ retrieval, or drain unrelated queued work.

## Check payload filter indexes independently of projection freshness
Zero pending projection records does not establish Qdrant filter-index readiness. Use chatbot:diagnostics --cloud --probe-filters to inspect metadata and verify the primary filter contract; this does not prove semantic relevance. The separately approved --missing-primary-indexes-only --apply initializer adds only missing expected indexes on the existing primary collection, preserves vectors/Neo4j and refuses incompatible dimensions or index types. Retain explicit approval for cloud writes.

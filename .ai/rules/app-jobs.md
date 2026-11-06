---
paths:
  - 'app/Jobs/**'
---

# App Jobs

## Inherit the environment default queue
All background jobs use the environment default connection and queue for the single Laravel Cloud managed queue. Do not force database or payments/notifications/chatbot-indexing queues. This supersedes the older notification database-connection requirement; preserve after-commit dispatch, retry policies and feature gates.

---
paths:
  - resources/js/app.js
---

# Resources Js

## Resolve pages lazily without sitewide dev prewarming
Use the explicit lazy import.meta.glob resolver and preserve the layout callback and SSR plugin. This avoids unnecessary whole-site page warm-up, but was not sufficient to fix the observed Windows timeout: CPU profiling identified generated-storage watcher setup as the root cause. Retain the storage watcher exclusions and verify fresh startup rather than only a later successful render.

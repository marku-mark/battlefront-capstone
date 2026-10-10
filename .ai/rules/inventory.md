---
paths:
  - 'app/Services/Dashboard/**,app/Repositories/Inventory/**'
---

# Inventory

## Separate actionable dashboard stock warnings from the inventory ledger
Dashboard stock KPIs and restock attention lists include only active products in active categories. Keep inactive products, including synthetic forecasting fixtures, in administrator inventory listings and ledger counts; do not change fixture stock or forecasting calculations to suppress alerts.

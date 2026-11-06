---
paths:
  - 'config/forecasting.php,database/seeders/DevelopmentCustomerSeeder.php,database/seeders/DevelopmentHistoricalSalesSeeder.php,app/Repositories/Reporting/SalesHistoryCoverageRepository.php'
---

# Repositories Reporting

## Gate Cloud synthetic history behind the demo flag
Keep FORECASTING_CLOUD_DEMO_HISTORY_ENABLED off by default. Enable it only for an isolated Laravel Cloud staging/demo environment. In that mode, retain synthetic_development provenance and persist coverage in the shared MySQL forecasting_history_coverages table so all replicas read the same coverage. Never enable this flag for live business data.

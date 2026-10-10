---
paths:
  - 'app/Console/Commands/**,app/Services/RealCatalogImportService.php,database/PrepareCatalogImageManifest.ps1'
---

# Services

## Keep prepared demo inventory separate from spreadsheet provenance
catalog:prepare-demo-inventory updates only private audited sources in local/testing. Its 650/3/1 profile applies to the 654 imported products, excluding forecasting fixtures. Preserve source_quantity and workbook/image metadata; development_demo quantities are not verified client stock and cannot import outside local/testing. Populated manifest quantities prohibit CSV overrides; ordinary reimports preserve operational quantities.

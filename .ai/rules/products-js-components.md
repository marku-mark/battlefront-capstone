---
paths:
    - 'routes/web.php,app/Providers/AppServiceProvider.php,resources/js/pages/Administration/Products/**,resources/js/components/CatalogNavigation.vue'
---

# Products Js Components

## Use the storefront product catalog for Staff, Manager, and Owner

Supersedes the earlier Staff full-administration-catalog browsing rule in this file. Staff, Manager, and Owner navigate to the public /products catalog; it shows only customer-available products. Staff cannot open administration product index/show. Manager and Owner retain their existing products.manage administration access. The retired IT role receives no catalog or administration access; existing assignments remain denied until an operator resolves them. Administrator catalog browsing does not grant customer cart, recommendations, or chatbot access.

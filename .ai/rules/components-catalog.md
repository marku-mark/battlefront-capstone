---
paths:
    - 'app/Models/Inventory.php,app/Repositories/Inventory/**,app/Actions/Chatbot/Context/ResolveProductContext.php,resources/js/components/catalog/StockAvailability.vue'
---

# Components Catalog

## Use inclusive low-stock boundaries and preserve named chatbot stock answers

Low stock is positive quantity <= reorder_level; in stock is quantity > reorder_level. Zero/missing inventory stays out_of_stock/unavailable (admin not_initialized), and zero must never count as low stock. Ordinary customer catalog/recommendation grids show only In stock or Low stock. Chatbot discovery lists only available products, but explicit full named-product inquiries and existing product follow-ups may report zero/missing stock; inactive products/categories remain excluded.

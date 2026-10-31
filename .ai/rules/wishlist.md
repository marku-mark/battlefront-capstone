---
paths:
    - 'resources/js/{components/catalog/WishlistButton.vue,composables/useWishlistInteractions*,pages/Wishlist/**}'
---

# Wishlist

## Preserve catalog state during wishlist mutations

Wishlist hearts use the shared interaction composable and Inertia Form options for preserveScroll/preserveState/preserveUrl with a wishlistCount-only partial response; never reload the infinite-scroll products or recommendations on heart clicks. Coordinate pending state per product and apply membership only after server success. Wishlist Add to cart retains the saved item and changes to View cart using customer-scoped server cart presence, including PC-build components.

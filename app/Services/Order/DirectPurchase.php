<?php

namespace App\Services\Order;

use App\Actions\Cart\CartOperationException;
use App\Models\Product;
use App\Services\Cart\CartService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** @phpstan-type Selection array{key: string, product_id: int, quantity: int, initial_price: numeric-string, customer_id: int|null, expires_at: int} */
class DirectPurchase
{
    /** @return Selection|null */
    public function selection(): ?array
    {
        if (! request()->hasSession() || ! request()->filled('direct_purchase_key')) {
            return null;
        }
        $selection = request()->session()->get('direct_purchase');
        if (! is_array($selection)
            || ! is_string($selection['key'] ?? null)
            || ! is_int($selection['product_id'] ?? null) || $selection['product_id'] <= 0
            || ! is_int($selection['quantity'] ?? null) || $selection['quantity'] <= 0
            || ! is_string($selection['initial_price'] ?? null) || ! is_numeric($selection['initial_price'])
            || ! is_int($selection['expires_at'] ?? null) || $selection['expires_at'] <= now()->timestamp
            || (isset($selection['customer_id']) && ! is_int($selection['customer_id']))
            || ! hash_equals($selection['key'], (string) request()->input('direct_purchase_key'))
            || (isset($selection['customer_id']) && $selection['customer_id'] !== request()->user()?->id)) {
            throw ValidationException::withMessages(['cart' => 'This Buy Now selection expired. Please select the product again.']);
        }
        if (! isset($selection['customer_id']) && request()->user() !== null) {
            $selection['customer_id'] = request()->user()->id;
            request()->session()->put('direct_purchase', $selection);
        }

        return ['key' => $selection['key'], 'product_id' => $selection['product_id'], 'quantity' => $selection['quantity'],
            'initial_price' => $selection['initial_price'], 'expires_at' => $selection['expires_at'], 'customer_id' => $selection['customer_id'] ?? null];
    }

    public function start(int $productId, int $quantity): string
    {
        $product = $this->product($productId, $quantity);
        $key = (string) Str::uuid();
        request()->session()->forget(['pending_cart_intent', 'pending_wishlist_intent']);
        request()->session()->put('direct_purchase', ['key' => $key, 'product_id' => $productId, 'quantity' => $quantity,
            'initial_price' => $product->discount_price ?? $product->price,
            'customer_id' => request()->user()?->id, 'expires_at' => now()->addMinutes(15)->timestamp]);

        return $key;
    }

    public function product(int $productId, int $quantity): Product
    {
        $product = Product::query()->with(['category', 'inventory'])->findOrFail($productId);
        try {
            app(CartService::class)->ensureAvailableForCheckout($product, $product->category, $product->inventory, $quantity);
        } catch (CartOperationException $exception) {
            throw ValidationException::withMessages([$exception->field() => $exception->getMessage()]);
        }

        return $product;
    }

    /** @param Selection $selection
     * @return array{items: list<array{id: null, quantity: int, pc_build_group: null, product: array{id: int, name: string, brand: string|null, image_url: string|null}, unit_price: numeric-string, line_total: numeric-string}>, item_count: int, total_quantity: int, total: numeric-string, direct_purchase_key: string, price_notice: string|null} */
    public function review(array $selection): array
    {
        $product = $this->product($selection['product_id'], $selection['quantity']);
        $price = $product->discount_price ?? $product->price;
        if (! is_numeric($price)) {
            throw new \InvalidArgumentException('Product prices must be numeric.');
        }
        $total = bcmul($price, (string) $selection['quantity'], 2);

        return ['items' => [['id' => null, 'quantity' => $selection['quantity'], 'pc_build_group' => null,
            'product' => ['id' => $product->id, 'name' => $product->name, 'brand' => $product->brand, 'image_url' => $product->image_url],
            'unit_price' => $price, 'line_total' => $total]], 'item_count' => 1, 'total_quantity' => $selection['quantity'],
            'total' => $total, 'direct_purchase_key' => $selection['key'],
            'price_notice' => bccomp($price, $selection['initial_price'], 2) !== 0 ? 'The product price has changed. Review the current price before placing your order.' : null];
    }
}

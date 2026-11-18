<?php

namespace App\Actions\Cart;

use App\Enums\CartAvailability;
use App\Models\CartItem;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Order\DirectPurchase;
use App\Services\PcBuilder\PcBuilderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewCheckoutCart
{
    public function __construct(private readonly CartService $cartService, private readonly PcBuilderService $builder) {}

    /**
     * Build a checkout-ready snapshot from current cart state.
     *
     * @param  list<int>  $cartItemIds
     * @return array{
     *     items: list<array{id: int|null, quantity: int, pc_build_group: array{uuid: string, name: string, validation: array<string, mixed>}|null, product: array{id: int, name: string, brand: string|null, image_url: string|null}, unit_price: string, line_total: string}>,
     *     item_count: int,
     *     total_quantity: int,
     *     total: string, direct_purchase_key?: string, price_notice?: string|null
     * }
     */
    public function execute(User $customer, array $cartItemIds): array
    {
        $purchase = app(DirectPurchase::class);
        if (($selection = $purchase->selection()) !== null) {
            return $purchase->review($selection);
        }
        if (! $this->cartService->isValidCheckoutSelection($cartItemIds)) {
            throw CheckoutUnavailableException::invalidSelection();
        }

        return DB::transaction(function () use ($customer, $cartItemIds): array {
            $totals = $this->cartService->totals($customer, $cartItemIds);

            if ($totals['item_count'] !== count($cartItemIds)) {
                throw CheckoutUnavailableException::invalidSelection();
            }

            if (! $this->cartService->selectionContainsCompletePcBuilds($customer, $cartItemIds)) {
                throw CheckoutUnavailableException::invalidSelection();
            }

            $availability = $this->cartService->availability($customer, $cartItemIds);

            if (collect($availability)->contains(
                fn (array $item): bool => $item['status'] !== CartAvailability::Available->value,
            )) {
                throw CheckoutUnavailableException::unavailableItems();
            }

            $lineTotalsByItem = collect($totals['lines'])->keyBy('cart_item_id');
            $cart = $customer->cart()
                ->with([
                    'items' => fn ($query) => $query
                        ->select(['id', 'cart_id', 'product_id', 'quantity', 'pc_build_cart_group_id'])
                        ->whereKey($cartItemIds)
                        ->orderBy('id'),
                    'items.product:id,name,brand,image_path',
                    'items.pcBuildGroup:id,uuid,name,configuration_snapshot',
                ])
                ->first();
            $items = [];
            $buildValidations = [];
            foreach ($cart->items->pluck('pcBuildGroup')->filter()->unique('id') as $group) {
                try {
                    $validation = $this->builder->validateSelection($group->configuration_snapshot['components'] ?? []);
                } catch (ValidationException) {
                    throw CheckoutUnavailableException::unavailableItems();
                }
                if (! $validation['can_purchase']) {
                    throw CheckoutUnavailableException::incompatibleBuild();
                }
                $buildValidations[$group->id] = $validation;
            }

            foreach ($cart->items as $item) {
                /** @var CartItem $item */
                $lineTotal = $lineTotalsByItem->get($item->id);

                $items[] = [
                    'id' => $item->id,
                    'quantity' => $item->quantity,
                    'pc_build_group' => $item->pcBuildGroup === null ? null : [
                        'uuid' => $item->pcBuildGroup->uuid,
                        'name' => $item->pcBuildGroup->name,
                        'validation' => $buildValidations[$item->pcBuildGroup->id],
                    ],
                    'product' => [
                        'id' => $item->product->id,
                        'name' => $item->product->name,
                        'brand' => $item->product->brand,
                        'image_url' => $item->product->image_url,
                    ],
                    'unit_price' => $lineTotal['unit_price'],
                    'line_total' => $lineTotal['line_total'],
                ];
            }

            return [
                'items' => $items,
                'item_count' => $totals['item_count'],
                'total_quantity' => $totals['total_quantity'],
                'total' => $totals['total'],
            ];
        }, attempts: 3);
    }
}

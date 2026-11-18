<?php

namespace App\Services\Order;

use App\Actions\Cart\CartOperationException;
use App\Actions\Inventory\AdjustInventoryStock;
use App\Actions\Order\OrderPlacementException;
use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\PcBuildCartGroup;
use App\Models\Product;
use App\Models\ProductComponentProfile;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Notifications\OrderNotificationPublisher;
use App\Services\PcBuilder\CompatibilityEngine;
use App\Services\PcBuilder\ComponentReadinessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * @phpstan-type CheckoutData array{
 *     direct_purchase?: array{product_id: int, quantity: int}|null,
 *     cart_item_ids: list<int>,
 *     recipient_name: string, contact_number: string, fulfillment_method: FulfillmentMethod,
 *     delivery_address: string|null, payment_method: PaymentMethod, payment_proof_path: string|null,
 *     hosted_payment_method?: string|null, delivery_address_components?: array<string, mixed>|null,
 *     checkout_submission_key?: string|null, checkout_request_fingerprint?: string|null
 * }
 */
class OrderPlacementService
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly AdjustInventoryStock $adjustInventoryStock,
        private readonly DeliveryRules $deliveryRules,
        private readonly OrderNotificationPublisher $notifications,
    ) {}

    /**
     * Create an order and consume its cart and stock atomically.
     *
     * @param  array{
     *     direct_purchase?: array{product_id: int, quantity: int}|null,
     *     cart_item_ids: list<int>,
     *     recipient_name: string,
     *     contact_number: string,
     *     fulfillment_method: FulfillmentMethod,
     *     delivery_address: string|null,
     *     payment_method: PaymentMethod,
     *     payment_proof_path: string|null, hosted_payment_method?: string|null,
     *     delivery_address_components?: array<string, mixed>|null,
     *     checkout_submission_key?: string|null, checkout_request_fingerprint?: string|null
     * }  $checkout
     */
    public function execute(User $customer, array $checkout): Order
    {
        return $this->place($customer, $checkout, null);
    }

    /**
     * Place a quoted delivery order using validated checkout data and a canonical zone.
     *
     * @param  CheckoutData  $checkout
     */
    public function executeWithDeliveryQuote(User $customer, array $checkout, string $deliveryDestination): Order
    {
        if ($checkout['fulfillment_method'] !== FulfillmentMethod::Delivery) {
            throw new InvalidArgumentException('Quoted delivery placement requires delivery fulfillment.');
        }

        return $this->place($customer, $checkout, $deliveryDestination);
    }

    /** @param CheckoutData $checkout */
    private function place(User $customer, array $checkout, ?string $deliveryDestination): Order
    {
        return DB::transaction(function () use ($customer, $checkout, $deliveryDestination): Order {
            $lockedCustomer = User::query()
                ->whereKey($customer->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCustomer->role !== UserRole::Customer) {
                throw new AuthorizationException('Orders may only be placed by customers.');
            }

            $cartItemIds = $checkout['cart_item_ids'];

            if (! isset($checkout['direct_purchase']) && ! $this->cartService->isValidCheckoutSelection($cartItemIds)) {
                throw OrderPlacementException::invalidSelection();
            }

            if ($checkout['payment_method'] === PaymentMethod::Cod
                && ($checkout['fulfillment_method'] !== FulfillmentMethod::Delivery
                    || $deliveryDestination === null
                    || ! filled($checkout['delivery_address'])
                    || $checkout['payment_proof_path'] !== null
                    || Config::string('battlefront.delivery.carrier') !== 'lbc')) {
                throw ValidationException::withMessages([
                    'payment_method' => 'COD requires a quoted LBC delivery with a delivery address and no payment proof.',
                ]);
            }

            $cart = Cart::query()
                ->where('user_id', $lockedCustomer->id)
                ->lockForUpdate()
                ->first();

            if ($cart === null && ! isset($checkout['direct_purchase'])) {
                throw OrderPlacementException::emptyCart();
            }

            /** @var Collection<int, CartItem> $cartItems */
            $cartItems = CartItem::query()
                ->where('cart_id', $cart?->id)
                ->whereKey($cartItemIds)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get();

            if (isset($checkout['direct_purchase'])) {
                $selection = $checkout['direct_purchase'];
                $cartItems = new Collection([new CartItem(['product_id' => $selection['product_id'], 'quantity' => $selection['quantity']])]);
            }
            if (! isset($checkout['direct_purchase']) && $cartItems->count() !== count($cartItemIds)) {
                throw OrderPlacementException::invalidSelection();
            }

            $selectedBuildGroupIds = $cartItems->pluck('pc_build_cart_group_id')->filter()->unique()->values();
            $buildGroups = PcBuildCartGroup::query()
                ->where('cart_id', $cart?->id)
                ->whereKey($selectedBuildGroupIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($buildGroups->count() !== $selectedBuildGroupIds->count()) {
                throw OrderPlacementException::invalidSelection();
            }

            if ($selectedBuildGroupIds->isNotEmpty()) {
                $allGroupedItemIds = CartItem::query()
                    ->where('cart_id', $cart->id)
                    ->whereIn('pc_build_cart_group_id', $selectedBuildGroupIds)
                    ->pluck('id');
                if ($allGroupedItemIds->diff($cartItemIds)->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'cart_item_ids' => 'Select every component in a PC configuration to check it out as a group.',
                    ]);
                }
            }

            $productIds = $cartItems->pluck('product_id')->all();
            $products = Product::query()
                ->whereKey($productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($products->count() !== count(array_unique($productIds))) {
                throw OrderPlacementException::unavailableItems();
            }
            $buildSnapshots = $this->validatePcBuildGroups($cartItems, $buildGroups);
            $categories = Category::query()
                ->whereKey($products->pluck('category_id')->unique()->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $inventories = Inventory::query()
                ->whereIn('product_id', $productIds)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');
            $lines = [];
            $total = '0.00';

            foreach ($cartItems->groupBy('product_id') as $productId => $productCartItems) {
                /** @var Product $product */
                $product = $products->get($productId);
                /** @var Category $category */
                $category = $categories->get($product->category_id);
                /** @var Inventory|null $inventory */
                $inventory = $inventories->get($product->id);
                try {
                    $this->cartService->ensureAvailableForCheckout(
                        $product,
                        $category,
                        $inventory,
                        (int) $productCartItems->sum('quantity'),
                    );
                } catch (CartOperationException) {
                    throw OrderPlacementException::unavailableItems();
                }
            }

            foreach ($cartItems as $cartItem) {
                /** @var Product $product */
                $product = $products->get($cartItem->product_id);
                /** @var Category $category */
                $category = $categories->get($product->category_id);
                /** @var Inventory|null $inventory */
                $inventory = $inventories->get($product->id);

                $unitPrice = $product->discount_price ?? $product->price;

                if (! is_numeric($unitPrice)) {
                    throw new InvalidArgumentException('Product prices must be numeric.');
                }

                $lineTotal = bcmul($unitPrice, (string) $cartItem->quantity, 2);
                $total = bcadd($total, $lineTotal, 2);
                $lines[] = [
                    'cart_item_id' => $cartItem->id,
                    'product' => $product,
                    'inventory' => $inventory,
                    'quantity' => $cartItem->quantity,
                    'unit_price' => $unitPrice,
                    'build_snapshot' => $buildSnapshots[$cartItem->id] ?? null,
                ];
            }

            $cartQuantitiesByProduct = $cartItems->groupBy('product_id')
                ->map(fn ($items): int => (int) $items->sum('quantity'))
                ->all();
            $quote = $deliveryDestination !== null
                ? $this->deliveryRules->quote(FulfillmentMethod::Delivery, $deliveryDestination, $products, $cartQuantitiesByProduct)
                : null;
            $zone = $deliveryDestination !== null ? $this->deliveryRules->zone($deliveryDestination) : null;
            $methodAvailableForZone = $checkout['payment_method'] === PaymentMethod::Online
                ? (bool) array_intersect(['gcash', 'maya'], $zone['payment_methods'] ?? [])
                : in_array($checkout['payment_method']->value, $zone['payment_methods'] ?? [], true);
            if ($zone !== null && ! $methodAvailableForZone) {
                throw ValidationException::withMessages(['payment_method' => 'This payment method is unavailable for the selected delivery route.']);
            }
            $deliveryFee = $quote['delivery_fee'] ?? '0.00';
            $finalTotal = bcadd($total, $deliveryFee, 2);

            if (bccomp($finalTotal, '9999999999.99', 2) > 0) {
                throw new InvalidArgumentException('Order total exceeds the supported monetary limit.');
            }

            $order = Order::query()->create([
                'handler_required' => true,
                'user_id' => $lockedCustomer->id,
                'recipient_name' => $checkout['recipient_name'],
                'contact_number' => $checkout['contact_number'],
                'fulfillment_method' => $checkout['fulfillment_method'],
                'delivery_address' => $checkout['delivery_address'],
                'product_subtotal' => $total,
                'delivery_fee' => $deliveryFee,
                'total_amount' => $finalTotal,
                'delivery_destination' => $zone['destination'] ?? null,
                'delivery_quote_metadata' => $zone !== null && $quote !== null ? [
                    'city_code' => $zone['city_code'], 'province_code' => $zone['province_code'], 'region_code' => $zone['region_code'],
                    'dataset_version' => PsgcDeliveryLocations::VERSION, 'rule_id' => $zone['rule_id'], 'rules_version' => $zone['rules_version'],
                    'pricing_status' => $quote['pricing_status'],
                    'customer_fee_policy' => in_array($quote['pricing_status'], ['configured_estimate', 'regional_estimate'], strict: true)
                        ? 'fixed_at_placement_battlefront_absorbs_variance'
                        : null,
                    'payment_methods' => $zone['payment_methods'],
                    'region_group' => $quote['region_group'] ?? null,
                    'weight_pricing' => array_intersect_key($quote, array_flip(['route_base_fee', 'weight_surcharge', 'actual_weight_kg', 'volumetric_weight_kg', 'billable_weight_kg', 'weight_rate_per_kg', 'weight_step_kg', 'included_weight_kg', 'volumetric_divisor_cm3_per_kg', 'estimate_disclosure', 'measurement_sources'])),
                    'delivery_arrangement_required' => $quote['delivery_arrangement_required'],
                    'address_components' => $checkout['delivery_address_components'] ?? null,
                    'payment_assumption' => $checkout['payment_method'] === PaymentMethod::Online
                        ? 'Online payment through PayMongo Hosted Checkout. Provider fees are absorbed by Battlefront.'
                        : ($quote['pricing_status'] === 'regional_estimate'
                            ? 'Battlefront’s regional delivery fee estimate is included in the total. Delivery arrangement and ETA are confirmed manually.'
                            : Config::string(($quote['pricing_status'] === 'configured_estimate'
                        ? 'battlefront.delivery.payment_assumptions.'
                        : 'battlefront.delivery.unconfigured_route.payment_assumptions.').$checkout['payment_method']->value)),
                ] : null,
                'delivery_base_fee' => $quote['base_fee'] ?? null,
                'shipping_profile' => $quote['shipping_profile'] ?? null,
                'handling_surcharge' => $quote['handling_surcharge'] ?? null,
                'delivery_origin_city' => $quote['origin_city'] ?? null,
                'delivery_is_demo' => $quote['is_demo'] ?? null,
                'delivery_assumption_label' => $quote['assumption_label'] ?? null,
                'status' => OrderStatus::Pending,
                'payment_status' => PaymentStatus::Pending,
                'payment_method' => $checkout['payment_method'],
                'hosted_payment_method' => $checkout['hosted_payment_method'] ?? null,
                'payment_proof_path' => $checkout['payment_proof_path'],
                'payment_origin' => $checkout['payment_method'] === PaymentMethod::Online ? 'paymongo' : 'manual',
                'checkout_submission_key' => $checkout['checkout_submission_key'] ?? null,
                'checkout_request_fingerprint' => $checkout['checkout_request_fingerprint'] ?? null,
            ]);

            foreach ($lines as $line) {
                $order->items()->create([
                    'product_id' => $line['product']->id,
                    'product_name_at_time' => $line['product']->name,
                    'quantity' => $line['quantity'],
                    'price_at_time' => $line['unit_price'],
                    'acquisition_cost_at_time' => $line['product']->acquisition_cost,
                    'acquisition_cost_source' => $line['product']->acquisition_cost_source,
                    'pc_build_group_uuid' => $line['build_snapshot']['group_uuid'] ?? null,
                    'pc_build_name_at_time' => $line['build_snapshot']['group_name'] ?? null,
                    'pc_build_component_snapshot' => $line['build_snapshot']['component'] ?? null,
                ]);

                $this->adjustInventoryStock->decrease(
                    $line['inventory'],
                    $line['quantity'],
                    $order->id,
                );
            }

            if ($quote !== null && $zone['configured'] === true) {
                $order->shipment()->create([
                    'carrier' => Config::string('battlefront.delivery.carrier'),
                    'status' => ShipmentStatus::AwaitingPreparation,
                    'preparation_days' => $quote['preparation_days'],
                    'transit_min_days' => $quote['transit_min_days'],
                    'transit_max_days' => $quote['transit_max_days'],
                    'eta_min_days' => $quote['eta_min_days'],
                    'eta_max_days' => $quote['eta_max_days'],
                ]);
            }

            if (! isset($checkout['direct_purchase'])) {
                $cart->items()->whereKey($cartItemIds)->delete();
                if ($selectedBuildGroupIds->isNotEmpty()) {
                    $cart->pcBuildGroups()->whereKey($selectedBuildGroupIds)->delete();
                }

                if (! $cart->items()->exists()) {
                    $cart->delete();
                }

            }

            $this->notifications->afterCommit($order, 'order.placed');
            if ($order->payment_method->requiresPaymentProof() && $order->payment_proof_path !== null) {
                $this->notifications->afterCommit($order, 'payment.proof_submitted');
            }

            return $order->load('items');
        }, attempts: 3);
    }

    /**
     * Revalidate all selected PC group lines and prepare purchase snapshots, including unfinished builds.
     *
     * @param  Collection<int, CartItem>  $cartItems
     * @param  Collection<int, PcBuildCartGroup>  $buildGroups
     * @return array<int, array{group_uuid: string, group_name: string, component: array<string, mixed>}>
     */
    private function validatePcBuildGroups(Collection $cartItems, Collection $buildGroups): array
    {
        if ($buildGroups->isEmpty()) {
            return [];
        }

        $profiles = ProductComponentProfile::query()
            ->whereIn('product_id', $cartItems->pluck('product_id')->unique())
            ->orderBy('id')
            ->lockForUpdate()
            ->with('revisions')
            ->get()
            ->keyBy('product_id');
        $snapshots = [];

        foreach ($buildGroups as $group) {
            $storedComponents = collect($group->configuration_snapshot['components'] ?? []);
            $groupItems = $cartItems->where('pc_build_cart_group_id', $group->id)->values();
            $components = [];
            $snapshotParts = [];

            foreach ($groupItems as $cartItem) {
                $storedParts = $storedComponents->where('product_id', $cartItem->product_id);
                if ($storedParts->isEmpty() || (int) $storedParts->sum('quantity') !== $cartItem->quantity) {
                    throw ValidationException::withMessages(['cart_item_ids' => 'A PC configuration changed. Remove it and add the current build again.']);
                }

                $profile = $profiles->get($cartItem->product_id);
                $revision = $profile?->revisions->firstWhere('revision', $profile->current_revision);
                $reviewed = $profile?->review_status === 'approved' && $revision?->review_status === 'approved';
                if ($profile === null) {
                    throw ValidationException::withMessages(['cart_item_ids' => 'A selected part is no longer configured for the PC Builder.']);
                }
                $slots = [];
                foreach ($storedParts as $stored) {
                    $component = [
                        'key' => (string) $stored['key'],
                        'product_id' => $cartItem->product_id,
                        'component_type' => $profile->component_type ?? (string) ($stored['component_type'] ?? 'unknown'),
                        'quantity' => (int) $stored['quantity'],
                        'configuration' => $stored['configuration'] ?? [],
                        'specifications' => $reviewed ? $revision->specifications : [],
                        'specifications_reviewed' => $reviewed && (! config('pc-builder.strict_selection_enabled') || app(ComponentReadinessService::class)->selectionIssues($profile) === []),
                        'evidence' => $revision->evidence ?? [],
                    ];
                    $components[] = $component;
                    $slots[] = $component;
                }
                $snapshotParts[$cartItem->id] = [
                    'component_type' => $profile->component_type,
                    'configuration' => $storedParts->first()['configuration'] ?? [],
                    'specifications' => $reviewed ? $revision->specifications : [],
                    'specification_revision' => $reviewed ? $revision->revision : null,
                    'component_slots' => $slots,
                ];
            }

            if ($storedComponents->count() !== count($components)) {
                throw ValidationException::withMessages(['cart_item_ids' => 'Select the full PC configuration to check out.']);
            }

            $validation = app(CompatibilityEngine::class)->evaluate($components, (bool) config('pc-builder.strict_selection_enabled'));
            if (! $validation['can_purchase']) {
                throw ValidationException::withMessages(['cart_item_ids' => implode(' ', $validation['purchase_blockers'])]);
            }

            foreach ($groupItems as $cartItem) {
                $part = $snapshotParts[$cartItem->id];
                $relatedFindings = array_values(array_filter(
                    $validation['findings'],
                    fn (array $finding): bool => array_intersect(array_column($part['component_slots'], 'key'), $finding['component_keys']) !== [],
                ));
                $snapshots[$cartItem->id] = [
                    'group_uuid' => $group->uuid,
                    'group_name' => $group->name,
                    'component' => [
                        ...$part,
                        'ruleset_version' => $validation['ruleset_version'],
                        'compatibility_status' => $validation['status'],
                        'compatibility_findings' => $relatedFindings,
                        'build_complete' => $validation['complete'],
                        'required_components' => $validation['required_components'],
                    ],
                ];
            }
        }

        return $snapshots;
    }
}

<?php

namespace App\Services\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DashboardService
{
    /** @return array<string, mixed> */
    public function administration(): array
    {
        $salesSummary = Sale::query()
            ->toBase()
            ->selectRaw('COUNT(*) as recorded_sales')
            ->selectRaw('COALESCE(SUM(amount), 0) as recorded_revenue')
            ->first();
        $orderSummary = Order::query()
            ->toBase()
            ->selectRaw(
                'COUNT(CASE WHEN status = ? THEN 1 END) as pending_orders',
                [OrderStatus::Pending->value],
            )
            ->selectRaw(
                'COUNT(CASE WHEN status = ? THEN 1 END) as processing_orders',
                [OrderStatus::Processing->value],
            )
            ->selectRaw(
                'COUNT(CASE WHEN payment_status = ? AND status IN (?, ?) THEN 1 END) as pending_payment_reviews',
                [
                    PaymentStatus::Pending->value,
                    OrderStatus::Pending->value,
                    OrderStatus::Processing->value,
                ],
            )
            ->first();
        $operationalInventory = Inventory::query()->whereIn('product_id', Product::query()->customerEligible()->select('id'));
        $lowStockQuery = (clone $operationalInventory)->lowStock();
        $outOfStockQuery = (clone $operationalInventory)->outOfStock();

        return [
            'kpis' => [
                'recorded_revenue' => $this->money($salesSummary->recorded_revenue),
                'recorded_sales' => (int) $salesSummary->recorded_sales,
                'pending_orders' => (int) $orderSummary->pending_orders,
                'processing_orders' => (int) $orderSummary->processing_orders,
                'low_stock_products' => (clone $lowStockQuery)->count(),
                'out_of_stock_products' => (clone $outOfStockQuery)->count(),
            ],
            'needs_attention' => [
                'pending_payment_reviews' => (int) $orderSummary->pending_payment_reviews,
                'payment_orders' => $this->paymentReviewOrders(),
                'out_of_stock_products' => $this->stockProducts($outOfStockQuery),
                'low_stock_products' => $this->stockProducts($lowStockQuery),
            ],
            'recent_orders' => $this->recentOrders(),
        ];
    }

    /** @return array<string, mixed> */
    public function customer(User $customer): array
    {
        $cart = $customer->cart()
            ->select(['id', 'user_id'])
            ->withCount('items')
            ->withSum('items as total_quantity', 'quantity')
            ->first();
        $orderSummary = $customer->orders()
            ->toBase()
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw(
                'COUNT(CASE WHEN status IN (?, ?) THEN 1 END) as active_orders',
                [OrderStatus::Pending->value, OrderStatus::Processing->value],
            )
            ->first();
        $latestOrder = $customer->orders()
            ->select([
                'id', 'user_id', 'fulfillment_method', 'total_amount', 'status',
                'payment_status', 'payment_method', 'created_at',
            ])
            ->withCount('items')
            ->withSum('items as total_quantity', 'quantity')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return [
            'summary' => [
                'cart_items' => (int) ($cart?->getAttribute('items_count') ?? 0),
                'cart_units' => (int) ($cart?->getAttribute('total_quantity') ?? 0),
                'active_orders' => (int) $orderSummary->active_orders,
                'total_orders' => (int) $orderSummary->total_orders,
            ],
            'latest_order' => $latestOrder === null
                ? null
                : $this->customerOrderData($latestOrder),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function paymentReviewOrders(): array
    {
        return array_values(Order::query()
            ->select([
                'id', 'user_id', 'total_amount', 'status', 'payment_status',
                'payment_method', 'created_at',
            ])
            ->with('user:id,name')
            ->where('payment_status', PaymentStatus::Pending->value)
            ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::Processing->value])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'reference' => $order->reference,
                'customer_name' => $order->user->name,
                'created_at' => $order->created_at->toIso8601String(),
                'status' => [
                    'value' => $order->status->value,
                    'label' => $order->status->label(),
                ],
                'payment_method' => $order->payment_method->label(),
                'total' => $order->total_amount,
            ])
            ->all());
    }

    /**
     * @param  Builder<Inventory>  $stockQuery
     * @return list<array<string, mixed>>
     */
    private function stockProducts(Builder $stockQuery): array
    {
        return array_values($stockQuery
            ->select(['id', 'product_id', 'quantity', 'reorder_level'])
            ->with('product:id,name,brand,is_active')
            ->orderBy('quantity')
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->map(fn (Inventory $inventory): array => [
                'id' => $inventory->product->id,
                'name' => $inventory->product->name,
                'brand' => $inventory->product->brand,
                'is_active' => $inventory->product->is_active,
                'quantity' => $inventory->quantity,
                'reorder_level' => $inventory->reorder_level,
            ])
            ->all());
    }

    /** @return list<array<string, mixed>> */
    private function recentOrders(): array
    {
        return array_values(Order::query()
            ->select([
                'id', 'user_id', 'fulfillment_method', 'total_amount', 'status',
                'payment_status', 'created_at',
            ])
            ->with('user:id,name')
            ->withCount('items')
            ->withSum('items as total_quantity', 'quantity')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'reference' => $order->reference,
                'customer_name' => $order->user->name,
                'created_at' => $order->created_at->toIso8601String(),
                'status' => [
                    'value' => $order->status->value,
                    'label' => $order->status->label(),
                ],
                'payment_status' => [
                    'value' => $order->payment_status->value,
                    'label' => $order->payment_status->label(),
                ],
                'item_count' => (int) $order->getAttribute('items_count'),
                'total_quantity' => (int) ($order->getAttribute('total_quantity') ?? 0),
                'total' => $order->total_amount,
            ])
            ->all());
    }

    /** @return array<string, mixed> */
    private function customerOrderData(Order $order): array
    {
        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'created_at' => $order->created_at->toIso8601String(),
            'status' => [
                'value' => $order->status->value,
                'label' => $order->status->customerLabel($order->fulfillment_method),
            ],
            'fulfillment' => [
                'value' => $order->fulfillment_method->value,
                'label' => $order->fulfillment_method->label(),
            ],
            'payment' => [
                'method' => $order->payment_method->label(),
                'status' => [
                    'value' => $order->payment_status->value,
                    'label' => $order->payment_status->label(),
                ],
            ],
            'item_count' => (int) $order->getAttribute('items_count'),
            'total_quantity' => (int) ($order->getAttribute('total_quantity') ?? 0),
            'total' => $order->total_amount,
        ];
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}

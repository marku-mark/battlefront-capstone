<?php

namespace App\Services\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Repositories\Reporting\SalesReportRepository;
use Illuminate\Database\Eloquent\Builder;

class DashboardService
{
    public function __construct(private readonly SalesReportRepository $salesReportRepository) {}

    /** @return array<string, mixed> */
    public function administration(User $actor): array
    {
        $canViewReports = $actor->can('reports.view');
        $canViewOrders = $actor->can('orders.view');
        $canViewInventory = $actor->can('inventory.view');
        $salesSummary = $canViewReports ? $this->salesReportRepository->recordedSalesSummary() : null;
        $profitSummary = $canViewReports && $actor->can('reports.profit') ? $this->salesReportRepository->recordedProfitabilitySummary() : null;
        $orderSummary = $canViewOrders ? Order::query()
            ->toBase()
            ->selectRaw(
                'COUNT(CASE WHEN status = ? THEN 1 END) as pending_orders',
                [OrderStatus::Pending->value],
            )
            ->selectRaw(
                'COUNT(CASE WHEN status = ? THEN 1 END) as processing_orders',
                [OrderStatus::Processing->value],
            )
            ->first() : null;
        $operationalInventory = $canViewInventory
            ? Inventory::query()->whereIn('product_id', Product::query()->customerEligible()->select('id'))
            : null;
        $lowStockQuery = $operationalInventory === null ? null : (clone $operationalInventory)->lowStock();
        $outOfStockQuery = $operationalInventory === null ? null : (clone $operationalInventory)->outOfStock();

        return [
            'kpis' => [
                'recorded_revenue' => $salesSummary === null ? null : $this->money($salesSummary->total_revenue),
                'recorded_sales' => $salesSummary === null ? null : (int) $salesSummary->total_sales,
                'recorded_gross_profit' => $profitSummary?->gross_profit,
                'profit_data_complete' => $profitSummary?->cost_data_complete,
                'pending_orders' => $orderSummary === null ? null : (int) $orderSummary->pending_orders,
                'processing_orders' => $orderSummary === null ? null : (int) $orderSummary->processing_orders,
                'low_stock_products' => $lowStockQuery === null ? null : (clone $lowStockQuery)->count(),
                'out_of_stock_products' => $outOfStockQuery === null ? null : (clone $outOfStockQuery)->count(),
            ],
            'needs_attention' => [
                'pending_payment_reviews' => $canViewOrders ? $this->pendingPaymentReviewsQuery()->count() : null,
                'payment_orders' => $canViewOrders ? $this->paymentReviewOrders() : [],
                'out_of_stock_products' => $outOfStockQuery === null ? [] : $this->stockProducts($outOfStockQuery),
                'low_stock_products' => $lowStockQuery === null ? [] : $this->stockProducts($lowStockQuery),
            ],
            'recent_orders' => $canViewOrders ? $this->recentOrders() : [],
        ];
    }

    /** @return array{pending_orders: ?int, pending_payment_reviews: ?int, low_stock_products: ?int, out_of_stock_products: ?int} */
    public function staff(User $actor): array
    {
        $canViewOrders = $actor->can('orders.view');
        $operationalInventory = $actor->can('inventory.view')
            ? Inventory::query()->whereIn('product_id', Product::query()->customerEligible()->select('id'))
            : null;

        return [
            'pending_orders' => $canViewOrders ? Order::query()->where('status', OrderStatus::Pending)->count() : null,
            'pending_payment_reviews' => $canViewOrders ? $this->pendingPaymentReviewsQuery()->count() : null,
            'low_stock_products' => $operationalInventory === null ? null : (clone $operationalInventory)->lowStock()->count(),
            'out_of_stock_products' => $operationalInventory === null ? null : (clone $operationalInventory)->outOfStock()->count(),
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
            ->with('shipment:id,order_id,status,carrier_cod_collected_at')
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
        return array_values($this->pendingPaymentReviewsQuery()
            ->select([
                'id', 'user_id', 'total_amount', 'status', 'payment_status',
                'payment_method', 'created_at',
            ])
            ->with(['user:id,name', 'shipment:id,order_id,carrier_cod_collected_at'])
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
                'payment_method' => $order->payment_method === PaymentMethod::Cod
                    ? $order->paymentStatusLabel()
                    : $order->payment_method->label(),
                'total' => $order->total_amount,
            ])
            ->all());
    }

    /** @return Builder<Order> */
    private function pendingPaymentReviewsQuery(): Builder
    {
        return Order::query()
            ->where('payment_status', PaymentStatus::Pending)
            ->whereIn('status', [OrderStatus::Pending, OrderStatus::Processing])
            ->where(function (Builder $query): void {
                $query->where('payment_method', '<>', PaymentMethod::Cod)
                    ->orWhere(function (Builder $cod): void {
                        $cod->where('payment_method', PaymentMethod::Cod)
                            ->where('status', OrderStatus::Processing)
                            ->whereHas('shipment', fn (Builder $shipment): Builder => $shipment
                                ->where('carrier', 'lbc')->where('status', ShipmentStatus::Delivered));
                    });
            });
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
                'payment_status', 'payment_method', 'created_at',
            ])
            ->with(['user:id,name', 'shipment:id,order_id,carrier_cod_collected_at'])
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
                    'label' => $order->paymentStatusLabel(),
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
                'label' => $order->customerStatusLabel(),
            ],
            'fulfillment' => [
                'value' => $order->fulfillment_method->value,
                'label' => $order->fulfillment_method->label(),
            ],
            'payment' => [
                'method' => $order->payment_method->label(),
                'status' => [
                    'value' => $order->payment_status->value,
                    'label' => $order->paymentStatusLabel(),
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

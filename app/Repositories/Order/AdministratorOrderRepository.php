<?php

namespace App\Repositories\Order;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdministratorOrderRepository
{
    /** @param array<string, mixed> $filters
     * @return Builder<Shipment>
     */
    public function shipmentQuery(array $filters): Builder
    {
        $query = Shipment::query()
            ->select(['id', 'order_id', 'status', 'created_at', 'package_count', 'package_weight_kg',
                'package_length_cm', 'package_width_cm', 'package_height_cm', 'declared_value',
                'tracking_reference', 'actual_carrier_cost', 'carrier_cost_reference', 'carrier_cost_recorded_at', 'handed_to_carrier_at'])
            ->whereHas('order', fn (Builder $order) => $order->where('fulfillment_method', FulfillmentMethod::Delivery))
            ->with(['order:id,user_id,recipient_name,delivery_destination,delivery_address,delivery_fee,status', 'order.user:id,name']);
        $status = $filters['shipment_status'] ?? 'active';
        if ($status === 'cancelled') {
            $query->where(fn (Builder $shipment) => $shipment->where('status', ShipmentStatus::Cancelled)
                ->orWhereHas('order', fn (Builder $order) => $order->where('status', OrderStatus::Cancelled)));
        } elseif ($status !== 'all') {
            $query->whereHas('order', fn (Builder $order) => $order->where('status', '!=', OrderStatus::Cancelled));
            $status === 'active'
                ? $query->whereNotIn('status', [ShipmentStatus::Delivered, ShipmentStatus::Cancelled])
                : $query->where('status', $status);
        }
        if (($filters['reconciliation'] ?? 'all') === 'confirmed') {
            $query->whereNotNull('carrier_cost_recorded_at');
        } elseif (($filters['reconciliation'] ?? 'all') === 'unconfirmed') {
            $query->whereNull('carrier_cost_recorded_at');
        }
        if (filled($filters['q'] ?? null)) {
            $search = $filters['q'];
            $referenceId = $this->referenceIdFromSearch($search);
            $query->where(function (Builder $shipment) use ($search, $referenceId): void {
                $shipment->where('tracking_reference', 'like', "%{$search}%")
                    ->orWhere('carrier_cost_reference', 'like', "%{$search}%")
                    ->orWhereHas('order', function (Builder $order) use ($search, $referenceId): void {
                        $order->where('recipient_name', 'like', "%{$search}%")
                            ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', "%{$search}%"));
                        if ($referenceId !== null) {
                            $order->orWhereKey($referenceId);
                        }
                    });
            });
        }
        if (filled($filters['from'] ?? null)) {
            $query->where('handed_to_carrier_at', '>=', CarbonImmutable::parse($filters['from'], config('battlefront.timezone'))->startOfDay()->utc());
        }
        if (filled($filters['to'] ?? null)) {
            $query->where('handed_to_carrier_at', '<', CarbonImmutable::parse($filters['to'], config('battlefront.timezone'))->startOfDay()->addDay()->utc());
        }

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Shipment>
     */
    public function shipmentDirectory(array $filters): LengthAwarePaginator
    {
        return $this->shipmentQuery($filters)->paginate(20)->appends(['view' => 'shipments', ...$filters]);
    }

    /**
     * Return the filtered order directory and counts for each operational view.
     *
     * @param  array<string, mixed>  $filters
     * @return array{orders: LengthAwarePaginator<int, Order>, counts: Collection<string, int>}
     */
    public function directory(array $filters, string $statusFilter): array
    {
        $filteredOrders = $this->applyIndexFilters(Order::query(), $filters);
        $counts = (clone $filteredOrders)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $orders = (($filters['gateway_state'] ?? null) === 'needs_attention' ? $filteredOrders : $this->applyStatusFilter($filteredOrders, $statusFilter))
            ->select([
                'id', 'user_id', 'recipient_name', 'fulfillment_method',
                'total_amount', 'status', 'payment_status', 'payment_method', 'created_at', 'payment_origin', 'payment_closure_requested_at',
            ])
            ->with(['paymongoFlows', 'user:id,name,email', 'shipment:id,order_id,status', 'cancellationRequest.refund'])
            ->withCount('items')
            ->withSum('items as total_quantity', 'quantity')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->appends($filters);

        return ['orders' => $orders, 'counts' => $counts];
    }

    public function loadDetails(Order $order): Order
    {
        return $order->load([
            'paymongoFlows',
            'cancellationRequest.refund',
            'shipment',
            'user:id,name,email',
            'items' => fn ($query) => $query
                ->select(['id', 'order_id', 'product_id', 'quantity', 'price_at_time', 'product_name_at_time'])
                ->orderBy('id'),
            'items.product:id,name,brand,image_path',
        ]);
    }

    /**
     * @return array{has_sale: bool, sales_returns: numeric-string, cost_recovered: numeric-string}
     */
    public function profitabilityInputs(Order $order): array
    {
        $orderHasSale = DB::table('sales')->where('order_id', $order->id)->exists();
        $salesReturns = (string) DB::table('customer_return_items as return_items')
            ->join('order_items', 'order_items.id', '=', 'return_items.order_item_id')
            ->where('order_items.order_id', $order->id)->sum('return_items.sales_return_amount');
        $costRecovered = (string) DB::table('customer_return_items as return_items')
            ->join('order_items', 'order_items.id', '=', 'return_items.order_item_id')
            ->where('order_items.order_id', $order->id)->sum('return_items.cost_recovered_amount');

        return ['has_sale' => $orderHasSale, 'sales_returns' => $salesReturns, 'cost_recovered' => $costRecovered];
    }

    /**
     * @return EloquentCollection<int, User>|array{}
     */
    public function handlerOptions(Order $order): EloquentCollection|array
    {
        return $order->status === OrderStatus::Completed || $order->status === OrderStatus::Cancelled
            ? []
            : User::query()->where('role', UserRole::Administrator->value)
                ->where('is_active', true)
                ->whereIn('administration_role_id', DB::table('administration_role_permissions as grants')
                    ->join('administration_permissions as permissions', 'permissions.id', '=', 'grants.administration_permission_id')
                    ->join('administration_roles as roles', 'roles.id', '=', 'grants.administration_role_id')
                    ->where('permissions.code', 'orders.process')->whereIn('roles.code', ['owner', 'manager', 'staff'])
                    ->select('grants.administration_role_id'))
                ->orderBy('name')->get(['id', 'name']);
    }

    public function assignedHandlerName(Order $order): ?string
    {
        return $order->assigned_handler_id === null ? null : User::query()->whereKey($order->assigned_handler_id)->value('name');
    }

    /**
     * @param  Builder<Order>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Order>
     */
    private function applyIndexFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['gateway_state'] ?? null, function (Builder $query, string $state): void {
                $query->where('payment_origin', 'paymongo');
                if ($state === 'needs_attention') {
                    $query->where(fn ($exceptions) => $exceptions->whereDoesntHave('paymongoFlows')->orWhereHas('paymongoFlows', fn ($flows) => $flows->where('requires_attention', true)));

                    return;
                }
                $query->where('payment_status', 'pending')->whereDoesntHave('paymongoFlows', fn ($flows) => $flows->where('requires_attention', true));
                if ($state === 'closing') {
                    $query->whereNotNull('payment_closure_requested_at');

                    return;
                }
                $query->whereNull('payment_closure_requested_at')->whereHas('paymongoFlows', function ($flows) use ($state): void {
                    $flows->whereRaw('paymongo_flows.id = (select coalesce(max(case when selected.active_slot = ? then selected.id end), max(selected.id)) from paymongo_flows selected where selected.order_id = orders.id)', ['active'])
                        ->whereRaw("CASE WHEN status IN ('reconciliation_required', 'creating', 'retrying') THEN 'checking' WHEN status = 'closed' THEN 'expired' WHEN status = 'failed' THEN 'failed' WHEN recovery_outcome IN ('failed', 'expired') THEN recovery_outcome ELSE 'awaiting' END = ?", [$state]);
                });
            })
            ->when($filters['q'] ?? null, function (Builder $query, string $search): void {
                $referenceId = $this->referenceIdFromSearch($search);

                $query->where(function (Builder $query) use ($referenceId, $search): void {
                    $query
                        ->where('recipient_name', 'like', "%{$search}%")
                        ->orWhereHas(
                            'user',
                            fn (Builder $userQuery): Builder => $userQuery->where('name', 'like', "%{$search}%"),
                        );

                    if ($referenceId !== null) {
                        $query->orWhereKey($referenceId);
                    }
                });
            })
            ->when(
                $filters['payment_status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('payment_status', $status),
            )
            ->when(
                $filters['payment_method'] ?? null,
                fn (Builder $query, string $method): Builder => $query->where('payment_method', $method),
            )
            ->when(
                $filters['fulfillment_method'] ?? null,
                fn (Builder $query, string $method): Builder => $query->where('fulfillment_method', $method),
            );
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private function applyStatusFilter(Builder $query, string $status): Builder
    {
        return match ($status) {
            'completed' => $query->where('status', OrderStatus::Completed->value),
            'cancelled' => $query->where('status', OrderStatus::Cancelled->value),
            default => $query->whereIn('status', [
                OrderStatus::Pending->value,
                OrderStatus::Processing->value,
            ]),
        };
    }

    private function referenceIdFromSearch(string $search): ?int
    {
        if (preg_match('/^BF-(\d+)$/i', trim($search), $matches) !== 1) {
            return null;
        }

        $referenceId = (int) $matches[1];

        return $referenceId > 0 ? $referenceId : null;
    }
}

<?php

namespace App\Http\Middleware;

use App\Enums\AppearancePreference;
use App\Services\AdministrationWorkQueue;
use App\Services\Cart\CartService;
use App\Services\Notifications\NotificationHistory;
use App\Services\Wishlist\WishlistService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $customer = $request->user();
        $canUseCustomerCart = $customer?->can('use-customer-cart') ?? false;
        $canBrowseProductCatalog = $customer?->isAdministrator()
            && $customer->is_active
            && in_array($customer->administrationRoleCode(), ['staff', 'manager', 'owner'], true);
        $sidebarCookieName = $request->user() === null
            ? null
            : 'sidebar_state_'.$request->user()->getKey();

        return [
            ...parent::share($request),
            ...($customer !== null && $canUseCustomerCart ? [
                'cartQuantity' => Inertia::always(fn (): int => app(CartService::class)->totalQuantity($customer)),
                'wishlistCount' => Inertia::always(fn (): int => app(WishlistService::class)->count($customer)),
            ] : []),
            'name' => config('app.name'),
            'chatbotMemory' => Inertia::always(function () use ($request, $customer): array {
                $enabled = (bool) config('ai_integration.memory.enabled', false)
                    && $request->hasSession()
                    && ($request->user() === null || $request->user()->can('use-chatbot'));

                return [
                    'enabled' => $enabled,
                    'identity' => $request->hasSession() && ($customer === null || $customer->can('use-chatbot'))
                        ? hash_hmac('sha256', $request->session()->getId().':'.($customer?->getKey() ?? 'guest'), (string) config('app.key')) : null,
                ];
            }),
            'workQueueCounts' => fn (): array => $request->user()?->can('access-administration')
                && $canBrowseProductCatalog
                    ? app(AdministrationWorkQueue::class)->counts($request->user()) : [],
            'notificationSummary' => fn (): ?array => $request->user() === null
                || (! $request->user()->can('access-administration') && ! $request->user()->can('use-customer-cart'))
                ? null : app(NotificationHistory::class)->summary($request->user()),
            'appearance' => $request->user()?->appearance->resolved()->value
                ?? AppearancePreference::Light->value,
            'auth' => [
                'user' => $request->user(),
                'can' => [
                    'accessAdministration' => $request->user()?->can('access-administration') ?? false,
                    'approveInventory' => $request->user()?->can('inventory.approve') ?? false,
                    'adjustInventory' => $request->user()?->can('inventory.adjust') ?? false,
                    'requestInventoryAdjustment' => $request->user()?->administrationRoleCode() === 'staff'
                        && $request->user()->can('inventory.request'),
                    'approveReturns' => $request->user()?->can('returns.approve') ?? false,
                    'resolveReturns' => $request->user()?->can('returns.resolve') ?? false,
                    'reconcileReturns' => $request->user()?->can('returns.reconcile') ?? false,
                    'assignOrders' => $request->user()?->can('orders.assign') ?? false,
                    'viewReports' => $request->user()?->can('reports.view') ?? false,
                    'viewStaffReports' => in_array($request->user()?->administrationRoleCode(), ['manager', 'owner'], true)
                        && $request->user()->can('staff_reports.view'),
                    'viewAudit' => $request->user()?->can('audit.view') ?? false,
                    'exportAudit' => $request->user()?->can('audit.export') ?? false,
                    'browseProductCatalog' => $canBrowseProductCatalog,
                    'manageProducts' => $request->user()?->can('products.manage') ?? false,
                    'priceProducts' => $request->user()?->can('products.price') ?? false,
                    'manageProductCosts' => $request->user()?->can('products.cost') ?? false,
                    'viewProfitReports' => $request->user()?->can('reports.profit') ?? false,
                    'deleteCatalog' => $request->user()?->can('catalog.delete') ?? false,
                    'manageSuppliers' => $request->user()?->can('suppliers.manage') ?? false,
                    'manageBranches' => $request->user()?->can('branches.manage') ?? false,
                    'manageUsers' => $request->user()?->can('users.manage') ?? false,
                    'viewCustomers' => $request->user()?->can('customers.view') ?? false,
                    'viewInventory' => $request->user()?->can('inventory.view') ?? false,
                    'viewOrders' => $request->user()?->can('orders.view') ?? false,
                    'useCustomerCart' => $canUseCustomerCart,
                    'requestInventory' => $request->user()?->can('inventory.request') ?? false,
                    'reorderInventory' => $request->user()?->can('inventory.reorder') ?? false,
                    'processOrders' => $request->user()?->can('orders.process') ?? false,
                    'requestReturns' => $request->user()?->can('returns.request') ?? false,
                    'dispatchReturns' => $request->user()?->can('returns.dispatch') ?? false,
                    'recordRefundPayments' => $request->user()?->can('returns.record_payment') ?? false,
                    'manageForecast' => $request->user()?->can('forecast.manage') ?? false,
                    'exportReports' => $request->user()?->can('reports.export') ?? false,
                ],
            ],
            'sidebarOpen' => $sidebarCookieName === null
                || ! $request->hasCookie($sidebarCookieName)
                || $request->cookie($sidebarCookieName) === 'true',
        ];
    }
}

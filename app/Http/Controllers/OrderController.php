<?php

namespace App\Http\Controllers;

use App\Actions\Order\PlaceCustomerOrder;
use App\Enums\PaymentMethod;
use App\Http\Requests\ValidateCheckoutRequest;
use App\Models\Order;
use App\Models\User;
use App\Repositories\Order\CustomerOrderRepository;
use App\Services\CustomerOrderPresenter;
use App\Services\PayMongo\ReconcilePayMongoReturn;
use App\Services\PayMongo\StartHostedCheckout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(Request $request, CustomerOrderRepository $orders, CustomerOrderPresenter $presenter): Response
    {
        /** @var User $customer */
        $customer = $request->user();

        return Inertia::render('Orders/Index', [
            'orders' => $orders->paginate($customer)->through(fn (Order $order): array => $presenter->summary($order)),
        ]);
    }

    public function store(
        ValidateCheckoutRequest $request,
        PlaceCustomerOrder $placeCustomerOrder,
        StartHostedCheckout $startHostedCheckout,
    ): RedirectResponse|\Symfony\Component\HttpFoundation\Response {
        /** @var User $customer */
        $customer = $request->user();
        $order = $placeCustomerOrder->execute($customer, $request->checkoutData(), $request->file('payment_proof'));
        if ($request->filled('direct_purchase_key')) {
            $request->session()->forget('direct_purchase');
        }
        $toast = ['type' => 'success', 'message' => 'Order placed successfully.'];

        if ($order->payment_method === PaymentMethod::Online) {
            try {
                $startHostedCheckout->execute($order);
            } catch (\RuntimeException) {
                $toast = ['type' => 'info', 'message' => 'Your order is saved. Online payment is being reconciled; check this order again shortly.'];
            }
        }

        Inertia::flash('toast', $toast);

        return to_route('orders.show', $order)
            ->with('confirmed_order_id', $order->id);
    }

    public function show(Request $request, int $order, CustomerOrderRepository $orders, CustomerOrderPresenter $presenter, ReconcilePayMongoReturn $reconcilePayMongoReturn): Response|RedirectResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $persistedOrder = $orders->find($customer, $order);

        if ($request->boolean('paymongo_return')) {
            $reconcilePayMongoReturn->execute($persistedOrder);

            return to_route('orders.show', [
                'order' => $persistedOrder,
                'paymongo_returned' => 1,
            ]);
        }

        $reconcilePayMongoReturn->refreshHosted($persistedOrder);
        $persistedOrder = $orders->find($customer, $order);

        return Inertia::render('Orders/Show', [
            'order' => $presenter->detail($persistedOrder),
            'isConfirmation' => $request->session()->get('confirmed_order_id') === $persistedOrder->id,
            'isPayMongoReturn' => $request->boolean('paymongo_returned'),
        ]);
    }
}

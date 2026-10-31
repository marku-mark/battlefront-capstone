<?php

namespace App\Http\Controllers;

use App\Actions\PayMongo\RetryPayMongoPayment;
use App\Models\User;
use App\Services\CustomerOrderPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class OrderPaymentRetryController extends Controller
{
    public function __invoke(
        Request $request,
        int $order,
        RetryPayMongoPayment $retryPayment,
        CustomerOrderPresenter $presenter,
    ): Response {
        /** @var User $customer */
        $customer = $request->user();
        $persistedOrder = $customer->orders()->whereKey($order)->firstOrFail();

        try {
            $payment = $retryPayment->execute($persistedOrder);

            if ($payment['channel'] === 'direct_qrph') {

                if ($request->expectsJson()) {
                    $data = $presenter->detail($persistedOrder->fresh());
                    $qrIsReady = data_get($data, 'payment.qr_code_image_url') !== null;

                    return response()->json([
                        'data' => $data,
                        'payment' => ['status' => $qrIsReady ? 'qrph_ready' : 'reconciliation_pending'],
                    ], $qrIsReady ? 200 : 202);
                }

                return to_route('orders.show', $persistedOrder);
            }

            if ($payment['channel'] === 'hosted_checkout') {
                $checkoutUrl = $payment['redirect_url'];

                if ($request->expectsJson()) {
                    return response()->json([
                        'data' => $presenter->detail($persistedOrder->fresh()),
                        'payment' => ['status' => 'checkout_ready', 'checkout_url' => $checkoutUrl],
                    ]);
                }

                Inertia::flash('toast', ['type' => 'success', 'message' => 'Your secure payment is ready. Continue in the payment tab.']);

                return to_route('orders.show', $persistedOrder);
            }

            return Inertia::location($payment['redirect_url']);
        } catch (RuntimeException) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'The online payment is being reconciled. Check this order again shortly.'], 202);
            }

            Inertia::flash('toast', ['type' => 'info', 'message' => 'The online payment is being reconciled. Check this order again shortly.']);

            return to_route('orders.show', $persistedOrder);
        }
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PayMongo\RetryPayMongoPayment;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CustomerOrderPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class OrderPaymentRetryController extends Controller
{
    public function __invoke(
        Request $request,
        int $order,
        RetryPayMongoPayment $retryPayment,
        CustomerOrderPresenter $presenter,
    ): JsonResponse {
        /** @var User $customer */
        $customer = $request->user();
        $persistedOrder = $customer->orders()->whereKey($order)->firstOrFail();

        try {
            $payment = $retryPayment->execute($persistedOrder);

            if ($payment['channel'] === 'direct_qrph') {
                $data = $presenter->detail($persistedOrder->fresh());
                $qrIsReady = data_get($data, 'payment.qr_code_image_url') !== null;

                return response()->json([
                    'data' => $data,
                    'payment' => ['status' => $qrIsReady ? 'qrph_ready' : 'reconciliation_pending'],
                ], $qrIsReady ? 200 : 202);
            }

            if ($payment['channel'] === 'hosted_checkout') {
                $checkoutUrl = $payment['redirect_url'];

                return response()->json([
                    'data' => $presenter->detail($persistedOrder->fresh()),
                    'payment' => ['status' => 'checkout_ready', 'redirect_url' => $checkoutUrl],
                ]);
            }

            return response()->json(['payment' => ['redirect_url' => $payment['redirect_url']]]);
        } catch (RuntimeException) {
            return response()->json(['message' => 'The online payment is being reconciled. Check this order again shortly.'], 202);
        }
    }
}

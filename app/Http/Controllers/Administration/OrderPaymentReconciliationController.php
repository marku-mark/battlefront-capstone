<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Jobs\ReconcilePayMongoOrder;
use App\Models\Order;
use App\Services\Order\OrderProcessingService;
use App\Services\PayMongo\PaymentRecovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OrderPaymentReconciliationController extends Controller
{
    public function __invoke(Request $request, Order $order, OrderProcessingService $processing, PaymentRecovery $recovery): RedirectResponse
    {
        DB::transaction(function () use ($request, $order, $processing, $recovery): void {
            $current = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($current->payment_origin === 'paymongo' && $processing->canProcess($current, $request->user()), 403);
            $flows = $current->paymongoFlows()->where(fn ($query) => $query->where('status', '!=', 'paid')->orWhere('requires_attention', true))->get();
            abort_if($flows->isEmpty(), 422, 'No payment requires reconciliation.');
            if (! Cache::add('paymongo-admin-reconcile:'.$order->id, true, 30)) {
                return;
            }
            foreach ($flows as $flow) {
                $flow->update(['next_check_at' => now()]);
                ReconcilePayMongoOrder::dispatch($flow->id)->afterCommit();
            }
            $recovery->audit($order->id, 'payment.reconciliation_requested', [], $request->user()->id);
        }, attempts: 3);

        return back()->with('success', 'Payment check requested. This does not create a payment or confirm payment manually.');
    }
}

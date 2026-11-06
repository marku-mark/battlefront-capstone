<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\PayMongoFlow;
use App\Models\PayMongoWebhookInbox;
use App\Services\Notifications\OrderNotificationPublisher;
use App\Services\PayMongo\RecordPayMongoPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessPayMongoWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $inboxId) {}

    public function handle(OrderNotificationPublisher $notifications, RecordPayMongoPayment $recordPayment): void
    {
        DB::transaction(function () use ($notifications, $recordPayment): void {
            $inbox = PayMongoWebhookInbox::query()->lockForUpdate()->find($this->inboxId);

            if ($inbox === null || $inbox->status === 'processed') {
                return;
            }

            if (! in_array($inbox->event_type, ['checkout_session.payment.paid', 'payment.paid', 'payment.failed', 'qrph.expired'], strict: true)) {
                $inbox->update(['status' => 'ignored', 'processed_at' => now()]);

                return;
            }

            $intentId = data_get($inbox->verified_data, 'attributes.payment_intent_id');
            $flow = PayMongoFlow::query()
                ->where('checkout_session_id', $inbox->resource_id)
                ->when(is_string($intentId), fn ($query) => $query->orWhere('payment_intent_id', $intentId)->orWhereHas('attempts', fn ($attempt) => $attempt->where('payment_intent_id', $intentId)))
                ->first();

            if ($flow === null) {
                $inbox->update(['status' => 'unmatched', 'next_check_at' => now()->addMinute()]);

                return;
            }

            if (Order::query()->lockForUpdate()->find($flow->order_id) === null) {
                $inbox->update(['status' => 'unmatched', 'next_check_at' => now()->addMinute()]);

                return;
            }

            $flow = PayMongoFlow::query()->lockForUpdate()->findOrFail($flow->id);

            if (in_array($inbox->event_type, ['payment.failed', 'qrph.expired'], true)
                && ($flow->payment_id !== null || (is_string($intentId) && $flow->payment_intent_id !== $intentId))) {
                $inbox->update(['status' => 'processed', 'processed_at' => now()]);

                return;
            }
            $providerPayments = $this->providerPayments($inbox);
            $providerPayment = $providerPayments[0] ?? null;

            if ($flow->channel === 'hosted_checkout' && $flow->status !== 'paid'
                && in_array($inbox->event_type, ['payment.failed', 'qrph.expired'], true)) {
                $flow->update(['status' => 'reconciliation_required']);
                ReconcilePayMongoOrder::dispatch($flow->id)->afterCommit();
                $inbox->update(['status' => 'processed', 'processed_at' => now()]);

                return;
            }

            if ($inbox->event_type === 'qrph.expired') {
                if ($flow->channel === 'direct_qrph' && $flow->status !== 'paid') {
                    $flow->update([
                        'status' => 'awaiting_payment_method',
                        'qr_code_image_url' => null,
                        'qr_expires_at' => null,
                    ]);
                }
                $inbox->update(['status' => 'processed', 'processed_at' => now()]);

                return;
            }

            if ($inbox->event_type === 'payment.failed') {
                if (in_array($flow->channel, ['direct_online_banking', 'direct_qrph'], strict: true)
                    && is_string($providerPayment['id'] ?? null)
                    && $flow->status !== 'paid') {
                    $attempt = $flow->attempts()->where('payment_id', $providerPayment['id'])->first()
                        ?? $flow->attempts()->where('payment_intent_id', $intentId)->whereNull('payment_id')->latest('id')->first();
                    if ($attempt === null) {
                        $flow->update(['status' => 'reconciliation_required', 'checkout_url' => null]);
                    } else {
                        $attempt->update([
                            'payment_id' => $providerPayment['id'],
                            'status' => 'failed',
                            'completed_at' => now(),
                        ]);
                    }
                    if ($attempt !== null && (int) $attempt->attempt_number === (int) $flow->attempts()->max('attempt_number')) {
                        $flow->update(['status' => 'awaiting_payment_method', 'checkout_url' => null]);
                    }
                }
                $inbox->update(['status' => 'processed', 'processed_at' => now()]);

                return;
            }

            $paid = collect($providerPayments)->first(fn (array $payment): bool => ($payment['status'] ?? null) === 'paid');

            if ($paid === null) {
                $flow->update(['status' => 'reconciliation_required']);
                $inbox->update(['status' => 'quarantined', 'processed_at' => now()]);

                return;
            }

            $paymentId = $paid['id'] ?? null;
            $amount = $paid['amount'] ?? null;
            $currency = $paid['currency'] ?? null;
            $netAmount = $paid['net_amount'] ?? null;
            $paymentMethod = $paid['method'] ?? null;

            if (! is_string($paymentId)
                || ! is_int($amount)
                || ! is_string($currency)
                || ($netAmount !== null && ! is_int($netAmount))
                || ($paymentMethod !== null && ! is_string($paymentMethod))) {
                $flow->update(['status' => 'reconciliation_required']);
                $inbox->update(['status' => 'quarantined', 'processed_at' => now()]);

                return;
            }

            $payment = [
                'id' => $paymentId,
                'status' => 'paid',
                'amount' => $amount,
                'net_amount' => $netAmount,
                'currency' => $currency,
                'method' => $paymentMethod,
            ];

            if (! $recordPayment->execute($flow, $payment, $notifications)) {
                $flow->update(['status' => 'reconciliation_required']);
                $inbox->update(['status' => 'quarantined', 'processed_at' => now()]);

                return;
            }

            $inbox->update(['status' => 'processed', 'processed_at' => now()]);
        }, attempts: 3);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function providerPayments(PayMongoWebhookInbox $inbox): array
    {
        $payments = data_get($inbox->verified_data, 'attributes.payments', []);

        if (! is_array($payments)) {
            return [];
        }

        $normalizedPayments = [];

        foreach ($payments as $payment) {
            if (! is_array($payment)) {
                continue;
            }

            $normalizedPayment = [];

            foreach ($payment as $key => $value) {
                if (is_string($key)) {
                    $normalizedPayment[$key] = $value;
                }
            }

            $normalizedPayments[] = $normalizedPayment;
        }

        return $normalizedPayments;
    }
}

<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Services\Order\OrderProcessingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OrderCancellationPresenter
{
    public function __construct(private readonly OrderProcessingService $processing) {}

    /** @return array<string, mixed>|null */
    public function summary(Order $order): ?array
    {
        $request = $order->cancellationRequest;
        if ($request === null) {
            return null;
        }
        $refund = $request->refund;
        [$status, $label] = match (true) {
            $request->status === 'pending' => ['requested', 'Cancellation requested'],
            $request->status === 'rejected' => ['rejected', 'Cancellation request rejected'],
            $refund === null => ['cancelled', 'Cancelled without refund'],
            bccomp($refund->confirmed_amount, $refund->approved_amount, 2) >= 0 => ['refunded', 'Refund completed'],
            default => ['refund_processing', 'Refund processing'],
        };

        return ['id' => $request->id, 'status' => ['value' => $status, 'label' => $label]];
    }

    /** @return array<string, mixed>|null */
    public function detail(Order $order, ?User $actor = null): ?array
    {
        $request = $order->cancellationRequest;
        $summary = $this->summary($order);
        if ($summary === null || $request === null) {
            return null;
        }
        $refund = $request->refund;
        $canRecord = $actor !== null && $refund !== null && $this->processing->canReviewCancellation($actor)
            && $actor->hasAdministrationPermission('returns.record_payment') && $refund->approved_by === $actor->id
            && bccomp($refund->approved_amount, $refund->paid_amount, 2) > 0;

        return [...$summary,
            'customer_note' => $request->customer_note,
            'decision_note' => $request->decision_note,
            'requested_at' => $request->created_at?->toIso8601String(),
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            'amount_received' => $request->amount_received,
            'refund' => $refund === null ? null : [
                'id' => $refund->id, 'approved_amount' => $refund->approved_amount,
                'confirmed_paid_amount' => $refund->confirmed_amount,
                'outstanding_amount' => bcsub($refund->approved_amount, $refund->confirmed_amount, 2),
                'unrecorded_amount' => bcsub($refund->approved_amount, $refund->paid_amount, 2),
                'payments' => $this->payments($order, $refund->id),
            ],
            ...($actor === null ? [] : [
                'can_review' => $request->status === 'pending' && $this->processing->canReviewCancellation($actor),
                'can_record_payment' => $canRecord,
                'payment_submission_key' => $canRecord ? (string) Str::uuid() : null,
            ]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function payments(Order $order, int $refundId): array
    {
        return array_values(DB::table('customer_refund_payments as payments')
            ->join('customer_refund_payment_reviews as reviews', 'reviews.customer_refund_payment_id', '=', 'payments.id')
            ->leftJoin('administration_evidence as evidence', fn ($join) => $join->on('evidence.subject_id', '=', 'payments.id')
                ->where('evidence.subject_type', 'refund_payment')->where('evidence.customer_visible', true))
            ->where('payments.customer_refund_id', $refundId)->where('reviews.decision', 'verified')
            ->whereRaw('reviews.id = (select max(latest.id) from customer_refund_payment_reviews as latest where latest.customer_refund_payment_id = payments.id)')
            ->orderBy('payments.id')->get(['payments.id', 'payments.amount', 'payments.payment_method', 'payments.paid_at',
                'reviews.confirmation_method', 'evidence.id as evidence_id', 'evidence.path'])
            ->map(fn ($payment): array => [
                'id' => $payment->id, 'amount' => bcadd($this->numericAmount((string) $payment->amount), '0.00', 2),
                'payment_method' => $payment->payment_method, 'paid_at' => CarbonImmutable::parse($payment->paid_at)->toIso8601String(),
                'proof' => $payment->confirmation_method === 'approver' && $payment->evidence_id !== null
                    && Storage::disk(AdministrationEvidenceService::diskNameForPath($payment->path))->exists($payment->path)
                    ? ['id' => $payment->evidence_id, 'url' => route('api.v1.orders.refund-payments.proof.show', [$order->id, $payment->id, $payment->evidence_id], false)] : null,
            ])->all());
    }

    /** @return numeric-string */
    private function numericAmount(string $amount): string
    {
        if (! is_numeric($amount) || preg_match('/^\d+(?:\.\d+)?$/D', $amount) !== 1) {
            throw new \UnexpectedValueException('A stored refund amount is not a valid decimal.');
        }

        return $amount;
    }
}

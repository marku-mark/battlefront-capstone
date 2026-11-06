<?php

namespace App\Services;

use App\Models\Order;
use App\Support\OperationalRequestReason;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use stdClass;

class CustomerReturnRequestPresenter
{
    /**
     * @param  array<int, list<array<string, mixed>>>|null  $paymentViews
     * @return list<array<string, mixed>>
     */
    public function forOrder(Order $order, ?array $paymentViews = null): array
    {
        $payments = $paymentViews ?? $this->paymentsForOrder($order);
        $verified = DB::table('customer_refund_payments as payments')
            ->leftJoin('customer_refund_payment_reviews as reviews', 'reviews.customer_refund_payment_id', '=', 'payments.id')
            ->where(fn ($query) => $query->whereNull('reviews.id')->orWhereRaw('reviews.id = (select max(latest.id) from customer_refund_payment_reviews as latest where latest.customer_refund_payment_id = payments.id)'))
            ->select('payments.customer_refund_id')->selectRaw("SUM(CASE WHEN reviews.decision = 'verified' THEN payments.amount ELSE 0 END) as verified_amount, SUM(CASE WHEN reviews.decision = 'voided' THEN 0 ELSE payments.amount END) as recorded_amount")
            ->groupBy('payments.customer_refund_id');

        return array_values(DB::table('customer_return_requests as requests')
            ->join('order_items', 'order_items.id', '=', 'requests.order_item_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('customer_returns as returns', 'returns.id', '=', 'requests.customer_return_id')
            ->leftJoin('customer_refunds as refunds', 'refunds.customer_return_id', '=', 'returns.id')
            ->leftJoin('customer_return_resolutions as resolution', 'resolution.customer_return_id', '=', 'returns.id')
            ->leftJoinSub($verified, 'verified', 'verified.customer_refund_id', '=', 'refunds.id')
            ->where('requests.order_id', $order->id)->where('requests.user_id', $order->user_id)
            ->select('requests.*', 'order_items.product_id', 'returns.status as return_status', 'returns.created_at as received_at',
                'refunds.approved_amount', 'verified.verified_amount', 'verified.recorded_amount', 'returns.public_feedback',
                'resolution.type as resolution_type', 'resolution.completed_at as resolution_completed_at')
            ->selectRaw('COALESCE(order_items.product_name_at_time, products.name) as product_name')
            ->orderByDesc('requests.id')->get()
            ->map(fn (stdClass $row): array => $this->present($row, $order, $payments[(int) $row->customer_return_id] ?? []))->all());
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     * @return array<string, mixed>
     */
    private function present(stdClass $row, Order $order, array $payments): array
    {
        $refund = null;
        $status = 'submitted';
        $feedback = null;
        $resolution = null;
        if ($row->withdrawn_at !== null) {
            $status = 'withdrawn';
        } elseif ($row->customer_return_id !== null) {
            $status = match ($row->return_status) {
                'accepted' => 'approved',
                'rejected', 'rejected_pending_handover' => 'rejected',
                default => 'under_review',
            };
            if ($status === 'approved') {
                $feedback = 'Your return has been accepted.';
                $resolution = ['type' => $row->resolution_type ?? 'unknown',
                    'status' => $row->resolution_type === null ? 'unknown' : ($row->resolution_completed_at === null ? 'pending' : 'completed'),
                    'summary' => null,
                    'completed_at' => $row->resolution_completed_at === null ? null : Carbon::parse($row->resolution_completed_at)->toIso8601String()];
                $approved = self::numericAmount((string) ($row->approved_amount ?? '0.00'));
                if (bccomp($approved, '0.00', 2) > 0) {
                    $approved = bcadd($approved, '0.00', 2);
                    $verified = bcadd(self::numericAmount((string) ($row->verified_amount ?? '0.00')), '0.00', 2);
                    $complete = bccomp($verified, $approved, 2) >= 0;
                    $status = $complete ? 'refunded' : 'refund_processing';
                    $refund = ['approved_amount' => $approved, 'verified_paid_amount' => $verified, 'confirmed_paid_amount' => $verified, 'payments' => $payments,
                        'payment_recorded' => bccomp(self::numericAmount((string) ($row->recorded_amount ?? '0.00')), '0.00', 2) > 0,
                        'remaining_amount' => $complete ? '0.00' : bcsub($approved, $verified, 2)];
                    $resolution = ['type' => 'refund', 'status' => $complete ? 'completed' : 'pending', 'summary' => null, 'completed_at' => null];
                } elseif ($row->resolution_type === null) {
                    $feedback = 'Your return was accepted, but its customer resolution was not recorded. Contact Battlefront for confirmation.';
                }
            } elseif ($status === 'rejected') {
                $feedback = $row->return_status === 'rejected_pending_handover'
                    ? 'Your return was not approved. Contact Battlefront about collecting your item.'
                    : 'Your return was not approved. Your item has been handed back.';
            }
        }

        return [
            'id' => (int) $row->id, 'order_id' => $order->id, 'order_reference' => $order->reference,
            'order_item_id' => (int) $row->order_item_id,
            'product' => ['id' => (int) $row->product_id, 'name' => $row->product_name],
            'quantity' => (int) $row->quantity, 'reason_code' => $row->reason_code,
            'reason_label' => OperationalRequestReason::customerReturnOptions()[$row->reason_code], 'reason' => $row->reason,
            'submitted_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'withdrawn_at' => $row->withdrawn_at === null ? null : Carbon::parse($row->withdrawn_at)->toIso8601String(),
            'received_at' => $row->received_at === null ? null : Carbon::parse($row->received_at)->toIso8601String(),
            'status' => ['value' => $status, 'label' => match ($status) {
                'withdrawn' => 'Withdrawn', 'under_review' => 'Item received / Under review',
                'approved' => 'Return accepted', 'rejected' => 'Rejected', 'refund_processing' => 'Refund processing',
                'refunded' => 'Refund completed', default => 'Request submitted / Awaiting item receipt',
            }],
            'can_withdraw' => $status === 'submitted', 'feedback' => $row->public_feedback ?? $feedback, 'refund' => $refund,
            'return_decision' => match ($row->return_status) {
                'accepted' => 'accepted', 'rejected', 'rejected_pending_handover' => 'rejected', default => 'pending',
            },
            'resolution' => $resolution,
            'eligibility_path' => $row->eligibility_path,
            'eligibility_receipt_at' => $row->eligibility_receipt_at === null ? null : Carbon::parse($row->eligibility_receipt_at)->toIso8601String(),
            'eligibility_deadline_at' => $row->eligibility_deadline_at === null ? null : Carbon::parse($row->eligibility_deadline_at)->toIso8601String(),
        ];
    }

    /** @return array<int, list<array<string, mixed>>> */
    public function paymentsForOrder(Order $order): array
    {
        $rows = DB::table('customer_refund_payments as payments')
            ->join('customer_refunds as refunds', 'refunds.id', '=', 'payments.customer_refund_id')
            ->join('customer_returns as returns', 'returns.id', '=', 'refunds.customer_return_id')
            ->join('customer_refund_payment_reviews as reviews', 'reviews.customer_refund_payment_id', '=', 'payments.id')
            ->leftJoin('administration_evidence as evidence', fn ($join) => $join->on('evidence.subject_id', '=', 'payments.id')->where('evidence.subject_type', 'refund_payment')->where('evidence.customer_visible', true))
            ->where('returns.order_id', $order->id)->where('reviews.decision', 'verified')
            ->whereRaw('reviews.id = (select max(latest.id) from customer_refund_payment_reviews as latest where latest.customer_refund_payment_id = payments.id)')
            ->orderBy('payments.id')->get(['payments.id', 'payments.amount', 'payments.payment_method', 'payments.paid_at', 'refunds.customer_return_id',
                'reviews.confirmation_method', 'evidence.id as evidence_id', 'evidence.path as evidence_path']);
        $result = [];
        foreach ($rows as $row) {
            $proof = $row->confirmation_method === 'approver' && $row->evidence_id !== null
                && Storage::disk(AdministrationEvidenceService::diskNameForPath($row->evidence_path))->exists($row->evidence_path)
                ? ['id' => (int) $row->evidence_id, 'url' => route('api.v1.orders.refund-payments.proof.show', ['order' => $order->id, 'payment' => $row->id, 'evidence' => $row->evidence_id], false)] : null;
            $result[(int) $row->customer_return_id][] = ['id' => (int) $row->id, 'amount' => bcadd(self::numericAmount((string) $row->amount), '0.00', 2),
                'payment_method' => $row->payment_method, 'paid_at' => Carbon::parse($row->paid_at)->toIso8601String(),
                'confirmation_method' => $row->confirmation_method === 'approver' ? 'approver' : 'legacy_review', 'proof' => $proof];
        }

        return $result;
    }

    /** @return numeric-string */
    private static function numericAmount(string $amount): string
    {
        if (! is_numeric($amount) || preg_match('/^\d+(?:\.\d+)?$/D', $amount) !== 1) {
            throw new \UnexpectedValueException('A stored refund amount is not a valid decimal.');
        }

        return $amount;
    }
}

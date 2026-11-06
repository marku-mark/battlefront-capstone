<?php

namespace App\Services;

use App\Models\CustomerReturnRequest;
use App\Models\OrderItem;
use App\Models\User;
use App\Repositories\Inventory\CustomerReturnRepository;
use App\Repositories\Inventory\InventoryRepository;
use App\Support\OperationalRequestReason;
use BcMath\Number;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CustomerReturnReviewService
{
    public function __construct(
        private readonly CustomerReturnRepository $returns,
        private readonly InventoryRepository $inventory,
        private readonly AdministrationEvidenceService $evidence,
        private readonly CustomerReturnEligibility $eligibility,
    ) {}

    public function resolveCase(int|string|null $case, int|string|null $paymentId): int|string|null
    {
        if ($paymentId !== null) {
            $returnId = $this->returns->paymentReturnId($paymentId);
            abort_if($returnId === null || ($case !== null && (int) $case !== (int) $returnId), 404);
            $case = (int) $returnId;
        }

        return $case;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function page(User $actor, array $filters, int|string|null $case, int|string|null $requestId, int|string|null $paymentId, ?string $orderLookup, bool $overdue, bool $canReceive, ?int $page = null): array
    {
        $overdueCutoff = now()->startOfDay()->subDays(3);
        $customerRequests = $this->returns->requests($requestId, $page)
            ->through(fn (CustomerReturnRequest $entry): array => [
                'id' => $entry->id, 'customer_name' => $entry->customer->name,
                'order_id' => $entry->order_id, 'order_reference' => $entry->order->reference,
                'product_name' => $entry->item->purchasedProductName(), 'quantity' => $entry->quantity,
                'reason_label' => OperationalRequestReason::customerReturnOptions()[$entry->reason_code],
                'reason' => $entry->reason, 'created_at' => $entry->created_at->toIso8601String(),
                'customer_return_id' => $entry->customer_return_id,
                'can_receive' => $canReceive && $entry->customer_return_id === null && $entry->withdrawn_at === null,
                'withdrawn' => $entry->withdrawn_at !== null,
                'eligibility_path' => $entry->eligibility_path,
                'eligibility_receipt_at' => $entry->eligibility_receipt_at?->toIso8601String(),
                'eligibility_deadline_at' => $entry->eligibility_deadline_at?->toIso8601String(),
            ]);
        $orderId = is_string($orderLookup) && preg_match('/^(?:BF-)?(\d+)$/i', trim($orderLookup), $matches) === 1
            ? (int) $matches[1]
            : null;
        $order = $this->returns->completedOrder($orderId);
        $selectedOrderItems = $order?->items->map(function (OrderItem $item): array {
            return [
                'id' => $item->id,
                'product' => $item->purchasedProductName(),
                'quantity' => $item->quantity,
                'remaining_returnable' => $this->eligibility->remaining($item),
                'price_at_time' => $item->price_at_time,
            ];
        })->all();
        $returns = $this->returns->paginateReturns($filters, $case, $overdue, $overdueCutoff, $page);
        $heldReturnIds = $this->returns->heldReturnIds($returns->getCollection()->pluck('id'));
        $payments = $this->returns->payments($returns->getCollection()->pluck('refund_id')->filter());
        $reviews = $this->returns->reviews($payments->pluck('id'));
        $refundBalances = $payments->groupBy('customer_refund_id')->map(function ($refundPayments) use ($reviews): array {
            $verified = '0.00';
            $awaitingReview = '0.00';
            $disputed = '0.00';

            foreach ($refundPayments as $payment) {
                $decision = $reviews->get($payment->id)?->decision;
                if ($decision === 'verified') {
                    $verified = (new Number(self::numericAmount($verified)))
                        ->add(self::numericAmount($payment->amount), 2)->value;
                } elseif ($decision === 'disputed') {
                    $disputed = (new Number(self::numericAmount($disputed)))
                        ->add(self::numericAmount($payment->amount), 2)->value;
                } elseif ($decision !== 'voided') {
                    $awaitingReview = (new Number(self::numericAmount($awaitingReview)))
                        ->add(self::numericAmount($payment->amount), 2)->value;
                }
            }

            return ['verified' => $verified, 'awaiting_review' => $awaitingReview, 'disputed' => $disputed];
        });
        $photoReturnIds = $this->returns->productPhotos($returns->getCollection()->pluck('id'))
            ->filter(fn ($file): bool => Storage::disk(AdministrationEvidenceService::diskNameForPath($file->path))->exists($file->path))
            ->pluck('subject_id')->flip();
        $returns->getCollection()->transform(function ($return) use ($heldReturnIds, $refundBalances, $overdueCutoff, $actor, $photoReturnIds) {
            $return->has_product_photo = $photoReturnIds->has($return->id);
            $return->can_upload_evidence = $this->evidence->canUpload($actor, 'customer_return', $return);
            $return->held_quantity = in_array($return->status, ['pending', 'rejected_pending_handover'], true)
                || ($return->status === 'accepted' && (($return->disposition === 'supplier_return' && $heldReturnIds->has($return->id))
                    || ($return->disposition === 'return_to_customer' && $return->resolution_type !== null && $return->resolution_completed_at === null)))
                ? $return->quantity : 0;
            $balance = $refundBalances->get($return->refund_id, ['verified' => '0.00', 'awaiting_review' => '0.00', 'disputed' => '0.00']);
            $return->verified_paid_amount = $balance['verified'];
            $return->awaiting_review_amount = $balance['awaiting_review'];
            $return->disputed_amount = $balance['disputed'];
            $approvedAmount = self::numericAmount($return->approved_amount ?? '0.00');
            $return->refundable_item_value = (new Number(self::numericAmount($return->purchased_unit_price)))->mul(self::numericAmount((string) $return->quantity), 2)->value;
            $unrecorded = (new Number($approvedAmount))->sub(self::numericAmount($return->paid_amount ?? '0.00'), 2);
            $return->unrecorded_refund_amount = $unrecorded->compare('0.00') > 0 ? $unrecorded->value : '0.00';
            $return->can_record_payment = $actor->can('returns.record_payment') && $actor->can('returns.approve')
                && (int) $return->refund_approver_id === $actor->id && $unrecorded->compare('0.00') > 0;
            $return->payment_submission_key = $return->can_record_payment ? (string) Str::uuid() : null;
            $refundBalance = (new Number($approvedAmount))
                ->sub(self::numericAmount($balance['verified']), 2);
            $return->refund_balance = $refundBalance->value;
            $return->refund_fully_verified = (new Number($approvedAmount))->compare('0.00') > 0
                && (new Number(self::numericAmount($balance['verified'])))->compare($approvedAmount) >= 0;
            $return->refund_has_verified_payment = (new Number(self::numericAmount($balance['verified'])))->compare('0.00') > 0;
            $return->refund_has_unreviewed_payment = (new Number(self::numericAmount($balance['awaiting_review'])))->compare('0.00') > 0;
            $return->refund_has_disputed_payment = (new Number(self::numericAmount($balance['disputed'])))->compare('0.00') > 0;
            $return->refund_has_outstanding_balance = $refundBalance->compare('0.00') > 0;
            $return->age_days = (int) now()->startOfDay()->diffInDays($return->created_at, true);
            $return->inspection_overdue = in_array($return->status, ['pending', 'rejected_pending_handover'], true)
                && Carbon::parse($return->created_at)->lt($overdueCutoff);

            return $return;
        });

        return [
            'can_receive_returns' => $canReceive,
            'returns' => $returns,
            'customerRequests' => $customerRequests,
            'selected_request' => $requestId === null ? null : (int) $requestId,
            'filters' => ['q' => $filters['q'] ?? null, 'overdue' => $overdue],
            'selected_case' => $case,
            'selected_payment' => $paymentId === null ? null : (int) $paymentId,
            'evidence' => $this->returns->evidence('customer_return', $returns->getCollection()->pluck('id')),
            'paymentEvidence' => $this->returns->evidence('refund_payment', $payments->pluck('id')),
            'refundPayments' => $payments->map(function ($payment) use ($reviews, $actor): array {
                return [
                    'id' => $payment->id,
                    'refund_id' => $payment->customer_refund_id,
                    'recorded_by' => $payment->recorded_by,
                    'recorder_name' => $payment->recorder_name,
                    'can_upload_evidence' => $this->evidence->canUpload($actor, 'refund_payment', $payment, $reviews->get($payment->id)?->decision),
                    'amount' => $payment->amount,
                    'payment_method' => $payment->payment_method,
                    'external_reference' => $payment->external_reference,
                    'paid_at' => $payment->paid_at,
                    'review' => $reviews->get($payment->id),
                ];
            })->groupBy('refund_id'),
            'selected_order' => $order === null ? null : [
                'id' => $order->id,
                'reference' => $order->reference,
                'items' => $selectedOrderItems,
            ],
            'suppliers' => $this->inventory->activeSuppliers(),
        ];
    }

    /**
     * @return numeric-string
     */
    private static function numericAmount(mixed $amount): string
    {
        if (! is_string($amount) && ! is_int($amount)) {
            throw new \UnexpectedValueException('A stored monetary amount is not a decimal string.');
        }

        $decimal = (string) $amount;

        if (! self::isDecimalAmount($decimal)) {
            throw new \UnexpectedValueException('A stored monetary amount is not a valid decimal.');
        }

        return $decimal;
    }

    /**
     * @phpstan-assert-if-true numeric-string $amount
     */
    private static function isDecimalAmount(string $amount): bool
    {
        return preg_match('/^-?\d+(?:\.\d+)?$/D', $amount) === 1;
    }
}

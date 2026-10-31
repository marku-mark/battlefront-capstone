<?php

namespace App\Actions\Chatbot\Context;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use App\Services\Chatbot\ChatbotLanguage;
use App\Services\CustomerReturnRequestPresenter;
use App\Services\OrderCancellationPresenter;
use App\Services\ShipmentPresenter;
use Illuminate\Support\Str;

class ResolveOrderContext
{
    public function __construct(
        private ShipmentPresenter $shipments,
        private OrderCancellationPresenter $cancellations,
        private CustomerReturnRequestPresenter $returns,
        private ChatbotLanguage $language,
    ) {}

    /**
     * Resolve status and, for a referenced payment inquiry, payment facts owned by the supplied customer.
     *
     * @return array{orders: list<array{
     *     reference: string,
     *     created_at: string,
     *     status: array{value: string, label: string},
     *     fulfillment: array{value: string, label: string},
     *     support?: array<string, mixed>,
     *     payment?: array{
     *         method: array{value: string, label: string},
     *         status: array{value: string, label: string}
     *     }
     * }>}
     */
    public function execute(?User $customer, string $message): array
    {
        if (
            ! $customer?->exists
            || ! User::query()
                ->whereKey($customer->getKey())
                ->where('role', UserRole::Customer->value)
                ->exists()
        ) {
            return ['orders' => []];
        }

        $message = $this->language->canonical($message);
        $referenceId = $this->referenceId($message);
        $includePayment = $referenceId !== null && $this->isPaymentInquiry($message);
        $columns = ['id', 'user_id', 'fulfillment_method', 'status', 'created_at', 'payment_method', 'payment_status'];
        $orderQuery = $customer->orders()->select($columns)->with('shipment:id,order_id,status');

        if ($referenceId !== null) {
            $orderQuery->whereKey($referenceId);
        } else {
            $orderQuery
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(5);
        }

        $orders = $orderQuery->get();

        return [
            'orders' => array_values($orders
                ->map(function (Order $order) use ($includePayment, $message, $referenceId): array {
                    $context = [
                        'reference' => $order->reference,
                        'created_at' => $order->created_at->copy()
                            ->timezone(config('battlefront.timezone'))
                            ->format('F j, Y \a\t g:i A (\U\T\CP)'),
                        'status' => [
                            'value' => $order->status->value,
                            'label' => $order->customerStatusLabel(),
                        ],
                        'fulfillment' => [
                            'value' => $order->fulfillment_method->value,
                            'label' => $order->fulfillment_method->label(),
                        ],
                    ];

                    if ($includePayment) {
                        $context['payment'] = [
                            'method' => [
                                'value' => $order->payment_method->value,
                                'label' => $order->payment_method->label(),
                            ],
                            'status' => [
                                'value' => $order->payment_status->value,
                                'label' => $order->paymentStatusLabel(),
                            ],
                        ];
                    }

                    if ($referenceId !== null && preg_match('/\b(?:tracking|track|where|delivery|shipment|cancel|cancellation|return|refund)\b/iu', $message) === 1) {
                        $context['support'] = $this->support($order, $message);
                    }

                    return $context;
                })
                ->all()),
        ];
    }

    /** @return array<string, mixed> */
    private function support(Order $order, string $message): array
    {
        if (preg_match('/\b(?:cancel|cancellation|return|refund)\b/iu', $message) !== 1) {
            $order->load('shipment');
            $shipment = $this->shipments->detail($order);

            return $shipment === null ? ['message' => 'No shipment tracking information is recorded for this order.'] : [
                'shipment_status' => $shipment['status']['label'],
                'tracking_reference' => $shipment['tracking_reference'],
                'estimated_delivery_start' => $shipment['eta']['estimated_delivery_start'],
                'estimated_delivery_end' => $shipment['eta']['estimated_delivery_end'],
                'message' => $shipment['notice'].' '.$shipment['eta']['notice'],
            ];
        }

        $records = [];
        if (preg_match('/\b(?:cancel|cancellation|refund)\b/iu', $message) === 1) {
            $summary = $this->cancellations->summary($order);
            if ($summary !== null) {
                $records[] = ['kind' => 'cancellation', 'label' => 'Cancellation request', 'status' => $summary['status']['label']];
            }
        }
        if (preg_match('/\b(?:return|refund)\b/iu', $message) === 1) {
            foreach ($this->returns->forOrder($order, []) as $request) {
                $records[] = [
                    'kind' => 'return',
                    'product' => $request['product']['name'],
                    'label' => 'Return request '.$request['id'].' ('.$request['product']['name'].')',
                    'status' => $request['status']['label'],
                    'refund' => $request['refund'] === null ? null : [
                        'approved_amount' => $request['refund']['approved_amount'],
                        'confirmed_paid_amount' => $request['refund']['confirmed_paid_amount'],
                        'remaining_amount' => $request['refund']['remaining_amount'],
                    ],
                ];
            }
        }
        if (preg_match('/\breturn request\s*#?(\d+)\b/iu', $message, $reference) === 1) {
            $records = array_values(array_filter($records, fn (array $record): bool => str_starts_with($record['label'], 'Return request '.$reference[1].' (')));
        } elseif (count($records) > 1) {
            $selected = array_values(array_filter($records, fn (array $record): bool => str_contains(Str::lower($message), Str::lower($record['label']))
                || (isset($record['product']) && str_contains(' '.$this->language->normalized($message).' ', ' '.$this->language->normalized($record['product']).' '))));
            if (count($selected) === 1) {
                $records = $selected;
            }
        }

        return [
            'needs_clarification' => count($records) > 1,
            'requests' => $records,
            'message' => $records === [] ? 'No matching cancellation, return or refund request is recorded for this order. Check its order details for available actions.' : '',
        ];
    }

    private function isPaymentInquiry(string $message): bool
    {
        return preg_match('/\b(?:payment|pay|paid|proof|gcash|maya|cash|card|cod)\b/iu', $message) === 1;
    }

    private function referenceId(string $message): ?int
    {
        $normalizedMessage = Str::of($message)
            ->trim()
            ->lower()
            ->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')
            ->squish()
            ->toString();

        if (preg_match('/\bbf\s*0*(\d+)\b/u', $normalizedMessage, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}

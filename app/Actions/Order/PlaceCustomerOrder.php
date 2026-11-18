<?php

namespace App\Actions\Order;

use App\Enums\FulfillmentMethod;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\OrderPlacementService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

class PlaceCustomerOrder
{
    public function __construct(
        private readonly OrderPlacementService $orderPlacementService,
        private readonly StorePaymentProof $storePaymentProof,
        private readonly DeletePaymentProof $deletePaymentProof,
    ) {}

    /**
     * @param  array{direct_purchase?: array{product_id: int, quantity: int}|null, cart_item_ids: list<int>, recipient_name: string, contact_number: string, fulfillment_method: string, delivery_address: string|null, delivery_address_components: array<string, mixed>|null, delivery_destination: string|null, payment_method: string, payment_channel: string|null, payment_bank: string|null, hosted_payment_method: string|null, checkout_submission_key: string|null}  $validated
     */
    public function execute(User $customer, array $validated, ?UploadedFile $paymentProof): Order
    {
        $paymentMethod = PaymentMethod::from($validated['payment_method']);
        $paymentProofPath = $this->storePaymentProof->execute(
            $paymentProof,
            $paymentMethod,
        );

        $checkout = null;

        try {
            $checkout = [
                'cart_item_ids' => $validated['cart_item_ids'],
                'direct_purchase' => $validated['direct_purchase'] ?? null,
                'recipient_name' => $validated['recipient_name'],
                'contact_number' => $validated['contact_number'],
                'fulfillment_method' => FulfillmentMethod::from($validated['fulfillment_method']),
                'delivery_address' => $validated['delivery_address'] ?? null,
                'delivery_address_components' => $validated['delivery_address_components'] ?? null,
                'payment_method' => $paymentMethod,
                'payment_channel' => $validated['payment_channel'] ?? null,
                'payment_bank' => $validated['payment_bank'] ?? null,
                'hosted_payment_method' => $validated['hosted_payment_method'] ?? null,
                'payment_proof_path' => $paymentProofPath,
                'checkout_submission_key' => $validated['checkout_submission_key'] ?? null,
                'checkout_request_fingerprint' => isset($validated['checkout_submission_key'])
                    ? hash('sha256', json_encode([
                        'cart_item_ids' => $validated['cart_item_ids'],
                        'direct_purchase' => $validated['direct_purchase'] ?? null,
                        'recipient_name' => $validated['recipient_name'],
                        'contact_number' => $validated['contact_number'],
                        'fulfillment_method' => $validated['fulfillment_method'],
                        'delivery_address' => $validated['delivery_address'] ?? null,
                        'delivery_destination' => $validated['delivery_destination'] ?? null,
                        'payment_method' => $validated['payment_method'],
                        'payment_channel' => $validated['payment_channel'] ?? null,
                        'payment_bank' => $validated['payment_bank'] ?? null,
                        'hosted_payment_method' => $validated['hosted_payment_method'] ?? null,
                    ], JSON_THROW_ON_ERROR))
                    : null,
            ];

            return $checkout['fulfillment_method'] === FulfillmentMethod::Delivery
                ? $this->orderPlacementService->executeWithDeliveryQuote($customer, $checkout, $validated['delivery_destination'] ?? '')
                : $this->orderPlacementService->execute($customer, $checkout);
        } catch (QueryException $exception) {
            if ($checkout !== null
                && ($validated['checkout_submission_key'] ?? null) !== null
                && str_contains($exception->getMessage(), 'checkout_submission_key')) {
                $existing = $customer->orders()->where('checkout_submission_key', $validated['checkout_submission_key'])->first();
                if ($existing !== null && hash_equals((string) $existing->checkout_request_fingerprint, (string) $checkout['checkout_request_fingerprint'])) {
                    return $existing;
                }
                throw ValidationException::withMessages(['checkout_submission_key' => 'This checkout token was already used with different order details. Refresh checkout to start a new order.']);
            }
            throw $exception;
        } catch (OrderPlacementException $exception) {
            $this->deletePaymentProof->execute($paymentProofPath);

            throw ValidationException::withMessages([
                'cart' => $exception->getMessage(),
            ]);
        } catch (DomainException $exception) {
            $this->deletePaymentProof->execute($paymentProofPath);

            if (! in_array($exception->getMessage(), [
                'Unsupported delivery destination.',
                'Delivery is unavailable for this location.',
            ], strict: true) && ! str_starts_with($exception->getMessage(), 'Shipping quotation required.')) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'delivery_destination' => $exception->getMessage() === 'Unsupported delivery destination.'
                    ? 'Select a supported delivery destination.' : $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            $this->deletePaymentProof->execute($paymentProofPath);

            throw $exception;
        }
    }
}

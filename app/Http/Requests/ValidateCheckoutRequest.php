<?php

namespace App\Http\Requests;

use App\Concerns\ValidatesDeliveryGeography;
use App\Enums\FulfillmentMethod;
use App\Enums\PaymentMethod;
use App\Services\Order\DeliveryRules;
use App\Services\Order\PsgcDeliveryLocations;
use App\Support\PhilippineMobileNumber;
use DomainException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class ValidateCheckoutRequest extends CheckoutPreviewRequest
{
    use ValidatesDeliveryGeography;

    protected function prepareForValidation(): void
    {
        if ($this->filled('delivery_city_code') && ! $this->has('delivery_destination')) {
            $this->merge(['delivery_destination' => $this->input('delivery_city_code')]);
        }
    }

    /**
     * Return the validated checkout fields shared by web and API placement.
     *
     * @return array{cart_item_ids: list<int>, recipient_name: string, contact_number: string, fulfillment_method: string, delivery_address: string|null, delivery_address_components: array<string, mixed>|null, delivery_destination: string|null, payment_method: string, payment_channel: string|null, payment_bank: string|null, hosted_payment_method: string|null, checkout_submission_key: string|null}
     */
    public function checkoutData(): array
    {
        $legacyAddress = $this->validated('delivery_address');
        $deliveryAddress = is_string($legacyAddress) ? $legacyAddress : null;

        if ($this->input('fulfillment_method') === FulfillmentMethod::Delivery->value && $this->usesStructuredAddress()) {
            $deliveryAddress = app(PsgcDeliveryLocations::class)->formatAddress(
                $this->string('delivery_destination')->toString(),
                $this->string('delivery_barangay_code')->toString(),
                $this->string('delivery_address_details')->toString(),
            );
        }

        return [
            'cart_item_ids' => $this->cartItemIds(),
            'recipient_name' => $this->string('recipient_name')->toString(),
            'contact_number' => PhilippineMobileNumber::normalize($this->string('contact_number')->toString()),
            'fulfillment_method' => $this->string('fulfillment_method')->toString(),
            'delivery_address' => $deliveryAddress,
            'delivery_address_components' => $this->input('fulfillment_method') === 'delivery' && $this->usesStructuredAddress()
                ? app(PsgcDeliveryLocations::class)->components($this->string('delivery_destination')->toString(), $this->string('delivery_barangay_code')->toString(), $this->string('delivery_address_details')->toString()) : null,
            'delivery_destination' => $this->filled('delivery_destination')
                ? $this->string('delivery_destination')->toString()
                : null,
            'payment_method' => $this->string('payment_method')->toString(),
            'payment_channel' => $this->input('payment_channel'),
            'payment_bank' => $this->input('payment_bank'),
            'hosted_payment_method' => $this->input('hosted_payment_method'),
            'checkout_submission_key' => $this->input('checkout_submission_key'),
        ];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isDelivery = $this->input('fulfillment_method') === FulfillmentMethod::Delivery->value;
        $isPickup = $this->input('fulfillment_method') === FulfillmentMethod::Pickup->value;
        $usesStructuredAddress = $this->usesStructuredAddress();
        $requiresPaymentProof = in_array(
            $this->input('payment_method'),
            [PaymentMethod::GCash->value, PaymentMethod::Maya->value],
            strict: true,
        );

        return [
            ...parent::rules(),
            ...$this->geographyRules('delivery', $isDelivery && $this->usesNationwideAddress('delivery'), $isPickup),
            'recipient_name' => ['bail', 'required', 'string', 'max:255'],
            'contact_number' => ['bail', 'required', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! PhilippineMobileNumber::isValid($value)) {
                    $fail('Enter a valid Philippine mobile number.');
                }
            }],
            'fulfillment_method' => ['bail', 'required', new Enum(FulfillmentMethod::class)],
            'delivery_destination' => [
                'bail',
                Rule::requiredIf($this->input('fulfillment_method') === FulfillmentMethod::Delivery->value),
                Rule::prohibitedIf($this->input('fulfillment_method') === FulfillmentMethod::Pickup->value),
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || (! preg_match('/\A[0-9]{10}\z/', $value) && ! array_key_exists($value, Config::array('battlefront.delivery.destinations')))) {
                        $fail('Select a supported delivery destination.');
                    }
                },
            ],
            'delivery_address' => [
                'bail',
                Rule::excludeIf($isDelivery && $usesStructuredAddress),
                Rule::requiredIf($isDelivery && ! $usesStructuredAddress),
                Rule::prohibitedIf($isPickup),
                'nullable',
                'string',
                'max:255',
            ],
            'delivery_barangay_code' => [
                'bail',
                Rule::requiredIf($isDelivery && $usesStructuredAddress),
                Rule::prohibitedIf($isPickup),
                'nullable',
                'string',
                'regex:/\A[0-9]{10}\z/',
            ],
            'delivery_address_details' => [
                'bail',
                Rule::requiredIf($isDelivery && $usesStructuredAddress),
                Rule::prohibitedIf($isPickup),
                'nullable',
                'string',
                'max:255',
            ],
            'payment_method' => ['bail', 'required', new Enum(PaymentMethod::class)],
            'checkout_submission_key' => [Rule::requiredIf($this->input('payment_method') === PaymentMethod::Online->value), 'nullable', 'uuid'],
            'payment_channel' => [
                Rule::requiredIf($this->input('payment_method') === PaymentMethod::Online->value),
                Rule::prohibitedIf($this->input('payment_method') !== PaymentMethod::Online->value),
                'nullable',
                Rule::in(['hosted_checkout']),
            ],
            'payment_bank' => [
                'prohibited',
            ],
            'hosted_payment_method' => [
                Rule::prohibitedIf($this->input('payment_method') !== PaymentMethod::Online->value),
                'nullable',
                Rule::in(['card', 'qrph', 'gcash', 'paymaya']),
            ],
            'payment_proof' => [
                'bail',
                Rule::requiredIf($requiresPaymentProof),
                Rule::prohibitedIf(! $requiresPaymentProof),
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'extensions:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ];
    }

    /**
     * Get the validation callbacks for cross-field checkout rules.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('fulfillment_method') !== 'delivery') {
                    return;
                }
                if (! is_string($this->input('delivery_destination'))) {
                    return;
                }
                $this->checkGeography($validator, 'delivery');
                if ($validator->errors()->hasAny(['delivery_destination', 'delivery_city_code', 'delivery_region_code', 'delivery_province_code'])) {
                    return;
                }
                $paymentMethod = PaymentMethod::tryFrom($this->string('payment_method')->toString());
                try {
                    $zone = app(DeliveryRules::class)->zone($this->string('delivery_destination')->toString());
                    if ($paymentMethod !== null
                        && $paymentMethod->isAvailableFor(FulfillmentMethod::Delivery)
                        && ! ($paymentMethod === PaymentMethod::Online
                            ? array_intersect(['gcash', 'maya'], $zone['payment_methods'])
                            : in_array($paymentMethod->value, $zone['payment_methods'], true))) {
                        $validator->errors()->add('payment_method', 'This payment method is unavailable for the selected delivery route.');
                    }
                } catch (DomainException $exception) {
                    $validator->errors()->add('delivery_destination', $exception->getMessage());
                }
            },
            function (Validator $validator): void {
                if ($this->input('fulfillment_method') !== FulfillmentMethod::Delivery->value
                    || ! $this->usesStructuredAddress()
                    || $validator->errors()->hasAny(['delivery_destination', 'delivery_barangay_code', 'delivery_address_details', 'delivery_city_code', 'delivery_region_code', 'delivery_province_code'])) {
                    return;
                }

                $locations = app(PsgcDeliveryLocations::class);
                $destination = $this->string('delivery_destination')->toString();
                $barangayCode = $this->string('delivery_barangay_code')->toString();

                foreach ($locations->addressErrors($destination, $barangayCode, $this->string('delivery_address_details')->toString()) as $field => $message) {
                    $validator->errors()->add('delivery_'.$field, $message);
                }
            },
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['fulfillment_method', 'payment_method'])) {
                    return;
                }

                $fulfillmentMethod = FulfillmentMethod::from($this->string('fulfillment_method')->toString());
                $paymentMethod = PaymentMethod::from($this->string('payment_method')->toString());

                if (config('services.paymongo.enabled') && in_array($paymentMethod, [PaymentMethod::GCash, PaymentMethod::Maya], strict: true)) {
                    $validator->errors()->add('payment_method', 'Select Pay online for new GCash or Maya payments.');

                    return;
                }

                $hostedPaymentMethod = $this->input('hosted_payment_method');
                $paymentChannel = $this->input('payment_channel');

                if ($paymentMethod === PaymentMethod::Online
                    && $paymentChannel === 'hosted_checkout'
                    && ! is_string($hostedPaymentMethod)
                    && config('services.paymongo.hosted_methods', []) === []) {
                    $validator->errors()->add('payment_channel', 'Hosted online payment is not currently available.');

                    return;
                }

                if ($paymentMethod === PaymentMethod::Online
                    && $paymentChannel === 'hosted_checkout'
                    && is_string($hostedPaymentMethod)
                    && ! in_array($hostedPaymentMethod, config('services.paymongo.hosted_methods', []), true)) {
                    $validator->errors()->add('hosted_payment_method', 'This online payment option is not currently available.');

                    return;
                }

                $onlineMethodAvailableForDestination = true;

                if ($paymentMethod === PaymentMethod::Online && $fulfillmentMethod === FulfillmentMethod::Delivery) {
                    if (! is_string($this->input('delivery_destination')) || $validator->errors()->has('delivery_destination')) {
                        return;
                    }

                    try {
                        $onlineMethodAvailableForDestination = (bool) array_intersect(
                            ['gcash', 'maya'],
                            app(DeliveryRules::class)->zone($this->string('delivery_destination')->toString())['payment_methods'],
                        );
                    } catch (DomainException $exception) {
                        $validator->errors()->add('delivery_destination', $exception->getMessage());
                        $onlineMethodAvailableForDestination = false;
                    }
                }

                if (! $paymentMethod->isAvailableFor($fulfillmentMethod)
                    || ($paymentMethod === PaymentMethod::Online && (! config('services.paymongo.enabled') || ! $onlineMethodAvailableForDestination))) {
                    $validator->errors()->add(
                        'payment_method',
                        $fulfillmentMethod === FulfillmentMethod::Pickup
                            ? 'Cash on Delivery (COD) is available for delivery orders only.'
                            : 'Delivery orders must use GCash, Maya, or Cash on Delivery (COD).',
                    );
                }
            },
        ];
    }

    /**
     * Get customer-facing validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'recipient_name.required' => 'Enter the recipient name.',
            'recipient_name.max' => 'The recipient name may not exceed 255 characters.',
            'contact_number.required' => 'Enter a contact number.',
            'contact_number.max' => 'The contact number may not exceed 20 characters.',
            'fulfillment_method.required' => 'Select pickup or delivery.',
            'fulfillment_method.*' => 'Select a valid fulfillment method.',
            'delivery_destination.required' => 'Select a supported delivery destination.',
            'delivery_destination.prohibited' => 'A delivery destination is not used for pickup orders.',
            'delivery_destination.string' => 'Select one supported delivery destination.',
            'delivery_destination.in' => 'Select a supported delivery destination.',
            'delivery_address.required' => 'Enter a delivery address.',
            'delivery_address.prohibited' => 'A delivery address is not used for pickup orders.',
            'delivery_address.max' => 'The delivery address may not exceed 255 characters.',
            'delivery_barangay_code.required' => 'Select a barangay.',
            'delivery_barangay_code.prohibited' => 'A barangay is not used for pickup orders.',
            'delivery_barangay_code.string' => 'Select a valid barangay.',
            'delivery_barangay_code.regex' => 'Select a valid barangay.',
            'delivery_address_details.required' => 'Enter the house, building, street, or landmark details.',
            'delivery_address_details.prohibited' => 'Delivery address details are not used for pickup orders.',
            'delivery_address_details.string' => 'Enter the house, building, street, or landmark details.',
            'delivery_address_details.max' => 'Delivery address details may not exceed 255 characters.',
            'payment_method.required' => 'Select a payment method.',
            'checkout_submission_key.required' => 'Refresh checkout before starting online payment.',
            'checkout_submission_key.uuid' => 'Refresh checkout before starting online payment.',
            'payment_method.*' => 'Select a valid payment method.',
            'payment_proof.required' => 'Upload a screenshot or snapshot of your successful payment.',
            'payment_proof.prohibited' => 'Payment proof is not required for this payment method.',
            'payment_proof.file' => 'The payment proof must be an uploaded file.',
            'payment_proof.image' => 'The payment proof must be an image.',
            'payment_proof.mimes' => 'The payment proof must be a JPEG, PNG, or WebP image.',
            'payment_proof.extensions' => 'The payment proof filename must end in JPG, JPEG, PNG, or WebP.',
            'payment_proof.max' => 'The payment proof may not be larger than 5 MB.',
        ];
    }

    private function usesStructuredAddress(): bool
    {
        return ! $this->routeIs('api.v1.orders.store')
            || $this->hasAny(['delivery_barangay_code', 'delivery_address_details']) || $this->usesNationwideAddress('delivery');
    }
}

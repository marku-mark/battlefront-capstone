<?php

namespace App\Actions\Checkout;

use App\Actions\Cart\ReviewCheckoutCart;
use App\Enums\FulfillmentMethod;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Services\DeliveryQuotePresenter;
use App\Services\Order\CheckoutPaymentOptions;
use App\Services\Order\DeliveryRules;
use App\Services\Order\PsgcDeliveryLocations;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Validation\ValidationException;

class PrepareCheckout
{
    public function __construct(
        private readonly ReviewCheckoutCart $reviewCheckoutCart,
        private readonly DeliveryRules $deliveryRules,
        private readonly DeliveryQuotePresenter $deliveryQuotePresenter,
        private readonly PsgcDeliveryLocations $deliveryLocations,
    ) {}

    /**
     * Build the complete checkout-page payload.
     *
     * @param  list<int>  $cartItemIds
     * @return array<string, mixed>
     */
    public function execute(User $customer, array $cartItemIds): array
    {
        $cart = $this->reviewCheckoutCart->execute($customer, $cartItemIds);
        $products = Product::query()
            ->select(['id', 'shipping_profile', 'package_weight_kg', 'package_length_cm', 'package_width_cm', 'package_height_cm', 'package_measurement_source'])
            ->whereKey(array_column(array_column($cart['items'], 'product'), 'id'))
            ->get();
        $anchor = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $deliveryQuotes = [];

        foreach ($this->deliveryLocations->destinations() as $location) {
            $destination = $location['destination'];
            try {
                $quote = $this->deliveryRules->quote(FulfillmentMethod::Delivery, $destination, $products, $this->quantities($cart['items']));
                $deliveryQuotes[] = $this->deliveryQuotePresenter->checkout($quote, $cart['total'], $anchor);
            } catch (DomainException) {
                // A specific configured exclusion can retire a legacy route.
            }
        }
        $pickupBranch = Branch::query()
            ->select(['name', 'address', 'city', 'contact_number', 'operating_hours'])
            ->operational()
            ->sole();
        $paymentOptions = app(CheckoutPaymentOptions::class);
        $paymongoHostedMethods = $paymentOptions->hostedMethods();

        return [
            'cart' => $cart,
            'deliveryQuotes' => $deliveryQuotes,
            'deliveryLocations' => $this->deliveryLocations->destinations(),
            'deliveryAddressPrefill' => $this->deliveryLocations->prefill(
                $customer->default_delivery_address,
                $customer->default_delivery_address_components,
            ),
            'pickupQuote' => [
                'product_subtotal' => $cart['total'],
                'delivery_fee' => '0.00',
                'total' => $cart['total'],
            ],
            'customer' => [
                'name' => $customer->name,
                'default_delivery_address' => $customer->default_delivery_address,
                'default_delivery_contact_number' => $customer->default_delivery_contact_number,
            ],
            'pickupLocation' => [
                'name' => "{$pickupBranch->name} — {$pickupBranch->city}",
                'address' => $pickupBranch->address,
                'contact_number' => $pickupBranch->contact_number,
                'operating_hours' => $pickupBranch->operating_hours,
            ],
            'fulfillmentMethods' => array_map(
                fn (FulfillmentMethod $method): array => [
                    'value' => $method->value,
                    'label' => $method->label(),
                ],
                FulfillmentMethod::cases(),
            ),
            'paymentMethods' => array_map(
                fn (PaymentMethod $method): array => [
                    'value' => $method->value,
                    'label' => $method->label(),
                    ...($method->isPayAtPickup() ? ['description' => $method->description()] : []),
                    'requires_proof' => $method->requiresPaymentProof(),
                    'payment_account' => $this->paymentAccount($method),
                    'available_for' => array_values(array_map(
                        fn (FulfillmentMethod $fulfillmentMethod): string => $fulfillmentMethod->value,
                        array_filter(
                            FulfillmentMethod::cases(),
                            fn (FulfillmentMethod $fulfillmentMethod): bool => $method->isAvailableFor($fulfillmentMethod),
                        ),
                    )),
                ],
                $paymentOptions->methods(),
            ),
            'paymongoBanks' => [],
            'paymongoHostedMethods' => $paymongoHostedMethods,
        ];
    }

    /** @param list<int> $cartItemIds
     * @return array<string, mixed>
     */
    public function quote(User $customer, array $cartItemIds, string $cityCode): array
    {
        $cart = $this->reviewCheckoutCart->execute($customer, $cartItemIds);
        $products = Product::query()->select(['id', 'shipping_profile', 'package_weight_kg', 'package_length_cm', 'package_width_cm', 'package_height_cm', 'package_measurement_source'])
            ->whereKey(array_column(array_column($cart['items'], 'product'), 'id'))->get();
        try {
            $this->deliveryLocations->city($cityCode);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['delivery_city_code' => $exception->getMessage()]);
        }
        try {
            $quote = $this->deliveryRules->quote(FulfillmentMethod::Delivery, $cityCode, $products, $this->quantities($cart['items']));
        } catch (DomainException $exception) {
            return ['available' => false, 'quote' => null, 'notice' => $exception->getMessage()];
        }

        return ['available' => true, 'quote' => $this->deliveryQuotePresenter->checkout($quote, $cart['total'], CarbonImmutable::now(config('app.timezone'))->startOfDay())];
    }

    /**
     * Get configured receiving-account details for an e-wallet method.
     *
     * @return array{account_name: string, account_number: string, is_demo: bool}|null
     */
    private function paymentAccount(PaymentMethod $method): ?array
    {
        if (! $method->requiresPaymentProof()) {
            return null;
        }

        return [
            'account_name' => (string) config("battlefront.payment_accounts.{$method->value}.account_name"),
            'account_number' => (string) config("battlefront.payment_accounts.{$method->value}.account_number"),
            'is_demo' => (bool) config("battlefront.payment_accounts.{$method->value}.is_demo"),
        ];
    }

    /** @param list<array<string, mixed>> $items
     * @return array<int, int>
     */
    private function quantities(array $items): array
    {
        $quantities = [];
        foreach ($items as $item) {
            $quantities[(int) $item['product']['id']] = (int) $item['quantity'];
        }

        return $quantities;
    }
}

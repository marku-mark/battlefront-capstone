<?php

namespace App\Services\Order;

use App\Enums\PaymentMethod;

class CheckoutPaymentOptions
{
    /** @return list<string> */
    public function hostedMethods(): array
    {
        return config('services.paymongo.enabled')
            ? array_values(array_intersect(['qrph', 'card', 'gcash', 'paymaya'], config('services.paymongo.hosted_methods', [])))
            : [];
    }

    /** @return list<PaymentMethod> */
    public function methods(): array
    {
        return array_values(array_filter(PaymentMethod::cases(), fn (PaymentMethod $method): bool => ($method !== PaymentMethod::Online || $this->hostedMethods() !== [])
            && (! config('services.paymongo.enabled') || ! in_array($method, [PaymentMethod::GCash, PaymentMethod::Maya], strict: true))));
    }
}

<?php

namespace App\Actions\Chatbot\Context;

use App\Enums\FulfillmentMethod;
use App\Enums\PaymentMethod;
use App\Services\Order\CheckoutPaymentOptions;

class ResolveChatbotRuleGuidance
{
    /** @return array{guidance: string} */
    public function execute(string $topic): array
    {
        $methods = fn (FulfillmentMethod $fulfillment): string => implode(', ', array_map(
            fn (PaymentMethod $method): string => $method->label(),
            array_values(array_filter(app(CheckoutPaymentOptions::class)->methods(), fn (PaymentMethod $method): bool => $method->isAvailableFor($fulfillment))),
        ));

        return ['guidance' => match ($topic) {
            'payment_methods' => 'Pickup orders support '.$methods(FulfillmentMethod::Pickup).'. Delivery orders support '.$methods(FulfillmentMethod::Delivery).'. '.$this->onlineGuidance().' Route restrictions still apply; use the options offered at checkout. Direct online banking is not offered for new orders.',
            'payment_proof' => $this->onlineGuidance().' Historical manual GCash and Maya orders retain their uploaded-proof and staff-review workflow. When manual wallet payment is offered at checkout, follow its proof-upload instructions. Cash (Pay at Pickup) and Debit/Credit Card (Pay at Store) require no upload; staff confirm payment and product handover together. COD is delivery-only and requires no upload. Collection after delivery and full remittance reconciliation are separate; collection alone does not complete payment. Check order details for the latest recorded status.',
            'fulfillment' => 'Battlefront supports '.implode(' and ', array_map(fn (FulfillmentMethod $method): string => strtolower($method->label()), FulfillmentMethod::cases())).'. Choose an available fulfillment option during checkout; a delivery address is required for delivery.',
            'delivery_quote' => 'New delivery estimates use regional base fees: Visayas/NIR PHP '.config('battlefront.delivery.regional_estimates.fees.visayas').', Luzon PHP '.config('battlefront.delivery.regional_estimates.fees.luzon').', Mindanao PHP '.config('battlefront.delivery.regional_estimates.fees.mindanao').', plus package-weight charges and the highest handling surcharge once per order. Checkout calculates the final estimate from selected products and destination. Configured local routes retain preparation plus transit-day estimates; nationwide fallback routes require manual arrangements without an automatic ETA or Shipment. Missing regional pricing blocks new delivery quotes. Only historical orders retain saved zero-fee placeholders. These are Battlefront demonstration estimates, not official LBC rates or guaranteed delivery dates.',
            'cancellation' => 'Open your order details and use the available cancellation action. Eligibility depends on current order, payment and shipment state. PayMongo transactions must be reconciled and their payable flow confirmed closed before an unpaid order can be cancelled and stock released; paid, processing or uncertain payments cannot be treated as unpaid. Historical manual-wallet orders with uploaded proof may require a staff cancellation review request; approval is not automatic. Cancellation is not an automatic refund and is separate from returning a received item. The chatbot cannot cancel an order or submit a request.',
            'returns' => 'For an eligible completed order, open its order details to request a return for an item. Submitted requests await physical item receipt and staff review; a request does not guarantee acceptance or a refund. Available quantities and reasons are checked by the application. '.$this->returnWindowGuidance().' The chatbot cannot submit or approve a return.',
            'refunds' => 'Cancellation refunds and refunds from accepted physical returns are separate workflows. Check the relevant request in your order details. Refund completion depends on confirmed refund payments, not merely recorded payments. No processing deadline can be confirmed here, and the chatbot cannot approve or issue a refund.',
            default => '',
        }];
    }

    private function onlineGuidance(): string
    {
        $labels = ['qrph' => 'QR Ph', 'card' => 'online card', 'gcash' => 'GCash', 'paymaya' => 'Maya'];
        $methods = app(CheckoutPaymentOptions::class)->hostedMethods();
        if ($methods === []) {
            return 'PayMongo online payment is not currently offered at checkout.';
        }

        return 'Pay online uses PayMongo Hosted Checkout for '.implode(', ', array_map(fn (string $method): string => $labels[$method], $methods)).'. New online payments require no receipt or screenshot upload. Confirmation comes from authenticated server processing; a browser return is not proof of payment. Use Continue payment in your saved order when available. Development transactions are test mode only.';
    }

    private function returnWindowGuidance(): string
    {
        $days = filter_var(config('battlefront.returns.window_days'), FILTER_VALIDATE_INT);
        if (config('battlefront.returns.window_approved') === true && $days !== false && $days > 0) {
            return 'The approved discretionary return window is '.$days.' days from recorded receipt; eligibility depends on the reason and current order details. Other reasons may require manual review.';
        }

        return 'The proposed discretionary return window is not a confirmed policy; staff review is required.';
    }
}

<?php

namespace App\Actions\Chatbot;

use App\Enums\ChatbotQueryCategory;
use App\Services\Chatbot\ChatbotLanguage;
use Illuminate\Support\Str;

class RenderChatbotFallback
{
    /** @param array<string, mixed> $context */
    public function execute(ChatbotQueryCategory $category, string $message, array $context): ?string
    {
        $message = (new ChatbotLanguage)->canonical($message);

        return match ($category) {
            ChatbotQueryCategory::Faq => $context['guidance'] ?? $this->faq($message, $context['knowledge'] ?? []),
            ChatbotQueryCategory::Store => $this->store($message, $context['branches']),
            ChatbotQueryCategory::Product => $this->product($message, $context['products']),
            ChatbotQueryCategory::Order => $this->order($context['orders']),
            ChatbotQueryCategory::Unsupported => null,
        };
    }

    /** @param list<array<string, mixed>> $records */
    private function faq(string $message, array $records): ?string
    {
        $normalize = fn (string $value): string => Str::of($value)->lower()->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')->squish()->toString();
        $exact = array_values(array_filter($records, fn (array $record): bool => $normalize($record['question_pattern']) === $normalize($message)));
        $candidates = $exact !== [] ? $exact : $records;

        if (count($candidates) > 1) {
            return 'Which question do you mean? '.implode(' / ', array_column($candidates, 'question_pattern'));
        }

        $answer = trim($candidates[0]['response_template'] ?? '');

        return $answer !== '' ? $answer : null;
    }

    /** @param list<array<string, mixed>> $branches */
    private function store(string $message, array $branches): ?string
    {
        if (count($branches) > 1) {
            return 'Which branch do you mean? '.implode(', ', array_column($branches, 'city')).'.';
        }
        if ($branches === []) {
            return null;
        }

        $branch = $branches[0];
        $parts = [];
        if (preg_match('/\b(?:contact|phone|number|email|call|reach)\b/iu', $message)) {
            $parts[] = 'Phone: '.($branch['contact_number'] ?: 'unavailable').'.';
            $parts[] = 'Email: '.($branch['email'] ?: 'unavailable').'.';
        }
        if (preg_match('/\b(?:where|location|address|located)\b/iu', $message)) {
            $parts[] = 'Address: '.($branch['address'] ?: 'unavailable').'.';
        }
        if (preg_match('/\b(?:hours|open|opening|close|closing|schedule)\b/iu', $message)) {
            $parts[] = 'Operating hours: '.($branch['operating_hours'] ?: 'unavailable').'.';
        }

        return $parts === []
            ? 'Please specify whether you need the branch address, contact information, or operating hours.'
            : $branch['city'].': '.implode(' ', $parts);
    }

    /** @param list<array<string, mixed>> $products */
    private function product(string $message, array $products): ?string
    {
        if (count($products) > 1) {
            return $this->productClarification(array_column($products, 'name'));
        }
        if ($products === []) {
            return null;
        }

        $product = $products[0];
        $parts = [$product['name'].'.'];
        if ($product['is_demo']) {
            $parts[] = $product['demo_notice'];
        }
        $price = preg_match('/\b(?:price|prices|pricing|cost|much)\b/iu', $message) === 1;
        $stock = preg_match('/\b(?:stock|stocks|available|availability|have|sell)\b/iu', $message) === 1;
        if ($price) {
            $parts[] = 'Listed price: PHP '.number_format((float) $product['price'], 2).'.';
            if ($product['discount_price'] !== null) {
                $parts[] = 'Discount price: PHP '.number_format((float) $product['discount_price'], 2).'.';
            }
        }
        if ($stock) {
            $parts[] = match ($product['inventory']['status']) {
                'in_stock', 'low_stock' => 'Sagay stock: '.$product['inventory']['quantity'].' available.',
                'out_of_stock' => 'Currently out of stock at Sagay.',
                default => 'Sagay stock information is unavailable.',
            };
        }
        if (! $price && ! $stock) {
            $parts[] = preg_match('/\b(?:specs|specifications|details|description)\b/iu', $message) === 1
                ? 'Product details: '.($product['description'] ?: 'unavailable').'.'
                : 'Please specify whether you need the product price or Sagay stock availability.';
        }

        return implode(' ', $parts);
    }

    /** @param list<string> $names */
    public function productClarification(array $names): string
    {
        return 'Which product do you mean? Examples: '.implode('; ', array_slice($names, 0, 3)).'. Please reply with one product name or model, or specify a brand.';
    }

    /** @param list<array<string, mixed>> $orders */
    private function order(array $orders): string
    {
        if (count($orders) !== 1) {
            return 'Which order do you mean? Please provide its BF order reference from your order history.';
        }

        $order = $orders[0];
        $support = $order['support'] ?? [];
        if ($support['needs_clarification'] ?? false) {
            return 'Which request do you mean? '.implode(' / ', array_column($support['requests'], 'label')).'. Please include the request label and BF order reference.';
        }
        $answer = $order['reference'].': Order status: '.$order['status']['label'].'. Fulfillment: '.$order['fulfillment']['label'].'.';
        if (isset($order['payment'])) {
            $answer .= ' Payment method: '.$order['payment']['method']['label'].'. Payment status: '.$order['payment']['status']['label'].'.';
        }

        if ($support !== []) {
            if (isset($support['shipment_status'])) {
                $answer .= ' Shipment status: '.$support['shipment_status'].'. Tracking reference: '.($support['tracking_reference'] ?? 'unavailable').'.';
                $answer .= isset($support['estimated_delivery_start'], $support['estimated_delivery_end'])
                    ? ' Estimated delivery: '.$support['estimated_delivery_start'].' to '.$support['estimated_delivery_end'].'.'
                    : ' Delivery ETA is unavailable.';
            }
            foreach ($support['requests'] ?? [] as $request) {
                $answer .= ' '.$request['label'].': '.$request['status'].'.';
                if (isset($request['refund'])) {
                    $answer .= ' Approved refund: PHP '.$request['refund']['approved_amount'].'. Confirmed paid: PHP '.$request['refund']['confirmed_paid_amount'].'. Remaining: PHP '.$request['refund']['remaining_amount'].'.';
                }
            }
            $answer .= ' '.($support['message'] ?? '');
        }

        return trim($answer);
    }
}

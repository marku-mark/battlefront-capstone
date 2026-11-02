<?php

namespace App\Services\Chatbot;

use App\Actions\Chatbot\ClassifyChatbotInquiry;
use App\Models\User;

class ChatbotPresentation
{
    /** @param array<string, mixed> $result
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function build(array $result, array $context, string $question, ?User $customer, string $language): array
    {
        $presentation = $result['presentation'] ?? [
            'intro' => $result['message'], 'products' => [], 'follow_up' => '', 'query' => '',
        ];
        $presentation['actions'] = [];
        $presentation['choices'] = [];
        $presentation['facts'] = [];
        if (app(ClassifyChatbotInquiry::class)->containsSensitiveInput($question)) {
            return $presentation;
        }
        if (count($context['products'] ?? []) === 1 && $presentation['products'] === []) {
            $product = $context['products'][0];
            $attributes = app(ChatbotLanguage::class)->interpret($question)['attributes'];
            if (($attributes === [] || in_array('price', $attributes, true)) && is_numeric($product['price'] ?? null)) {
                $presentation['facts'][] = ['label' => $product['name'], 'value' => 'PHP '.number_format((float) ($product['discount_price'] ?? $product['price']), 2)];
            }
            if ($attributes === [] || in_array('stock', $attributes, true)) {
                $status = match ($product['inventory']['status'] ?? null) {
                    'in_stock' => 'In stock at Sagay', 'low_stock' => 'Low stock at Sagay',
                    'out_of_stock' => 'Out of stock at Sagay', default => 'Stock is unconfirmed',
                };
                $presentation['facts'][] = ['label' => $product['name'], 'value' => app(ChatbotLocalizedResponse::class)->text($status, $language)];
            }
            if (isset($product['demo_notice'])) {
                $presentation['facts'][] = ['label' => $product['name'], 'value' => $product['demo_notice']];
            }
        }
        if ($presentation['products'] === []) {
            foreach (array_slice($context['products'] ?? [], 0, 6) as $product) {
                if (isset($product['id'])) {
                    $presentation['actions'][] = ['kind' => 'product', 'id' => $product['id'], 'label' => $product['name']];
                }
            }
        }
        $kind = match ($result['category']->value) {
            'product' => 'catalog',
            'store' => 'branches',
            'order' => $customer === null ? 'login' : 'orders',
            default => preg_match('/\bpc builder\b/iu', $question) === 1 ? 'builder'
                : (preg_match('/\b(?:products?|catalog|search|filter)\b/iu', $question) === 1 ? 'catalog'
                : (preg_match('/\b(?:order|payment|refund|return|cancel|delivery)\b/iu', app(ChatbotLanguage::class)->canonical($question)) === 1
                    ? ($customer === null ? 'login' : 'orders') : null)),
        };
        if ($kind !== null) {
            $catalogQuery = $context['search_query'] ?? $context['products'][0]['category'] ?? preg_replace('/\b(?:what|is|the|price|prices|cost|stock|availability|available|of|do|you|have|please|show|me)\b/iu', ' ', app(ChatbotLanguage::class)->canonical($question));
            $presentation['actions'][] = ['kind' => $kind, 'label' => $this->label($kind, $language),
                'query' => $kind === 'catalog' && $result['category']->value === 'product' ? mb_substr(trim($catalogQuery ?? '', " \t\n\r\0\x0B?.!"), 0, 200) : ''];
        }
        if ($result['category']->value === 'product' && count($context['products'] ?? []) > 1
            && str_contains($result['message'], 'Which product')) {
            foreach (array_slice($context['products'], 0, 3) as $product) {
                $presentation['choices'][] = ['label' => $product['name'], 'question' => $product['name']];
            }
        }
        if (count($context['knowledge'] ?? []) > 1) {
            foreach (array_slice($context['knowledge'], 0, 3) as $faq) {
                $presentation['choices'][] = ['label' => $faq['question_pattern'], 'question' => $faq['question_pattern']];
            }
        }
        if (count($context['branches'] ?? []) > 1) {
            foreach (array_slice($context['branches'], 0, 5) as $branch) {
                $presentation['choices'][] = ['label' => $branch['city'], 'question' => $question.' at '.$branch['city'].' branch'];
            }
        }
        $presentation['facts'] = array_values(array_filter($presentation['facts'], fn (array $fact): bool => ! str_contains($result['message'], $fact['value'])));

        return $presentation;
    }

    public function label(string $kind, string $language): string
    {
        $labels = [
            'catalog' => ['Browse products', 'Tan-awa ang mga produkto', 'Tingnan ang mga produkto', 'Tan-awa ang mga produkto'],
            'branches' => ['View branches', 'Tan-awa ang mga branch', 'Tingnan ang mga branch', 'Tan-awa ang mga branch'],
            'orders' => ['View your orders', 'Tan-awa ang imong mga order', 'Tingnan ang iyong mga order', 'Tan-awa ang imo mga order'],
            'login' => ['Sign in', 'Sign in', 'Mag-sign in', 'Mag-sign in'],
            'builder' => ['Open PC Builder', 'Ablihi ang PC Builder', 'Buksan ang PC Builder', 'Abrihi ang PC Builder'],
        ];

        return $labels[$kind][array_search($language, ['en', 'ceb', 'tl', 'hil'], true) ?: 0] ?? $kind;
    }
}

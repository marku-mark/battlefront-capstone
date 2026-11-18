<?php

namespace App\Services\Chatbot;

use App\Actions\Chatbot\ClassifyChatbotInquiry;
use App\Models\User;
use Illuminate\Support\Str;

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
        $presentation['clarification'] = isset($context['clarification']) || count($context['knowledge'] ?? []) > 1;
        if (app(ClassifyChatbotInquiry::class)->containsSensitiveInput($question)) {
            return $presentation;
        }
        $preserveAnswer = isset($context['compatibility']) || isset($context['clarification'])
            || str_contains($result['message'], 'Which product')
            || preg_match('/\b(?:compare|comparison|versus|vs|recommend|suggest|compatible|compatibility|work with|fit with|help me choose|should i buy|which should i get|best for)\b/iu', app(ChatbotLanguage::class)->canonical($question)) === 1;
        if ($result['category']->value === 'product' && ($context['products'] ?? []) !== [] && ! $preserveAnswer) {
            $products = array_values(array_slice($context['products'], 0, 6));
            $intro = $this->withoutProductFields($presentation['intro'], $products);
            foreach (($result['source'] ?? 'fallback') === 'gemini' ? [] : $products as $product) {
                $position = mb_stripos($intro, $product['name']);
                if ($position !== false) {
                    $intro = mb_substr($intro, 0, $position);
                }
            }
            $intro = preg_replace('/(?:^|\R)\s*(?:[-*•]|\d+[.)])\s*$/u', '', $intro) ?? $intro;
            $presentation['intro'] = trim($intro) !== '' ? trim($intro) : match ($language) {
                'tl' => 'Narito po ang mga produktong tugma sa hinahanap ninyo.',
                'ceb' => 'Ania ang mga produkto nga mohaom sa imong gipangita.',
                'hil' => 'Ari ang mga produkto nga nagaigo sa imo ginapangita.',
                default => 'Here are the products matching your request.',
            };
            $presentation['products'] = array_map(function (array $product) use ($language): array {
                $quantity = $product['inventory']['quantity'] ?? null;
                $status = match ($product['inventory']['status'] ?? null) {
                    'in_stock' => 'In stock at Sagay',
                    'low_stock' => 'Low stock at Sagay',
                    'out_of_stock' => 'Out of stock at Sagay',
                    default => 'Stock is unconfirmed',
                };
                $stock = app(ChatbotLocalizedResponse::class)->text($status, $language);
                if (is_int($quantity) && $quantity >= 0) {
                    $stock .= match ($language) {
                        'tl' => " — {$quantity} ang available",
                        'ceb' => " — {$quantity} ang available",
                        'hil' => " — {$quantity} ang available",
                        default => " — {$quantity} available",
                    };
                }

                return [
                    'id' => $product['id'],
                    'name' => $product['name'],
                    'price' => $product['discount_price'] ?? $product['price'] ?? null,
                    'stock' => $stock,
                    'labels' => match ($language) {
                        'tl' => ['name' => 'Pangalan ng produkto', 'price' => 'Presyo', 'stock' => 'Katayuan ng stock', 'view' => 'Tingnan ang produkto'],
                        'ceb' => ['name' => 'Ngalan sa produkto', 'price' => 'Presyo', 'stock' => 'Kahimtang sa stock', 'view' => 'Tan-awa ang produkto'],
                        'hil' => ['name' => 'Ngalan sang produkto', 'price' => 'Presyo', 'stock' => 'Kahimtangan sang stock', 'view' => 'Tan-awa ang produkto'],
                        default => ['name' => 'Product name', 'price' => 'Price', 'stock' => 'Availability', 'view' => 'View Product'],
                    },
                    'notice' => $product['demo_notice'] ?? null,
                ];
            }, $products);
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
        if (($context['products'] ?? []) !== []) {
            foreach (array_slice($context['products'] ?? [], 0, 6) as $product) {
                if (isset($product['id'])) {
                    $presentation['actions'][] = ['kind' => 'product', 'id' => $product['id'], 'label' => $product['name']];
                }
            }
        }
        $kind = match ($result['category']->value) {
            'unsupported' => 'catalog',
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
        $filters = $context['selection_constraints'] ?? [];
        if ($filters !== []) {
            $values = array_values(array_filter([$filters['brand'] ?? '', $filters['capacity'] ?? '', $filters['use_case'] ?? '', $filters['branch'] ?? '']));
            foreach (['minimum' => ['above', 'at least'], 'maximum' => ['under', 'up to']] as $bound => [$strict, $inclusive]) {
                if (isset($filters['price'][$bound])) {
                    $values[] = app(ChatbotLocalizedResponse::class)->text(($filters['price'][$bound.'_inclusive'] ?? true) ? $inclusive : $strict, $language)
                        .' PHP '.number_format((float) $filters['price'][$bound], 2);
                }
            }
            if (($filters['excluded_brands'] ?? []) !== []) {
                $values[] = app(ChatbotLocalizedResponse::class)->text('Excluding', $language).' '.implode(', ', $filters['excluded_brands']);
            }
            if ($values !== []) {
                $presentation['facts'][] = ['label' => app(ChatbotLocalizedResponse::class)->text('Your filters', $language), 'value' => implode(' · ', $values)];
            }
            $brandRecovery = ! array_key_exists('recovery_options', $context) || in_array('brand', $context['recovery_options'], true);
            $budgetRecovery = ! array_key_exists('recovery_options', $context) || in_array('budget', $context['recovery_options'], true);
            if ($brandRecovery && (($filters['brand'] ?? '') !== '' || ($filters['excluded_brands'] ?? []) !== [])) {
                $presentation['choices'][] = ['label' => app(ChatbotLocalizedResponse::class)->text('Any brand', $language), 'question' => app(ChatbotLocalizedResponse::class)->text('Any brand', $language)];
            }
            if ($budgetRecovery && (isset($filters['price']['minimum']) || isset($filters['price']['maximum']))) {
                $presentation['choices'][] = ['label' => app(ChatbotLocalizedResponse::class)->text('Any price', $language), 'question' => app(ChatbotLocalizedResponse::class)->text('Any price', $language)];
            }
            if (array_key_exists('recovery_options', $context)) {
                $presentation['actions'][] = ['kind' => 'branches', 'label' => $this->label('branches', $language)];
            }
        }
        $presentation['facts'] = array_values(array_filter($presentation['facts'], fn (array $fact): bool => ! str_contains($result['message'], $fact['value'])));

        return $presentation;
    }

    /** @param list<array<string, mixed>> $products */
    public function withoutProductFields(string $text, array $products): string
    {
        $names = array_map(fn (string $name): string => Str::lower(Str::squish($name)), array_column($products, 'name'));
        $lines = [];
        $inProduct = false;
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $plain = trim(str_replace('**', '', $line));
            if (preg_match('/^(?:[-*•]|\d+[.)])?\s*(?:Product name|Pangalan ng produkto|Ngalan sa produkto|Ngalan sang produkto)\s*:\s*(.+)$/iu', $plain, $match) === 1
                && in_array(Str::lower(Str::squish($match[1])), $names, true)) {
                $inProduct = true;

                continue;
            }
            if ($inProduct && ($plain === ''
                || preg_match('/^(?:[-*•]|\d+[.)])?\s*(?:Price|Presyo|Availability|Katayuan ng stock|Kahimtang sa stock|Kahimtangan sang stock|Link)\s*:/iu', $plain) === 1)) {
                continue;
            }
            if ($inProduct) {
                $lines[] = '';
                $inProduct = false;
            }
            $lines[] = $line;
        }

        $paragraphs = preg_split('/\R\s*\R/u', trim(implode("\n", $lines))) ?: [];

        $paragraphs = array_map(function (string $paragraph) use ($names): string {
            if ($this->isProductFactListing($paragraph, $names)) {
                return '';
            }
            $first = null;
            foreach ($names as $name) {
                if (preg_match($this->productNamePattern($name), $paragraph, $match, PREG_OFFSET_CAPTURE) === 1) {
                    $first = $first === null ? $match[0][1] : min($first, $match[0][1]);
                }
            }
            if ($first !== null && $this->isProductFactListing(substr($paragraph, $first), $names)) {
                return trim(substr($paragraph, 0, $first));
            }

            return $paragraph;
        }, $paragraphs);

        return trim(implode("\n\n", array_filter($paragraphs, fn (string $paragraph): bool => $paragraph !== '')));
    }

    private function productNamePattern(string $name): string
    {
        $name = preg_replace('/["\'“”‘’]+$/u', '', $name) ?? $name;

        return '/'.implode('\s+', array_map(fn (string $word): string => preg_quote($word, '/'), explode(' ', $name))).'["\'“”‘’]?/iu';
    }

    /** @param list<string> $names */
    private function isProductFactListing(string $text, array $names): bool
    {
        $matched = false;
        foreach ($names as $name) {
            $pattern = $this->productNamePattern($name);
            if ($name !== '' && preg_match($pattern, $text) === 1) {
                $text = preg_replace($pattern, ' ', $text) ?? $text;
                $matched = true;
            }
        }
        if (! $matched || preg_match('/(?:PHP|₱|\b(?:stock|available|presyo)\b)/iu', $text) !== 1) {
            return false;
        }
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($text), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $factWords = ['php', 'is', 'are', 'the', 'and', 'at', 'in', 'stock', 'out', 'of', 'low', 'sagay', 'city',
            'price', 'priced', 'cost', 'costs', 'listed', 'selling', 'current', 'with', 'has', 'have', 'units', 'unit',
            'available', 'availability', 'quantity', 'only', 'remaining', 'left', 'ang', 'sa', 'sang', 'ng', 'ay', 'kay',
            'amo', 'presyo', 'kantidad', 'nagkantidad', 'may', 'ara', 'yara', 'mayroong', 'meron', 'naa', 'aduna', 'adunay', 'ka', 'piraso'];

        return $words !== [] && array_diff($words, $factWords) === [];
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

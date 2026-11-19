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
            'browse_label' => $this->label('catalog', $language),
        ];
        $presentation['actions'] = [];
        $presentation['choices'] = [];
        $presentation['facts'] = [];
        $presentation['clarification'] = isset($context['clarification']) || count($context['knowledge'] ?? []) > 1;
        if (app(ClassifyChatbotInquiry::class)->containsSensitiveInput($question)) {
            return $presentation;
        }
        if ($result['category']->value === 'product' && ($context['products'] ?? []) !== [] && ! $presentation['clarification']
            && ! str_contains($result['message'], 'Which product')) {
            $products = collect($context['products'])->unique('id')->take(6)->values()->all();
            $intro = $this->withoutProductFields($presentation['intro'], $products, $language);
            $intro = preg_replace('/(?:^|\R)\s*(?:[-*•]|\d+[.)])\s*$/u', '', $intro) ?? $intro;
            $constraints = $context['selection_constraints'] ?? [];
            $brandCleared = ($result['source'] ?? 'fallback') === 'fallback' && $constraints !== []
                && ($constraints['brand'] ?? '') === '' && ($constraints['excluded_brands'] ?? []) === []
                && preg_match('/^\s*any brand[.!?]?\s*$/iu', app(ChatbotLanguage::class)->canonical($question)) === 1;
            $presentation['intro'] = $brandCleared ? $this->catalogOpening($language, anyBrand: true)
                : (trim($intro) !== '' ? trim($intro) : $this->catalogOpening($language));
            $presentation['products'] = array_map(function (array $product) use ($language): array {
                $quantity = $product['inventory']['quantity'] ?? null;
                $service = ($product['category'] ?? null) === 'Service';
                $status = match ($product['inventory']['status'] ?? null) {
                    'in_stock' => $service ? 'Available at Sagay' : 'In stock at Sagay',
                    'low_stock' => $service ? 'Available at Sagay' : 'Low stock at Sagay',
                    'out_of_stock' => $service ? 'Unavailable at Sagay' : 'Out of stock at Sagay',
                    default => 'Stock is unconfirmed',
                };
                $stock = app(ChatbotLocalizedResponse::class)->text($status, $language);
                if (! $service && is_int($quantity) && $quantity >= 0) {
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
                    'unavailable_price' => $this->unavailablePrice($language),
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
        if (count($context['products'] ?? []) === 1 && $presentation['products'] === [] && ! $presentation['clarification']) {
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
        if (! $presentation['clarification'] && ($context['products'] ?? []) !== []) {
            foreach (array_slice($context['products'] ?? [], 0, 6) as $product) {
                if (isset($product['id'])) {
                    $presentation['actions'][] = ['kind' => 'product', 'id' => $product['id'], 'label' => $product['name'], 'view_label' => $this->label('product', $language)];
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
        if ($result['category']->value === 'faq' && in_array(app(ClassifyChatbotInquiry::class)->execute($question)['rule_topic'], ['payment_methods', 'delivery_quote'], true)) {
            $kind = null;
        }
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
            $brandOnly = ($filters['category_key'] ?? null) === 'other' && ($filters['brand'] ?? '') !== ''
                && in_array(mb_strtolower(trim($filters['search_query'] ?? '')), ['', mb_strtolower($filters['brand'])], true);
            $budgetRecovery = ! array_key_exists('recovery_options', $context) || in_array('budget', $context['recovery_options'], true);
            if ($brandRecovery && ! $brandOnly && (($filters['brand'] ?? '') !== '' || ($filters['excluded_brands'] ?? []) !== [])) {
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
    public function withoutProductFields(string $text, array $products, string $language): string
    {
        $names = array_map(fn (string $name): string => Str::lower(Str::squish($name)), array_column($products, 'name'));
        $notices = [...array_column($products, 'demo_notice'), ...array_column($products, 'notice')];
        $lines = [];
        $inProduct = false;
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $plain = trim(str_replace('**', '', $line));
            if ($plain !== '' && in_array($plain, $notices, true)) {
                continue;
            }
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

        $paragraphs = array_map(function (string $paragraph) use ($names, $language): string {
            $paragraph = $this->condenseAvailabilityList($paragraph, $names, $language);
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
                $opening = trim(substr($paragraph, 0, $first));

                return preg_replace('/(?:^|(?<=[.!?])\s+)(?:Mao kini ang|Mao ni ang|These are|They are)\s*$/iu', '', $opening) ?? $opening;
            }

            return $paragraph;
        }, $paragraphs);

        return trim(implode("\n\n", array_filter($paragraphs, fn (string $paragraph): bool => $paragraph !== '')));
    }

    /** @param list<string> $names */
    private function condenseAvailabilityList(string $paragraph, array $names, string $language): string
    {
        $names = array_values(array_filter($names, fn (string $name): bool => $name !== ''));
        if ($names === []) {
            return $paragraph;
        }

        $name = '(?:\*\*)?(?:'.implode('|', array_map($this->productNameExpression(...), $names)).')(?:\*\*)?';
        $price = '(?:(?:PHP|₱)\s*|(?:price|presyo)\s*:\s*(?:(?:PHP|₱)\s*)?)[\d,]+(?:\.\d{1,2})?';
        $entry = $name.'(?:\s*\('.$price.'\))?';
        $join = '(?:\s*[,;]\s*(?:(?:and|kag|ug|og|at)\s+)?|\s+(?:and|kag|ug|og|at)\s+)';
        $list = $entry.'(?:'.$join.$entry.')*';
        $lead = '(?:(?:yes|oo|huo),?\s+)?(?:we\s+(?:have|stock)|may\s+ara\s+kami(?:\s+sang)?|(?:naa|aduna)\s+(?:kami|mi)(?:\s+(?:nga|og|ug))?|(?:meron|mayroon)(?:\s+po)?\s+kaming|may\s+stock)';
        $location = '(?:available\s+|in stock\s+)?(?:at|in|sa|diri sa|dinhi sa|dito sa)\s+(?:(?:the|among|aming|amon)\s+)?(?:branch sa\s+)?Sagay(?:\s+(?:City|branch))?';
        $listLead = '(?:'.$lead.'|here\s+(?:are|is)|narito|ania|ari)\b[^.!?\r\n:]*:';
        $pattern = '/^(?:(?<intro>'.$listLead.')\s*'.$list.'|'
            .'(?<stock_intro>'.$lead.')\s+'.$list.'(?:\s+(?<stock>'.$location.'|in stock))?)(?:[.!](?=\s|$)|$)/iu';

        return preg_replace_callback($pattern, function (array $match) use ($language, $names): string {
            if (($match['intro'] ?? '') !== '') {
                return $match['intro'];
            }

            if (! isset($match['stock'])) {
                return $this->catalogOpening($language);
            }

            $opening = 'These matching products are available at the Sagay branch.';
            $localized = app(ChatbotLocalizedResponse::class)->text($opening, $language);
            if (count($names) === 1 || ($language !== 'en' && $localized === $opening)) {
                $lead = preg_replace('/\s+(?:sang|nga|og|ug)$/iu', '', $match['stock_intro']) ?? $match['stock_intro'];

                return $lead.' '.$match['stock'].'.';
            }

            return $localized;
        }, $paragraph, 1) ?? $paragraph;
    }

    private function catalogOpening(string $language, bool $anyBrand = false): string
    {
        if ($anyBrand) {
            return match ($language) {
                'tl' => 'Narito po ang mga tugmang produkto mula sa anumang brand.',
                'ceb' => 'Ania ang mga produkto nga mohaom sa imong gipangita, bisan unsang brand.',
                'hil' => 'Ari ang mga produkto nga nagaigo sa imo ginapangita, bisan ano nga brand.',
                default => 'Here are the products matching your request across brands.',
            };
        }

        return match ($language) {
            'tl' => 'Narito po ang mga produktong tugma sa hinahanap ninyo.',
            'ceb' => 'Ania ang mga produkto nga mohaom sa imong gipangita.',
            'hil' => 'Ari ang mga produkto nga nagaigo sa imo ginapangita.',
            default => 'Here are the products matching your request.',
        };
    }

    private function productNameExpression(string $name): string
    {
        $name = preg_replace('/["\'“”‘’]+$/u', '', $name) ?? $name;

        return implode('\s+', array_map(fn (string $word): string => preg_quote($word, '/'), explode(' ', $name))).'["\'“”‘’]?';
    }

    private function productNamePattern(string $name): string
    {
        return '/'.$this->productNameExpression($name).'/iu';
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
        if ($words === ['stock', 'details']) {
            return true;
        }
        $factWords = ['php', 'is', 'are', 'the', 'and', 'at', 'in', 'stock', 'out', 'of', 'low', 'sagay', 'city',
            'price', 'priced', 'cost', 'costs', 'listed', 'selling', 'current', 'currently', 'discount', 'with', 'has', 'have', 'units', 'unit',
            'available', 'availability', 'quantity', 'only', 'remaining', 'left', 'ang', 'sa', 'sang', 'ng', 'ay', 'kay',
            'amo', 'presyo', 'kantidad', 'nagkantidad', 'may', 'ara', 'yara', 'mayroong', 'meron', 'naa', 'aduna', 'adunay', 'ka', 'piraso',
            'nga', 'tag', 'ug', 'og', 'kag', 'at', 'na', 'lang', 'nabilin', 'kadaghanon', 'kadamuon', 'bilang',
            'yes', 'no', 'oo', 'huo', 'wala', 'walang'];

        return $words !== [] && array_diff($words, $factWords) === [];
    }

    public function label(string $kind, string $language): string
    {
        $labels = [
            'product' => ['View Product', 'Tan-awa ang produkto', 'Tingnan ang produkto', 'Tan-awa ang produkto'],
            'catalog' => ['Browse products', 'Tan-awa ang mga produkto', 'Tingnan ang mga produkto', 'Tan-awa ang mga produkto'],
            'branches' => ['View branches', 'Tan-awa ang mga branch', 'Tingnan ang mga branch', 'Tan-awa ang mga branch'],
            'orders' => ['View your orders', 'Tan-awa ang imong mga order', 'Tingnan ang iyong mga order', 'Tan-awa ang imo mga order'],
            'login' => ['Sign in', 'Sign in', 'Mag-sign in', 'Mag-sign in'],
            'builder' => ['Open PC Builder', 'Ablihi ang PC Builder', 'Buksan ang PC Builder', 'Abrihi ang PC Builder'],
        ];

        return $labels[$kind][array_search($language, ['en', 'ceb', 'tl', 'hil'], true) ?: 0] ?? $kind;
    }

    private function unavailablePrice(string $language): string
    {
        return match ($language) {
            'ceb' => 'Walay kumpirmadong presyo',
            'tl' => 'Walang kumpirmadong presyo',
            'hil' => 'Wala sing kumpirmado nga presyo',
            default => 'Price unavailable',
        };
    }

    /** @param array<string, mixed> $presentation */
    public function plainText(array $presentation, string $fallback): string
    {
        $sections = $presentation['sections'] ?? [$presentation];
        if (! array_filter($sections, fn (array $section): bool => ($section['products'] ?? []) !== [])) {
            return $fallback;
        }

        $blocks = array_map(function (array $section): string {
            $facts = array_map(fn (array $fact): string => $fact['label'].': '.$fact['value'], $section['facts'] ?? []);
            $blocks = array_map(function (array $product): string {
                $price = is_numeric($product['price']) ? 'PHP '.number_format((float) $product['price'], 2) : $product['unavailable_price'];

                return $product['labels']['name'].': '.$product['name']."\n"
                    .$product['labels']['price'].': '.$price."\n"
                    .$product['labels']['stock'].': '.$product['stock']."\n"
                    .'Link: '.$product['labels']['view'].' '.route('products.show', $product['id'], absolute: false)
                    .(! empty($product['notice']) ? "\n".$product['notice'] : '');
            }, $section['products'] ?? []);

            return implode("\n\n", array_filter([
                isset($section['heading']) ? $section['heading'].':' : '',
                $section['intro'] ?? '',
                $facts === [] ? '' : implode("\n", $facts),
                ...$blocks,
            ], fn (string $block): bool => trim($block) !== ''));
        }, $sections);

        $blocks[] = $presentation['follow_up'] ?? '';

        return implode("\n\n", array_filter($blocks, fn (string $block): bool => trim($block) !== ''));
    }
}

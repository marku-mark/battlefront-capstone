<?php

namespace App\Services\Chatbot;

use App\Actions\Chatbot\ClassifyChatbotInquiry;
use App\Enums\ChatbotQueryCategory;
use App\Models\Product;
use App\Repositories\Catalog\CatalogSearchIntent;

class ChatbotCompoundRequest
{
    /** @param array<string, mixed> $state
     * @return array{parts: list<array<string, mixed>>, state: array<string, mixed>, clarification: string|null, follow_up: bool}|null */
    public function resolve(string $message, array $state): ?array
    {
        $boundary = app(ClassifyChatbotInquiry::class);
        if ($boundary->containsSensitiveInput($message)
            || in_array($boundary->execute($message)['category'], [ChatbotQueryCategory::Order, ChatbotQueryCategory::Store, ChatbotQueryCategory::Faq], true)) {
            return null;
        }
        $language = app(ChatbotLanguage::class);
        $query = trim($language->canonical($message), ' ?.!');
        $price = app(ChatbotPriceIntent::class)->resolve($query);
        $shared = preg_match('/\b(?:both|each|every one|pareho|parehong|duha)\b/iu', $query) === 1;
        $clean = trim(preg_replace('/\b(?:both|each|every one|pareho|parehong|duha|only)\b/iu', '', $query) ?? $query);
        $parts = app(ChatbotMultipartResponse::class)->categoryList($clean);
        $fields = $this->priceFields($price);
        if ($shared) {
            $capacity = $language->interpret($query)['capacity'];
            if ($capacity !== null) {
                $fields['capacity'] = $capacity;
            }
            foreach (Product::query()->customerEligible()->whereNotNull('brand')->distinct()->pluck('brand') as $brand) {
                if (preg_match('/\b'.preg_quote($brand, '/').'\b/iu', $query) === 1) {
                    $fields['brand'] = $brand;
                    break;
                }
            }
        }
        if (count($parts) >= 2) {
            foreach ($parts as &$part) {
                $part['brand'] = $this->mentionedBrand($part['search_query']);
            }
            unset($part);
            unset($state['multipart_sections'], $state['pending_compound'], $state['pending_section']);
            if ($shared) {
                foreach ($parts as &$part) {
                    $part = [...$part, ...$fields];
                }
                unset($part);
            } elseif (count($parts) === 2 && ! preg_match('/\bonly\b/iu', $query)) {
                $firstPrice = app(ChatbotPriceIntent::class)->resolve($parts[0]['search_query']);
                $lastPrice = app(ChatbotPriceIntent::class)->resolve($parts[1]['search_query']);
                if (! $firstPrice['has_minimum'] && ! $firstPrice['has_maximum'] && ($lastPrice['has_minimum'] || $lastPrice['has_maximum'])) {
                    $pendingParts = $this->bounded($parts);
                    foreach ($pendingParts as &$pendingPart) {
                        $pendingPart['minimum_php'] = null;
                        $pendingPart['maximum_php'] = null;
                    }
                    unset($pendingPart);
                    $state['pending_compound'] = ['parts' => $pendingParts, 'price' => $this->priceFields($lastPrice)];

                    return ['parts' => [], 'state' => $state, 'clarification' => 'Does that budget apply to both product types, or only '.$this->label($parts[1]).'?', 'follow_up' => false];
                }
            }

            return ['parts' => $parts, 'state' => $state, 'clarification' => null, 'follow_up' => false];
        }
        if (isset($state['pending_compound'])) {
            $pending = $state['pending_compound'];
            $selected = $this->matching($clean, $pending['parts']);
            if ($shared || count($selected) === 1) {
                $parts = $pending['parts'];
                foreach ($parts as $index => &$part) {
                    if ($shared || in_array($index, $selected, true)) {
                        $part = [...$part, ...$pending['price']];
                    }
                }
                unset($part, $state['pending_compound']);

                return ['parts' => $parts, 'state' => $state, 'clarification' => null, 'follow_up' => false];
            }
        }
        $sections = $state['multipart_sections'] ?? [];
        if ($sections === []) {
            return null;
        }
        $intents = array_column($sections, 'intent');
        $selected = $this->matching($clean, $intents);
        if ($selected === [] && app(CatalogSearchIntent::class)->resolve($clean) !== null) {
            return null;
        }
        $interpreted = $language->interpret($query);
        $brand = $this->mentionedBrand($query);
        if ($brand !== '') {
            $fields['brand'] = $brand;
        }
        if (preg_match('/\b(?:any brand|all brands)\b/iu', $query)) {
            $fields['clear_brand'] = true;
        }
        if ($interpreted['capacity'] !== null) {
            $fields['capacity'] = $interpreted['capacity'];
        }
        if (preg_match('/\b(?:any price|no budget)\b/iu', $query)) {
            $fields['clear_price'] = true;
        }
        $refining = $fields !== [] || $interpreted['capacity'] !== null || $shared || (isset($state['pending_section']) && $selected !== [])
            || preg_match('/\b(?:instead|any brand|any price|no budget|cheaper|stock|price|available|first|second|third)\b/iu', $query) === 1;
        if (! $refining) {
            return null;
        }
        if (count($selected) !== 1 && ! $shared) {
            $state['pending_section'] = $fields;

            return ['parts' => [], 'state' => $state, 'clarification' => 'Which product section should I refine: '.implode(' or ', array_map($this->label(...), $intents)).'?', 'follow_up' => true];
        }
        $parts = [];
        foreach ($intents as $index => $intent) {
            if (! $shared && ! in_array($index, $selected, true)) {
                continue;
            }
            $previousBrand = $intent['brand'];
            $intent = [...$intent, ...($state['pending_section'] ?? []), ...$fields, 'reference' => 'previous', 'operation' => 'refine'];
            if (preg_match('/\b(first|second|third)\b/iu', $query, $ordinal)) {
                $intent['position'] = ['first' => 1, 'second' => 2, 'third' => 3][mb_strtolower($ordinal[1])];
                $intent['intent'] = 'facts';
            }
            $intent['search_query'] = trim($intent['search_query']);
            if ($intent['brand'] !== $previousBrand || ($intent['clear_brand'] ?? false)) {
                if ($previousBrand !== '') {
                    $intent['search_query'] = trim(preg_replace('/\b'.preg_quote($previousBrand, '/').'\b/iu', '', $intent['search_query']) ?? $intent['search_query']);
                }
                if ($intent['clear_brand'] ?? false) {
                    $intent['brand'] = '';
                    $intent['search_query'] .= ' any brand';
                }
            }
            if (($intent['capacity'] ?? '') !== '') {
                $intent['search_query'] = trim(preg_replace('/\b\d+(?:\.\d+)?\s*(?:GB|TB)\b/iu', '', $intent['search_query']) ?? $intent['search_query']);
            }
            if ($intent['clear_price'] ?? false) {
                $intent['minimum_php'] = $intent['maximum_php'] = null;
                $intent['search_query'] .= ' any price';
            }
            $parts[] = $intent;
        }
        unset($state['pending_section']);

        return ['parts' => $parts, 'state' => $state, 'clarification' => null, 'follow_up' => true];
    }

    /** @param array<string, mixed> $price
     * @return array<string, mixed> */
    private function priceFields(array $price): array
    {
        $fields = [];
        if ($price['sort'] !== null) {
            $fields['sort'] = $price['sort'] === 'asc' ? 'price_asc' : 'price_desc';
        }
        if ($price['has_minimum']) {
            $fields['minimum_php'] = $price['minimum'];
            $fields['minimum_inclusive'] = $price['minimum_inclusive'];
        }
        if ($price['has_maximum']) {
            $fields['maximum_php'] = $price['maximum'];
            $fields['maximum_inclusive'] = $price['maximum_inclusive'];
        }

        return $fields;
    }

    /** @param list<array<string, mixed>> $parts
     * @return list<array<string, mixed>> */
    public function bounded(array $parts): array
    {
        return array_map(function (array $part): array {
            unset($part['clear_price'], $part['clear_brand'], $part['operation'], $part['constraint_updates']);
            $price = app(ChatbotPriceIntent::class)->resolve($part['search_query'], $part);

            return [...$part, ...$this->priceFields($price), 'search_query' => mb_substr($price['query'], 0, 250)];
        }, array_slice($parts, 0, 2));
    }

    /** @param list<array<string, mixed>> $parts
     * @return list<int> */
    private function matching(string $query, array $parts): array
    {
        $catalog = app(CatalogSearchIntent::class)->resolve($query);
        $matches = [];
        foreach ($parts as $index => $part) {
            $original = app(CatalogSearchIntent::class)->resolve($part['search_query']);
            if ($catalog !== null && $original !== null && $catalog['category'] === $original['category']) {
                $matches[] = $index;
            }
        }

        return $matches;
    }

    private function mentionedBrand(string $query): string
    {
        return Product::query()->customerEligible()->whereNotNull('brand')->distinct()->pluck('brand')
            ->first(fn (string $brand): bool => $brand !== '' && preg_match('/\b'.preg_quote($brand, '/').'\b/iu', $query) === 1) ?? '';
    }

    /** @param array<string, mixed> $part */
    private function label(array $part): string
    {
        return app(ChatbotMultipartResponse::class)->heading($part);
    }
}

<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Facades\Log;

class ChatbotGroundedResponse
{
    /** @param array<string, mixed> $context
     * @return array<string, string> */
    public function conversationalEvidence(array $context): array
    {
        $evidence = $this->evidence($context);
        if (isset($context['price_selection'])) {
            $evidence['selection:ranking'] = 'Authoritative selection order and PHP price scope; catalog extrema apply only to the matching eligible catalog, selected_products only to the referenced selection: '.json_encode($context['price_selection'], JSON_THROW_ON_ERROR);
        }
        foreach (array_slice($context['branches'] ?? [], 0, 6) as $index => $branch) {
            $evidence['branch:'.$index] = 'Published branch information: '.json_encode($branch, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        foreach (array_slice($context['knowledge'] ?? [], 0, 1) as $index => $faq) {
            $evidence['faq:'.$index] = 'Approved answer: '.($faq['response_template'] ?? '');
        }
        if (is_string($context['guidance'] ?? null) && $context['guidance'] !== '') {
            $evidence['policy:guidance'] = 'Authoritative application guidance: '.$context['guidance'];
        }
        foreach (array_slice($context['products'] ?? [], 0, 6) as $index => $product) {
            $id = 'product:'.($product['id'] ?? $index);
            if (! isset($evidence[$id])) {
                continue;
            }
            foreach (['brand', 'category', 'description'] as $field) {
                if (is_string($product[$field] ?? null) && trim($product[$field]) !== '') {
                    $evidence[$id] .= ' Catalog '.$field.': '.mb_substr(strip_tags($product[$field]), 0, $field === 'description' ? 500 : 120).'.';
                }
            }
            if (($product['specification_review_status'] ?? null) === 'approved' && ($product['approved_specifications'] ?? []) !== []) {
                $evidence[$id] .= ' Approved current specifications: '.json_encode($product['approved_specifications'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).'.';
            } elseif (array_key_exists('specification_review_status', $product)) {
                $evidence[$id] .= ' Verified technical specifications are unavailable; do not infer performance or compatibility from the name or description.';
            }
            if (preg_match('/\b(?:barebone|barebones)\b/iu', $product['name']) === 1) {
                $evidence[$id] .= ' Barebones system: included parts do not establish a complete ready-to-use computer; confirm missing components with staff.';
            }
        }

        return $evidence;
    }

    /** @param array<string, string> $evidence
     * @param array<string, mixed> $selection
     * @param array<string, mixed> $intent */
    public function draft(array $evidence, array $selection, array $intent = []): ?string
    {
        if (count($selection) !== 2 || ! array_key_exists('answer', $selection) || ! array_key_exists('evidence_ids', $selection)
            || ! is_string($selection['answer']) || trim($selection['answer']) === '' || mb_strlen($selection['answer']) > 4000) {
            return null;
        }
        $references = $this->render($evidence, ['evidence_ids' => $selection['evidence_ids']]);
        if ($references === null) {
            return null;
        }
        $answer = trim($selection['answer']);
        if (! $this->validPriceSelection($answer, $evidence, $selection['evidence_ids'], $intent['price_selection'] ?? null)) {
            Log::notice('Chatbot ranking validation rejected.', ['status' => 'order_or_price_claim_mismatch']);

            return null;
        }
        if (preg_match('/\b(?:complete|ready[- ]to[- ]use|fully assembled)\b/iu', $answer) === 1) {
            foreach ($selection['evidence_ids'] as $id) {
                if (is_string($id) && preg_match('/\b(?:barebone|barebones)\b/iu', $evidence[$id] ?? '') === 1) {
                    return null;
                }
            }
        }
        if ($references === null || preg_match('~<[^>]+>|https?://~iu', $answer)
            || (! isset($evidence['compatibility:summary']) && preg_match('/\b(?:compatible|compatibility confirmed|will fit|will work|fully supported)\b/iu', $answer))) {
            return null;
        }
        if (isset($evidence['compatibility:summary'])) {
            if (! in_array('compatibility:summary', $selection['evidence_ids'], true)) {
                return null;
            }
            foreach (['incompatible', 'unknown', 'warning'] as $status) {
                if (str_contains($evidence['compatibility:summary'], '"'.$status.'"') && ! str_contains($answer, '['.$status.']')) {
                    return null;
                }
            }
            if (preg_match('/\b(?:fully compatible|guaranteed|complete compatible|will definitely work)\b/iu', $answer)) {
                return null;
            }
        }
        preg_match_all('/\d+(?:[,.]\d+)*/u', $references, $facts);
        preg_match_all('/\d+(?:[,.]\d+)*/u', $answer, $claims);
        $numbers = array_map(fn (string $number): string => (string) (float) str_replace(',', '', $number), $facts[0]);
        if (is_numeric($intent['maximum_php'] ?? null)) {
            $numbers[] = (string) (float) $intent['maximum_php'];
        }
        if (is_numeric($intent['price_comparison']['difference_php'] ?? null)) {
            $numbers[] = (string) (float) $intent['price_comparison']['difference_php'];
        }
        foreach ($claims[0] as $claim) {
            if (! in_array((string) (float) str_replace(',', '', $claim), $numbers, true)) {
                return null;
            }
        }
        foreach ($selection['evidence_ids'] as $id) {
            if (preg_match('/^(.+?) — PHP ([\d,.]+)/u', $evidence[$id], $fact) !== 1) {
                continue;
            }
            $pattern = '/'.preg_quote($fact[1], '/').'\s*(?:is|costs|:|—|ay|kay|amo|nagkantidad)?\s*(?:PHP|₱)\s*([\d,.]+)/iu';
            if (preg_match($pattern, $answer, $assertion) === 1
                && (float) str_replace(',', '', $assertion[1]) !== (float) str_replace(',', '', $fact[2])) {
                return null;
            }
        }
        $productFacts = [];
        foreach ($selection['evidence_ids'] as $id) {
            if (str_starts_with($id, 'product:') && preg_match('/^(.+?) — PHP ([\d,.]+)/u', $evidence[$id], $fact) === 1) {
                $productFacts[$fact[1]] = (float) str_replace(',', '', $fact[2]);
            }
        }
        foreach (preg_split('/\n+|(?<=[.!?])\s+(?=[\p{L}])/u', $answer) ?: [] as $sentence) {
            $named = array_filter(array_keys($productFacts), fn (string $name): bool => mb_stripos($sentence, $name) !== false);
            if (count($named) !== 1 || preg_match('/\b(?:difference|cheaper by|more expensive by|budget|under|below)\b/iu', $sentence)) {
                continue;
            }
            preg_match_all('/(?:PHP|₱)\s*([\d,]+(?:\.\d{1,2})?)/iu', $sentence, $amounts);
            foreach ($amounts[1] as $amount) {
                if ((float) str_replace(',', '', $amount) !== $productFacts[array_values($named)[0]]) {
                    return null;
                }
            }
        }
        if (str_contains($references, 'Demo item only') && ! preg_match('/\b(?:demo|sample)\b/iu', $answer)) {
            return null;
        }

        return $this->spaceProductEntries($answer, $productFacts);
    }

    /** @param array<string, float> $products */
    private function spaceProductEntries(string $answer, array $products): string
    {
        if ($products !== []) {
            $names = implode('|', array_map(fn (string $name): string => preg_quote($name, '/'), array_keys($products)));
            $answer = preg_replace('/(?<=\S)[\t ]+(?=(?:[-*•]|\d+[.)])[\t ]+(?:\*\*)?(?:'.$names.'))/iu', "\n\n", $answer) ?? $answer;
        }
        $lines = preg_split('/\R/u', $answer) ?: [];
        $formatted = [];
        $previous = null;
        foreach ($lines as $line) {
            $named = array_values(array_filter(array_keys($products), fn (string $name): bool => mb_stripos($line, $name) !== false));
            if (count($named) === 1) {
                if ($previous !== null && $previous !== $named[0] && trim(end($formatted) ?: '') !== '') {
                    $formatted[] = '';
                }
                $previous = $named[0];
            }
            $formatted[] = $line;
        }

        return implode("\n", $formatted);
    }

    /** @param array<string, string> $evidence
     * @param list<string> $references
     * @param array<string, mixed>|null $selection */
    private function validPriceSelection(string $answer, array $evidence, array $references, ?array $selection): bool
    {
        $superlatives = '/\b(?:cheapest|least expensive|lowest[- ]priced|most expensive|highest[- ]priced|pinakamura|pinakabarato|pinakabaratu|pinakamahal)\b/iu';
        if ($selection === null) {
            return true;
        }
        $names = $selection['names'] ?? [];
        $seen = [];
        foreach ($selection['ordered_ids'] as $position => $id) {
            $offset = isset($names[$id]) ? mb_stripos($answer, $names[$id]) : false;
            if ($offset !== false) {
                $seen[$position] = $offset;
            }
        }
        $offsets = array_values($seen);
        $ordered = $offsets;
        sort($ordered);
        if ($offsets !== $ordered) {
            return false;
        }
        if (preg_match($superlatives, $answer) !== 1) {
            return true;
        }
        if (($selection['sort'] ?? null) === null && $selection['scope'] !== 'selected_products') {
            return false;
        }
        $prices = array_intersect_key($selection['prices'], array_flip($selection['comparison_ids'] ?? $selection['ordered_ids']));
        foreach (preg_split('/\n+|(?<=[.!?])\s+(?=[\p{L}])/u', $answer) ?: [] as $sentence) {
            if (preg_match($superlatives, $sentence) !== 1) {
                continue;
            }
            $expensive = preg_match('/\b(?:most expensive|highest[- ]priced|pinakamahal)\b/iu', $sentence) === 1;
            if ($selection['scope'] !== 'selected_products' && $selection['sort'] !== ($expensive ? 'desc' : 'asc')) {
                return false;
            }
            $named = array_keys(array_filter($names, fn (string $name): bool => mb_stripos($sentence, $name) !== false));
            if (count($named) !== 1 || ! isset($prices[$named[0]]) || ! in_array('product:'.$named[0], $references, true)) {
                return false;
            }
            foreach ($prices as $price) {
                $comparison = bccomp($prices[$named[0]], $price, 2);
                if (($expensive && $comparison < 0) || (! $expensive && $comparison > 0)) {
                    return false;
                }
            }
            if ($selection['scope'] === 'selected_products' && preg_match('/\b(?:entire catalog|whole catalog|in the catalog|in the store)\b/iu', $sentence)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $context
     * @return array<string, string>
     */
    public function evidence(array $context): array
    {
        $evidence = [];
        if (is_array($context['compatibility'] ?? null)) {
            $evidence['compatibility:summary'] = 'Authoritative PHP CompatibilityEngine result for identified parts only; never a complete-build or installation guarantee: '.json_encode($context['compatibility'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        foreach (array_slice($context['products'] ?? [], 0, 6) as $index => $product) {
            $price = $product['discount_price'] ?? $product['price'] ?? null;
            if (! is_string($product['name'] ?? null) || ! is_numeric($price)) {
                continue;
            }
            $status = match ($product['inventory']['status'] ?? null) {
                'in_stock' => 'In stock at Sagay',
                'low_stock' => 'Low stock at Sagay',
                'out_of_stock' => 'Out of stock at Sagay',
                default => 'Stock is unconfirmed',
            };
            $text = $product['name'].' — PHP '.number_format((float) $price, 2).'. '.$status.'.';
            if ($product['is_demo'] ?? false) {
                $text .= ' Demo item only; listed prices are samples and Battlefront stock is unconfirmed.';
            }
            $evidence['product:'.($product['id'] ?? $index)] = $text;
        }

        return $evidence;
    }

    /** @param array<string, string> $evidence
     * @param  array<string, mixed>  $selection
     */
    public function render(array $evidence, array $selection): ?string
    {
        if (array_keys($selection) !== ['evidence_ids'] || ! is_array($selection['evidence_ids'])
            || ! array_is_list($selection['evidence_ids']) || $selection['evidence_ids'] === []
            || count($selection['evidence_ids']) > 6) {
            return null;
        }
        $ids = $selection['evidence_ids'];
        foreach ($ids as $id) {
            if (! is_string($id) || ! array_key_exists($id, $evidence)) {
                return null;
            }
        }
        if (count(array_unique($ids)) !== count($ids)) {
            return null;
        }

        return implode("\n\n", array_map(fn (string $id): string => $evidence[$id], $ids));
    }
}

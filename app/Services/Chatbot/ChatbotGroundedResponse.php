<?php

namespace App\Services\Chatbot;

class ChatbotGroundedResponse
{
    /** @param array<string, mixed> $context
     * @return array<string, string> */
    public function conversationalEvidence(array $context): array
    {
        $evidence = $this->evidence($context);
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
        $answer = trim($selection['answer']);
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

        return $answer;
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

        return implode("\n", array_map(fn (string $id): string => $evidence[$id], $ids));
    }
}

<?php

namespace App\Services\Chatbot;

use InvalidArgumentException;

class ChatbotPriceIntent
{
    /** @param array<string, mixed> $model
     * @return array{sort: 'asc'|'desc'|null, minimum: numeric-string|null, maximum: numeric-string|null,
     *     minimum_inclusive: bool, maximum_inclusive: bool, has_minimum: bool, has_maximum: bool,
     *     cheaper: bool, scope: string, error: string|null, query: string} */
    public function resolve(string $message, array $model = []): array
    {
        $query = mb_strtolower($message);
        $descending = 'most expensive|highest price|price high to low|pinakamahal';
        $ascending = 'cheapest|lowest price|least expensive|price low to high|pinakamura|pinakabarato|pinakabaratu';
        $asc = preg_match('/\b(?:'.$ascending.')\b/u', $query) === 1;
        $desc = preg_match('/\b(?:'.$descending.')\b/u', $query) === 1;
        $sort = $desc ? 'desc' : ($asc ? 'asc' : match ($model['sort'] ?? 'relevance') {
            'price_asc' => 'asc', 'price_desc' => 'desc', default => null,
        });
        $error = $asc && $desc ? 'Please choose either lowest or highest price first.' : null;
        if (preg_match('/(?:\b(?:USD|EUR|GBP|dollars?|euros?)\b|[\x{24}\x{20AC}\x{A3}])/iu', $message)) {
            $error = 'Battlefront prices are in PHP. Please provide your budget in Philippine pesos.';
        }
        if (preg_match('/(?:\b(?:under|below|above|over|between|budget)\s+(?:PHP\s*|\x{20B1}\s*)?)-\d/iu', $message)) {
            $error = 'Please provide a non-negative price range in PHP.';
        }
        $query = preg_replace('/\b(?:'.$descending.'|'.$ascending.')\b/u', ' ', $query);
        $amount = '(?:PHP\s*|\x{20B1}\s*)?((?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?\s*k?)(?![\p{L}\p{N},.])';
        $minimum = isset($model['minimum_php']) ? $this->money((string) $model['minimum_php']) : null;
        $maximum = isset($model['maximum_php']) ? $this->money((string) $model['maximum_php']) : null;
        $minimumInclusive = is_bool($model['minimum_inclusive'] ?? null) ? $model['minimum_inclusive'] : true;
        $maximumInclusive = is_bool($model['maximum_inclusive'] ?? null) ? $model['maximum_inclusive'] : true;
        $scope = is_string($model['price_scope'] ?? null) ? $model['price_scope'] : 'catalog';
        $hasMinimum = $minimum !== null;
        $hasMaximum = $maximum !== null;
        if (preg_match('/\bbetween\s+'.$amount.'\s+and\s+'.$amount.'/iu', $query, $match)) {
            $minimum = $this->money($match[1]);
            $maximum = $this->money($match[2]);
            $minimumInclusive = $maximumInclusive = $hasMinimum = $hasMaximum = true;
            $query = str_replace($match[0], '', $query);
        }
        foreach (['minimum' => 'above|over|at least', 'maximum' => 'under|below|less than|up to|at most|budget(?: of)?'] as $bound => $words) {
            if (preg_match('/\b('.$words.')\s+'.$amount.'/iu', $query, $match)) {
                if ($bound === 'minimum') {
                    $minimum = $this->money($match[2]);
                    $minimumInclusive = $match[1] === 'at least';
                    $hasMinimum = true;
                } else {
                    $maximum = $this->money($match[2]);
                    $maximumInclusive = in_array($match[1], ['up to', 'at most', 'budget', 'budget of'], true);
                    $hasMaximum = true;
                }
                $query = str_replace($match[0], '', $query);
            }
        }
        if ($minimum !== null && $maximum !== null && (bccomp($minimum, $maximum, 2) > 0 || (bccomp($minimum, $maximum, 2) === 0 && (! $minimumInclusive || ! $maximumInclusive)))) {
            $error = 'Please provide a price range whose minimum is below its maximum.';
        }
        if (($minimum !== null && bccomp($minimum, '9999999999', 2) > 0) || ($maximum !== null && bccomp($maximum, '9999999999', 2) > 0)) {
            $error = 'Please provide a smaller price amount in PHP.';
        }

        return ['sort' => $sort, 'minimum' => $minimum, 'maximum' => $maximum,
            'minimum_inclusive' => $minimumInclusive, 'maximum_inclusive' => $maximumInclusive,
            'has_minimum' => $hasMinimum, 'has_maximum' => $hasMaximum,
            'cheaper' => preg_match('/\b(?:cheaper|more affordable|mas mura|mas barato|mas baratu)\b/iu', $message) === 1,
            'scope' => $scope, 'error' => $error,
            'query' => trim(preg_replace('/\s+/u', ' ', $query))];
    }

    /** @return numeric-string */
    public function money(string $value): string
    {
        $value = mb_strtolower(str_replace([',', ' '], '', $value));
        $amount = $this->numericString(rtrim($value, 'k'));
        $multiplier = $this->numericString(str_ends_with($value, 'k') ? '1000' : '1');

        return bcmul($amount, $multiplier, 2);
    }

    /** @param array<string, mixed> $price
     * @param array<string, mixed> $product */
    public function matches(array $price, array $product): bool
    {
        $amount = $this->numericString($product['discount_price'] ?? $product['price']);
        foreach (['minimum', 'maximum'] as $bound) {
            if ($price[$bound] === null) {
                continue;
            }
            $comparison = bccomp($amount, $this->numericString($price[$bound]), 2);
            if (($bound === 'minimum' && ($comparison < 0 || ($comparison === 0 && ! $price['minimum_inclusive'])))
                || ($bound === 'maximum' && ($comparison > 0 || ($comparison === 0 && ! $price['maximum_inclusive'])))) {
                return false;
            }
        }

        return true;
    }

    /** @return numeric-string */
    private function numericString(mixed $value): string
    {
        if (! is_string($value) || ! is_numeric($value)) {
            throw new InvalidArgumentException('A catalog price must be a numeric string.');
        }

        return $value;
    }
}

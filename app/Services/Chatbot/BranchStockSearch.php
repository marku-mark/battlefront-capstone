<?php

namespace App\Services\Chatbot;

use App\Models\Branch;

final readonly class BranchStockSearch
{
    /** @param array{category: string, query: string, label: string}|null $scope
     * @param list<int>|null $productIds
     * @param array{brand: string, capacity: string, excluded_brands: list<string>, other_than_brand?: string, product_role: string, minimum: ?string, maximum: ?string, minimum_inclusive: bool, maximum_inclusive: bool, sort: ?string} $filters
     * @param array{branch: ?Branch, outcome: string, clarification?: string, clarification_code?: string} $location */
    public function __construct(
        public ?array $scope,
        public ?array $productIds,
        public array $filters,
        public array $location,
    ) {}

    public function canCheckStock(): bool
    {
        return $this->location['outcome'] === 'sagay' && ($this->scope !== null || $this->productIds !== null);
    }
}

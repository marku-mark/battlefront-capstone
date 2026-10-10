<?php

namespace Database\Factories;

use App\Enums\ShippingProfile;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_code' => fake()->unique()->regexify('TEST[A-Z0-9]{16}'),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'category_id' => Category::factory(),
            'brand' => fake()->company(),
            'price' => fake()->randomFloat(2, 0, 500000),
            'is_featured' => false,
            'discount_price' => null,
            'image_path' => null,
        ];
    }

    /**
     * Indicate that the product is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function available(): static
    {
        return $this->has(Inventory::factory()->state([
            'quantity' => 10,
            'reorder_level' => 5,
        ]));
    }

    public function standard(): static
    {
        return $this->state(fn (array $attributes): array => [
            'shipping_profile' => ShippingProfile::Standard,
        ]);
    }

    public function fragile(): static
    {
        return $this->state(fn (array $attributes): array => [
            'shipping_profile' => ShippingProfile::Fragile,
        ]);
    }

    public function bulky(): static
    {
        return $this->state(fn (array $attributes): array => [
            'shipping_profile' => ShippingProfile::Bulky,
        ]);
    }
}

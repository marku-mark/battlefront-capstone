<?php

use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

test('the category schema follows the approved ERD decisions', function () {
    expect(Schema::getColumnListing('categories'))->toEqualCanonicalizing([
        'id',
        'name',
        'name_key',
        'description',
        'is_active',
    ]);
});

test('a category persists with its approved defaults and casts', function () {
    $category = Category::factory()->create([
        'name' => 'Graphics Cards',
        'description' => null,
    ]);

    $this->assertModelExists($category);
    expect($category->name)->toBe('Graphics Cards')
        ->and($category->description)->toBeNull()
        ->and($category->is_active)->toBeTrue()
        ->and($category->is_active)->toBeBool();
});

test('duplicate category names are rejected', function () {
    Category::factory()->create(['name' => 'Processors']);

    expect(fn () => Category::factory()->create(['name' => 'Processors']))
        ->toThrow(QueryException::class);
});

test('a category name is required', function () {
    expect(fn () => Category::query()->create(['description' => 'Missing a name.']))
        ->toThrow(QueryException::class);
});

test('the active scope excludes inactive categories', function () {
    $activeCategory = Category::factory()->create(['name' => 'Monitors']);
    Category::factory()->inactive()->create(['name' => 'Legacy Hardware']);

    $activeCategories = Category::active()->get();

    expect($activeCategories)->toHaveCount(1)
        ->and($activeCategories->sole()->is($activeCategory))->toBeTrue();
});

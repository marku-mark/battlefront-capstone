<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function catalogNameMigration(): Migration
{
    return require database_path('migrations/2026_10_10_143955_add_catalog_name_keys.php');
}

test('name key migration preserves imported names identities and existing constraints', function () {
    $product = Product::factory()->create(['name' => '  Source  Product™ ', 'is_catalog_imported' => true]);
    $category = $product->category;
    $before = $product->refresh()->toArray();
    $migration = catalogNameMigration();
    $migration->down();

    $migration->up();

    expect($product->refresh()->toArray())->toBe($before);
    $this->assertModelExists($category);
    expect(fn () => Product::factory()->create(['name' => 'source product™']))->toThrow(QueryException::class);
    expect(fn () => $product->update(['price' => '-1.00']))->toThrow(QueryException::class);
    expect(fn () => DB::table('products')->where('id', $product->id)->update(['shipping_profile' => 'invalid']))->toThrow(QueryException::class);
    expect(fn () => DB::table('products')->where('id', $product->id)->update(['name_key' => null]))->toThrow(QueryException::class);
});

test('name key migration refuses legacy collisions before changing either table', function (string $table) {
    $model = $table === 'products' ? Product::class : Category::class;
    $first = $model::factory()->create(['name' => 'Source Name']);
    $second = $model::factory()->create(['name' => 'Different Name']);
    $migration = catalogNameMigration();
    $migration->down();
    DB::table($table)->where('id', $second->id)->update(['name' => ' source  NAME ']);

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Duplicate normalized names');

    expect(Schema::hasColumn('products', 'name_key'))->toBeFalse();
    expect(Schema::hasColumn('categories', 'name_key'))->toBeFalse();
    $this->assertDatabaseHas($table, ['id' => $first->id, 'name' => 'Source Name']);
    $this->assertDatabaseHas($table, ['id' => $second->id, 'name' => ' source  NAME ']);
})->with(['products', 'categories']);

test('name key rollback retains product constraints and catalog data', function () {
    $product = Product::factory()->create();

    catalogNameMigration()->down();

    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => $product->name]);
    expect(fn () => DB::table('products')->where('id', $product->id)->update(['price' => '-1.00']))->toThrow(QueryException::class);
    expect(fn () => DB::table('products')->where('id', $product->id)->update(['shipping_profile' => 'invalid']))->toThrow(QueryException::class);
});

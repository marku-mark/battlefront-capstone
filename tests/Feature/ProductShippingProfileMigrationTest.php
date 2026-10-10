<?php

use App\Actions\Catalog\CatalogName;
use App\Enums\ShippingProfile;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('migration defaults existing products to standard while preserving their other attributes', function () {
    $product = Product::factory()->create(['price' => '100.00', 'discount_price' => '80.00']);
    $migration = require database_path('migrations/2026_10_07_151621_add_shipping_profile_to_products_table.php');
    $migration->down();
    $before = (array) DB::table('products')->where('id', $product->id)->first();

    $migration->up();

    expect((array) DB::table('products')->where('id', $product->id)->first())
        ->toBe([...$before, 'shipping_profile' => 'standard']);
    expect($product->refresh()->shipping_profile)->toBe(ShippingProfile::Standard);
    expect(fn () => DB::table('products')->where('id', $product->id)->update(['discount_price' => '100.00']))
        ->toThrow(QueryException::class);
});

test('the database defaults new products to standard without a supplied profile', function () {
    $product = Product::factory()->create();
    $attributes = $product->getAttributes();
    unset($attributes['id'], $attributes['shipping_profile']);
    $attributes['product_code'] = 'DBDEFAULT001';
    $attributes['name'] = 'Database default profile product';
    $attributes['name_key'] = CatalogName::key($attributes['name']);

    $id = DB::table('products')->insertGetId($attributes);

    $this->assertDatabaseHas('products', ['id' => $id, 'shipping_profile' => 'standard']);
});

test('invalid shipping profiles are rejected for direct database inserts', function (?string $profile) {
    $product = Product::factory()->create();
    $attributes = $product->getAttributes();
    unset($attributes['id']);
    $attributes['product_code'] = 'INVALIDPROFILE001';
    $attributes['name'] = 'Invalid profile product';
    $attributes['name_key'] = CatalogName::key($attributes['name']);
    $attributes['shipping_profile'] = $profile;

    expect(fn () => DB::table('products')->insert($attributes))->toThrow(QueryException::class);
    $this->assertDatabaseMissing('products', ['product_code' => 'INVALIDPROFILE001']);
})->with([
    'unsupported profile' => ['oversized'],
    'wrong case' => ['Standard'],
    'blank profile' => [''],
    'missing profile' => [null],
]);

test('invalid shipping profiles are rejected for direct database updates', function (?string $profile) {
    $product = Product::factory()->fragile()->create();

    expect(fn () => DB::table('products')->where('id', $product->id)->update(['shipping_profile' => $profile]))
        ->toThrow(QueryException::class);
    expect($product->refresh()->shipping_profile)->toBe(ShippingProfile::Fragile);
})->with([
    'unsupported profile' => ['oversized'],
    'wrong case' => ['Standard'],
    'blank profile' => [''],
    'missing profile' => [null],
]);

test('rollback removes only the shipping profile and preserves existing money constraints', function () {
    $product = Product::factory()->bulky()->create(['price' => '100.00', 'discount_price' => '80.00']);
    $before = (array) DB::table('products')->where('id', $product->id)->first();
    unset($before['shipping_profile']);
    $migration = require database_path('migrations/2026_10_07_151621_add_shipping_profile_to_products_table.php');

    $migration->down();

    expect(Schema::hasColumn('products', 'shipping_profile'))->toBeFalse();
    expect((array) DB::table('products')->where('id', $product->id)->first())->toBe($before);
    expect(fn () => DB::table('products')->where('id', $product->id)->update(['price' => '-1.00']))
        ->toThrow(QueryException::class);
});

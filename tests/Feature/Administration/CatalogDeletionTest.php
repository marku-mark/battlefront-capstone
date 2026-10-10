<?php

use App\Actions\Category\DeleteCategory;
use App\Actions\Product\DeleteManagedProductImage;
use App\Actions\Product\DeleteProduct;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CustomerProductView;
use App\Models\Forecast;
use App\Models\GuestRecommendationProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RecommendationInteraction;
use App\Models\Sale;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('administrators can delete unused products and their disposable associations', function (bool $imported) {
    Storage::fake('local');
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create(['is_catalog_imported' => $imported]);
    $inventory = $product->inventory;
    $tag = Tag::factory()->create();
    $product->tags()->attach($tag);
    $view = CustomerProductView::factory()->for($product)->create();
    $guestView = CustomerProductView::factory()->for($product)->create([
        'user_id' => null, 'guest_recommendation_profile_id' => GuestRecommendationProfile::factory()->create()->id,
    ]);
    $interaction = RecommendationInteraction::factory()->for($product)->create();
    $unrelated = Product::factory()->available()->create();

    $this->actingAs($administrator)->delete(route('administration.products.destroy', $product))
        ->assertSessionHasNoErrors()->assertRedirect(route('administration.products.index'));

    $this->assertModelMissing($product);
    $this->assertModelMissing($inventory);
    $this->assertModelMissing($view);
    $this->assertModelMissing($guestView);
    $this->assertModelMissing($interaction);
    $this->assertDatabaseMissing('product_tag', ['product_id' => $product->id]);
    $this->assertModelExists($tag);
    $this->assertModelExists($unrelated);
    $this->assertModelExists($unrelated->inventory);
    $this->assertModelExists($product->category);
    $this->assertModelExists($view->user);
    $this->assertModelExists($guestView->guestRecommendationProfile);
})->with(['manual' => false, 'imported' => true]);

test('product deletion removes its owned image after commit', function (bool $imported) {
    Storage::fake('public');
    Storage::fake('local');
    $product = Product::factory()->create(['is_catalog_imported' => $imported]);
    $path = 'products/admin/'.$product->id.'/'.str_repeat('a', 64).'.webp';
    $product->update(['image_path' => $path]);
    Storage::disk('public')->put($path, 'owned bytes');

    $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.products.destroy', $product))
        ->assertSessionHasNoErrors();

    $this->assertModelMissing($product);
    Storage::disk('public')->assertMissing($path);
})->with(['manual' => false, 'imported with administrator upload' => true]);

test('product deletion preserves shared manifest and unrelated images', function (string $path, bool $shared) {
    Storage::fake('public');
    Storage::fake('local');
    $product = Product::factory()->create(['image_path' => $path]);
    Storage::disk('public')->put($path, 'preserved bytes');
    if ($shared) {
        Product::factory()->create(['image_path' => $path]);
    }

    $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.products.destroy', $product))
        ->assertSessionHasNoErrors();

    expect(Storage::disk('public')->get($path))->toBe('preserved bytes');
})->with([
    'manifest' => ['products/graphics-card/00123.webp', false],
    'shared legacy upload' => ['products/shared.jpg', true],
    'unrelated namespace' => ['unrelated/image.webp', false],
    'another product owner' => ['products/admin/999/'.str_repeat('a', 64).'.webp', false],
]);

test('every order status protects a product from deletion', function (string $status) {
    $product = Product::factory()->available()->create();
    $order = Order::factory()->create(['status' => $status]);
    $item = OrderItem::factory()->for($order)->for($product)->create();

    $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.products.destroy', $product))
        ->assertSessionHasErrors(['deletion' => DeleteProduct::PROTECTED_MESSAGE]);

    $this->assertModelExists($product);
    $this->assertModelExists($product->inventory);
    $this->assertModelExists($item);
})->with(['pending', 'processing', 'completed', 'cancelled']);

test('recorded sales and all forecast methods protect product history', function (string $reference) {
    $product = Product::factory()->create(['is_catalog_imported' => true]);
    if ($reference === 'sale') {
        $sale = Sale::factory()->create();
        $record = OrderItem::factory()->for($sale->order)->for($product)->create();
    } else {
        $record = Forecast::factory()->for($product)->create(['method' => $reference]);
    }

    $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.products.destroy', $product))
        ->assertSessionHasErrors(['deletion' => DeleteProduct::PROTECTED_MESSAGE]);

    $this->assertModelExists($product);
    $this->assertModelExists($record);
    if (isset($sale)) {
        $this->assertModelExists($sale);
    }
})->with(['sale', 'additive_holt_winters', 'moving_average', 'linear_trend']);

test('existing customer carts protect even inactive imported products', function () {
    $product = Product::factory()->available()->create(['is_catalog_imported' => true]);
    $item = CartItem::factory()->for($product)->create();
    $product->update(['is_active' => false]);

    $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.products.destroy', $product))
        ->assertSessionHasErrors(['deletion' => DeleteProduct::PROTECTED_MESSAGE]);

    $this->assertModelExists($product);
    $this->assertModelExists($item);
});

test('declared historical coverage protects products without sales or forecasts', function (bool $development) {
    Storage::fake('local');
    $product = Product::factory()->create();
    if ($development) {
        Storage::disk('local')->put(config('forecasting.development_manifest'), json_encode([
            'version' => 2, 'products' => [$product->product_code => ['stale' => true]],
        ], JSON_THROW_ON_ERROR));
        $original = Storage::disk('local')->get(config('forecasting.development_manifest'));
    } else {
        config(['forecasting.operational_coverage' => [$product->product_code => ['stale' => true]]]);
    }

    $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.products.destroy', $product))
        ->assertSessionHasErrors(['deletion' => DeleteProduct::PROTECTED_MESSAGE]);

    $this->assertModelExists($product);
    if ($development) {
        expect(Storage::disk('local')->get(config('forecasting.development_manifest')))->toBe($original);
    }
})->with(['operational' => false, 'development' => true]);

test('a newly detected foreign key rolls back cleanup and returns a friendly error', function () {
    Storage::fake('public');
    $product = Product::factory()->available()->create(['image_path' => 'products/owned.jpg']);
    Storage::disk('public')->put($product->image_path, 'owned bytes');
    $tag = Tag::factory()->create();
    $product->tags()->attach($tag);
    $order = Order::factory()->create();
    $event = 'eloquent.deleting: '.Product::class;
    Event::listen($event, function (Product $deleting) use ($order): void {
        OrderItem::factory()->for($order)->for($deleting, 'product')->create();
    });

    try {
        $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.products.destroy', $product))
            ->assertSessionHasErrors(['deletion' => DeleteProduct::PROTECTED_MESSAGE]);
    } finally {
        Event::forget($event);
    }

    $this->assertModelExists($product);
    $this->assertModelExists($product->inventory);
    $this->assertDatabaseHas('product_tag', ['product_id' => $product->id, 'tag_id' => $tag->id]);
    $this->assertDatabaseMissing('order_items', ['product_id' => $product->id]);
    Storage::disk('public')->assertExists($product->image_path);
});

test('unreadable history metadata refuses deletion without changing product data', function () {
    Storage::fake('local');
    Exceptions::fake();
    $product = Product::factory()->available()->create();
    Storage::disk('local')->put(config('forecasting.development_manifest'), '{invalid json');

    $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.products.destroy', $product))
        ->assertSessionHasErrors(['deletion' => 'This product cannot be deleted because its historical references could not be verified. Check the history metadata and try again.']);

    $this->assertModelExists($product);
    $this->assertModelExists($product->inventory);
    expect(Storage::disk('local')->get(config('forecasting.development_manifest')))->toBe('{invalid json');
    Exceptions::assertReported(RuntimeException::class);
});

test('rollback never removes an owned product image', function () {
    Storage::fake('public');
    $product = Product::factory()->available()->create(['image_path' => 'products/owned.jpg']);
    Storage::disk('public')->put($product->image_path, 'owned bytes');
    DB::beginTransaction();

    try {
        app(DeleteProduct::class)->execute($product);
        Storage::disk('public')->assertExists($product->image_path);
    } finally {
        DB::rollBack();
    }

    $this->assertModelExists($product);
    $this->assertModelExists($product->inventory);
    Storage::disk('public')->assertExists($product->image_path);
});

test('image cleanup failure is reported after the deletion remains committed', function () {
    Exceptions::fake();
    $product = Product::factory()->create(['image_path' => 'products/owned.jpg']);
    $this->mock(DeleteManagedProductImage::class)->shouldReceive('execute')->once()
        ->with($product->image_path, $product->id)->andThrow(new RuntimeException('Storage unavailable.'));

    $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.products.destroy', $product))
        ->assertSessionHasNoErrors()->assertRedirect(route('administration.products.index'));

    $this->assertModelMissing($product);
    Exceptions::assertReported(RuntimeException::class);
});

test('unrelated database deletion failures are not disguised as protected references', function () {
    $product = Product::factory()->available()->create();
    DB::unprepared("CREATE TRIGGER fail_product_delete BEFORE DELETE ON products BEGIN SELECT RAISE(ABORT, 'Storage constraint failure'); END");

    expect(fn () => app(DeleteProduct::class)->execute($product))->toThrow(QueryException::class);

    $this->assertModelExists($product);
    $this->assertModelExists($product->inventory);
});

test('administrators can permanently delete empty active or inactive categories', function (bool $active) {
    $category = Category::factory()->create(['is_active' => $active]);
    $otherProduct = Product::factory()->create();

    $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.categories.destroy', $category))
        ->assertSessionHasNoErrors()->assertRedirect(route('administration.categories.index'));

    $this->assertModelMissing($category);
    $this->assertModelExists($otherProduct);
    $this->assertModelExists($otherProduct->category);
})->with(['active' => true, 'inactive' => false]);

test('active and inactive products prevent category deletion', function (bool $active) {
    $product = Product::factory()->create(['is_active' => $active]);

    $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.categories.destroy', $product->category))
        ->assertSessionHasErrors(['deletion' => DeleteCategory::PROTECTED_MESSAGE]);

    $this->assertModelExists($product);
    $this->assertModelExists($product->category);
})->with(['active' => true, 'inactive' => false]);

test('category foreign key failures preserve newly attached products and return an error', function () {
    $category = Category::factory()->create();
    $event = 'eloquent.deleting: '.Category::class;
    Event::listen($event, fn (Category $deleting) => Product::factory()->for($deleting)->create());

    try {
        $this->actingAs(User::factory()->administrator()->create())->delete(route('administration.categories.destroy', $category))
            ->assertSessionHasErrors(['deletion' => DeleteCategory::PROTECTED_MESSAGE]);
    } finally {
        Event::forget($event);
    }

    $this->assertModelExists($category);
    $this->assertDatabaseCount('products', 0);
});

test('guests and customers cannot delete administrator catalog records', function (string $resource, bool $authenticated) {
    $record = $resource === 'products' ? Product::factory()->create() : Category::factory()->create();
    if ($authenticated) {
        $this->actingAs(User::factory()->customer()->create());
    }

    $response = $this->delete(route('administration.'.$resource.'.destroy', $record));

    if ($authenticated) {
        $response->assertForbidden();
    } else {
        $response->assertRedirect(route('login'));
    }
    $this->assertModelExists($record);
})->with([
    'guest product' => ['products', false], 'customer product' => ['products', true],
    'guest category' => ['categories', false], 'customer category' => ['categories', true],
]);

test('administrator product views expose import origin for deletion confirmation', function () {
    $product = Product::factory()->create(['is_catalog_imported' => true]);
    $this->actingAs(User::factory()->administrator()->create());

    $this->get(route('administration.products.index'))->assertInertia(fn (Assert $page) => $page
        ->where('products.data.0.is_catalog_imported', true));
    $this->get(route('administration.products.show', $product))->assertInertia(fn (Assert $page) => $page
        ->where('product.is_catalog_imported', true));
});

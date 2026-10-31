<?php

use App\Enums\ShippingProfile;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('archived product listing preserves search archived categories and private field omissions', function () {
    $owner = User::factory()->administrator()->create();
    $category = Category::factory()->create();
    $product = Product::factory()->for($category)->create(['description' => 'Archive search marker', 'deleted_by_id' => $owner->id]);
    $product->delete();
    $category->delete();
    Product::factory()->create(['description' => 'Archive search marker']);

    $this->actingAs($owner)->get(route('administration.products.index', ['deleted' => 1, 'q' => 'Archive search marker', 'status' => 'inactive']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.id', $product->id)
            ->where('products.data.0.is_active', false)
            ->where('products.data.0.deleted_by', $owner->name)
            ->where('products.data.0.category.is_deleted', true)
            ->where('products.data.0.tags', [])
            ->missing('products.data.0.acquisition_cost')
            ->where('filters.status', null)
            ->where('filter_options', ['categories' => [], 'tags' => [], 'brands' => []]));
});

test('new bundle products use the same acquisition cost rate as migrated products', function () {
    $owner = User::factory()->administrator()->create();
    $category = Category::factory()->create(['name' => 'Bundles & Packages']);

    $this->actingAs($owner)->post(route('administration.products.store'), [...validPackageMeasurements(),
        'name' => 'New computer bundle', 'category_id' => $category->id,
        'price' => '1000.00', 'is_featured' => false,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $this->assertDatabaseHas('products', [
        'name' => 'New computer bundle', 'acquisition_cost' => '850.00', 'acquisition_cost_source' => 'category_policy_v1',
    ]);
    $this->assertDatabaseHas('audit_logs', ['actor_id' => $owner->id, 'action' => 'product.created']);
});

test('updated product codes are required and use the safe catalog format', function (mixed $code, string $message) {
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create();
    $product = Product::factory()->for($category)->create(['product_code' => 'UNCHANGED']);

    $this->actingAs($administrator)->put(route('administration.products.update', $product), [...validPackageMeasurements(),
        'edit_version' => $product->edit_version,
        'product_code' => $code, 'name' => 'New product', 'category_id' => $category->id,
        'brand' => 'Brand', 'price' => '100', 'is_featured' => false,
    ])->assertSessionHasErrors(['product_code' => $message]);

    expect($product->refresh()->product_code)->toBe('UNCHANGED');
})->with([
    'null' => [null, 'Enter a product code.'],
    'empty' => ['', 'Enter a product code.'],
    'unsafe' => ['../unsafe', 'Use 1 to 64 letters or digits for the product code.'],
    'whitespace' => ['AB CD', 'Use 1 to 64 letters or digits for the product code.'],
    'too long' => [str_repeat('A', 65), 'Use 1 to 64 letters or digits for the product code.'],
]);

test('updating cannot reuse another products code ignoring case', function (string $code) {
    $administrator = User::factory()->administrator()->create();
    $existing = Product::factory()->create(['product_code' => 'ABC123']);
    $other = Product::factory()->create(['product_code' => 'OTHER']);
    $payload = [...validPackageMeasurements(), 'edit_version' => $other->edit_version, 'product_code' => $code, 'name' => 'Product', 'category_id' => $existing->category_id,
        'brand' => 'Brand', 'price' => '100', 'is_featured' => false];

    $this->actingAs($administrator)->put(route('administration.products.update', $other), $payload)
        ->assertSessionHasErrors(['product_code' => 'This product code is already in use.']);

    expect($other->refresh()->product_code)->toBe('OTHER');
    $this->assertDatabaseCount('products', 2);
})->with(['exact' => 'ABC123', 'case only' => 'abc123']);

test('imported codes are visible and protected from changes and forged origin flags', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create(['product_code' => '000123', 'is_catalog_imported' => true]);

    $this->actingAs($administrator)->get(route('administration.products.edit', $product))
        ->assertInertia(fn (Assert $page) => $page->where('product.product_code', '000123')->where('product.is_catalog_imported', true)
            ->where('product.edit_version', $product->edit_version));
    $this->put(route('administration.products.update', $product), [...validPackageMeasurements(),
        'edit_version' => $product->edit_version,
        'product_code' => 'CHANGED', 'is_catalog_imported' => false,
        'name' => $product->name, 'category_id' => $product->category_id,
        'brand' => $product->brand, 'price' => $product->price, 'is_featured' => false,
    ])->assertSessionHasErrors(['product_code' => 'Imported product codes cannot be changed.']);

    expect($product->refresh()->product_code)->toBe('000123');
    expect($product->is_catalog_imported)->toBeTrue();
});

test('administration product and inventory search returns matching product codes', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->has(Inventory::factory())->create(['product_code' => '000123']);
    Product::factory()->has(Inventory::factory())->create();

    foreach (['administration.products.index', 'administration.inventory.index'] as $route) {
        $this->actingAs($administrator)->get(route($route, ['q' => '000123']))
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)
                ->where('products.data.0.id', $product->id)->where('products.data.0.product_code', '000123'));
    }
});

test('guests are redirected when viewing catalog products', function () {
    $this->get(route('administration.products.index'))
        ->assertRedirect(route('login'));
});

test('owners can restore a deleted product with its inventory while keeping it inactive', function () {
    $owner = User::factory()->administrator()->create();
    $product = Product::factory()->has(Inventory::factory()->state(['quantity' => 0]))->create([
        'is_active' => true,
    ]);

    $this->actingAs($owner)->delete(route('administration.products.destroy', $product))
        ->assertRedirect(route('administration.products.index'));

    expect(Product::query()->find($product->id))->toBeNull();
    $this->assertDatabaseHas('products', ['id' => $product->id, 'is_active' => false, 'deleted_by_id' => $owner->id]);
    $this->assertDatabaseHas('inventories', ['product_id' => $product->id, 'quantity' => 0]);

    $this->get(route('administration.products.index', ['deleted' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('deleted', true)
            ->where('products.data.0.name', $product->name)
            ->where('products.data.0.deleted_by', $owner->name));

    $this->post(route('administration.products.restore', $product->id))
        ->assertRedirect(route('administration.products.index', ['deleted' => 1]));

    $restored = Product::query()->findOrFail($product->id);
    expect($restored->is_active)->toBeFalse();
    $this->assertDatabaseHas('inventories', ['product_id' => $product->id, 'quantity' => 0]);
});

test('completed customer returns no longer block deleting a stockless product', function () {
    $owner = User::factory()->administrator()->create();
    $product = Product::factory()->has(Inventory::factory()->state(['quantity' => 0, 'quarantined_quantity' => 0]))->create();
    $order = Order::factory()->create(['status' => 'completed']);
    $itemId = DB::table('order_items')->insertGetId([
        'order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'price_at_time' => '100.00',
    ]);
    $returnId = DB::table('customer_returns')->insertGetId([
        'order_id' => $order->id, 'requester_id' => $owner->id, 'status' => 'accepted', 'reason' => 'Resolved return',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('customer_return_items')->insert([
        'customer_return_id' => $returnId, 'order_item_id' => $itemId, 'quantity' => 1,
        'condition' => 'damaged', 'disposition' => 'write_off',
    ]);

    $this->actingAs($owner)->delete(route('administration.products.destroy', $product))
        ->assertRedirect(route('administration.products.index'));

    expect(Product::query()->find($product->id))->toBeNull();
    $this->assertDatabaseHas('customer_return_items', ['customer_return_id' => $returnId, 'order_item_id' => $itemId]);
});

test('an acknowledged supplier return without resolution blocks product deletion', function () {
    $owner = User::factory()->administrator()->create();
    $product = Product::factory()->has(Inventory::factory()->state(['quantity' => 0, 'quarantined_quantity' => 0]))->create();
    $supplierId = DB::table('suppliers')->insertGetId([
        'name' => 'Unresolved supplier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('supplier_returns')->insert([
        'supplier_id' => $supplierId, 'product_id' => $product->id, 'requester_id' => $owner->id,
        'quantity' => 1, 'status' => 'closed', 'reason' => 'Acknowledged return', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($owner)->delete(route('administration.products.destroy', $product))
        ->assertSessionHasErrors('deletion');

    expect(Product::query()->find($product->id))->not->toBeNull();
});

test('only owners can view or restore deleted products', function () {
    $managerRoleId = DB::table('administration_roles')->where('code', 'manager')->value('id');
    $manager = User::factory()->administrator()->create(['administration_role_id' => $managerRoleId]);
    $product = Product::factory()->create();
    $product->delete();

    $this->actingAs($manager)->get(route('administration.products.index', ['deleted' => 1]))->assertForbidden();
    $this->post(route('administration.products.restore', $product->id))->assertForbidden();
});

test('legacy archived products wait for their archived category to be restored', function () {
    $owner = User::factory()->administrator()->create();
    $category = Category::factory()->create();
    $product = Product::factory()->for($category)->create();

    $this->actingAs($owner)->delete(route('administration.products.destroy', $product));
    $category->delete();

    $this->get(route('administration.products.index', ['deleted' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('products.data.0.category.is_deleted', true));

    $this->post(route('administration.products.restore', $product->id))
        ->assertSessionHasErrors(['restore' => 'Restore the product category before restoring this product.']);

    $this->post(route('administration.categories.restore', $category->id));
    $this->post(route('administration.products.restore', $product->id))->assertSessionHasNoErrors();

    expect(Product::query()->findOrFail($product->id)->is_active)->toBeFalse();
});

test('administrators can view a product with its catalog details and low stock', function () {
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create(['name' => 'Processors']);
    $tag = Tag::factory()->create(['name' => 'Gaming']);
    $product = Product::factory()->for($category)->has(Inventory::factory()->state([
        'quantity' => 1,
        'reorder_level' => 2,
    ]))->create([
        'product_code' => '80520996',
        'name' => 'Ryzen 7 Processor',
        'description' => 'Eight cores',
        'brand' => null,
        'price' => '21999.00',
        'discount_price' => '19999.00',
        'image_path' => 'products/processors/80520996.webp',
        'is_active' => false,
    ]);
    $product->tags()->attach($tag);

    $this->actingAs($administrator)->get(route('administration.products.show', $product))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Administration/Products/Show')
            ->where('product.id', $product->id)
            ->where('product.product_code', '80520996')
            ->where('product.name', 'Ryzen 7 Processor')
            ->where('product.description', 'Eight cores')
            ->where('product.brand', null)
            ->where('product.price', '21999.00')
            ->where('product.discount_price', '19999.00')
            ->where('product.image_url', $product->image_url)
            ->where('product.is_active', false)
            ->where('product.category.name', 'Processors')
            ->where('product.tags.0.name', 'Gaming')
            ->where('product.inventory.quantity', 1)
            ->where('product.inventory.reorder_level', 2)
            ->where('product.stock_status', 'low_stock'));
});

test('product detail uses the inventory thresholds and handles missing inventory', function (?int $quantity, string $status) {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create();

    if ($quantity !== null) {
        Inventory::factory()->for($product)->create(['quantity' => $quantity, 'reorder_level' => 2]);
    }

    $response = $this->actingAs($administrator)->get(route('administration.products.show', $product));

    $response->assertInertia(fn (Assert $page) => $page->where('product.stock_status', $status));

    if ($quantity === null) {
        $response->assertInertia(fn (Assert $page) => $page->where('product.inventory', null));
    } else {
        $response->assertInertia(fn (Assert $page) => $page->where('product.inventory.quantity', $quantity));
    }
})->with([
    'out of stock' => [0, 'out_of_stock'],
    'at reorder level' => [2, 'low_stock'],
    'above reorder level' => [3, 'in_stock'],
    'not initialized' => [null, 'not_initialized'],
]);

test('only administrators can open product detail', function () {
    $product = Product::factory()->create();

    $this->get(route('administration.products.show', $product))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->customer()->create())
        ->get(route('administration.products.show', $product))->assertForbidden();
});

test('customers are forbidden from catalog product actions', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get(route('administration.products.index'))
        ->assertForbidden();
    $this->actingAs($customer)
        ->post(route('administration.products.store'), [...validPackageMeasurements(),
            'name' => 'Unauthorized product',
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('products', [
        'name' => 'Unauthorized product',
    ]);
});

test('administrators can view active and inactive products with catalog relationships', function () {
    $administrator = User::factory()->administrator()->create();
    $processors = Category::factory()->create(['name' => 'Processors']);
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $product = Product::factory()->for($processors)->create([
        'name' => 'AMD Ryzen 7 9700X',
        'brand' => 'AMD',
        'price' => '21999.00',
        'discount_price' => '19999.00',
        'is_featured' => true,
    ]);
    $product->tags()->attach($gaming);
    Product::factory()->inactive()->create(['name' => 'Legacy processor']);

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.products.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Administration/Products/Index')
        ->has('products.data', 2)
        ->where('products.data.0.name', 'AMD Ryzen 7 9700X')
        ->where('products.data.0.category.name', 'Processors')
        ->where('products.data.0.tags.0.name', 'Gaming')
        ->where('products.data.0.price', '21999.00')
        ->where('products.data.0.discount_price', '19999.00')
        ->where('products.data.0.is_featured', true)
        ->where('products.data.1.name', 'Legacy processor')
        ->where('products.data.1.is_active', false));
});

test('catalog products are paginated in stable name order', function () {
    $administrator = User::factory()->administrator()->create();
    Product::factory()->count(13)->sequence(
        fn ($sequence): array => [
            'name' => sprintf('Product %02d', 13 - $sequence->index),
        ],
    )->create();

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.products.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 12)
        ->where('products.total', 13)
        ->where('products.last_page', 2)
        ->where('products.data.0.name', 'Product 01')
        ->where('products.data.11.name', 'Product 12'));
});

test('administrators can search and filter catalog records', function () {
    $administrator = User::factory()->administrator()->create();
    $processors = Category::factory()->create(['name' => 'Processors']);
    $graphicsCards = Category::factory()->create(['name' => 'Graphics cards']);
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $target = Product::factory()->for($processors)->inactive()->create([
        'name' => 'Ryzen workstation processor',
        'description' => 'Zen architecture for demanding workloads.',
        'brand' => 'AMD',
    ]);
    $target->tags()->attach($gaming);
    Product::factory()->for($processors)->inactive()->create([
        'name' => 'Different processor',
        'brand' => 'Intel',
    ]);
    Product::factory()->for($graphicsCards)->inactive()->create([
        'name' => 'Zen graphics card',
        'brand' => 'AMD',
    ]);
    Product::factory()->for($processors)->create([
        'name' => 'Active Zen processor',
        'brand' => 'AMD',
    ])->tags()->attach($gaming);

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.products.index', [
            'q' => 'Zen',
            'category_id' => $processors->id,
            'brand' => 'AMD',
            'tag_id' => $gaming->id,
            'status' => 'inactive',
        ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('filters.q', 'Zen')
        ->where('filters.category_id', $processors->id)
        ->where('filters.brand', 'AMD')
        ->where('filters.tag_id', $gaming->id)
        ->where('filters.status', 'inactive')
        ->has('products.data', 1)
        ->where('products.data.0.id', $target->id)
        ->where('filter_options.categories.1.name', 'Processors')
        ->where('filter_options.brands.0', 'AMD')
        ->where('filter_options.tags.0.name', 'Gaming'));
});

test('catalog pagination preserves the active administration query', function () {
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $products = Product::factory()->count(13)->for($category)->sequence(
        fn ($sequence): array => ['name' => sprintf('Matching catalog product %02d', $sequence->index + 1)],
    )->create([
        'brand' => 'Battlefront',
        'is_active' => true,
    ]);

    $products->each(fn (Product $product) => $product->tags()->attach($tag));

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.products.index', [
            'q' => 'Matching',
            'category_id' => $category->id,
            'brand' => 'Battlefront',
            'tag_id' => $tag->id,
            'status' => 'active',
        ]));

    $nextPageUrl = $response->inertiaProps('products.next_page_url');

    expect($nextPageUrl)->toContain('page=2')
        ->and($nextPageUrl)->toContain('q=Matching')
        ->and($nextPageUrl)->toContain("category_id={$category->id}")
        ->and($nextPageUrl)->toContain('brand=Battlefront')
        ->and($nextPageUrl)->toContain("tag_id={$tag->id}")
        ->and($nextPageUrl)->toContain('status=active');
});

test('invalid administration catalog filters are rejected', function () {
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)
        ->from(route('administration.products.index'))
        ->get(route('administration.products.index', [
            'category_id' => PHP_INT_MAX,
            'status' => 'archived',
            'page' => 0,
        ]))
        ->assertRedirect(route('administration.products.index'))
        ->assertSessionHasErrors(['category_id', 'status', 'page']);
});

test('administrators can open product forms with approved category and tag options', function () {
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->inactive()->create(['name' => 'Graphics cards']);
    $tag = Tag::factory()->create(['name' => 'Gaming']);
    $product = Product::factory()->for($category)->inactive()->create([
        'name' => 'Radeon RX 9070',
    ]);
    $product->tags()->attach($tag);

    $this->actingAs($administrator)
        ->get(route('administration.products.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Administration/Products/Create')
            ->where('categories.0.name', 'Graphics cards')
            ->where('categories.0.is_active', false)
            ->where('tags.0.name', 'Gaming'));
    $this->actingAs($administrator)
        ->get(route('administration.products.edit', $product))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Administration/Products/Edit')
            ->where('product.name', 'Radeon RX 9070')
            ->where('product.is_active', false)
            ->where('product.tag_ids.0', $tag->id));
});

test('administrators can create products with an uploaded image and validated tags', function () {
    Storage::fake('public');
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create();
    $tags = Tag::factory()->count(2)->create();
    $image = UploadedFile::fake()->image('rtx-5070.webp')->size(1024);

    $response = $this
        ->actingAs($administrator)
        ->post(route('administration.products.store'), [...validPackageMeasurements(),
            'name' => 'GeForce RTX 5070',
            'description' => 'A graphics card for modern games.',
            'category_id' => $category->id,
            'brand' => 'NVIDIA',
            'price' => '39999.00',
            'discount_price' => '37999.50',
            'image' => $image,
            'is_featured' => true,
            'is_active' => false,
            'tag_ids' => $tags->pluck('id')->all(),
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.products.index'))
        ->assertInertiaFlash('toast.message', 'Product created successfully')
        ->assertInertiaFlash('toast.action.label', 'Go to Inventory')
        ->assertInertiaFlash(
            'toast.action.url',
            route('administration.inventory.index', ['q' => 'GeForce RTX 5070']),
        );
    $product = Product::query()->where('name', 'GeForce RTX 5070')->firstOrFail();
    expect($product->is_active)->toBeTrue()
        ->and($product->inventory)->toBeNull()
        ->and($product->image_path)->toStartWith('products/')
        ->and($product->image_path)->not->toStartWith('http')
        ->and($product->tags->modelKeys())->toEqualCanonicalizing($tags->modelKeys());
    Storage::disk('public')->assertExists($product->image_path);
    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'category_id' => $category->id,
        'brand' => 'NVIDIA',
        'price' => '39999.00',
        'discount_price' => '37999.50',
        'is_featured' => true,
        'is_active' => true,
    ]);
});

test('administrators can create a product without an unverified brand', function () {
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create();

    $this->actingAs($administrator)->post(route('administration.products.store'), [...validPackageMeasurements(),
        'name' => 'Product awaiting brand verification',
        'category_id' => $category->id,
        'brand' => '',
        'price' => '100.00',
        'is_featured' => false,
    ])->assertSessionHasNoErrors();

    $product = Product::query()->sole();
    expect($product->brand)->toBeNull();
});

test('product details reject invalid catalog values', function (array $payload, array $errors) {
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create();
    $validPayload = [
        'product_code' => 'RTX5070',
        'name' => 'GeForce RTX 5070',
        'category_id' => $category->id,
        'brand' => 'NVIDIA',
        'price' => '39999.00',
        'is_featured' => false,
    ];

    $response = $this
        ->actingAs($administrator)
        ->from(route('administration.products.create'))
        ->post(
            route('administration.products.store'),
            [...validPackageMeasurements(), ...$validPayload, ...$payload],
        );

    $response
        ->assertRedirect(route('administration.products.create'))
        ->assertSessionHasErrors($errors);
    $this->assertDatabaseMissing('products', [
        'product_code' => 'RTX5070',
        'name' => 'GeForce RTX 5070',
    ]);
})->with([
    'required fields' => [
        [
            'name' => null,
            'category_id' => null,
            'price' => null,
            'is_featured' => null,
        ],
        [
            'name' => 'Enter a product name.',
            'category_id' => 'Select a category.',
            'price' => 'Enter the regular price.',
            'is_featured' => 'Choose whether this is a featured product.',
        ],
    ],
    'unknown relationships' => [
        ['category_id' => PHP_INT_MAX, 'tag_ids' => [PHP_INT_MAX]],
        [
            'category_id' => 'Select a valid category.',
            'tag_ids.0' => 'Select valid product tags.',
        ],
    ],
    'invalid prices' => [
        ['price' => '-1.00', 'discount_price' => '40000.00'],
        [
            'price' => 'The regular price must be zero or greater.',
            'discount_price' => 'The discount price must be lower than the regular price.',
        ],
    ],
    'duplicate tags' => [
        ['tag_ids' => [1, 1]],
        ['tag_ids.0' => 'Each tag may only be selected once.'],
    ],
]);

test('product images reject invalid file types and files larger than 5 MB', function (UploadedFile $image, string $message) {
    Storage::fake('public');
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create();

    $this->actingAs($administrator)
        ->from(route('administration.products.create'))
        ->post(route('administration.products.store'), [...validPackageMeasurements(),
            'product_code' => 'RTX5070',
            'name' => 'GeForce RTX 5070',
            'category_id' => $category->id,
            'brand' => 'NVIDIA',
            'price' => '39999.00',
            'is_featured' => false,
            'image' => $image,
        ])
        ->assertRedirect(route('administration.products.create'))
        ->assertSessionHasErrors(['image' => $message]);

    $this->assertDatabaseMissing('products', ['name' => 'GeForce RTX 5070']);
    Storage::disk('public')->assertDirectoryEmpty('products');
})->with([
    'invalid type' => [
        fn (): UploadedFile => UploadedFile::fake()->image('product.gif'),
        'The product image must be a JPG, JPEG, PNG, or WebP file.',
    ],
    'oversized image' => [
        fn (): UploadedFile => UploadedFile::fake()->image('large.jpg')->size(5121),
        'The product image may not be larger than 5 MB.',
    ],
]);

test('administrators can update product details tags and shipping assignments', function () {
    $administrator = User::factory()->administrator()->create();
    $oldCategory = Category::factory()->create();
    $newCategory = Category::factory()->inactive()->create();
    $oldTag = Tag::factory()->create();
    $newTag = Tag::factory()->create();
    $product = Product::factory()->for($oldCategory)->inactive()->bulky()->create([
        'name' => 'Old product name',
    ]);
    $product->tags()->attach($oldTag);

    $response = $this
        ->actingAs($administrator)
        ->put(route('administration.products.update', $product), [...validPackageMeasurements(),
            'edit_version' => $product->edit_version,
            'price_change_reason' => 'New catalog price approved',
            'product_code' => 'UPDATED001',
            'name' => 'Updated product name',
            'description' => null,
            'category_id' => $newCategory->id,
            'brand' => 'Updated brand',
            'price' => '10000.00',
            'discount_price' => null,
            'is_featured' => false,
            'is_active' => true,
            'shipping_profile' => 'standard',
            'tag_ids' => [$newTag->id],
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.products.index'));
    $product->refresh()->load('tags');
    expect($product->name)->toBe('Updated product name')
        ->and($product->category->is($newCategory))->toBeTrue()
        ->and($product->is_active)->toBeFalse()
        ->and($product->shipping_profile)->toBe(ShippingProfile::Standard)
        ->and($product->shipping_profile_source)->toBe('manual')
        ->and($product->tags->modelKeys())->toBe([$newTag->id]);
    $this->assertDatabaseMissing('product_tag', [
        'product_id' => $product->id,
        'tag_id' => $oldTag->id,
    ]);
});

test('administrators can add a verified brand to an imported product', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create(['brand' => null]);
    $product->is_catalog_imported = true;
    $product->save();

    $this->actingAs($administrator)->put(route('administration.products.update', $product), [...validPackageMeasurements(),
        'edit_version' => $product->edit_version,
        'product_code' => $product->product_code,
        'name' => $product->name,
        'category_id' => $product->category_id,
        'brand' => 'Verified Brand',
        'price' => $product->price,
        'is_featured' => false,
    ])->assertSessionHasNoErrors();

    expect($product->refresh()->brand)->toBe('Verified Brand');
});

test('updating a product without a new image keeps the existing image', function () {
    Storage::fake('public');
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create();
    $existingPath = 'products/existing-image.jpg';
    Storage::disk('public')->put($existingPath, 'existing image');
    $product = Product::factory()->for($category)->create([
        'image_path' => $existingPath,
    ]);

    $this->actingAs($administrator)
        ->post(route('administration.products.update', $product), [...validPackageMeasurements(),
            '_method' => 'put',
            'edit_version' => $product->edit_version,
            'product_code' => 'UPDATED001',
            'name' => 'Updated product',
            'category_id' => $category->id,
            'brand' => $product->brand,
            'price' => $product->price,
            'is_featured' => false,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.products.index'));

    expect($product->refresh()->image_path)->toBe($existingPath);
    Storage::disk('public')->assertExists($existingPath);
});

test('replacing a product image stores the new path and removes the old managed file', function () {
    Storage::fake('public');
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create();
    $oldPath = 'products/old-image.jpg';
    Storage::disk('public')->put($oldPath, 'old image');
    $product = Product::factory()->for($category)->create([
        'image_path' => $oldPath,
    ]);

    $this->actingAs($administrator)
        ->post(route('administration.products.update', $product), [...validPackageMeasurements(),
            '_method' => 'put',
            'edit_version' => $product->edit_version,
            'product_code' => $product->product_code,
            'name' => $product->name,
            'category_id' => $category->id,
            'brand' => $product->brand,
            'price' => $product->price,
            'is_featured' => false,
            'image' => UploadedFile::fake()->image('replacement.png'),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.products.index'));

    $newPath = $product->refresh()->image_path;
    expect($newPath)->toStartWith('products/')
        ->and($newPath)->not->toBe($oldPath);
    Storage::disk('public')->assertExists($newPath);
    Storage::disk('public')->assertMissing($oldPath);
});

test('an invalid product relationship leaves details and tags unchanged', function () {
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $product = Product::factory()->for($category)->create([
        'name' => 'Original product',
        'price' => '10000.00',
    ]);
    $product->tags()->attach($tag);

    $response = $this
        ->actingAs($administrator)
        ->from(route('administration.products.edit', $product))
        ->put(route('administration.products.update', $product), [...validPackageMeasurements(),
            'edit_version' => $product->edit_version,
            'product_code' => 'UPDATED001',
            'name' => 'Changed product',
            'category_id' => $category->id,
            'brand' => $product->brand,
            'price' => '12000.00',
            'is_featured' => false,
            'tag_ids' => [PHP_INT_MAX],
        ]);

    $response
        ->assertRedirect(route('administration.products.edit', $product))
        ->assertSessionHasErrors('tag_ids.0');
    $product->refresh()->load('tags');
    expect($product->name)->toBe('Original product')
        ->and($product->price)->toBe('10000.00')
        ->and($product->tags->modelKeys())->toBe([$tag->id]);
});

test('administrators can deactivate and reactivate products without changing relationships', function () {
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $product = Product::factory()->for($category)->create();
    $product->tags()->attach($tag);
    $inventory = Inventory::factory()->for($product)->create();

    $this->actingAs($administrator)
        ->from(route('administration.products.index'))
        ->patch(route('administration.products.activation.update', $product), [
            'is_active' => false,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.products.index'));

    $product->refresh()->load(['category', 'tags', 'inventory']);
    expect($product->is_active)->toBeFalse()
        ->and($product->category->is($category))->toBeTrue()
        ->and($product->tags->sole()->is($tag))->toBeTrue()
        ->and($product->inventory->is($inventory))->toBeTrue();

    $this->actingAs($administrator)
        ->patch(route('administration.products.activation.update', $product), [
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($product->refresh()->is_active)->toBeTrue();
});

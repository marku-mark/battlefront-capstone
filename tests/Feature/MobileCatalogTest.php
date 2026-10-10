<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Tag;

test('guests receive twelve eligible products per page with stable ordering and filter links', function () {
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $products = Product::factory()->available()->count(13)->for($category)
        ->sequence(fn ($sequence): array => [
            'name' => sprintf('Atlas %02d', $sequence->index + 1),
            'brand' => 'Atlas Hardware',
        ])->create();
    $products->each(fn (Product $product) => $product->tags()->attach($tag));
    $products->last()->update(['is_featured' => true]);
    Product::factory()->available()->for($category)->inactive()->create(['name' => 'Atlas Hidden']);
    Product::factory()->available()->for(Category::factory()->inactive())->create(['name' => 'Atlas Hidden Category']);
    $filters = ['q' => 'Atlas', 'category_id' => $category->id, 'brand' => 'Atlas Hardware', 'tag_id' => $tag->id];

    $firstPage = $this->get(route('api.v1.products.index', $filters));
    $secondPage = $this->get(route('api.v1.products.index', [...$filters, 'page' => 2]));

    $firstPage->assertOk()->assertHeader('Content-Type', 'application/json')
        ->assertJsonCount(12, 'data')
        ->assertJsonPath('data.0.id', $products->last()->id)
        ->assertJsonPath('data.1.name', 'Atlas 01')
        ->assertJsonPath('meta.per_page', 12)
        ->assertJsonPath('meta.total', 13)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonStructure(['links' => ['first', 'last', 'prev', 'next']]);
    $secondPage->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Atlas 12')
        ->assertJsonPath('meta.current_page', 2);
    expect($firstPage->json('links.next'))->toContain('q=Atlas')
        ->toContain('category_id='.$category->id)
        ->toContain('brand=Atlas%20Hardware')
        ->toContain('tag_id='.$tag->id);
});

test('catalog search matches names brands and descriptions with the same web results', function () {
    Product::factory()->available()->create(['name' => 'Atlas Graphics Card']);
    Product::factory()->available()->create(['name' => 'Keyboard', 'brand' => 'Atlas Hardware']);
    Product::factory()->available()->create(['name' => 'Processor', 'description' => 'Built for Atlas workstations.']);
    Product::factory()->available()->create(['name' => 'Unrelated Monitor']);
    Product::factory()->available()->inactive()->create(['name' => 'Inactive Atlas Product']);

    $response = $this->get(route('api.v1.products.index', ['q' => 'Atlas']));
    $webResponse = $this->get(route('products.index', ['q' => 'Atlas']));

    $response->assertOk()->assertJsonCount(3, 'data');
    expect(collect($response->json('data'))->pluck('name')->all())
        ->toBe(['Atlas Graphics Card', 'Keyboard', 'Processor']);
    expect(collect($response->json('data'))->pluck('id')->all())
        ->toBe(collect($webResponse->inertiaProps('products.data'))->pluck('id')->all());
});

test('catalog category brand and tag filters combine', function () {
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $matching = Product::factory()->available()->for($category)->create(['brand' => 'AMD']);
    $matching->tags()->attach($tag);
    $wrongCategory = Product::factory()->available()->create(['brand' => 'AMD']);
    $wrongCategory->tags()->attach($tag);
    $wrongBrand = Product::factory()->available()->for($category)->create(['brand' => 'Intel']);
    $wrongBrand->tags()->attach($tag);
    Product::factory()->available()->for($category)->create(['brand' => 'AMD']);

    $this->get(route('api.v1.products.index', [
        'category_id' => $category->id, 'brand' => 'AMD', 'tag_id' => $tag->id,
    ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id);
});

test('filter options include only eligible categories brands and tags', function () {
    $category = Category::factory()->create(['name' => 'Processors']);
    $tag = Tag::factory()->create(['name' => 'Gaming']);
    $product = Product::factory()->available()->for($category)->create(['brand' => 'AMD']);
    $product->tags()->attach($tag);
    Product::factory()->available()->for($category)->create(['brand' => null]);
    $hidden = Product::factory()->available()->for(Category::factory()->inactive())->create(['brand' => 'Hidden Brand']);
    $hidden->tags()->attach(Tag::factory()->create());
    Product::factory()->available()->inactive()->create(['brand' => 'Inactive Brand']);
    Category::factory()->create();
    Tag::factory()->create();

    $this->get(route('api.v1.products.filters'))->assertOk()->assertExactJson(['data' => [
        'categories' => [['id' => $category->id, 'name' => 'Processors']],
        'brands' => ['AMD'],
        'tags' => [['id' => $tag->id, 'name' => 'Gaming']],
    ]]);
});

test('product detail returns only the mobile catalog fields', function () {
    config(['filesystems.disks.public.url' => 'https://assets.example.test/storage']);
    $category = Category::factory()->create(['name' => 'Graphics Cards']);
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $performance = Tag::factory()->create(['name' => 'Performance']);
    $product = Product::factory()->for($category)->create([
        'name' => 'Atlas Graphics Card', 'description' => 'Current catalog description.',
        'brand' => 'Atlas', 'price' => '39999.00', 'discount_price' => '36999.00',
        'image_path' => 'products/atlas.webp', 'is_featured' => true,
    ]);
    $product->tags()->attach([$performance->id, $gaming->id]);
    Inventory::factory()->for($product)->create(['quantity' => 2, 'reorder_level' => 3]);

    $this->get(route('api.v1.products.show', $product))->assertOk()->assertExactJson(['data' => [
        'id' => $product->id, 'name' => 'Atlas Graphics Card',
        'description' => 'Current catalog description.', 'brand' => 'Atlas',
        'price' => '39999.00', 'discount_price' => '36999.00',
        'image_url' => 'https://assets.example.test/storage/products/atlas.webp',
        'is_featured' => true, 'category' => ['id' => $category->id, 'name' => 'Graphics Cards'],
        'tags' => [['id' => $gaming->id, 'name' => 'Gaming'], ['id' => $performance->id, 'name' => 'Performance']],
        'inventory' => ['status' => 'low_stock'],
    ]]);
});

test('configured image hosts are preserved on product lists as well as details', function () {
    config(['filesystems.disks.public.url' => 'https://assets.example.test/catalog']);
    $product = Product::factory()->available()->create(['image_path' => 'products/example.webp']);
    $this->getJson(route('api.v1.products.index'))->assertOk()
        ->assertJsonPath('data.0.image_url', 'https://assets.example.test/catalog/products/example.webp');
});

test('available list and detail expose stock status without exact quantities', function (int $quantity, int $reorderLevel, string $status) {
    $product = Product::factory()->create(['image_path' => null, 'brand' => null]);
    if ($quantity !== null) {
        Inventory::factory()->for($product)->create(['quantity' => $quantity, 'reorder_level' => $reorderLevel]);
    }

    $this->get(route('api.v1.products.index'))->assertOk()
        ->assertJsonPath('data.0.id', $product->id)
        ->assertJsonPath('data.0.inventory', ['status' => $status])
        ->assertJsonMissingPath('data.0.inventory.quantity')
        ->assertJsonMissingPath('data.0.inventory.reorder_level');
    $this->get(route('api.v1.products.show', $product))->assertOk()
        ->assertJsonPath('data.inventory', ['status' => $status])
        ->assertJsonPath('data.image_url', null)
        ->assertJsonPath('data.brand', null);
})->with([
    'below threshold' => [2, 3, 'low_stock'],
    'at threshold' => [3, 3, 'low_stock'],
    'above threshold' => [4, 3, 'in_stock'],
    'zero threshold' => [1, 0, 'in_stock'],
]);

test('subsequent product requests reflect current Sagay inventory', function () {
    $product = Product::factory()->create();
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 5, 'reorder_level' => 3]);

    $this->get(route('api.v1.products.show', $product))->assertJsonPath('data.inventory.status', 'in_stock');
    $inventory->update(['quantity' => 0]);

    $this->get(route('api.v1.products.show', $product))->assertNotFound();
});

test('inactive products and categories are excluded from lists and return 404 on detail', function (Product $product) {
    $this->get(route('api.v1.products.index'))->assertOk()->assertJsonCount(0, 'data');
    $this->get(route('api.v1.products.show', $product))->assertNotFound()
        ->assertHeader('Content-Type', 'application/json')->assertJsonStructure(['message']);
})->with([
    'inactive product' => fn (): Product => Product::factory()->available()->inactive()->create(),
    'inactive category' => fn (): Product => Product::factory()->available()->for(Category::factory()->inactive())->create(),
]);

test('unknown product identifiers return JSON 404', function (string $identifier) {
    $this->get('/api/v1/products/'.$identifier)->assertNotFound()
        ->assertHeader('Content-Type', 'application/json')->assertJsonStructure(['message']);
})->with(['999999', 'not-a-product']);

test('invalid catalog input returns JSON 422 without an Accept header', function (string $field, mixed $value, string $message) {
    $this->get(route('api.v1.products.index', [$field => $value]))
        ->assertUnprocessable()->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['message', 'errors'])->assertJsonPath('errors.'.$field.'.0', $message);
})->with([
    'search type' => ['q', ['invalid'], 'Search must be text.'],
    'search length' => ['q', str_repeat('a', 256), 'Search may not be longer than 255 characters.'],
    'category type' => ['category_id', 'invalid', 'Select a valid category.'],
    'missing category' => ['category_id', 999999, 'Select an available category.'],
    'brand type' => ['brand', ['invalid'], 'Select a valid brand.'],
    'missing tag' => ['tag_id', 999999, 'Select a valid tag.'],
    'page value' => ['page', 0, 'The catalog page must be at least 1.'],
]);

test('an inactive category filter returns JSON 422', function () {
    $category = Category::factory()->inactive()->create();

    $this->get(route('api.v1.products.index', ['category_id' => $category->id]))
        ->assertUnprocessable()->assertJsonPath('errors.category_id.0', 'Select an available category.');
});

test('unmatched filters return an empty paginated collection', function () {
    Product::factory()->available()->create(['brand' => 'AMD']);

    $this->get(route('api.v1.products.index', ['brand' => 'Intel']))->assertOk()
        ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0)
        ->assertJsonStructure(['data', 'links', 'meta']);
});

test('unsupported stock filtering does not expose unavailable products', function () {
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 5, 'reorder_level' => 2]);
    Product::factory()->create();

    $this->get(route('api.v1.products.index', ['stock' => 'out_of_stock']))
        ->assertOk()->assertJsonCount(1, 'data');
});

test('mobile categories combine multiple categories with effective price and search filters', function () {
    $categories = Category::factory()->count(2)->create();
    foreach ($categories as $category) {
        Product::factory()->available()->for($category)->create(['name' => "Selected GPU {$category->id} Discounted", 'brand' => 'Atlas', 'price' => '9000.00', 'discount_price' => '4999.99']);
        Product::factory()->available()->for($category)->create(['name' => "Selected GPU {$category->id} Regular", 'brand' => 'Atlas', 'price' => '5000.00', 'discount_price' => null]);
        Product::factory()->available()->for($category)->create(['name' => "Selected GPU {$category->id} Other", 'brand' => 'Other', 'price' => '100.00']);
    }
    Product::factory()->available()->create(['name' => 'Selected GPU Outside category', 'brand' => 'Atlas', 'price' => '100.00']);

    $this->getJson(route('api.v1.products.index', ['category_ids' => $categories->modelKeys(), 'brand' => 'Atlas', 'q' => 'GPU', 'max_price' => '4999.99']))
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
    $this->getJson(route('api.v1.products.index', ['category_ids' => $categories->modelKeys(), 'brand' => 'Atlas', 'min_price' => '5000']))
        ->assertOk()->assertJsonCount(2, 'data');
});

test('price ordering uses discounts and stays stable across pages', function () {
    $products = Product::factory()->available()->count(13)->sequence(fn ($sequence): array => [
        'name' => sprintf('Part %02d', $sequence->index), 'price' => '20000.00', 'discount_price' => '15000.00',
    ])->create();
    $cheapest = Product::factory()->available()->create(['name' => 'Discounted', 'price' => '30000.00', 'discount_price' => '14999.99']);
    $this->getJson(route('api.v1.products.index', ['sort' => 'price_asc']))
        ->assertOk()->assertJsonPath('data.0.id', $cheapest->id)->assertJsonPath('meta.total', 14);
    $this->getJson(route('api.v1.products.index', ['sort' => 'price_asc', 'page' => 2]))
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $products[11]->id);
    $this->getJson(route('api.v1.products.index', ['sort' => 'price_desc', 'min_price' => '15000']))
        ->assertOk()->assertJsonPath('data.0.id', $products[0]->id)->assertJsonPath('meta.total', 13);
    $this->getJson(route('api.v1.products.index', ['max_price' => '14999.99']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $cheapest->id);
});

test('invalid category price and sort inputs are rejected', function (array $filters, string $field) {
    $this->getJson(route('api.v1.products.index', $filters))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['category_ids' => 'one'], 'category_ids'],
    [['category_ids' => [999999]], 'category_ids.0'],
    [['min_price' => '-1'], 'min_price'],
    [['min_price' => '20', 'max_price' => '10'], 'max_price'],
    [['max_price' => '1.001'], 'max_price'],
    [['sort' => 'price desc; DROP TABLE products'], 'sort'],
]);

test('mobile category lists combine with search brand tag and inclusive effective price bounds', function () {
    $categories = Category::factory()->count(2)->create();
    $tag = Tag::factory()->create();
    $matchingIds = [];

    foreach ($categories as $category) {
        foreach ([['price' => '9000.00', 'discount_price' => '4999.99'], ['price' => '5000.00', 'discount_price' => null]] as $prices) {
            $product = Product::factory()->available()->for($category)->create([
                'name' => sprintf('Selected GPU %02d', count($matchingIds) + 1), 'brand' => 'Atlas', ...$prices,
            ]);
            $product->tags()->attach($tag);
            $matchingIds[] = $product->id;
        }
    }

    $excluded = Product::factory()->available()->for($categories->first())->createMany([
        ['name' => 'Selected GPU Below bound', 'brand' => 'Atlas', 'price' => '4999.98'],
        ['name' => 'Selected GPU Above bound', 'brand' => 'Atlas', 'price' => '5000.01'],
        ['name' => 'Selected GPU Other brand', 'brand' => 'Other', 'price' => '5000.00'],
        ['name' => 'Monitor', 'brand' => 'Atlas', 'price' => '5000.00'],
        ['name' => 'Selected GPU Inactive', 'brand' => 'Atlas', 'price' => '5000.00', 'is_active' => false],
    ]);
    $excluded->each(fn (Product $product) => $product->tags()->attach($tag));
    Product::factory()->available()->for($categories->first())->create(['name' => 'Selected GPU Without tag', 'brand' => 'Atlas', 'price' => '5000.00']);
    $outsideCategory = Product::factory()->available()->create(['name' => 'Selected GPU Outside category', 'brand' => 'Atlas', 'price' => '5000.00']);
    $outsideCategory->tags()->attach($tag);

    $response = $this->get(route('api.v1.products.index', [
        'category_ids' => $categories->modelKeys(), 'brand' => 'Atlas', 'q' => 'GPU', 'tag_id' => $tag->id,
        'min_price' => '4999.99', 'max_price' => '5000.00',
    ]));

    $response->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('meta.total', 4);
    expect(collect($response->json('data'))->pluck('id')->all())->toBe($matchingIds);
});

test('mobile price sorts use effective prices and name ordering across complete pages', function (string $sort) {
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $products = Product::factory()->available()->count(13)->for($category)->sequence(
        fn ($sequence): array => ['name' => sprintf('Same Part %02d', $sequence->index + 1)],
    )->create([
        'brand' => 'Atlas', 'price' => '20000.00', 'discount_price' => '15000.00',
    ]);
    $cheapest = Product::factory()->available()->for($category)->create([
        'name' => 'Zulu Discounted Part', 'brand' => 'Atlas', 'price' => '30000.00', 'discount_price' => '14999.99',
    ]);
    $expensive = Product::factory()->available()->for($category)->create([
        'name' => 'Alpha Expensive Part', 'brand' => 'Atlas', 'price' => '15000.01', 'is_featured' => true,
    ]);
    $alphaEqual = Product::factory()->available()->for($category)->create(['name' => 'Alpha Equal Part', 'brand' => 'Atlas', 'price' => '15000.00']);
    $zuluEqual = Product::factory()->available()->for($category)->create(['name' => 'Zulu Equal Part', 'brand' => 'Atlas', 'price' => '15000.00']);
    $products->each(fn (Product $product) => $product->tags()->attach($tag));
    foreach ([$cheapest, $expensive, $alphaEqual, $zuluEqual] as $product) {
        $product->tags()->attach($tag);
    }
    $filters = [
        'q' => 'Part', 'brand' => 'Atlas', 'tag_id' => $tag->id,
        'category_ids' => ['selected' => (string) $category->id],
        'min_price' => '14999.99', 'max_price' => '15000.01', 'sort' => $sort,
    ];

    $firstPage = $this->get(route('api.v1.products.index', $filters));
    $secondPage = $this->get($firstPage->json('links.next'));

    $firstPage->assertOk()->assertJsonCount(12, 'data')->assertJsonPath('meta.total', 17);
    $secondPage->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.current_page', 2);
    $expectedIds = $sort === 'price_asc'
        ? [$cheapest->id, $alphaEqual->id, ...$products->modelKeys(), $zuluEqual->id, $expensive->id]
        : [$expensive->id, $alphaEqual->id, ...$products->modelKeys(), $zuluEqual->id, $cheapest->id];
    expect([
        ...collect($firstPage->json('data'))->pluck('id')->all(),
        ...collect($secondPage->json('data'))->pluck('id')->all(),
    ])->toBe($expectedIds);
    parse_str(parse_url($firstPage->json('links.next'), PHP_URL_QUERY), $nextQuery);
    expect($nextQuery)->toBe([
        'q' => 'Part', 'brand' => 'Atlas', 'tag_id' => (string) $tag->id,
        'category_ids' => [(string) $category->id],
        'min_price' => '14999.99', 'max_price' => '15000.01', 'sort' => $sort, 'page' => '2',
    ]);
})->with(['ascending' => ['price_asc'], 'descending' => ['price_desc']]);

test('mobile price bounds preserve zero discounts fallback prices and maximum precision', function (array $filters, string $expectedName) {
    Product::factory()->available()->create(['name' => 'Zero Discount', 'price' => '100.00', 'discount_price' => '0.00']);
    Product::factory()->available()->create(['name' => 'Regular Price', 'price' => '100.01', 'discount_price' => null]);
    Product::factory()->available()->create(['name' => 'Maximum Price', 'price' => '9999999999.99', 'discount_price' => null]);

    $this->get(route('api.v1.products.index', $filters))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', $expectedName);
})->with([
    'zero upper bound' => [['max_price' => '0'], 'Zero Discount'],
    'regular fallback with equal bounds' => [['min_price' => '100.01', 'max_price' => '100.01'], 'Regular Price'],
    'maximum lower bound' => [['min_price' => '9999999999.99'], 'Maximum Price'],
]);

test('empty mobile options retain the existing category and featured ordering', function (mixed $categoryIds, mixed $sort) {
    $category = Category::factory()->create();
    $featured = Product::factory()->available()->for($category)->create(['name' => 'Zulu', 'price' => '100.00', 'is_featured' => true]);
    $regular = Product::factory()->available()->for($category)->create(['name' => 'Alpha', 'price' => '1.00']);
    Product::factory()->available()->create(['name' => 'Outside Category']);

    $response = $this->json('GET', route('api.v1.products.index'), [
        'category_id' => $category->id, 'category_ids' => $categoryIds,
        'min_price' => null, 'max_price' => '', 'sort' => $sort,
    ]);

    $response->assertOk()->assertJsonCount(2, 'data');
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$featured->id, $regular->id]);
})->with([
    'null options' => [null, null],
    'empty options' => [[], ''],
    'explicit featured sort' => [[], 'featured'],
]);

test('populated singular and multiple mobile categories conflict with JSON 422', function () {
    $category = Category::factory()->create();

    $this->get(route('api.v1.products.index', [
        'category_id' => $category->id, 'category_ids' => [$category->id],
    ]))->assertUnprocessable()->assertJsonValidationErrors([
        'category_ids' => 'The category ids field prohibits category id from being present.',
    ]);
});

test('duplicate and inactive mobile categories return JSON 422', function (string $case) {
    $category = Category::factory()->create(['is_active' => $case !== 'inactive']);
    $categoryIds = $case === 'duplicate' ? [$category->id, (string) $category->id] : [$category->id];

    $this->get(route('api.v1.products.index', ['category_ids' => $categoryIds]))
        ->assertUnprocessable()->assertJsonValidationErrors([
            'category_ids.0' => $case === 'duplicate'
                ? 'The category_ids.0 field has a duplicate value.'
                : 'The selected category_ids.0 is invalid.',
        ]);
})->with(['duplicate', 'inactive']);

test('invalid mobile catalog options return native JSON 422 without an Accept header', function (array $filters, string $field, string $message) {
    $this->get(route('api.v1.products.index', $filters))->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['message', 'errors'])
        ->assertOnlyJsonValidationErrors([$field => $message]);
})->with([
    'category scalar' => [['category_ids' => 'one'], 'category_ids', 'The category ids field must be an array.'],
    'missing category' => [['category_ids' => [999999]], 'category_ids.0', 'The selected category_ids.0 is invalid.'],
    'blank category element normalized to null' => [['category_ids' => ['']], 'category_ids.0', 'The category_ids.0 field is required.'],
    'nested category element' => [['category_ids' => [[1]]], 'category_ids.0', 'The category_ids.0 field must be an integer.'],
    'oversized category list' => [['category_ids' => range(1, 51)], 'category_ids', 'The category ids field must not have more than 50 items.'],
    'price type' => [['min_price' => ['invalid']], 'min_price', 'The min price field must be a number.'],
    'negative minimum' => [['min_price' => '-1'], 'min_price', 'The min price field must be at least 0.'],
    'negative maximum' => [['max_price' => '-1'], 'max_price', 'The max price field must be at least 0.'],
    'reversed bounds' => [['min_price' => '20', 'max_price' => '10'], 'max_price', 'The max price field must be greater than or equal to 20.'],
    'minimum precision' => [['min_price' => '1.001'], 'min_price', 'The min price field must have 0-2 decimal places.'],
    'maximum precision' => [['max_price' => '1.001'], 'max_price', 'The max price field must have 0-2 decimal places.'],
    'minimum overflow' => [['min_price' => '10000000000'], 'min_price', 'The min price field must not be greater than 9999999999.99.'],
    'maximum overflow' => [['max_price' => '10000000000'], 'max_price', 'The max price field must not be greater than 9999999999.99.'],
    'scientific notation' => [['min_price' => '1e3'], 'min_price', 'The min price field must have 0-2 decimal places.'],
    'sort value' => [['sort' => 'price desc; DROP TABLE products'], 'sort', 'The selected sort is invalid.'],
    'sort array' => [['sort' => ['price_asc']], 'sort', 'The selected sort is invalid.'],
]);

<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('guests can browse paginated customer eligible products in stable order', function () {
    $activeCategory = Category::factory()->create();
    $inactiveCategory = Category::factory()->inactive()->create();
    Product::factory()
        ->available()
        ->count(13)
        ->for($activeCategory)
        ->sequence(fn ($sequence): array => [
            'name' => sprintf('Product %02d', 13 - $sequence->index),
        ])
        ->create();
    Product::factory()->available()->for($activeCategory)->inactive()->create([
        'name' => 'Inactive product',
    ]);
    Product::factory()->available()->for($inactiveCategory)->create([
        'name' => 'Hidden category product',
    ]);

    $response = $this->get(route('products.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Products/Index')
        ->has('products.data', 12)
        ->where('products.total', 13)
        ->where('products.last_page', 2)
        ->where('products.data.0.name', 'Product 01')
        ->where('products.data.11.name', 'Product 12'));

    expect(collect($response->inertiaProps('products.data'))->pluck('name'))
        ->not->toContain('Inactive product')
        ->not->toContain('Hidden category product');
});

test('available catalog products retain featured and name ordering', function () {
    $inStockZulu = Product::factory()->create([
        'name' => 'Zulu in-stock product',
        'is_featured' => true,
    ]);
    $inStockAlpha = Product::factory()->create(['name' => 'Alpha in-stock product']);
    $inStockVariantFirst = Product::factory()->create(['name' => 'Same in-stock product 01']);
    $inStockVariantSecond = Product::factory()->create(['name' => 'Same in-stock product 02']);
    $lowStockZulu = Product::factory()->create([
        'name' => 'Zulu low-stock product',
        'is_featured' => true,
    ]);
    $lowStockAlpha = Product::factory()->create(['name' => 'Alpha low-stock product']);
    $outOfStock = Product::factory()->create([
        'name' => 'Zulu out-of-stock product',
        'is_featured' => true,
    ]);
    $uninitialized = Product::factory()->create(['name' => 'Alpha out-of-stock product']);

    Inventory::factory()->for($inStockZulu)->create([
        'quantity' => 10,
        'reorder_level' => 5,
    ]);
    Inventory::factory()->for($inStockAlpha)->create([
        'quantity' => 5,
        'reorder_level' => 5,
    ]);
    Inventory::factory()->for($inStockVariantFirst)->create([
        'quantity' => 2,
        'reorder_level' => 0,
    ]);
    Inventory::factory()->for($inStockVariantSecond)->create([
        'quantity' => 2,
        'reorder_level' => 0,
    ]);
    Inventory::factory()->for($lowStockZulu)->create([
        'quantity' => 1,
        'reorder_level' => 2,
    ]);
    Inventory::factory()->for($lowStockAlpha)->create([
        'quantity' => 2,
        'reorder_level' => 3,
    ]);
    Inventory::factory()->for($outOfStock)->create([
        'quantity' => 0,
        'reorder_level' => 3,
    ]);

    $response = $this->get(route('products.index'));

    expect(collect($response->inertiaProps('products.data'))->pluck('id')->all())
        ->toBe([
            $inStockZulu->id,
            $lowStockZulu->id,
            $inStockAlpha->id,
            $lowStockAlpha->id,
            $inStockVariantFirst->id,
            $inStockVariantSecond->id,
        ]);
    expect(collect($response->inertiaProps('products.data'))->pluck('inventory.status')->all())
        ->toBe([
            'in_stock',
            'low_stock',
            'low_stock',
            'low_stock',
            'in_stock',
            'in_stock',
        ]);
});

test('the catalog returns an explicit empty result when no products are eligible', function () {
    Product::factory()->available()->inactive()->create();

    $this->get(route('products.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Products/Index')
            ->has('products.data', 0)
            ->where('products.total', 0));
});

test('the catalog includes products without verified brands but omits null brand filters', function () {
    $product = Product::factory()->available()->create(['brand' => null]);

    $this->get(route('products.index'))->assertInertia(fn (Assert $page) => $page
        ->component('Products/Index')
        ->where('products.data.0.id', $product->id)
        ->where('products.data.0.brand', null)
        ->has('filter_options.brands', 0));
});

test('product details use authoritative catalog relationships and stock data', function () {
    $category = Category::factory()->create(['name' => 'Graphics Cards']);
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $performance = Tag::factory()->create(['name' => 'High Performance']);
    $product = Product::factory()->for($category)->create([
        'name' => 'Atlas Graphics Card',
        'description' => 'A current product description from the catalog.',
        'brand' => 'Battlefront Demo',
        'price' => '39999.00',
        'discount_price' => '36999.00',
        'image_path' => 'products/atlas.png',
        'is_featured' => true,
    ]);
    $product->tags()->attach([$performance->id, $gaming->id]);
    Inventory::factory()->for($product)->create([
        'quantity' => 2,
        'reorder_level' => 3,
    ]);

    $this->get(route('products.show', $product))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Products/Show')
            ->where('product.id', $product->id)
            ->where('product.name', 'Atlas Graphics Card')
            ->where('product.description', 'A current product description from the catalog.')
            ->where('product.brand', 'Battlefront Demo')
            ->where('product.price', '39999.00')
            ->where('product.discount_price', '36999.00')
            ->where('product.image_url', Storage::disk('public')->url('products/atlas.png'))
            ->where('product.is_featured', true)
            ->where('product.category.name', 'Graphics Cards')
            ->where('product.tags.0.name', 'Gaming')
            ->where('product.tags.1.name', 'High Performance')
            ->where('product.inventory.quantity', 2)
            ->where('product.inventory.status', 'low_stock'));
});

test('products without an image expose the catalog fallback state', function () {
    $product = Product::factory()->available()->create(['image_path' => null]);

    $this->get(route('products.show', $product))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Products/Show')
            ->where('product.image_url', null));
});

test('unavailable product details return 404', function (bool $hasInventory) {
    $product = Product::factory()->create();

    if ($hasInventory) {
        Inventory::factory()->for($product)->create([
            'quantity' => 0,
            'reorder_level' => 3,
        ]);
    }

    $this->get(route('products.show', $product))->assertNotFound();
})->with([
    'out of stock' => [true],
    'inventory unavailable' => [false],
]);

test('customer ineligible products are not exposed', function (Product $product) {
    $this->get(route('products.show', $product))->assertNotFound();
})->with([
    'inactive product' => fn (): Product => Product::factory()->available()->inactive()->create(),
    'product in inactive category' => fn (): Product => Product::factory()->available()
        ->for(Category::factory()->inactive())
        ->create(),
]);

test('unknown product identifiers return a not found response', function (string $identifier) {
    $this->get(route('products.show', $identifier))->assertNotFound();
})->with([
    'missing numeric identifier' => '999999',
    'non-numeric identifier' => 'not-a-product',
]);

test('customers can search eligible product names brands and descriptions', function () {
    Product::factory()->available()->create(['name' => 'Atlas Graphics Card']);
    Product::factory()->available()->create([
        'name' => 'Keyboard',
        'brand' => 'Atlas Hardware',
    ]);
    Product::factory()->available()->create([
        'name' => 'Processor',
        'description' => 'Built for Atlas workstations.',
    ]);
    Product::factory()->available()->create(['name' => 'Unrelated Monitor']);
    Product::factory()->available()->inactive()->create(['name' => 'Inactive Atlas Product']);

    $response = $this->get(route('products.index', ['q' => 'Atlas']));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Products/Index')
        ->where('filters.q', 'Atlas')
        ->has('products.data', 3));

    expect(collect($response->inertiaProps('products.data'))->pluck('name')->all())
        ->toBe(['Atlas Graphics Card', 'Keyboard', 'Processor']);
});

test('customers can combine category brand and tag filters', function () {
    $processors = Category::factory()->create(['name' => 'Processors']);
    $storage = Category::factory()->create(['name' => 'Storage']);
    $gaming = Tag::factory()->create(['name' => 'Gaming']);
    $office = Tag::factory()->create(['name' => 'Office']);

    $matchingProduct = Product::factory()->available()->for($processors)->create([
        'name' => 'Matching Processor',
        'brand' => 'AMD',
    ]);
    $matchingProduct->tags()->attach($gaming);

    $wrongCategory = Product::factory()->available()->for($storage)->create([
        'name' => 'Wrong Category',
        'brand' => 'AMD',
    ]);
    $wrongCategory->tags()->attach($gaming);

    $wrongBrand = Product::factory()->available()->for($processors)->create([
        'name' => 'Wrong Brand',
        'brand' => 'Intel',
    ]);
    $wrongBrand->tags()->attach($gaming);

    $wrongTag = Product::factory()->available()->for($processors)->create([
        'name' => 'Wrong Tag',
        'brand' => 'AMD',
    ]);
    $wrongTag->tags()->attach($office);

    $response = $this->get(route('products.index', [
        'category_id' => $processors->id,
        'brand' => 'AMD',
        'tag_id' => $gaming->id,
    ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('filters.category_id', $processors->id)
        ->where('filters.brand', 'AMD')
        ->where('filters.tag_id', $gaming->id)
        ->has('products.data', 1)
        ->where('products.data.0.id', $matchingProduct->id));
});

test('stock availability is not accepted as a customer catalog filter', function () {
    $inStock = Product::factory()->create(['name' => 'In Stock Product']);
    Inventory::factory()->for($inStock)->create([
        'quantity' => 10,
        'reorder_level' => 5,
    ]);
    $outOfStock = Product::factory()->create(['name' => 'Out Of Stock Product']);
    Inventory::factory()->for($outOfStock)->create([
        'quantity' => 0,
        'reorder_level' => 5,
    ]);

    $this->get(route('products.index', ['stock' => 'out_of_stock']))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('filters.stock')
            ->missing('filter_options.stock')
            ->has('products.data', 1)
            ->where('products.data.0.id', $inStock->id));
});

test('catalog pagination preserves active filters and deterministic ordering', function () {
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $products = Product::factory()
        ->available()
        ->count(13)
        ->for($category)
        ->sequence(fn ($sequence): array => [
            'name' => sprintf('Gaming Product %02d', $sequence->index + 1),
            'brand' => 'Battlefront Demo',
        ])
        ->create();
    $products->each(fn (Product $product) => $product->tags()->attach($tag));

    $response = $this->get(route('products.index', [
        'q' => 'Gaming',
        'category_id' => $category->id,
        'brand' => 'Battlefront Demo',
        'tag_id' => $tag->id,
        'page' => 2,
    ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 1)
        ->where('products.data.0.name', 'Gaming Product 13'));

    expect($response->inertiaProps('products.prev_page_url'))
        ->toContain('q=Gaming')
        ->toContain('category_id='.$category->id)
        ->toContain('brand=Battlefront%20Demo')
        ->toContain('tag_id='.$tag->id);
});

test('catalog scroll requests return one page with next and previous page metadata', function () {
    Product::factory()->available()->count(13)->create();

    $firstPage = $this->get(route('products.index'));
    $initialPage = $firstPage->viewData('page');

    $firstPage->assertInertia(fn (Assert $page) => $page->has('products.data', 12));
    expect($initialPage['scrollProps']['products'])->toBe([
        'pageName' => 'page',
        'previousPage' => null,
        'nextPage' => 2,
        'currentPage' => 1,
        'reset' => false,
    ]);

    $lastPage = $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $initialPage['version'],
        'X-Inertia-Partial-Component' => 'Products/Index',
        'X-Inertia-Partial-Data' => 'products',
    ])->get(route('products.index', ['page' => 2]));

    $lastPage
        ->assertJsonCount(1, 'props.products.data')
        ->assertJsonPath('scrollProps.products.currentPage', 2)
        ->assertJsonPath('scrollProps.products.nextPage', null)
        ->assertJsonPath('scrollProps.products.previousPage', 1);

    expect(array_keys($lastPage->json('props')))
        ->toContain('products')
        ->not->toContain('filter_options');
});

test('a filtered catalog scroll response resets previously accumulated products', function () {
    Product::factory()->available()->create(['name' => 'Graphics Card']);
    Product::factory()->available()->create(['name' => 'Keyboard']);
    $version = $this->get(route('products.index'))->viewData('page')['version'];

    $response = $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'Products/Index',
        'X-Inertia-Partial-Data' => 'products',
        'X-Inertia-Reset' => 'products',
    ])->get(route('products.index', ['q' => 'Graphics']));

    $response
        ->assertJsonCount(1, 'props.products.data')
        ->assertJsonPath('props.products.data.0.name', 'Graphics Card')
        ->assertJsonPath('scrollProps.products.reset', true);
});

test('fresh catalog pagination counts only current available inventory', function () {
    $products = Product::factory()
        ->count(13)
        ->sequence(fn ($sequence): array => [
            'name' => sprintf('Product %02d', $sequence->index + 1),
        ])
        ->create();
    $products->each(fn (Product $product) => Inventory::factory()->for($product)->create([
        'quantity' => 5,
        'reorder_level' => 2,
    ]));

    $firstPage = $this->get(route('products.index'));
    $products->first()->inventory()->update(['quantity' => 0]);
    $secondPage = $this->get(route('products.index', ['page' => 2]));

    expect($firstPage->inertiaProps('products.total'))->toBe(13);
    expect($secondPage->inertiaProps('products.total'))->toBe(12);
    expect($secondPage->inertiaProps('products.data'))->toBe([]);
    $refreshed = $this->get(route('products.index'));
    expect(collect($refreshed->inertiaProps('products.data'))->pluck('id')->all())
        ->toBe($products->skip(1)->values()->modelKeys());
});

test('catalog query parameters are validated', function (string $field, mixed $value, string $message) {
    $this->from(route('products.index'))
        ->get(route('products.index', [$field => $value]))
        ->assertRedirect(route('products.index'))
        ->assertSessionHasErrors([$field => $message]);
})->with([
    'search type' => ['q', ['invalid'], 'Search must be text.'],
    'search length' => ['q', str_repeat('a', 256), 'Search may not be longer than 255 characters.'],
    'category identifier' => ['category_id', 'invalid', 'Select a valid category.'],
    'brand type' => ['brand', ['invalid'], 'Select a valid brand.'],
    'tag identifier' => ['tag_id', 999999, 'Select a valid tag.'],
    'page value' => ['page', 0, 'The catalog page must be at least 1.'],
]);

test('inactive categories are rejected as catalog filters', function () {
    $inactiveCategory = Category::factory()->inactive()->create();

    $this->from(route('products.index'))
        ->get(route('products.index', ['category_id' => $inactiveCategory->id]))
        ->assertRedirect(route('products.index'))
        ->assertSessionHasErrors([
            'category_id' => 'Select an available category.',
        ]);
});

test('filter options do not expose values from customer ineligible products', function () {
    $visibleCategory = Category::factory()->create(['name' => 'Visible Category']);
    $visibleTag = Tag::factory()->create(['name' => 'Visible Tag']);
    $visibleProduct = Product::factory()->available()->for($visibleCategory)->create([
        'brand' => 'Visible Brand',
    ]);
    $visibleProduct->tags()->attach($visibleTag);

    $hiddenCategory = Category::factory()->inactive()->create([
        'name' => 'Hidden Category',
    ]);
    $hiddenTag = Tag::factory()->create(['name' => 'Hidden Tag']);
    $hiddenProduct = Product::factory()->available()->for($hiddenCategory)->create([
        'brand' => 'Hidden Brand',
    ]);
    $hiddenProduct->tags()->attach($hiddenTag);

    $response = $this->get(route('products.index'));

    expect($response->inertiaProps('filter_options.categories'))->toHaveCount(1)
        ->and($response->inertiaProps('filter_options.categories.0.name'))->toBe('Visible Category')
        ->and($response->inertiaProps('filter_options.brands'))->toBe(['Visible Brand'])
        ->and($response->inertiaProps('filter_options.tags'))->toHaveCount(1)
        ->and($response->inertiaProps('filter_options.tags.0.name'))->toBe('Visible Tag');
});

test('filtered catalog results are consistent for guests and authenticated customers', function () {
    Product::factory()->available()->create([
        'name' => 'Customer Product',
        'brand' => 'Battlefront Demo',
    ]);
    $customer = User::factory()->create();
    $url = route('products.index', ['brand' => 'Battlefront Demo']);

    $guestResponse = $this->get($url);
    $customerResponse = $this->actingAs($customer)->get($url);

    expect($customerResponse->inertiaProps('products'))->toBe($guestResponse->inertiaProps('products'))
        ->and($customerResponse->inertiaProps('filters'))->toBe($guestResponse->inertiaProps('filters'))
        ->and($customerResponse->inertiaProps('filter_options'))->toBe($guestResponse->inertiaProps('filter_options'));
});

test('the catalog returns an explicit empty result when filters have no matches', function () {
    Product::factory()->available()->create(['brand' => 'AMD']);

    $this->get(route('products.index', ['brand' => 'Intel']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.brand', 'Intel')
            ->has('products.data', 0)
            ->where('products.total', 0));
});

test('web catalog ignores mobile inputs while preserving filters pagination and scroll resets', function (array $mobileFilters) {
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $products = Product::factory()->available()->count(13)->for($category)
        ->sequence(fn ($sequence): array => [
            'name' => sprintf('Atlas Part %02d', $sequence->index),
            'brand' => 'Atlas',
            'price' => (string) ($sequence->index * 100),
        ])->create();
    $products->each(fn (Product $product) => $product->tags()->attach($tag));
    $products[5]->update(['is_featured' => true]);
    $filters = ['q' => 'Atlas', 'category_id' => $category->id, 'brand' => 'Atlas', 'tag_id' => $tag->id];
    $baseline = $this->get(route('products.index', $filters));
    $secondPageBaseline = $this->get(route('products.index', [...$filters, 'page' => 2]));

    $response = $this->get(route('products.index', [...$filters, ...$mobileFilters]));

    $response->assertOk()->assertSessionHasNoErrors();
    expect($response->inertiaProps('filters'))->toBe($filters);
    expect($response->inertiaProps('products'))->toBe($baseline->inertiaProps('products'));
    expect($response->inertiaProps('filter_options'))->toBe($baseline->inertiaProps('filter_options'));

    $version = $baseline->viewData('page')['version'];
    $scrollResponse = $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'Products/Index',
        'X-Inertia-Partial-Data' => 'products',
    ])->get(route('products.index', [...$filters, ...$mobileFilters, 'page' => 2]));

    $scrollResponse->assertOk()
        ->assertJsonPath('props.products', $secondPageBaseline->inertiaProps('products'))
        ->assertJsonPath('scrollProps.products.currentPage', 2)
        ->assertJsonPath('scrollProps.products.nextPage', null)
        ->assertJsonPath('scrollProps.products.reset', false)
        ->assertJsonMissingPath('props.filter_options');

    $resetResponse = $this->withHeaders(['X-Inertia-Reset' => 'products'])
        ->get(route('products.index', [...$filters, ...$mobileFilters, 'q' => 'No matching product']));

    $resetResponse->assertOk()->assertJsonCount(0, 'props.products.data')
        ->assertJsonPath('props.products.total', 0)
        ->assertJsonPath('scrollProps.products.reset', true);
})->with([
    'valid mobile inputs with ascending sort' => [[
        'category_ids' => [999999], 'min_price' => '2000', 'max_price' => '3000', 'sort' => 'price_asc',
    ]],
    'valid price and descending sort inputs' => [[
        'min_price' => '0', 'max_price' => '0', 'sort' => 'price_desc',
    ]],
    'malformed mobile inputs' => [[
        'category_ids' => 'invalid', 'min_price' => ['invalid'], 'max_price' => '-1', 'sort' => 'unsupported',
    ]],
]);

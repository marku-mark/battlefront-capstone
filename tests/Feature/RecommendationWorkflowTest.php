<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CustomerSearch;
use App\Models\GuestRecommendationProfile;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<string, mixed>  $attributes
 */
function createWorkflowProduct(Category $category, array $attributes = []): Product
{
    $product = Product::factory()->for($category)->create($attributes);
    Inventory::factory()->for($product)->create(['quantity' => 8, 'reorder_level' => 2]);

    return $product;
}

test('guests see popular completed-order products and customers see purchase-based recommendations', function () {
    $category = Category::factory()->create(['name' => 'Graphics Cards']);
    $purchased = createWorkflowProduct($category);
    $companion = createWorkflowProduct($category);
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();

    $customerOrder = Order::factory()->for($customer)->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
    ]);
    OrderItem::factory()->for($customerOrder)->for($purchased)->create();

    $relatedOrder = Order::factory()->for($otherCustomer)->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
    ]);
    OrderItem::factory()->for($relatedOrder)->for($purchased)->create();
    OrderItem::factory()->for($relatedOrder)->for($companion)->create();

    $this->get(route('products.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Products/Index')
            ->where('is_personalized', false)
            ->has('recommendations', 2)
            ->where('recommendations.0.reasons.0.code', 'popular_with_customers'));

    $this->actingAs($customer)->get(route('products.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Products/Index')
            ->where('is_personalized', true)
            ->has('recommendations', 1)
            ->where('recommendations.0.product.id', $companion->id)
            ->where('recommendations.0.reasons.0.code', 'bought_with_purchase_history'));
});

test('customers see recommendations based on products in their cart', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $category = Category::factory()->create();
    $cartAnchor = createWorkflowProduct($category);
    $companion = createWorkflowProduct($category);
    $cart = Cart::factory()->for($customer)->create();
    CartItem::factory()->for($cart)->for($cartAnchor)->create();
    $completedOrder = Order::factory()->for($otherCustomer)->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
    ]);
    OrderItem::factory()->for($completedOrder)->for($cartAnchor)->create();
    OrderItem::factory()->for($completedOrder)->for($companion)->create();

    $this->actingAs($customer)->get(route('cart.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('is_personalized', true)
            ->has('recommendations', 1)
            ->where('recommendations.0.product.id', $companion->id)
            ->where('recommendations.0.reasons.0.code', 'bought_with_cart_products'));
});

test('guests and customers are authorized to use recommendations while administrators are denied', function () {
    $customer = User::factory()->customer()->create();
    $administrator = User::factory()->administrator()->create();

    expect(Gate::forUser(null)->allows('use-recommendations'))->toBeTrue()
        ->and(Gate::forUser($customer)->allows('use-recommendations'))->toBeTrue()
        ->and(Gate::forUser($administrator)->allows('use-recommendations'))->toBeFalse();

    $this->actingAs($administrator)
        ->postJson(route('recommendations.interactions.store'), [])
        ->assertForbidden();
});

test('guest catalog recommendations are empty when no eligible fallback exists', function () {
    $this->get(route('products.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Products/Index')
            ->where('is_personalized', false)
            ->where('recommendations', []));
});

test('customers without recommendation signals see a non-personalized empty state', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Customer')
            ->where('is_personalized', false)
            ->where('has_featured_fallback', false)
            ->where('recommendations', []));
});

test('home page omits recommendation data even when eligible products exist', function (bool $authenticated) {
    $category = Category::factory()->create();
    $popularProduct = createWorkflowProduct($category);
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
    ]);
    OrderItem::factory()->for($order)->for($popularProduct)->create();

    if ($authenticated) {
        $this->actingAs($customer);
    }

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->missing('is_personalized')
            ->missing('has_featured_fallback')
            ->missing('recommendations'));
})->with(['guest' => false, 'customer' => true]);

test('product page recommendations exclude the product currently being viewed', function () {
    $category = Category::factory()->create();
    $popularProduct = createWorkflowProduct($category, ['price' => '100.00']);
    $currentProduct = createWorkflowProduct($category, ['price' => '100.00']);
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
    ]);
    OrderItem::factory()->for($order)->for($popularProduct)->create();
    OrderItem::factory()->for($order)->for($currentProduct)->create();

    $this->get(route('products.show', $currentProduct))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Products/Show')
            ->where('is_personalized', true)
            ->has('recommendations', 1)
            ->where('recommendations.0.product.id', $popularProduct->id));
});

test('retired web results route is no longer available', function () {
    $this->get('/recommendations/results')
        ->assertNotFound();
});

test('customers resume personalization through profile settings without changing independent signal preferences', function () {
    $customer = User::factory()->customer()->create([
        'personalized_recommendations_enabled' => false,
        'search_recommendations_enabled' => false,
    ]);

    $this->actingAs($customer)->patch(route('profile.update'), [
        'name' => $customer->name,
        'email' => $customer->email,
        'personalized_recommendations_enabled' => true,
    ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

    expect($customer->refresh()->personalized_recommendations_enabled)->toBeTrue()
        ->and($customer->search_recommendations_enabled)->toBeFalse();
});

test('dedicated recommendation page and personalization shortcut are removed', function () {
    $this->get('/recommendations')->assertNotFound();
    $this->post('/recommendations/personalization')->assertNotFound();

    expect(Route::has('recommendations.index'))->toBeFalse()
        ->and(Route::has('recommendations.personalization.enable'))->toBeFalse()
        ->and(Route::has('recommendations.interactions.store'))->toBeTrue();
});

test('customer dashboard receives a concise behavioral selection', function () {
    $customer = User::factory()->customer()->create();
    $category = Category::factory()->create();
    $match = createWorkflowProduct($category, ['name' => 'RTX 5070', 'is_featured' => false]);
    foreach (range(1, 6) as $index) {
        createWorkflowProduct($category, ['name' => 'Featured product '.$index, 'is_featured' => true]);
    }
    CustomerSearch::factory()->for($customer)->create(['query' => 'RTX 5070', 'expires_at' => now()->addDays(90)]);

    $this->actingAs($customer)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Customer')
            ->where('is_personalized', true)
            ->has('recommendations', 4)
            ->where('recommendations.0.product.id', $match->id)
            ->where('recommendations.0.reasons.0.code', 'matched_recent_searches'));
});

test('administrator dashboard does not receive customer recommendation data', function () {
    $administrator = User::factory()->administrator()->create();
    createWorkflowProduct(Category::factory()->create(), ['is_featured' => true]);

    $this->actingAs($administrator)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Administration')
            ->missing('recommendations')
            ->missing('is_personalized')
            ->missing('has_featured_fallback')
            ->missing('guest_recommendation_scope'));
});

test('default guest catalog uses retained browser history after a search', function () {
    $token = 'catalog-guest-profile';
    $profile = GuestRecommendationProfile::factory()->create(['token_hash' => hash('sha256', $token)]);
    $product = createWorkflowProduct(Category::factory()->create(), ['name' => 'RTX 5070']);

    $this->withCookie('battlefront_recommendation_profile', $token)
        ->get(route('products.index', ['q' => 'RTX 5070']))
        ->assertInertia(fn (Assert $page) => $page->where('recommendations', []));

    $this->get(route('products.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('is_personalized', true)
            ->has('recommendations', 1)
            ->where('recommendations.0.product.id', $product->id)
            ->where('recommendations.0.reasons.0.code', 'matched_recent_searches')
            ->where('guest_recommendation_scope', hash('sha256', 'dismissals:'.$profile->token_hash)));

    $this->assertDatabaseHas('customer_searches', ['guest_recommendation_profile_id' => $profile->id, 'query' => 'rtx 5070']);
});

test('explicit catalog intent suppresses recommendations and clearing restores suggestions', function (string $filter, bool $authenticated) {
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $product = createWorkflowProduct($category, ['name' => 'RTX 5070', 'brand' => 'Atlas', 'is_featured' => true]);
    $product->tags()->attach($tag);
    if ($authenticated) {
        $this->actingAs(User::factory()->customer()->create());
    }
    $value = match ($filter) {
        'q' => 'RTX 5070',
        'category_id' => $category->id,
        'brand' => 'Atlas',
        'tag_id' => $tag->id,
    };

    $this->get(route('products.index', [$filter => $value]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('recommendations', [])
            ->where('is_personalized', false)
            ->where('has_featured_fallback', false)
            ->where('products.total', 1));

    $this->get(route('products.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('recommendations', 1)
            ->where('products.total', 1));
})->with(['q', 'category_id', 'brand', 'tag_id'])->with(['guest' => false, 'customer' => true]);

test('catalog recommendations do not change normal counts pagination or scroll responses', function () {
    $category = Category::factory()->create();
    foreach (range(1, 13) as $index) {
        createWorkflowProduct($category, ['name' => sprintf('Product %02d', $index), 'is_featured' => true]);
    }

    $initial = $this->get(route('products.index'));
    $initial->assertInertia(fn (Assert $page) => $page
        ->has('recommendations', 4)
        ->has('products.data', 12)
        ->where('products.total', 13)
        ->where('products.data.0.name', 'Product 01'));

    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $initial->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'Products/Index',
        'X-Inertia-Partial-Data' => 'products',
    ])->get(route('products.index', ['page' => 2]))
        ->assertJsonCount(1, 'props.products.data')
        ->assertJsonPath('props.products.total', 13)
        ->assertJsonPath('props.products.data.0.name', 'Product 13')
        ->assertJsonMissingPath('props.recommendations')
        ->assertJsonMissingPath('props.is_personalized')
        ->assertJsonPath('scrollProps.products.nextPage', null);
});

test('paused personalization hides dashboard and catalog recommendations even when fallback exists', function (string $routeName) {
    $customer = User::factory()->customer()->create(['personalized_recommendations_enabled' => false]);
    $category = Category::factory()->create();
    $match = createWorkflowProduct($category, ['name' => 'RTX 5070', 'is_featured' => false]);
    createWorkflowProduct($category, ['name' => 'Featured keyboard', 'is_featured' => true]);
    CustomerSearch::factory()->for($customer)->create(['query' => $match->name, 'expires_at' => now()->addDays(90)]);

    $this->actingAs($customer)->get(route($routeName))
        ->assertInertia(fn (Assert $page) => $page
            ->where('is_personalized', false)
            ->where('has_featured_fallback', false)
            ->where('recommendations', []));
})->with(['dashboard', 'products.index']);

test('saving personalization off hides shopping discovery sections until reenabled', function () {
    $customer = User::factory()->customer()->create();
    $category = Category::factory()->create();
    $match = createWorkflowProduct($category, ['name' => 'RTX 5070', 'is_featured' => false]);
    createWorkflowProduct($category, ['name' => 'Featured keyboard', 'is_featured' => true]);
    $search = CustomerSearch::factory()->for($customer)->create(['query' => $match->name, 'expires_at' => now()->addDays(90)]);

    $this->actingAs($customer)->patch(route('profile.update'), [
        'name' => $customer->name,
        'email' => $customer->email,
        'personalized_recommendations_enabled' => false,
    ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

    expect($customer->refresh()->personalized_recommendations_enabled)->toBeFalse()
        ->and($customer->search_recommendations_enabled)->toBeTrue()
        ->and($customer->product_view_recommendations_enabled)->toBeTrue();
    $this->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('personalizedRecommendationsEnabled', false));
    foreach (['dashboard', 'products.index'] as $routeName) {
        $this->get(route($routeName))->assertInertia(fn (Assert $page) => $page
            ->where('recommendations', [])
            ->where('is_personalized', false)
            ->where('has_featured_fallback', false));
    }
    $initial = $this->get(route('products.index'));
    $headers = [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $initial->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'Products/Index',
        'X-Inertia-Partial-Data' => 'products,filters,recommendations,is_personalized,has_featured_fallback,guest_recommendation_scope',
        'X-Inertia-Reset' => 'products',
    ];
    $this->withHeaders($headers)->get(route('products.index', ['q' => 'keyboard']))
        ->assertJsonPath('props.recommendations', []);
    $this->get(route('products.index'))
        ->assertJsonPath('props.filters.q', null)
        ->assertJsonPath('props.recommendations', []);
    $this->flushHeaders();
    $this->get(route('products.index'))->assertInertia(fn (Assert $page) => $page
        ->where('recommendations', []));
    $this->assertDatabaseCount('customer_searches', 1);
    $this->assertModelExists($search);

    $this->patch(route('profile.update'), [
        'name' => $customer->name,
        'email' => $customer->email,
        'personalized_recommendations_enabled' => true,
    ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

    foreach (['dashboard', 'products.index'] as $routeName) {
        $this->get(route($routeName))->assertInertia(fn (Assert $page) => $page
            ->where('is_personalized', true)
            ->where('recommendations.0.product.id', $match->id)
            ->where('recommendations.0.reasons.0.code', 'matched_recent_searches'));
    }
});

test('paused personalization retains contextual and mobile featured fallback', function (string $placement) {
    $customer = User::factory()->customer()->create(['personalized_recommendations_enabled' => false]);
    $category = Category::factory()->create();
    $currentProduct = createWorkflowProduct($category, ['name' => 'RTX 5070', 'is_featured' => false]);
    $featured = createWorkflowProduct($category, ['name' => 'Featured keyboard', 'is_featured' => true]);

    if ($placement === 'mobile') {
        $this->withToken($customer->createToken('Phone')->plainTextToken)
            ->getJson('/api/v1/recommendations')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product.id', $featured->id)
            ->assertJsonPath('data.0.reasons.0.code', 'featured_fallback');

        return;
    }

    $this->actingAs($customer)->get($placement === 'product'
        ? route('products.show', $currentProduct)
        : route('cart.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('is_personalized', false)
            ->where('has_featured_fallback', true)
            ->has('recommendations', 1)
            ->where('recommendations.0.product.id', $featured->id)
            ->where('recommendations.0.reasons.0.code', 'featured_fallback'));
})->with(['product', 'cart', 'mobile']);

test('enabled discovery sections retain featured fallback when browsing signals are disabled', function (string $routeName) {
    $customer = User::factory()->customer()->create([
        'personalized_recommendations_enabled' => true,
        'search_recommendations_enabled' => false,
        'product_view_recommendations_enabled' => false,
    ]);
    $featured = createWorkflowProduct(Category::factory()->create(), ['is_featured' => true]);

    $this->actingAs($customer)->get(route($routeName))
        ->assertInertia(fn (Assert $page) => $page
            ->where('is_personalized', false)
            ->where('has_featured_fallback', true)
            ->has('recommendations', 1)
            ->where('recommendations.0.product.id', $featured->id)
            ->where('recommendations.0.reasons.0.code', 'featured_fallback'));
})->with(['dashboard', 'products.index']);

test('catalog resolves recommendation data once and skips ranking for product-only scrolling', function () {
    $customer = User::factory()->customer()->create();
    $category = Category::factory()->create();
    createWorkflowProduct($category, ['name' => 'RTX 5070']);
    CustomerSearch::factory()->for($customer)->create(['query' => 'RTX 5070', 'expires_at' => now()->addDays(90)]);
    DB::enableQueryLog();

    $initial = $this->actingAs($customer)->get(route('products.index'));

    $searchQueries = collect(DB::getQueryLog())->filter(
        static fn (array $query): bool => str_contains($query['query'], 'customer_searches'),
    );
    expect($searchQueries)->toHaveCount(1);
    DB::flushQueryLog();

    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $initial->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'Products/Index',
        'X-Inertia-Partial-Data' => 'products',
    ])->get(route('products.index', ['page' => 2]))
        ->assertJsonMissingPath('props.recommendations');

    expect(collect(DB::getQueryLog())->filter(
        static fn (array $query): bool => str_contains($query['query'], 'customer_searches'),
    ))->toBeEmpty();
    DB::disableQueryLog();
});

test('catalog query resets return current filters and recommendation state for searching and first clear', function () {
    $customer = User::factory()->customer()->create();
    $category = Category::factory()->create();
    createWorkflowProduct($category, ['name' => 'Laptop', 'is_featured' => true]);
    createWorkflowProduct($category, ['name' => 'Keyboard', 'is_featured' => true]);
    $initial = $this->actingAs($customer)->get(route('products.index'));
    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $initial->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'Products/Index',
        'X-Inertia-Partial-Data' => 'products,filters,filter_options,recommendations,is_personalized,has_featured_fallback,guest_recommendation_scope',
        'X-Inertia-Reset' => 'products',
    ]);

    $this->get(route('products.index', ['q' => 'Laptop']))
        ->assertJsonPath('props.filters.q', 'Laptop')
        ->assertJsonPath('props.products.total', 1)
        ->assertJsonPath('props.recommendations', [])
        ->assertJsonPath('props.is_personalized', false)
        ->assertJsonPath('scrollProps.products.reset', true);

    $this->get(route('products.index'))
        ->assertJsonPath('url', '/products')
        ->assertJsonPath('props.filters.q', null)
        ->assertJsonPath('props.products.total', 2)
        ->assertJsonCount(2, 'props.recommendations')
        ->assertJsonPath('props.is_personalized', true)
        ->assertJsonPath('scrollProps.products.reset', true);
});

<?php

use App\Actions\Recommendation\BuildRecommendationViewData;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CustomerProductView;
use App\Models\CustomerSearch;
use App\Models\GuestRecommendationProfile;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Repositories\Catalog\ProductCatalogRepository;
use App\Services\CatalogProductPresenter;
use App\Services\Recommendation\BehavioralRecommendationEngine;
use Illuminate\Support\Facades\Http;

arch('behavioral recommendations remain independent of chatbot and AI services')
    ->expect([
        'App\Services\Recommendation',
        BuildRecommendationViewData::class,
        ProductCatalogRepository::class,
        CatalogProductPresenter::class,
    ])
    ->not->toUse(['App\Actions\Chatbot', 'App\Services\Chatbot', 'App\Ai', 'Laravel\Ai', Http::class]);

test('sold out historical views still provide similarity anchors while only available products are recommended', function () {
    $customer = User::factory()->customer()->create();
    $category = Category::factory()->create();
    $anchor = createBehavioralRecommendationTestProduct(['category_id' => $category->id, 'brand' => 'Atlas', 'price' => '100.00']);
    $candidate = createBehavioralRecommendationTestProduct(['category_id' => $category->id, 'brand' => 'Atlas', 'price' => '100.00']);
    CustomerProductView::factory()->for($customer)->for($anchor)->create(['expires_at' => now()->addDay()]);
    $anchor->inventory()->update(['quantity' => 0]);

    $results = app(BehavioralRecommendationEngine::class)->recommendFor($customer);

    expect($results->pluck('product.id')->all())->toBe([$candidate->id]);
    expect($results->sole()->reasons)->toContain(['code' => 'similar_to_viewed_product', 'value' => 'Similar to a product you viewed']);
});

function createBehavioralRecommendationTestProduct(array $attributes = []): Product
{
    $product = Product::factory()->create($attributes);
    Inventory::factory()->for($product)->create(['quantity' => 8, 'reorder_level' => 2]);

    return $product;
}

test('unavailable search matches do not consume limits or suppress available featured fallback', function () {
    $customer = User::factory()->customer()->create();
    CustomerSearch::factory()->for($customer)->create(['query' => 'monitor']);
    for ($index = 0; $index < 15; $index++) {
        $product = createBehavioralRecommendationTestProduct(['name' => 'Monitor '.$index]);
        $product->inventory()->update(['quantity' => 0]);
    }
    $available = createBehavioralRecommendationTestProduct(['name' => 'Z monitor']);
    $featured = createBehavioralRecommendationTestProduct(['name' => 'Featured keyboard', 'is_featured' => true]);
    $inactiveCategory = Category::factory()->inactive()->create();
    createBehavioralRecommendationTestProduct(['category_id' => $inactiveCategory->id, 'is_featured' => true]);
    Product::factory()->create(['is_featured' => true]);

    $results = app(BehavioralRecommendationEngine::class)->recommendFor($customer, 4);
    expect($results->pluck('product.id')->all())->toBe([$available->id, $featured->id]);
});

test('similarity evaluates eligible prices and relevance before choosing forty candidates', function () {
    $customer = User::factory()->customer()->create();
    $category = Category::factory()->create();
    $anchor = createBehavioralRecommendationTestProduct(['category_id' => $category->id, 'brand' => 'Atlas', 'price' => '100.00']);
    CustomerProductView::factory()->for($customer)->for($anchor)->create();
    for ($index = 0; $index < 41; $index++) {
        createBehavioralRecommendationTestProduct(['category_id' => $category->id, 'name' => 'A '.$index, 'brand' => null, 'price' => '100.00']);
    }
    $best = createBehavioralRecommendationTestProduct(['category_id' => $category->id, 'name' => 'Z strongest match', 'brand' => 'ATLAS', 'price' => '200.00']);
    createBehavioralRecommendationTestProduct(['category_id' => $category->id, 'brand' => 'Atlas', 'price' => '201.00']);
    expect(app(BehavioralRecommendationEngine::class)->recommendFor($customer, 1)->first()->product->id)->toBe($best->id);
});

test('longer dwell increases a matching product rank without exposing personal durations', function () {
    $customer = User::factory()->customer()->create();
    $firstCategory = Category::factory()->create();
    $secondCategory = Category::factory()->create();
    $firstAnchor = createBehavioralRecommendationTestProduct(['category_id' => $firstCategory->id, 'brand' => null, 'price' => '100.00']);
    $first = createBehavioralRecommendationTestProduct(['category_id' => $firstCategory->id, 'brand' => null, 'price' => '100.00']);
    $secondAnchor = createBehavioralRecommendationTestProduct(['category_id' => $secondCategory->id, 'brand' => null, 'price' => '100.00']);
    $second = createBehavioralRecommendationTestProduct(['category_id' => $secondCategory->id, 'brand' => null, 'price' => '100.00']);
    CustomerProductView::factory()->for($customer)->for($firstAnchor)->create(['dwell_seconds' => 0]);
    CustomerProductView::factory()->for($customer)->for($secondAnchor)->create(['dwell_seconds' => 120]);
    $results = app(BehavioralRecommendationEngine::class)->recommendFor($customer);
    expect($results->pluck('product.id')->all())->toBe([$second->id, $first->id])
        ->and(array_column($results->first()->reasons, 'code'))->toContain('spent_time_viewing_product');
});

test('overlapping customers contribute purchases from separate completed orders', function () {
    $customer = User::factory()->customer()->create();
    $other = User::factory()->customer()->create();
    $anchor = createBehavioralRecommendationTestProduct();
    $candidate = createBehavioralRecommendationTestProduct();
    foreach ([[$customer, $anchor], [$other, $anchor], [$other, $candidate]] as [$buyer, $product]) {
        $order = Order::factory()->for($buyer)->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified]);
        OrderItem::factory()->for($order)->for($product)->create();
    }
    $results = app(BehavioralRecommendationEngine::class)->recommendFor($customer);
    expect($results->pluck('product.id')->all())->toBe([$candidate->id])
        ->and(array_column($results->first()->reasons, 'code'))->toContain('bought_by_similar_customers')
        ->and(array_column($results->first()->reasons, 'code'))->not->toContain('bought_with_purchase_history');
});

test('current product exclusion happens before selection even when personalization is paused', function () {
    $customer = User::factory()->customer()->create(['personalized_recommendations_enabled' => false]);
    $excluded = createBehavioralRecommendationTestProduct(['is_featured' => true, 'name' => 'A']);
    $remaining = createBehavioralRecommendationTestProduct(['is_featured' => true, 'name' => 'Z']);
    expect(app(BehavioralRecommendationEngine::class)->recommendFor($customer, 1, [$excluded->id])->pluck('product.id')->all())
        ->toBe([$remaining->id]);
});

test('frozen-time behavioral ranking is repeatable and returns each candidate once', function () {
    $this->freezeTime();
    $customer = User::factory()->customer()->create();
    $product = createBehavioralRecommendationTestProduct(['name' => 'Keyboard', 'is_featured' => true]);
    CustomerSearch::factory()->count(2)->for($customer)->create(['query' => 'keyboard']);
    $engine = app(BehavioralRecommendationEngine::class);
    expect($engine->recommendFor($customer)->pluck('product.id')->all())->toBe([$product->id])
        ->and($engine->recommendFor($customer)->pluck('product.id')->all())->toBe([$product->id]);
});

test('personalized recommendations use recent opted-in catalog searches and rank newer matches first', function () {
    $customer = User::factory()->customer()->create([
        'search_recommendations_enabled' => true,
    ]);
    $olderSearchMatch = createBehavioralRecommendationTestProduct([
        'name' => 'AMD Radeon RX 7600 Graphics Card',
    ]);
    $newerSearchMatch = createBehavioralRecommendationTestProduct([
        'name' => 'NVIDIA GeForce RTX 4070 Graphics Card',
    ]);

    CustomerSearch::factory()->for($customer)->create([
        'query' => 'Radeon RX 7600',
        'created_at' => now()->subMinute(),
        'expires_at' => now()->addDays(90),
    ]);
    CustomerSearch::factory()->for($customer)->create([
        'query' => 'RTX 4070',
        'created_at' => now(),
        'expires_at' => now()->addDays(90),
    ]);

    $recommendations = app(BehavioralRecommendationEngine::class)->recommendFor($customer);

    expect($recommendations->pluck('product.id')->all())->toBe([$newerSearchMatch->id, $olderSearchMatch->id])
        ->and($recommendations->first()->reasons)->toContain([
            'code' => 'matched_recent_searches',
            'value' => 'Matches a recent catalog search',
        ]);
});

test('guest recommendations use the same recent search ranking as customer recommendations', function () {
    $profile = GuestRecommendationProfile::factory()->create();
    $match = createBehavioralRecommendationTestProduct(['name' => 'RTX 5080 Graphics Card']);
    $profile->searches()->create([
        'query' => 'rtx 5080',
        'expires_at' => now()->addDays(90),
    ]);

    $recommendations = app(BehavioralRecommendationEngine::class)->recommendFor($profile);

    expect($recommendations->first()->product->id)->toBe($match->id)
        ->and($recommendations->first()->reasons)->toContain([
            'code' => 'matched_recent_searches',
            'value' => 'Matches a recent catalog search',
        ]);
});

test('personalized recommendations suggest products often bought with recent viewed products', function () {
    $customer = User::factory()->customer()->create([
        'product_view_recommendations_enabled' => true,
    ]);
    $otherCustomer = User::factory()->customer()->create();
    $viewedProduct = createBehavioralRecommendationTestProduct();
    $companion = createBehavioralRecommendationTestProduct();
    $olderCompanion = createBehavioralRecommendationTestProduct();

    CustomerProductView::factory()->for($customer)->for($viewedProduct)->create([
        'expires_at' => now()->addDays(90),
    ]);

    foreach ([[$viewedProduct, $companion], [$viewedProduct, $olderCompanion]] as [$anchor, $companionProduct]) {
        $order = Order::factory()->for($otherCustomer)->create([
            'status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Verified,
        ]);
        OrderItem::factory()->for($order)->for($anchor)->create();
        OrderItem::factory()->for($order)->for($companionProduct)->create();
    }

    $recommendations = app(BehavioralRecommendationEngine::class)->recommendFor($customer);

    expect($recommendations->pluck('product.id')->all())->toContain($companion->id, $olderCompanion->id)
        ->and($recommendations->pluck('product.id')->all())->not->toContain($viewedProduct->id)
        ->and($recommendations->first()->reasons)->toContain([
            'code' => 'bought_with_viewed_products',
            'value' => 'Often purchased with products you viewed',
        ]);
});

test('one opted-in product view produces catalog-similar recommendations without order history', function () {
    $customer = User::factory()->customer()->create([
        'product_view_recommendations_enabled' => true,
    ]);
    $category = Category::factory()->create();
    $viewedProduct = createBehavioralRecommendationTestProduct([
        'category_id' => $category->id,
        'price' => '100.00',
        'is_featured' => false,
    ]);
    $similarProduct = createBehavioralRecommendationTestProduct([
        'category_id' => $category->id,
        'price' => '150.00',
        'is_featured' => false,
    ]);

    CustomerProductView::factory()->for($customer)->for($viewedProduct)->create([
        'expires_at' => now()->addDays(90),
    ]);

    $recommendations = app(BehavioralRecommendationEngine::class)->recommendFor($customer);

    expect($recommendations->pluck('product.id')->all())->toBe([$similarProduct->id])
        ->and($recommendations->first()->reasons)->toContain([
            'code' => 'similar_to_viewed_product',
            'value' => 'Similar to a product you viewed',
        ]);
});

test('a fresh search can rank above a cart companion through combined scoring', function () {
    $customer = User::factory()->customer()->create([
        'search_recommendations_enabled' => true,
    ]);
    $otherCustomer = User::factory()->customer()->create();
    $cartAnchor = createBehavioralRecommendationTestProduct();
    $cartCompanion = createBehavioralRecommendationTestProduct();
    $searchMatch = createBehavioralRecommendationTestProduct([
        'name' => 'RTX 5080 graphics card',
        'is_featured' => false,
    ]);
    $cart = Cart::factory()->for($customer)->create();
    CartItem::factory()->for($cart)->for($cartAnchor)->create();
    $order = Order::factory()->for($otherCustomer)->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
    ]);
    OrderItem::factory()->for($order)->for($cartAnchor)->create();
    OrderItem::factory()->for($order)->for($cartCompanion)->create();
    CustomerSearch::factory()->for($customer)->create([
        'query' => 'rtx 5080',
        'created_at' => now(),
        'expires_at' => now()->addDays(90),
    ]);

    $recommendations = app(BehavioralRecommendationEngine::class)->recommendFor($customer);

    expect($recommendations->first()->product->id)->toBe($searchMatch->id)
        ->and($recommendations->pluck('product.id')->all())->toContain($cartCompanion->id);
});

test('recommendation ranking mixes categories when enough relevant candidates exist', function () {
    $customer = User::factory()->customer()->create(['search_recommendations_enabled' => true]);
    $graphicsCards = Category::factory()->create();
    $peripherals = Category::factory()->create();

    foreach (['A', 'B', 'C'] as $suffix) {
        createBehavioralRecommendationTestProduct([
            'category_id' => $graphicsCards->id,
            'name' => "RTX 5080 Graphics Card {$suffix}",
            'is_featured' => false,
        ]);
    }

    $otherCategoryMatch = createBehavioralRecommendationTestProduct([
        'category_id' => $peripherals->id,
        'name' => 'RTX 5080 Display Accessory',
        'is_featured' => false,
    ]);
    CustomerSearch::factory()->for($customer)->create([
        'query' => 'rtx 5080',
        'expires_at' => now()->addDays(90),
    ]);

    $recommendations = app(BehavioralRecommendationEngine::class)->recommendFor($customer, limit: 3);

    expect($recommendations->pluck('product.id')->all())->toContain($otherCategoryMatch->id);
});

test('product view recommendations ignore expired views and disabled consent', function () {
    $customer = User::factory()->customer()->create([
        'product_view_recommendations_enabled' => true,
    ]);
    $otherCustomer = User::factory()->customer()->create();
    $viewedProduct = createBehavioralRecommendationTestProduct();
    $companion = createBehavioralRecommendationTestProduct();
    CustomerProductView::factory()->for($customer)->for($viewedProduct)->create([
        'expires_at' => now()->subSecond(),
    ]);
    $order = Order::factory()->for($otherCustomer)->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
    ]);
    OrderItem::factory()->for($order)->for($viewedProduct)->create();
    OrderItem::factory()->for($order)->for($companion)->create();

    $expiredViewRecommendations = app(BehavioralRecommendationEngine::class)->recommendFor($customer);
    $customer->update(['product_view_recommendations_enabled' => false]);
    $disabledConsentRecommendations = app(BehavioralRecommendationEngine::class)->recommendFor($customer);

    $expiredViewReasonCodes = $expiredViewRecommendations->flatMap(
        static fn ($recommendation): array => array_column($recommendation->reasons, 'code'),
    );
    $disabledConsentReasonCodes = $disabledConsentRecommendations->flatMap(
        static fn ($recommendation): array => array_column($recommendation->reasons, 'code'),
    );

    expect($expiredViewReasonCodes)->not->toContain('bought_with_viewed_products')
        ->and($disabledConsentReasonCodes)->not->toContain('bought_with_viewed_products');
});

test('personalized recommendations ignore search history when consent is off or search history expired', function () {
    $nonconsentingCustomer = User::factory()->customer()->create([
        'search_recommendations_enabled' => false,
    ]);
    $nonconsentingMatch = createBehavioralRecommendationTestProduct(['name' => 'RTX 5090 Graphics Card']);
    CustomerSearch::factory()->for($nonconsentingCustomer)->create([
        'query' => 'RTX 5090',
        'expires_at' => now()->addDays(90),
    ]);

    $expiredCustomer = User::factory()->customer()->create([
        'search_recommendations_enabled' => true,
    ]);
    $expiredMatch = createBehavioralRecommendationTestProduct(['name' => 'RX 8900 Graphics Card']);
    CustomerSearch::factory()->for($expiredCustomer)->create([
        'query' => 'RX 8900',
        'expires_at' => now()->subSecond(),
    ]);

    expect(app(BehavioralRecommendationEngine::class)->recommendFor($nonconsentingCustomer))->toBeEmpty()
        ->and(app(BehavioralRecommendationEngine::class)->recommendFor($expiredCustomer))->toBeEmpty()
        ->and($nonconsentingMatch->exists)->toBeTrue()
        ->and($expiredMatch->exists)->toBeTrue();
});

test('personalized recommendations rank products bought with the customer’s cart and completed purchases', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $purchasedAnchor = createBehavioralRecommendationTestProduct();
    $cartAnchor = createBehavioralRecommendationTestProduct();
    $purchasedCompanion = createBehavioralRecommendationTestProduct();
    $cartCompanion = createBehavioralRecommendationTestProduct();
    $sharedCompanion = createBehavioralRecommendationTestProduct();
    $pendingOnlyCompanion = createBehavioralRecommendationTestProduct();

    $purchase = Order::factory()->for($customer)->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
    ]);
    OrderItem::factory()->for($purchase)->for($purchasedAnchor)->create();

    $cart = Cart::factory()->for($customer)->create();
    CartItem::factory()->for($cart)->for($cartAnchor)->create();

    foreach ([
        [$purchasedAnchor, $purchasedCompanion],
        [$purchasedAnchor, $sharedCompanion],
        [$cartAnchor, $cartCompanion],
        [$cartAnchor, $sharedCompanion],
    ] as [$anchor, $companion]) {
        $order = Order::factory()->for($otherCustomer)->create([
            'status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Verified,
        ]);
        OrderItem::factory()->for($order)->for($anchor)->create();
        OrderItem::factory()->for($order)->for($companion)->create();
    }

    $pendingOrder = Order::factory()->for($otherCustomer)->create(['status' => OrderStatus::Processing]);
    OrderItem::factory()->for($pendingOrder)->for($cartAnchor)->create();
    OrderItem::factory()->for($pendingOrder)->for($pendingOnlyCompanion)->create();

    $recommendations = app(BehavioralRecommendationEngine::class)->recommendFor($customer);
    $results = $recommendations->keyBy(fn ($recommendation): int => $recommendation->product->id);

    expect($recommendations->pluck('product.id')->all())->toContain($sharedCompanion->id, $purchasedCompanion->id, $cartCompanion->id)
        ->and($recommendations->pluck('product.id')->all())->not->toContain(
            $purchasedAnchor->id,
            $cartAnchor->id,
            $pendingOnlyCompanion->id,
        )
        ->and($results->get($sharedCompanion->id)->reasons)->toContain(
            ['code' => 'bought_with_cart_products', 'value' => 'Often purchased with products in your cart'],
            ['code' => 'bought_with_purchase_history', 'value' => 'Often purchased with products you bought'],
        );
});

test('personalized recommendations fall back to completed-order popularity and keep live catalog eligibility', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $popular = createBehavioralRecommendationTestProduct();
    $outOfStock = createBehavioralRecommendationTestProduct();
    $inactive = createBehavioralRecommendationTestProduct(['is_active' => false]);

    foreach ([[$popular, $outOfStock], [$popular, $inactive]] as [$product, $alsoPurchased]) {
        $order = Order::factory()->for($otherCustomer)->create([
            'status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Verified,
        ]);
        OrderItem::factory()->for($order)->for($product)->create();
        OrderItem::factory()->for($order)->for($alsoPurchased)->create();
    }

    $outOfStock->inventory()->update(['quantity' => 0]);

    $recommendations = app(BehavioralRecommendationEngine::class)->recommendFor($customer);

    expect($recommendations->pluck('product.id')->all())->toBe([$popular->id])
        ->and($recommendations->first()->reasons)->toBe([
            ['code' => 'popular_with_customers', 'value' => 'Popular with Battlefront customers'],
        ]);
});

test('personalized recommendation endpoint requires a customer token', function () {
    $this->getJson('/api/v1/recommendations/personalized')->assertUnauthorized();

    $administrator = User::factory()->administrator()->create();
    $this->withToken($administrator->createToken('Phone')->plainTextToken)
        ->getJson('/api/v1/recommendations/personalized')->assertForbidden();
});

test('personalized recommendation endpoint returns recommendations for the authenticated customer', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $anchor = createBehavioralRecommendationTestProduct();
    $companion = createBehavioralRecommendationTestProduct(['price' => '250.00', 'discount_price' => '200.00']);
    $pastOrder = Order::factory()->for($customer)->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
    ]);
    OrderItem::factory()->for($pastOrder)->for($anchor)->create();
    $pairedOrder = Order::factory()->for($otherCustomer)->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
    ]);
    OrderItem::factory()->for($pairedOrder)->for($anchor)->create();
    OrderItem::factory()->for($pairedOrder)->for($companion)->create();

    $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->getJson('/api/v1/recommendations/personalized')
        ->assertOk()
        ->assertJsonPath('data.0.product.id', $companion->id)
        ->assertJsonPath('data.0.effective_price', '200.00')
        ->assertJsonPath('data.0.reasons.0.code', 'bought_with_purchase_history');
});

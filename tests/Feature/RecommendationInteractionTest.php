<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\RecommendationInteraction;
use App\Models\User;
use Illuminate\Support\Str;

function createInteractionEligibleProduct(): Product
{
    $product = Product::factory()->for(Category::factory()->create())->create();
    Inventory::factory()->for($product)->create(['quantity' => 5]);

    return $product;
}

test('delayed recommendation feedback remains recordable after the displayed product sells out', function () {
    $product = createInteractionEligibleProduct();
    $product->inventory()->update(['quantity' => 0]);
    $eventId = (string) Str::uuid();

    $this->postJson(route('recommendations.interactions.store'), [
        'event_id' => $eventId, 'product_id' => $product->id, 'event_type' => 'impression',
        'placement' => 'catalog', 'position' => 1, 'reason_code' => 'popular_with_customers',
    ])->assertNoContent();

    $this->assertDatabaseHas('recommendation_interactions', ['event_id' => $eventId, 'product_id' => $product->id]);
    $this->get(route('products.show', $product))->assertNotFound();
});

test('guests can record anonymous recommendation impressions and clicks', function (string $eventType) {
    $product = createInteractionEligibleProduct();
    $eventId = (string) Str::uuid();

    $payload = [
        'event_id' => $eventId,
        'product_id' => $product->id,
        'event_type' => $eventType,
        'placement' => 'home',
        'position' => 2,
        'reason_code' => 'popular_with_customers',
    ];

    $this->postJson(route('recommendations.interactions.store'), $payload)
        ->assertNoContent();

    $this->assertDatabaseHas('recommendation_interactions', [
        'event_id' => $eventId,
        'product_id' => $product->id,
        'event_type' => $eventType,
        'placement' => 'home',
        'position' => 2,
        'reason_code' => 'popular_with_customers',
    ]);
})->with([
    'impression' => 'impression',
    'click' => 'click',
    'dismissal' => 'dismiss',
    'problem report' => 'report_wrong',
]);

test('mobile clients can record anonymous aggregate recommendation events through the api feed contract', function () {
    $product = createInteractionEligibleProduct();
    $eventId = (string) Str::uuid();

    $this->postJson('/api/v1/recommendations/interactions', [
        'event_id' => $eventId,
        'product_id' => $product->id,
        'event_type' => 'impression',
        'placement' => 'recommendations',
        'position' => 1,
        'reason_code' => 'similar_to_viewed_product',
    ])->assertNoContent();

    $this->assertDatabaseHas('recommendation_interactions', [
        'event_id' => $eventId,
        'product_id' => $product->id,
        'placement' => 'recommendations',
        'reason_code' => 'similar_to_viewed_product',
    ]);
});

test('shopping flow placements record every recommendation feedback event', function (string $placement, string $eventType) {
    $product = createInteractionEligibleProduct();
    $customer = User::factory()->customer()->create();
    $eventId = (string) Str::uuid();

    $this->actingAs($customer)->postJson(route('recommendations.interactions.store'), [
        'event_id' => $eventId,
        'product_id' => $product->id,
        'event_type' => $eventType,
        'placement' => $placement,
        'position' => 1,
        'reason_code' => 'matched_recent_searches',
    ])->assertNoContent();

    $this->assertDatabaseHas('recommendation_interactions', [
        'event_id' => $eventId,
        'placement' => $placement,
        'event_type' => $eventType,
    ]);
})->with(['dashboard', 'catalog'])->with(['impression', 'click', 'dismiss', 'report_wrong']);

test('retrying an interaction event does not create a duplicate or alter the original event', function () {
    $product = createInteractionEligibleProduct();
    $eventId = (string) Str::uuid();
    $payload = [
        'event_id' => $eventId,
        'product_id' => $product->id,
        'event_type' => 'impression',
        'placement' => 'product',
        'position' => 1,
    ];

    $this->postJson(route('recommendations.interactions.store'), $payload)->assertNoContent();
    $this->postJson(route('recommendations.interactions.store'), [...$payload, 'event_type' => 'click'])
        ->assertNoContent();

    $this->assertDatabaseCount('recommendation_interactions', 1);
    $this->assertDatabaseHas('recommendation_interactions', [
        'event_id' => $eventId,
        'event_type' => 'impression',
    ]);
});

test('invalid recommendation interaction fields are rejected without persistence', function () {
    $product = createInteractionEligibleProduct();

    $this->postJson(route('recommendations.interactions.store'), [
        'event_id' => 'not-a-uuid',
        'product_id' => $product->id,
        'event_type' => 'view',
        'placement' => 'checkout',
        'position' => 13,
        'reason_code' => 'Invalid reason',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['event_id', 'event_type', 'placement', 'position', 'reason_code']);

    $this->assertDatabaseCount('recommendation_interactions', 0);
});

test('administrators cannot record customer recommendation interactions', function () {
    $product = createInteractionEligibleProduct();
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)
        ->postJson(route('recommendations.interactions.store'), [
            'event_id' => (string) Str::uuid(),
            'product_id' => $product->id,
            'event_type' => 'click',
            'placement' => 'home',
            'position' => 1,
        ])
        ->assertForbidden();

    $this->assertDatabaseCount('recommendation_interactions', 0);
});

test('inactive products are rejected without recording recommendation interactions', function () {
    $product = Product::factory()->inactive()->create();

    $this->postJson(route('recommendations.interactions.store'), [
        'event_id' => (string) Str::uuid(),
        'product_id' => $product->id,
        'event_type' => 'click',
        'placement' => 'home',
        'position' => 1,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('product_id');

    $this->assertDatabaseCount('recommendation_interactions', 0);
});

test('expired recommendation interaction events are pruned while active events are retained', function () {
    $expired = RecommendationInteraction::factory()->create(['expires_at' => now()->subSecond()]);
    $current = RecommendationInteraction::factory()->create(['expires_at' => now()->addSecond()]);

    $this->artisan('app:prune-expired-recommendation-interactions')->assertSuccessful();

    $this->assertDatabaseMissing('recommendation_interactions', ['id' => $expired->id]);
    $this->assertDatabaseHas('recommendation_interactions', ['id' => $current->id]);
});

test('unknown reason codes and inactive categories cannot pollute engagement reports', function () {
    $product = createInteractionEligibleProduct();
    $payload = ['event_id' => (string) Str::uuid(), 'product_id' => $product->id, 'event_type' => 'click', 'placement' => 'home', 'position' => 1];
    $this->postJson(route('recommendations.interactions.store'), [...$payload, 'reason_code' => 'invented_signal'])
        ->assertUnprocessable()->assertJsonValidationErrors('reason_code');
    $product->category->update(['is_active' => false]);
    $this->postJson(route('recommendations.interactions.store'), $payload)->assertNotFound();
    $this->assertDatabaseCount('recommendation_interactions', 0);
});

test('mobile interaction requests retain optional bearer authorization', function (string $access, int $status) {
    $product = createInteractionEligibleProduct();
    if ($access === 'administrator') {
        $this->withToken(User::factory()->administrator()->create()->createToken('Phone')->plainTextToken);
    } elseif ($access === 'invalid') {
        $this->withToken('invalid-token');
    }
    $this->postJson('/api/v1/recommendations/interactions', ['event_id' => (string) Str::uuid(), 'product_id' => $product->id, 'event_type' => 'click', 'placement' => 'home', 'position' => 1])
        ->assertStatus($status);
    $this->assertDatabaseCount('recommendation_interactions', $status === 204 ? 1 : 0);
})->with([['guest', 204], ['administrator', 403], ['invalid', 401]]);

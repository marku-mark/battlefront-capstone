<?php

use App\Actions\Checkout\PrepareCheckout;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use Database\Seeders\BranchSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/** @return array<string, mixed> */
function mobileCheckoutData(array $cartItemIds, array $overrides = []): array
{
    return array_replace([
        'cart_item_ids' => $cartItemIds,
        'recipient_name' => 'Mobile Customer',
        'contact_number' => '09171234567',
        'fulfillment_method' => 'pickup',
        'payment_method' => 'cash',
    ], $overrides);
}

beforeEach(function () {
    Storage::fake('local');
    $this->customer = User::factory()->customer()->create(['default_delivery_address' => 'Saved address']);
    $this->product = Product::factory()->create(['price' => '10.15', 'discount_price' => '9.33']);
    $this->stock = Inventory::factory()->for($this->product)->create(['quantity' => 5]);
    $this->cartItem = app(CartService::class)->add($this->customer, $this->product->id, 2);
    $this->withToken($this->customer->createToken('Phone')->plainTextToken);
});

test('mobile checkout returns shared preparation and manual payment options', function () {
    $this->seed(BranchSeeder::class);
    $prepared = app(PrepareCheckout::class)->execute($this->customer, [$this->cartItem->id]);

    $this->get('/api/v1/checkout?'.http_build_query(['cart_item_ids' => [$this->cartItem->id]]))->assertOk()->assertExactJson(['data' => [
        'cart' => $prepared['cart'],
        'customer' => $prepared['customer'],
        'pickup_location' => $prepared['pickupLocation'],
        'fulfillment_methods' => $prepared['fulfillmentMethods'],
        'payment_methods' => $prepared['paymentMethods'],
        'delivery_quotes' => $prepared['deliveryQuotes'],
        'pickup_quote' => $prepared['pickupQuote'],
    ]])->assertJsonPath('data.cart.total', '18.66')
        ->assertJsonPath('data.customer.default_delivery_address', 'Saved address');

    expect($this->stock->refresh()->quantity)->toBe(5);
    $this->assertDatabaseCount('orders', 0);
    $this->assertModelExists($this->cartItem);
});

test('mobile checkout places all approved payment and fulfillment combinations', function (string $fulfillment, string $payment) {
    $wallet = in_array($payment, ['gcash', 'maya'], true);
    $payload = mobileCheckoutData([$this->cartItem->id], [
        'fulfillment_method' => $fulfillment,
        'payment_method' => $payment,
        'user_id' => User::factory()->customer()->create()->id,
        'total_amount' => '0.01',
        'status' => 'completed',
        'payment_status' => 'verified',
        'items' => [['quantity' => 99]],
    ]);
    if ($fulfillment === 'delivery') {
        $payload['delivery_address'] = 'Checkout address';
        $payload['delivery_destination'] = 'Sagay City';
    }
    if ($wallet) {
        $payload['payment_proof'] = UploadedFile::fake()->image('proof.png');
    }

    $response = $this->post('/api/v1/orders', $payload)->assertCreated()
        ->assertJsonPath('data.total', $fulfillment === 'delivery' ? '98.66' : '18.66')
        ->assertJsonPath('data.status.value', 'pending')
        ->assertJsonPath('data.payment.status.value', 'pending')
        ->assertJsonPath('data.payment.method.value', $payment)
        ->assertJsonPath('data.payment.proof_submitted', $wallet)
        ->assertJsonPath('data.items.0.unit_price', '9.33')
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonMissingPath('data.user_id')
        ->assertJsonMissingPath('data.payment_proof_path')
        ->assertJsonMissingPath('data.payment.proof_path');
    $order = Order::sole();

    expect($response->json('data.id'))->toBe($order->id)
        ->and($order->user_id)->toBe($this->customer->id)
        ->and($order->delivery_address)->toBe($fulfillment === 'delivery' ? 'Checkout address' : null)
        ->and($this->stock->refresh()->quantity)->toBe(3)
        ->and($this->customer->refresh()->default_delivery_address)->toBe('Saved address');
    $this->assertDatabaseCount('carts', 0);
    $this->assertDatabaseCount('cart_items', 0);
    $this->assertDatabaseCount('order_items', 1);

    if ($wallet) {
        Storage::disk('local')->assertExists($order->payment_proof_path);
        expect($response->getContent())->not->toContain($order->payment_proof_path);
    } else {
        expect($order->payment_proof_path)->toBeNull();
    }
})->with([
    ['pickup', 'cash'], ['pickup', 'card_at_store'], ['pickup', 'gcash'],
    ['pickup', 'maya'], ['delivery', 'gcash'], ['delivery', 'maya'],
]);

test('mobile checkout returns native required-field validation', function () {
    $this->post('/api/v1/orders')->assertUnprocessable()
        ->assertJsonValidationErrors(['recipient_name', 'contact_number', 'fulfillment_method', 'payment_method'])
        ->assertJsonStructure(['message', 'errors']);
    $this->assertDatabaseCount('orders', 0);
    expect($this->stock->refresh()->quantity)->toBe(5);
});

test('mobile checkout applies shared fulfillment payment and upload validation', function (Closure $changes, string $field) {
    $coveragePath = config('forecasting.development_manifest');
    $coverageContents = '{"version":2,"products":{}}';
    Storage::disk('local')->put($coveragePath, $coverageContents);

    $this->post('/api/v1/orders', mobileCheckoutData([$this->cartItem->id], $changes()))
        ->assertUnprocessable()->assertJsonValidationErrors($field)
        ->assertJsonStructure(['message', 'errors']);
    $this->assertDatabaseCount('orders', 0);
    $this->assertModelExists($this->cartItem);
    expect($this->stock->refresh()->quantity)->toBe(5)
        ->and(Storage::disk('local')->allFiles('payment-proofs'))->toBe([]);
    expect(Storage::disk('local')->get($coveragePath))->toBe($coverageContents);
})->with([
    'delivery address' => [fn () => ['fulfillment_method' => 'delivery', 'delivery_destination' => 'Sagay City', 'payment_method' => 'gcash', 'payment_proof' => UploadedFile::fake()->image('proof.png')], 'delivery_address'],
    'pickup address' => [fn () => ['delivery_address' => 'Unexpected address'], 'delivery_address'],
    'delivery cash' => [fn () => ['fulfillment_method' => 'delivery', 'delivery_destination' => 'Sagay City', 'delivery_address' => 'Address'], 'payment_method'],
    'delivery card' => [fn () => ['fulfillment_method' => 'delivery', 'delivery_destination' => 'Sagay City', 'delivery_address' => 'Address', 'payment_method' => 'card_at_store'], 'payment_method'],
    'unknown payment' => [fn () => ['payment_method' => 'paypal'], 'payment_method'],
    'missing wallet proof' => [fn () => ['payment_method' => 'gcash'], 'payment_proof'],
    'cash proof' => [fn () => ['payment_proof' => UploadedFile::fake()->image('proof.png')], 'payment_proof'],
    'invalid proof' => [fn () => ['payment_method' => 'maya', 'payment_proof' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')], 'payment_proof'],
    'large proof' => [fn () => ['payment_method' => 'maya', 'payment_proof' => UploadedFile::fake()->image('proof.png')->size(5121)], 'payment_proof'],
]);

test('mobile checkout rejects unavailable stock without orders deductions or orphan proof', function (string $state) {
    match ($state) {
        'inactive' => $this->product->update(['is_active' => false]),
        'category' => $this->product->category->update(['is_active' => false]),
        'missing' => $this->stock->delete(),
        'empty' => $this->stock->update(['quantity' => 0]),
        'insufficient' => $this->stock->update(['quantity' => 1]),
    };
    $this->get('/api/v1/checkout?'.http_build_query(['cart_item_ids' => [$this->cartItem->id]]))->assertUnprocessable()->assertJsonValidationErrors('cart');

    $this->post('/api/v1/orders', mobileCheckoutData([$this->cartItem->id], [
        'payment_method' => 'gcash',
        'payment_proof' => UploadedFile::fake()->image('proof.png'),
    ]))->assertUnprocessable()->assertExactJson([
        'message' => 'Review unavailable products or quantities in your cart before placing an order.',
        'errors' => ['cart' => ['Review unavailable products or quantities in your cart before placing an order.']],
    ]);

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->assertModelExists($this->cartItem);
    expect(Storage::disk('local')->allFiles('payment-proofs'))->toBe([]);
    if ($state !== 'missing') {
        expect($this->stock->refresh()->quantity)->toBe(match ($state) {
            'empty' => 0, 'insufficient' => 1, default => 5,
        });
    }
})->with(['inactive', 'category', 'missing', 'empty', 'insufficient']);

test('mobile order retries cannot place or deduct twice after cart consumption', function () {
    $this->postJson('/api/v1/orders', mobileCheckoutData([$this->cartItem->id]))->assertCreated();
    $this->postJson('/api/v1/orders', mobileCheckoutData([$this->cartItem->id]))->assertUnprocessable()
        ->assertJsonPath('errors.cart', ['Add at least one available product before placing an order.']);
    $this->get('/api/v1/checkout?'.http_build_query(['cart_item_ids' => [$this->cartItem->id]]))->assertUnprocessable()->assertJsonValidationErrors('cart');
    $this->assertDatabaseCount('orders', 1);
    expect($this->stock->refresh()->quantity)->toBe(3);
});

test('mobile placement rolls back stock order and cart on a later line failure and removes proof', function () {
    $second = Product::factory()->create();
    $secondStock = Inventory::factory()->for($second)->create(['quantity' => 5]);
    $secondItem = app(CartService::class)->add($this->customer, $second->id, 1);
    Event::listen('eloquent.creating: '.OrderItem::class, function (OrderItem $item) use ($second): void {
        if ($item->product_id === $second->id) {
            throw new RuntimeException('Forced failure.');
        }
    });
    config(['app.debug' => false]);

    try {
        $this->post('/api/v1/orders', mobileCheckoutData([$this->cartItem->id, $secondItem->id], [
            'payment_method' => 'gcash',
            'payment_proof' => UploadedFile::fake()->image('proof.png'),
        ]))->assertInternalServerError()->assertExactJson(['message' => 'Server Error']);
    } finally {
        Event::forget('eloquent.creating: '.OrderItem::class);
    }

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->assertModelExists($this->cartItem);
    $this->assertModelExists($secondItem);
    expect($this->stock->refresh()->quantity)->toBe(5)
        ->and($secondStock->refresh()->quantity)->toBe(5)
        ->and(Storage::disk('local')->allFiles('payment-proofs'))->toBe([]);
});

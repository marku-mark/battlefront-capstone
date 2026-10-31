<?php

use App\Actions\Cart\CheckoutUnavailableException;
use App\Actions\Cart\ReviewCheckoutCart;
use App\Actions\Order\OrderPlacementException;
use App\Enums\OrderStatus;
use App\Enums\ShippingProfile;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PayMongoFlow;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Order\OrderPlacementService;
use App\Services\Order\OrderProcessingService;
use App\Services\PayMongo\CloseHostedCheckout;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function selectiveCheckoutCart(): array
{
    $customer = User::factory()->customer()->create();
    $items = [];
    $stocks = [];
    foreach ([ShippingProfile::Standard, ShippingProfile::Fragile, ShippingProfile::Bulky] as $index => $profile) {
        $product = Product::factory()->create([
            'price' => ['10.15', '20.00', '30.00'][$index],
            'discount_price' => $index === 0 ? '9.33' : null,
            'shipping_profile' => $profile,
        ]);
        $stocks[] = Inventory::factory()->for($product)->create(['quantity' => 5]);
        $items[] = app(CartService::class)->add($customer, $product->id, $index === 0 ? 2 : 1);
    }

    return [$customer, $items, $stocks];
}

function selectiveCheckoutPayload(array $ids, string $fulfillment = 'pickup'): array
{
    return [
        'cart_item_ids' => $ids,
        'recipient_name' => 'Selected Customer',
        'contact_number' => '09171234567',
        'fulfillment_method' => $fulfillment,
        'payment_method' => $fulfillment === 'pickup' ? 'cash' : 'online',
        ...($fulfillment === 'delivery' ? [
            'payment_channel' => 'hosted_checkout',
            'hosted_payment_method' => 'paymaya',
            'checkout_submission_key' => (string) Str::uuid(),
        ] : []),
        ...($fulfillment === 'delivery' ? [
            'delivery_destination' => 'Sagay City',
            'delivery_barangay_code' => '1804523018',
            'delivery_address_details' => 'Selected delivery address',
        ] : []),
    ];
}

test('selected checkout previews and purchases exactly one multiple or all owned items', function (string $channel, string $fulfillment, array $indexes, string $subtotal, string $profile, string $fee, int $preparation) {
    Storage::fake('local');
    $this->travelTo(Carbon::parse('2026-10-08 12:00:00', 'UTC'));
    $this->seed(BranchSeeder::class);
    [$customer, $items, $stocks] = selectiveCheckoutCart();
    $selectedItems = array_values(array_intersect_key($items, array_flip($indexes)));
    $ids = array_map(fn ($item) => $item->id, $selectedItems);
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);
    $previewUrl = $channel === 'web' ? route('checkout.index') : '/api/v1/checkout';

    $preview = $this->get($previewUrl.'?'.http_build_query(['cart_item_ids' => $ids]));
    $cart = $channel === 'web' ? $preview->inertiaProps('cart') : $preview->json('data.cart');
    $quotes = $channel === 'web' ? $preview->inertiaProps('deliveryQuotes') : $preview->json('data.delivery_quotes');

    expect(array_column($cart['items'], 'id'))->toBe($ids);
    expect($cart['total'])->toBe($subtotal);
    expect($quotes[0])->shipping_profile->toBe($profile)->handling_surcharge->toBe(match ($profile) {
        'standard' => '0.00', 'fragile' => '50.00', 'bulky' => '100.00',
    })->delivery_fee->toBe($fee)->preparation_days->toBe($preparation)
        ->eta_min_days->toBe($preparation)->eta_max_days->toBe($preparation + 1)
        ->estimated_delivery_start->toBe('2026-10-'.str_pad((string) (8 + $preparation), 2, '0', STR_PAD_LEFT))
        ->estimated_delivery_end->toBe('2026-10-'.(9 + $preparation));
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('shipments', 0);

    $response = $this->post($channel === 'web' ? route('orders.store') : '/api/v1/orders', [
        ...selectiveCheckoutPayload(array_map('strval', $ids), $fulfillment),
        'total' => '0.01', 'shipping_profile' => 'bulky', 'delivery_fee' => '999.00',
        'items' => [['product_id' => $items[2]->product_id, 'quantity' => 999, 'price' => '0.01']],
    ]);
    $order = Order::sole();
    $channel === 'web' ? $response->assertRedirectToRoute('orders.show', $order)->assertSessionHasNoErrors() : $response->assertSuccessful();

    expect($order->items->pluck('product_id')->all())->toBe(array_map(fn ($item) => $item->product_id, $selectedItems));
    expect($order)->product_subtotal->toBe($subtotal)->delivery_fee->toBe($fulfillment === 'pickup' ? '0.00' : $fee)
        ->total_amount->toBe(bcadd($subtotal, $fulfillment === 'pickup' ? '0.00' : $fee, 2));
    foreach ($items as $index => $item) {
        if (in_array($index, $indexes, true)) {
            $this->assertModelMissing($item);
        } else {
            $this->assertModelExists($item);
            expect($item->refresh()->quantity)->toBe($index === 0 ? 2 : 1);
        }
        expect($stocks[$index]->refresh()->quantity)->toBe(in_array($index, $indexes, true) ? 5 - $item->quantity : 5);
    }
    expect($customer->refresh()->cart === null)->toBe(count($indexes) === 3);
    if ($fulfillment === 'delivery') {
        expect($order->shipping_profile->value)->toBe($profile);
        expect($order->shipment)->preparation_days->toBe($preparation)->eta_min_days->toBe($preparation)->eta_max_days->toBe($preparation + 1);
        expect($order->payment_proof_path)->toBeNull();
    } else {
        expect($order->shipment)->toBeNull();
    }
})->with(['web', 'api'])->with(['pickup', 'delivery'])->with([
    'single standard leaves fragile and bulky' => [[0], '18.66', 'standard', '195.00', 1],
    'single last item leaves earlier items' => [[2], '30.00', 'bulky', '250.00', 3],
    'multiple leaves bulky' => [[0, 1], '38.66', 'fragile', '290.00', 2],
    'all items' => [[0, 1, 2], '68.66', 'bulky', '385.00', 3],
]);

test('checkout rejects missing empty malformed and duplicate selections without writes', function (string $channel, mixed $selection, string $field) {
    Storage::fake('local');
    [$customer, $items, $stocks] = selectiveCheckoutCart();
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);
    $data = $selection === null ? [] : ['cart_item_ids' => $selection];
    $preview = $this->get(($channel === 'web' ? route('checkout.index') : '/api/v1/checkout').'?'.http_build_query($data));
    $payload = selectiveCheckoutPayload([], 'delivery');
    unset($payload['cart_item_ids']);
    $placement = $this->post($channel === 'web' ? route('orders.store') : '/api/v1/orders', [...$payload, ...$data]);

    if ($channel === 'web') {
        $preview->assertRedirectToRoute('cart.index')->assertSessionHasErrors($field);
        $placement->assertSessionHasErrors($field);
    } else {
        $preview->assertUnprocessable()->assertJsonValidationErrors($field);
        $placement->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('cart_items', 3);
    expect(array_map(fn ($stock) => $stock->refresh()->quantity, $stocks))->toBe([5, 5, 5]);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['web', 'api'])->with([
    'missing' => [null, 'cart_item_ids'], 'empty' => [[], 'cart_item_ids'],
    'scalar' => [1, 'cart_item_ids'], 'associative' => [['item' => 1], 'cart_item_ids'],
    'nonnumeric' => [['tampered'], 'cart_item_ids.0'], 'zero' => [[0], 'cart_item_ids.0'],
    'negative' => [[-1], 'cart_item_ids.0'], 'nested' => [[[1]], 'cart_item_ids.0'],
    'duplicate' => [[1, '1'], 'cart_item_ids.0'],
]);

test('foreign missing or removed selected IDs reject the entire selection safely', function (string $channel, string $invalid) {
    Storage::fake('local');
    [$customer, $items, $stocks] = selectiveCheckoutCart();
    $invalidId = match ($invalid) {
        'foreign' => app(CartService::class)->add(User::factory()->customer()->create(), $items[0]->product_id, 1)->id,
        'removed' => $items[1]->id,
        default => PHP_INT_MAX,
    };
    if ($invalid === 'removed') {
        app(CartService::class)->remove($customer, $invalidId);
    }
    $ids = [$items[0]->id, $invalidId];
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);

    $preview = $this->get(($channel === 'web' ? route('checkout.index') : '/api/v1/checkout').'?'.http_build_query(['cart_item_ids' => $ids]));
    $placement = $this->post($channel === 'web' ? route('orders.store') : '/api/v1/orders', selectiveCheckoutPayload($ids, 'delivery'));

    $message = 'Your selected cart items changed or are unavailable. Review your cart and select items again.';
    if ($channel === 'web') {
        $preview->assertRedirectToRoute('cart.index')->assertInertiaFlash('toast.message', $message);
        $placement->assertSessionHasErrors(['cart' => $message]);
    } else {
        $preview->assertUnprocessable()->assertJsonPath('errors.cart', [$message]);
        $placement->assertUnprocessable()->assertJsonPath('errors.cart', [$message]);
    }
    $this->assertModelExists($items[0]);
    $this->assertModelExists($items[2]);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('shipments', 0);
    expect(array_map(fn ($stock) => $stock->refresh()->quantity, $stocks))->toBe([5, 5, 5]);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['web', 'api'])->with(['foreign', 'missing', 'removed']);

test('unselected unavailable bulky products do not block selected preview or placement', function (string $channel) {
    Storage::fake('local');
    $this->seed(BranchSeeder::class);
    [$customer, $items] = selectiveCheckoutCart();
    $items[2]->product->update(['is_active' => false]);
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);

    $preview = $this->get(($channel === 'web' ? route('checkout.index') : '/api/v1/checkout').'?'.http_build_query(['cart_item_ids' => [$items[0]->id]]));
    $preview->assertOk();
    $response = $this->post($channel === 'web' ? route('orders.store') : '/api/v1/orders', selectiveCheckoutPayload([$items[0]->id], 'delivery'));

    $channel === 'web' ? $response->assertSessionHasNoErrors() : $response->assertSuccessful();
    expect(Order::sole()->shipping_profile)->toBe(ShippingProfile::Standard);
    $this->assertModelExists($items[2]);
})->with(['web', 'api']);

test('partial placement retries never purchase the remaining cart and cancellation restores only purchased stock', function (string $channel, string $fulfillment) {
    Storage::fake('local');
    [$customer, $items, $stocks] = selectiveCheckoutCart();
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);
    $url = $channel === 'web' ? route('orders.store') : '/api/v1/orders';
    $payload = selectiveCheckoutPayload([$items[0]->id], $fulfillment);

    $response = $this->post($url, $payload);
    $channel === 'web' ? $response->assertSessionHasNoErrors() : $response->assertSuccessful();
    $retry = $this->post($url, $payload);
    $channel === 'web' ? $retry->assertSessionHasErrors('cart') : $retry->assertUnprocessable()->assertJsonValidationErrors('cart');

    $order = Order::sole();
    if ($fulfillment === 'delivery') {
        PayMongoFlow::query()->where('order_id', $order->id)->update(['status' => 'closed', 'active_slot' => null, 'closed_at' => now()]);
        $this->mock(CloseHostedCheckout::class)->shouldReceive('execute')->once()->andReturn(true);
    }
    app(OrderProcessingService::class)->updateStatus($order, OrderStatus::Cancelled);

    expect(array_map(fn ($stock) => $stock->refresh()->quantity, $stocks))->toBe([5, 5, 5]);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 1);
    $this->assertModelExists($items[1]);
    $this->assertModelExists($items[2]);
    $this->assertModelMissing($items[0]);
})->with(['web', 'api'])->with(['pickup', 'delivery']);

test('selected stock changes reject every selected line without consuming the remaining cart', function (string $channel) {
    Storage::fake('local');
    $this->seed(BranchSeeder::class);
    [$customer, $items, $stocks] = selectiveCheckoutCart();
    $ids = [$items[0]->id, $items[1]->id];
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);
    $this->get(($channel === 'web' ? route('checkout.index') : '/api/v1/checkout').'?'.http_build_query(['cart_item_ids' => $ids]))->assertOk();
    $stocks[1]->update(['quantity' => 0]);

    $response = $this->post($channel === 'web' ? route('orders.store') : '/api/v1/orders', selectiveCheckoutPayload($ids, 'delivery'));

    $channel === 'web' ? $response->assertSessionHasErrors('cart') : $response->assertUnprocessable()->assertJsonValidationErrors('cart');
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->assertDatabaseCount('shipments', 0);
    $this->assertDatabaseCount('cart_items', 3);
    expect(array_map(fn ($stock) => $stock->refresh()->quantity, $stocks))->toBe([5, 0, 5]);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['web', 'api']);

test('selected placement reloads current quantity price and shipping profile after preview', function (string $channel) {
    Storage::fake('local');
    $this->seed(BranchSeeder::class);
    [$customer, $items, $stocks] = selectiveCheckoutCart();
    $ids = [$items[0]->id];
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);
    $this->get(($channel === 'web' ? route('checkout.index') : '/api/v1/checkout').'?'.http_build_query(['cart_item_ids' => $ids]))->assertOk();
    $items[0]->product->update(['discount_price' => '7.25', 'shipping_profile' => ShippingProfile::Fragile]);
    app(CartService::class)->updateQuantity($customer, $items[0]->id, 3);

    $response = $this->post($channel === 'web' ? route('orders.store') : '/api/v1/orders', selectiveCheckoutPayload($ids, 'delivery'));

    $channel === 'web' ? $response->assertSessionHasNoErrors() : $response->assertSuccessful();
    $order = Order::sole();
    expect($order)->product_subtotal->toBe('21.75')->delivery_fee->toBe('290.00')->total_amount->toBe('311.75')->shipping_profile->toBe(ShippingProfile::Fragile);
    expect($order->items->sole())->quantity->toBe(3)->price_at_time->toBe('7.25');
    expect($order->shipment)->preparation_days->toBe(2)->eta_min_days->toBe(2)->eta_max_days->toBe(3);
    expect(array_map(fn ($stock) => $stock->refresh()->quantity, $stocks))->toBe([2, 5, 5]);
    $this->assertModelExists($items[1]);
    $this->assertModelExists($items[2]);
})->with(['web', 'api']);

test('selected placement failure rolls back stock lines shipment and cart with proof cleanup', function (string $channel, string $failure) {
    Storage::fake('local');
    [$customer, $items, $stocks] = selectiveCheckoutCart();
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);
    $event = 'eloquent.creating: '.($failure === 'line' ? OrderItem::class : Shipment::class);
    Event::listen($event, function ($model) use ($failure, $items): void {
        if ($failure === 'shipment' || $model->product_id === $items[1]->product_id) {
            throw new RuntimeException('Selected placement failed.');
        }
    });
    config(['app.debug' => false]);

    try {
        $this->post($channel === 'web' ? route('orders.store') : '/api/v1/orders', selectiveCheckoutPayload([$items[0]->id, $items[1]->id], 'delivery'))->assertInternalServerError();
    } finally {
        Event::forget($event);
    }

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->assertDatabaseCount('shipments', 0);
    $this->assertDatabaseCount('cart_items', 3);
    expect(array_map(fn ($stock) => $stock->refresh()->quantity, $stocks))->toBe([5, 5, 5]);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['web', 'api'])->with(['line', 'shipment']);

test('direct shared checkout callers cannot silently normalize invalid selections', function (array $ids) {
    [$customer] = selectiveCheckoutCart();

    expect(fn () => app(ReviewCheckoutCart::class)->execute($customer, $ids))->toThrow(CheckoutUnavailableException::class);
    expect(fn () => app(OrderPlacementService::class)->execute($customer, ['cart_item_ids' => $ids]))->toThrow(OrderPlacementException::class);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('cart_items', 3);
})->with(['empty' => [[]], 'duplicate' => [[1, 1]], 'string' => [['1']], 'negative' => [[-1]]]);

test('cart service subset totals do not change ordinary full cart calculations', function () {
    [$customer, $items] = selectiveCheckoutCart();
    $service = app(CartService::class);

    expect($service->totals($customer)['total'])->toBe('68.66');
    expect($service->totals($customer, [$items[0]->id])['total'])->toBe('18.66');
    expect($service->totals($customer, [])['total'])->toBe('0.00');
    expect($service->availability($customer, []))->toBe([]);
});

<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Enums\ShippingProfile;
use App\Jobs\CheckExpoReceipt;
use App\Jobs\SendExpoNotification;
use App\Models\Inventory;
use App\Models\NotificationPushDelivery;
use App\Models\Order;
use App\Models\Product;
use App\Models\PushDevice;
use App\Models\User;
use App\Services\Order\OrderProcessingService;
use Database\Seeders\BranchSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

test('wallet consumer journey purchases selected catalog items and preserves its delivery snapshot and remaining cart', function (string $method) {
    Storage::fake('local');
    $coveragePath = config('forecasting.development_manifest');
    $coverageContents = '{"version":2,"products":{}}';
    Storage::disk('local')->put($coveragePath, $coverageContents);
    $this->travelTo('2026-10-08 09:00:00');
    $this->seed(BranchSeeder::class);
    $customer = User::factory()->customer()->create(['default_delivery_address' => 'Saved address']);
    $products = Product::factory()->count(3)->sequence(
        ['name' => 'Consumer Mouse', 'price' => '10.15', 'discount_price' => '9.33', 'shipping_profile' => ShippingProfile::Standard],
        ['name' => 'Consumer Monitor', 'price' => '20.00', 'discount_price' => null, 'shipping_profile' => ShippingProfile::Fragile],
        ['name' => 'Consumer Case', 'price' => '30.00', 'discount_price' => null, 'shipping_profile' => ShippingProfile::Bulky],
    )->create();
    foreach ($products as $product) {
        Inventory::factory()->for($product)->create(['quantity' => 5]);
    }
    $login = $this->postJson('/api/v1/auth/login', [
        'email' => $customer->email, 'password' => 'password', 'device_name' => 'Consumer phone',
    ])->assertOk()->assertJsonPath('data.expires_at', '2026-11-07T09:00:00+00:00');
    $this->withToken($login->json('data.token'));

    $this->getJson('/api/v1/products?q=Consumer&sort=price_asc')->assertOk()
        ->assertJsonPath('data.0.id', $products[0]->id)->assertJsonPath('meta.per_page', 12);
    $ids = [];
    foreach ($products as $index => $product) {
        $cart = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => $index === 0 ? 2 : 1])->assertOk();
        $ids[] = collect($cart->json('data.items'))->firstWhere('product.id', $product->id)['id'];
    }
    $products[2]->inventory->update(['quantity' => 0]);
    $preview = $this->getJson('/api/v1/checkout?cart_item_ids[]='.$ids[0].'&cart_item_ids[]='.$ids[1])
        ->assertOk()->assertJsonCount(2, 'data.cart.items')->assertJsonPath('data.cart.total', '38.66');
    $quote = collect($preview->json('data.delivery_quotes'))->firstWhere('destination', 'Sagay City');
    expect($quote)->shipping_profile->toBe('fragile')->handling_surcharge->toBe('50.00')
        ->delivery_fee->toBe('130.00')->total->toBe('168.66')->preparation_days->toBe(2)
        ->estimated_delivery_start->toBe('2026-10-10')->estimated_delivery_end->toBe('2026-10-11');

    $payload = [
        'cart_item_ids' => [(string) $ids[0], (string) $ids[1]],
        'recipient_name' => 'Consumer Recipient', 'contact_number' => '09171234567',
        'fulfillment_method' => 'delivery', 'delivery_destination' => 'Sagay City',
        'delivery_address' => 'Submitted street and barangay', 'payment_method' => $method,
        'payment_proof' => UploadedFile::fake()->image('proof.png'),
        'total' => '0.01', 'delivery_fee' => '0.00', 'shipping_profile' => 'bulky',
    ];
    $placed = $this->post('/api/v1/orders', $payload)->assertCreated()
        ->assertJsonPath('data.product_subtotal', '38.66')->assertJsonPath('data.delivery_fee', '130.00')
        ->assertJsonPath('data.total', '168.66')->assertJsonPath('data.fulfillment.delivery_destination', 'Sagay City')
        ->assertJsonPath('data.fulfillment.delivery_address', 'Submitted street and barangay')
        ->assertJsonPath('data.payment.proof_submitted', true)->assertJsonPath('data.payment.status.value', 'pending')
        ->assertJsonPath('data.delivery_quote.shipping_profile', 'fragile')->assertJsonPath('data.shipment.status.value', 'awaiting_preparation')
        ->assertJsonPath('data.shipment.eta.anchor_date', null)->assertJsonCount(2, 'data.items')
        ->assertJsonMissingPath('data.payment_proof_path');
    $order = Order::sole();
    Storage::disk('local')->assertExists($order->payment_proof_path);
    expect($order)->product_subtotal->toBe('38.66')->delivery_fee->toBe('130.00')->total_amount->toBe('168.66');
    expect($order->shipment)->preparation_days->toBe(2)->eta_min_days->toBe(2)->eta_max_days->toBe(3);
    expect($order->items->pluck('product_id')->all())->toBe([$products[0]->id, $products[1]->id]);
    expect($products[0]->inventory->refresh()->quantity)->toBe(3);
    expect($products[1]->inventory->refresh()->quantity)->toBe(4);
    $this->getJson('/api/v1/cart')->assertOk()->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.id', $ids[2])->assertJsonPath('data.items.0.quantity', 1);
    $this->getJson('/api/v1/orders/'.$order->id)->assertExactJson($placed->json());
    $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('data.0.id', $order->id)
        ->assertJsonPath('data.0.total', '168.66')->assertJsonPath('meta.per_page', 10);

    $this->post('/api/v1/orders', [...$payload, 'payment_proof' => UploadedFile::fake()->image('retry.png')])
        ->assertUnprocessable()->assertJsonValidationErrors('cart');
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('shipments', 1);
    expect(Storage::disk('local')->allFiles('payment-proofs'))->toBe([$order->payment_proof_path]);
    expect(Storage::disk('local')->get($coveragePath))->toBe($coverageContents);
    expect($customer->refresh()->default_delivery_address)->toBe('Saved address');
    $products[0]->update(['price' => '999.00', 'discount_price' => null, 'shipping_profile' => ShippingProfile::Bulky]);
    config(['battlefront.delivery.destinations' => [], 'battlefront.delivery.carrier' => 'changed']);
    $this->travel(1)->days();
    $this->getJson('/api/v1/orders/'.$order->id)->assertExactJson($placed->json());
})->with(['gcash', 'maya']);

test('pickup consumer journey uses cart mutations and buys only the selected line with no shipment', function (string $method) {
    $this->seed(BranchSeeder::class);
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create(['price' => '10.15', 'discount_price' => '9.33']);
    $remaining = Product::factory()->create(['shipping_profile' => ShippingProfile::Bulky]);
    Inventory::factory()->for($product)->create(['quantity' => 5]);
    Inventory::factory()->for($remaining)->create(['quantity' => 5]);
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => $customer->email, 'password' => 'password', 'device_name' => 'Pickup phone',
    ])->assertOk()->json('data.token');
    $this->withToken($token);

    $selectedId = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])
        ->assertOk()->json('data.items.0.id');
    $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])
        ->assertOk()->assertJsonPath('data.items.0.quantity', 2);
    $this->patchJson('/api/v1/cart/items/'.$selectedId, ['quantity' => 3])->assertOk()->assertJsonPath('data.total', '27.99');
    $this->deleteJson('/api/v1/cart/items/'.$selectedId)->assertOk()->assertJsonCount(0, 'data.items');
    $selectedId = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2])
        ->assertOk()->json('data.items.0.id');
    $this->postJson('/api/v1/cart/items', ['product_id' => $remaining->id, 'quantity' => 1])->assertOk();
    $this->getJson('/api/v1/checkout?cart_item_ids[]='.$selectedId)->assertOk()
        ->assertJsonPath('data.pickup_quote', ['product_subtotal' => '18.66', 'delivery_fee' => '0.00', 'total' => '18.66']);
    $this->postJson('/api/v1/orders', [
        'cart_item_ids' => [$selectedId], 'recipient_name' => 'Pickup Customer', 'contact_number' => '09171234567',
        'fulfillment_method' => 'pickup', 'payment_method' => $method,
    ])->assertCreated()->assertJsonPath('data.total', '18.66')->assertJsonPath('data.delivery_fee', '0.00')
        ->assertJsonPath('data.fulfillment.delivery_destination', null)->assertJsonPath('data.fulfillment.delivery_address', null)
        ->assertJsonPath('data.delivery_quote', null)->assertJsonPath('data.shipment', null)->assertJsonPath('data.payment.proof_submitted', false);
    $this->getJson('/api/v1/cart')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.product.id', $remaining->id);
    expect($product->inventory->quantity)->toBe(3);
    expect($remaining->inventory->quantity)->toBe(5);
    $this->assertDatabaseCount('shipments', 0);
})->with(['cash', 'card_at_store']);

test('placed delivery milestones share read state and owned deep links while logout suppresses queued push only for its session', function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    Http::fake(['exp.host/--/api/v2/push/send' => Http::response(['data' => ['status' => 'ok', 'id' => 'consumer-ticket']])]);
    Bus::fake([SendExpoNotification::class, CheckExpoReceipt::class]);
    config(['services.expo.enabled' => true]);
    $this->travelTo('2026-10-08 09:00:00');
    $customer = User::factory()->customer()->create();
    $tokens = [];
    $deviceIds = [(string) Str::uuid(), (string) Str::uuid()];
    foreach ($deviceIds as $index => $deviceId) {
        Auth::forgetGuards();
        $tokens[] = $this->postJson('/api/v1/auth/login', [
            'email' => $customer->email, 'password' => 'password', 'device_name' => 'Phone '.$index,
        ])->assertOk()->json('data.token');
        $this->withToken($tokens[$index])->putJson('/api/v1/push-devices/'.$deviceId, [
            'expo_push_token' => 'ExpoPushToken[consumer_'.$index.']', 'platform' => 'android',
        ])->assertOk()->assertJsonMissingPath('data.expo_push_token');
    }
    Auth::forgetGuards();
    $this->withToken($tokens[0]);
    $product = Product::factory()->create(['price' => '10.15', 'discount_price' => '9.33']);
    $stock = Inventory::factory()->for($product)->create(['quantity' => 5]);
    $itemId = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2])->assertOk()->json('data.items.0.id');
    $this->post('/api/v1/orders', [
        'cart_item_ids' => [(string) $itemId], 'recipient_name' => 'Consumer Recipient', 'contact_number' => '09171234567',
        'fulfillment_method' => 'delivery', 'delivery_destination' => 'Sagay City', 'delivery_address' => 'Synthetic address',
        'payment_method' => 'gcash', 'payment_proof' => UploadedFile::fake()->image('proof.png'),
    ])->assertCreated();
    $order = Order::sole();
    $processing = app(OrderProcessingService::class);
    $processing->updatePaymentStatus($order, PaymentStatus::Verified);
    $processing->updateStatus($order, OrderStatus::Processing);
    foreach ([ShipmentStatus::Preparing, ShipmentStatus::ReadyForDispatch, ShipmentStatus::HandedToLbc,
        ShipmentStatus::InTransit, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
        $this->travel(1)->hours();
        $processing->updateShipmentStatus($order, $status, $status === ShipmentStatus::HandedToLbc ? 'SYNTHETIC-TEST-REFERENCE' : null);
    }
    $this->getJson('/api/v1/orders/'.$order->id)->assertOk()->assertJsonPath('data.status.value', 'completed')
        ->assertJsonPath('data.shipment.status.value', 'delivered')->assertJsonCount(7, 'data.shipment.timeline')
        ->assertJsonPath('data.shipment.tracking_reference', 'SYNTHETIC-TEST-REFERENCE')
        ->assertJsonPath('data.shipment.eta.estimated_delivery_start', '2026-10-09')
        ->assertJsonPath('data.shipment.eta.estimated_delivery_end', '2026-10-10')->assertJsonPath('data.total', '98.66');
    expect($stock->refresh()->quantity)->toBe(3);
    expect($order->refresh()->sale->amount)->toBe('98.66');
    $this->assertDatabaseCount('sales', 1);
    $history = $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(7, 'data')
        ->assertJsonPath('meta.unread_count', 7)->assertJsonPath('data.0.event', 'shipment.delivered')
        ->assertJsonPath('data.0.order.deep_link', ['screen' => 'order_detail', 'order_id' => $order->id]);
    $this->getJson($history->json('data.0.order.api_url'))->assertOk()->assertJsonPath('data.id', $order->id);
    $notificationId = $history->json('data.0.id');
    $read = $this->patchJson('/api/v1/notifications/'.$notificationId.'/read')->assertOk()
        ->assertJsonPath('data.is_read', true)->assertJsonPath('meta.unread_count', 6)->json('data.read_at');
    Auth::forgetGuards();
    $this->withToken($tokens[1])->patchJson('/api/v1/notifications/'.$notificationId.'/read')->assertOk()->assertJsonPath('data.read_at', $read);
    $this->patchJson('/api/v1/notifications/read-all')->assertExactJson(['data' => ['unread_count' => 0]]);
    Auth::forgetGuards();
    $this->withToken($tokens[0])->getJson('/api/v1/notifications/unread-count')->assertExactJson(['data' => ['unread_count' => 0]]);

    Bus::assertDispatchedTimes(SendExpoNotification::class, 14);
    $this->postJson('/api/v1/auth/logout')->assertNoContent();
    Auth::forgetGuards();
    $this->getJson('/api/v1/orders/'.$order->id)->assertUnauthorized();
    $devices = PushDevice::query()->whereBelongsTo($customer)->get()->keyBy('device_id');
    expect($devices[$deviceIds[0]])->is_active->toBeFalse()->expo_push_token->toBeNull();
    expect($devices[$deviceIds[1]]->is_active)->toBeTrue();
    foreach (NotificationPushDelivery::all() as $delivery) {
        app()->call([new SendExpoNotification($delivery->id), 'handle']);
        expect($delivery->refresh()->status)->toBe($delivery->push_device_id === $devices[$deviceIds[0]]->id ? 'skipped' : 'accepted');
    }
    Http::assertSentCount(7);
    Http::assertSent(fn (Request $request): bool => $request['to'] === 'ExpoPushToken[consumer_1]'
        && $request['data']['deep_link'] === ['screen' => 'order_detail', 'order_id' => $order->id]
        && array_keys($request['data']) === ['notification_id', 'order_id', 'deep_link']
        && ! str_contains($request->body(), 'Synthetic address') && ! str_contains($request->body(), 'payment-proofs/'));
    Bus::assertDispatchedTimes(CheckExpoReceipt::class, 7);
    Auth::forgetGuards();
    $this->withToken($tokens[1])->getJson('/api/v1/orders/'.$order->id)->assertOk();
    $this->deleteJson('/api/v1/push-devices/'.$deviceIds[1])->assertNoContent();
    expect($devices[$deviceIds[1]]->refresh()->is_active)->toBeFalse();
});

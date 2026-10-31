<?php

use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** @return array{User, CartItem, Inventory} */
function psgcCheckoutCart(?string $defaultAddress = null): array
{
    $customer = User::factory()->customer()->create(['default_delivery_address' => $defaultAddress]);
    $product = Product::factory()->standard()->create(['price' => '100.00', 'discount_price' => null]);
    $stock = Inventory::factory()->for($product)->create(['quantity' => 5]);
    $item = app(CartService::class)->add($customer, $product->id, 1);

    return [$customer, $item, $stock];
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function psgcCheckoutPayload(CartItem $item, array $overrides = []): array
{
    $payload = array_replace([
        'cart_item_ids' => [$item->id],
        'recipient_name' => 'Delivery Customer',
        'contact_number' => '09171234567',
        'fulfillment_method' => 'delivery',
        'delivery_destination' => 'Sagay City',
        'delivery_barangay_code' => '1804523018',
        'delivery_address_details' => '12 Mabini Street',
        'payment_method' => 'online',
        'payment_channel' => 'hosted_checkout',
        'hosted_payment_method' => 'qrph',
        'checkout_submission_key' => (string) Str::uuid(),
    ], $overrides);

    if ($payload['payment_method'] !== 'online') {
        unset($payload['payment_channel'], $payload['hosted_payment_method'], $payload['checkout_submission_key']);
    }

    return $payload;
}

test('web and mobile previews expose identical local dropdown data and conservative prefills', function () {
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    $this->seed(BranchSeeder::class);
    [$customer, $item, $stock] = psgcCheckoutCart('12 Mabini Street, Brgy. Poblacion I, Sagay City, Negros Occidental');
    $query = ['cart_item_ids' => [$item->id]];
    $web = $this->actingAs($customer)->get(route('checkout.index', $query))->assertOk();
    $api = $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->get('/api/v1/checkout?'.http_build_query($query))->assertOk();

    expect($web->inertiaProps('deliveryLocations'))->toHaveCount(12)->toBe($api->json('data.delivery_locations'));
    expect($web->inertiaProps('deliveryAddressPrefill'))->toBe([
        'delivery_destination' => 'Sagay City',
        'delivery_barangay_code' => '1804523018',
        'delivery_address_details' => '12 Mabini Street',
    ])->toBe($api->json('data.delivery_address_prefill'));
    $api->assertJsonPath('data.customer.default_delivery_address', '12 Mabini Street, Brgy. Poblacion I, Sagay City, Negros Occidental');
    expect($stock->refresh()->quantity)->toBe(5);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('shipments', 0);
    Http::assertNothingSent();
});

test('structured delivery placement saves each supported destination with its regional fee and readable address', function (
    string $channel,
    string $destination,
    string $barangayCode,
    string $address,
    string $fee,
    string $total,
) {
    Storage::fake('local');
    [$customer, $item, $stock] = psgcCheckoutCart('Saved address');
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);

    $response = $this->post($channel === 'web' ? route('orders.store') : '/api/v1/orders', psgcCheckoutPayload($item, [
        'delivery_destination' => $destination,
        'delivery_barangay_code' => $barangayCode,
        'delivery_address' => ['untrusted assembled address'],
    ]));

    if ($channel === 'web') {
        $response->assertSessionHasNoErrors()->assertRedirectToRoute('orders.show', Order::sole());
    } else {
        $response->assertSuccessful()->assertJsonPath('data.fulfillment.delivery_address', $address);
    }
    $order = Order::sole();
    expect($order->delivery_address)->toBe($address);
    expect($order->delivery_destination)->toBe($destination);
    expect($order->delivery_fee)->toBe($fee);
    expect($order->total_amount)->toBe($total);
    expect($order->shipment)->not->toBeNull();
    expect($stock->refresh()->quantity)->toBe(4);
    expect($customer->refresh()->default_delivery_address)->toBe('Saved address');
    $this->assertModelMissing($item);
    expect($order->payment_proof_path)->toBeNull();
})->with(['web', 'api'])->with([
    ['Sagay City', '1804523018', '12 Mabini Street, Poblacion I, Sagay City, Negros Occidental', '150.00', '250.00'],
    ['Escalante City', '1804509002', '12 Mabini Street, Balintawak, Escalante City, Negros Occidental', '150.00', '250.00'],
    ['Cadiz City', '1804504001', '12 Mabini Street, Andres Bonifacio, Cadiz City, Negros Occidental', '150.00', '250.00'],
    ['Toboso', '1804529001', '12 Mabini Street, Bandila, Toboso, Negros Occidental', '150.00', '250.00'],
    ['Manapla', '1804518001', '12 Mabini Street, Chambery, Manapla, Negros Occidental', '150.00', '250.00'],
    ['Calatrava', '1804505001', '12 Mabini Street, Agpangi, Calatrava, Negros Occidental', '150.00', '250.00'],
    ['Victorias City', '1804531004', '12 Mabini Street, Barangay I, Victorias City, Negros Occidental', '150.00', '250.00'],
    ['E.B. Magalona', '1804508001', '12 Mabini Street, Alacaygan, E.B. Magalona, Negros Occidental', '150.00', '250.00'],
    ['San Carlos City', '1804524001', '12 Mabini Street, Bagonbon, San Carlos City, Negros Occidental', '150.00', '250.00'],
    ['Silay City', '1804526001', '12 Mabini Street, Balaring, Silay City, Negros Occidental', '150.00', '250.00'],
    ['Talisay City', '1804528001', '12 Mabini Street, Bubog, Talisay City, Negros Occidental', '150.00', '250.00'],
    ['Bacolod City', '1830200008', '12 Mabini Street, Barangay 13, Bacolod City', '150.00', '250.00'],
]);

test('invalid structured addresses fail before placement with no inventory or proof side effects', function (
    string $channel,
    array $overrides,
    string $field,
    string $message,
) {
    Storage::fake('local');
    [$customer, $item, $stock] = psgcCheckoutCart();
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);

    $response = $this->post($channel === 'web' ? route('orders.store') : '/api/v1/orders', psgcCheckoutPayload($item, $overrides));

    if ($channel === 'web') {
        $response->assertSessionHasErrors([$field => $message]);
    } else {
        $response->assertUnprocessable()->assertJsonPath('errors.'.$field, [$message]);
    }
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->assertDatabaseCount('shipments', 0);
    $this->assertModelExists($item);
    expect($stock->refresh()->quantity)->toBe(5);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['web', 'api'])->with([
    'unsupported city' => [['delivery_destination' => 'Guihulngan City'], 'delivery_destination', 'Select a supported delivery destination.'],
    'mismatched barangay' => [['delivery_barangay_code' => '1804524001'], 'delivery_barangay_code', 'Select a barangay belonging to the selected city or municipality.'],
    'unknown barangay' => [['delivery_barangay_code' => '9999999999'], 'delivery_barangay_code', 'Select a barangay belonging to the selected city or municipality.'],
    'missing barangay' => [['delivery_barangay_code' => null], 'delivery_barangay_code', 'Select a barangay.'],
    'numeric code' => [['delivery_barangay_code' => 1804523018], 'delivery_barangay_code', 'Select a valid barangay.'],
    'array code' => [['delivery_barangay_code' => ['1804523018']], 'delivery_barangay_code', 'Select a valid barangay.'],
    'short code' => [['delivery_barangay_code' => '180452301'], 'delivery_barangay_code', 'Select a valid barangay.'],
    'missing details' => [['delivery_address_details' => null], 'delivery_address_details', 'Enter the house, building, street, or landmark details.'],
    'blank details' => [['delivery_address_details' => '   '], 'delivery_address_details', 'Enter the house, building, street, or landmark details.'],
    'array details' => [['delivery_address_details' => ['Street']], 'delivery_address_details', 'Enter the house, building, street, or landmark details.'],
    'details too long' => [['delivery_address_details' => str_repeat('a', 256)], 'delivery_address_details', 'Delivery address details may not exceed 255 characters.'],
    'assembled address too long' => [['delivery_address_details' => str_repeat('a', 255)], 'delivery_address_details', 'The complete delivery address may not exceed 255 characters. Shorten the house, building, street, or landmark details.'],
]);

test('complete addresses accept exactly 255 multibyte characters without truncation', function () {
    Storage::fake('local');
    [$customer, $item] = psgcCheckoutCart();
    $details = str_repeat('ñ', 211);

    $this->actingAs($customer)->post(route('orders.store'), psgcCheckoutPayload($item, [
        'delivery_address_details' => $details,
    ]))->assertSessionHasNoErrors();

    expect(Order::sole()->delivery_address)->toBe($details.', Poblacion I, Sagay City, Negros Occidental');
    expect(mb_strlen(Order::sole()->delivery_address))->toBe(255);
});

test('web delivery cannot fall back to the legacy address using a JSON accept header', function () {
    Storage::fake('local');
    [$customer, $item, $stock] = psgcCheckoutCart();
    $payload = psgcCheckoutPayload($item, ['delivery_address' => 'Legacy full address']);
    unset($payload['delivery_barangay_code'], $payload['delivery_address_details']);

    $this->actingAs($customer)->postJson(route('orders.store'), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['delivery_barangay_code', 'delivery_address_details']);

    $this->assertDatabaseCount('orders', 0);
    expect($stock->refresh()->quantity)->toBe(5);
});

test('legacy mobile delivery still saves its original address contract', function () {
    Storage::fake('local');
    [$customer, $item] = psgcCheckoutCart('Saved profile address');
    $payload = psgcCheckoutPayload($item, ['delivery_address' => '12 Mabini Street, Sagay City']);
    unset($payload['delivery_barangay_code'], $payload['delivery_address_details']);

    $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->post('/api/v1/orders', $payload)->assertSuccessful()
        ->assertJsonPath('data.fulfillment.delivery_address', '12 Mabini Street, Sagay City');

    expect(Order::sole()->delivery_address)->toBe('12 Mabini Street, Sagay City');
    expect($customer->refresh()->default_delivery_address)->toBe('Saved profile address');
});

test('partial mobile structured fields cannot silently fall back to a legacy address', function (string $missingField, ?string $presentValue) {
    Storage::fake('local');
    [$customer, $item] = psgcCheckoutCart();
    $payload = psgcCheckoutPayload($item, ['delivery_address' => 'Legacy address']);
    unset($payload[$missingField]);
    $presentField = $missingField === 'delivery_barangay_code' ? 'delivery_address_details' : 'delivery_barangay_code';
    $payload[$presentField] = $presentValue;

    $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->post('/api/v1/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors($missingField);

    $this->assertDatabaseCount('orders', 0);
})->with([
    'details only' => ['delivery_barangay_code', '12 Mabini Street'],
    'null details key' => ['delivery_barangay_code', null],
    'empty details key' => ['delivery_barangay_code', ''],
    'barangay only' => ['delivery_address_details', '1804523018'],
    'null barangay key' => ['delivery_address_details', null],
    'empty barangay key' => ['delivery_address_details', ''],
]);

test('pickup needs no structured address and rejects nonempty delivery fields', function (string $channel) {
    Storage::fake('local');
    [$customer, $item] = psgcCheckoutCart('Saved delivery address');
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);
    $url = $channel === 'web' ? route('orders.store') : '/api/v1/orders';
    $payload = psgcCheckoutPayload($item, ['fulfillment_method' => 'pickup', 'payment_method' => 'cash']);
    unset($payload['payment_proof'], $payload['delivery_destination']);

    $invalid = $this->post($url, $payload);
    if ($channel === 'web') {
        $invalid->assertSessionHasErrors(['delivery_barangay_code', 'delivery_address_details']);
    } else {
        $invalid->assertUnprocessable()->assertJsonValidationErrors(['delivery_barangay_code', 'delivery_address_details']);
    }
    $this->assertDatabaseCount('orders', 0);
    unset($payload['delivery_barangay_code'], $payload['delivery_address_details']);
    $valid = $this->post($url, $payload);
    $channel === 'web' ? $valid->assertSessionHasNoErrors() : $valid->assertSuccessful();

    expect(Order::sole()->delivery_address)->toBeNull();
    expect(Order::sole()->delivery_fee)->toBe('0.00');
    expect(Order::sole()->shipment)->toBeNull();
    expect($customer->refresh()->default_delivery_address)->toBe('Saved delivery address');
})->with(['web', 'api']);

test('placed addresses remain unchanged in history after profile and current coverage changes', function () {
    Storage::fake('local');
    [$customer, $item] = psgcCheckoutCart();
    $this->actingAs($customer)->post(route('orders.store'), psgcCheckoutPayload($item))->assertSessionHasNoErrors();
    $order = Order::sole();
    $customer->update(['default_delivery_address' => 'Changed profile address']);
    config(['battlefront.delivery.destinations' => []]);

    $this->get(route('orders.show', $order))->assertOk()
        ->assertInertia(fn ($page) => $page->where('order.fulfillment.delivery_address', '12 Mabini Street, Poblacion I, Sagay City, Negros Occidental'));
    $this->withToken($customer->createToken('Phone')->plainTextToken)->get('/api/v1/orders/'.$order->id)
        ->assertOk()->assertJsonPath('data.fulfillment.delivery_address', '12 Mabini Street, Poblacion I, Sagay City, Negros Occidental');
});

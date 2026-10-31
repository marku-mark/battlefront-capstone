<?php

use App\Actions\Order\PlaceCustomerOrder;
use App\Enums\ShipmentStatus;
use App\Enums\ShippingProfile;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Order\PsgcDeliveryLocations;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{customer: User, product: Product, stock: Inventory} */
function deliveryCheckoutCart(ShippingProfile $profile = ShippingProfile::Standard): array
{
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create([
        'price' => '10.15', 'discount_price' => '9.33', 'shipping_profile' => $profile,
    ]);
    $stock = Inventory::factory()->for($product)->create(['quantity' => 10]);
    app(CartService::class)->add($customer, $product->id, 2);

    return compact('customer', 'product', 'stock');
}

/** @return array<string, mixed> */
function deliveryCheckoutSubmission(array $cartItemIds, array $overrides = []): array
{
    $submission = array_replace([
        'cart_item_ids' => $cartItemIds,
        'recipient_name' => 'Delivery Customer',
        'contact_number' => '09171234567',
        'fulfillment_method' => 'delivery',
        'delivery_destination' => 'Sagay City',
        'delivery_barangay_code' => '1804523018',
        'delivery_address_details' => '12 Example Street',
        'payment_method' => 'online',
        'payment_channel' => 'hosted_checkout',
        'hosted_payment_method' => 'qrph',
        'checkout_submission_key' => (string) Str::uuid(),
    ], $overrides);

    if ($submission['payment_method'] !== 'online') {
        unset($submission['payment_channel'], $submission['hosted_payment_method'], $submission['checkout_submission_key']);
    }

    return $submission;
}

test('web and mobile checkout expose equivalent quotes for all destination fees and profiles', function (ShippingProfile $profile, int $surcharge, int $preparation) {
    $this->seed(BranchSeeder::class);
    $this->travelTo(Carbon::parse('2026-12-30 23:30:00', 'UTC'));
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    ['customer' => $customer, 'stock' => $stock] = deliveryCheckoutCart($profile);

    $web = $this->actingAs($customer)->get(route('checkout.index', ['cart_item_ids' => $customer->cart->items->modelKeys()]))->assertOk();
    $api = $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->get('/api/v1/checkout?'.http_build_query(['cart_item_ids' => $customer->cart->items->modelKeys()]))->assertOk();
    $quotes = $api->json('data.delivery_quotes');

    expect($quotes)->toBe($web->inertiaProps('deliveryQuotes'))->toHaveCount(12);
    expect($api->json('data.pickup_quote'))->toBe([
        'product_subtotal' => '18.66', 'delivery_fee' => '0.00', 'total' => '18.66',
    ])->toBe($web->inertiaProps('pickupQuote'));
    foreach ([
        ['Sagay City', 150, 0, 1], ['Escalante City', 150, 1, 1], ['Cadiz City', 150, 1, 1],
        ['Toboso', 150, 1, 1], ['Manapla', 150, 1, 1], ['Calatrava', 150, 1, 1],
        ['Victorias City', 150, 1, 1], ['E.B. Magalona', 150, 1, 2], ['San Carlos City', 150, 1, 2],
        ['Silay City', 150, 1, 2], ['Talisay City', 150, 1, 2], ['Bacolod City', 150, 1, 2],
    ] as $index => [$destination, $base, $minimum, $maximum]) {
        expect($quotes[$index])->destination->toBe($destination)
            ->base_fee->toBe(($base + 45).'.00')->route_base_fee->toBe($base.'.00')
            ->weight_surcharge->toBe('45.00')->handling_surcharge->toBe($surcharge.'.00')
            ->delivery_fee->toBe(($base + 45 + $surcharge).'.00')->total->toBe(($base + 45 + $surcharge + 18).'.66')
            ->product_subtotal->toBe('18.66')->shipping_profile->toBe($profile->value)
            ->preparation_days->toBe($preparation)->transit_min_days->toBe($minimum)->transit_max_days->toBe($maximum)
            ->eta_min_days->toBe($preparation + $minimum)->eta_max_days->toBe($preparation + $maximum)
            ->carrier->toBe('lbc')->origin_city->toBe('Sagay City')->is_demo->toBeTrue()
            ->eta_anchor_date->toBe('2026-12-30')->eta_timezone->toBe('UTC');
        expect($quotes[$index]['notice'])->toContain('not official LBC rates', 'prepaid orders require payment verification');
    }
    expect($quotes[0]['estimated_delivery_start'])->toBe(match ($profile) {
        ShippingProfile::Standard => '2026-12-31', ShippingProfile::Fragile => '2027-01-01', ShippingProfile::Bulky => '2027-01-02',
    });
    expect($quotes[0]['estimated_delivery_end'])->toBe(match ($profile) {
        ShippingProfile::Standard => '2027-01-01', ShippingProfile::Fragile => '2027-01-02', ShippingProfile::Bulky => '2027-01-03',
    });
    expect($quotes[11]['estimated_delivery_end'])->toBe(match ($profile) {
        ShippingProfile::Standard => '2027-01-02', ShippingProfile::Fragile => '2027-01-03', ShippingProfile::Bulky => '2027-01-04',
    });
    expect($stock->refresh()->quantity)->toBe(10);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('shipments', 0);
    $this->assertDatabaseCount('cart_items', 1);
    Http::assertNothingSent();
})->with([
    'standard' => [ShippingProfile::Standard, 0, 1],
    'fragile' => [ShippingProfile::Fragile, 50, 2],
    'bulky' => [ShippingProfile::Bulky, 100, 3],
]);

test('checkout calendar windows use the configured timezone across leap days', function () {
    $this->seed(BranchSeeder::class);
    config(['app.timezone' => 'Asia/Manila']);
    $this->travelTo(Carbon::parse('2028-02-27 18:00:00', 'UTC'));
    ['customer' => $customer] = deliveryCheckoutCart();

    $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->get('/api/v1/checkout?'.http_build_query(['cart_item_ids' => $customer->cart->items->modelKeys()]))->assertOk()
        ->assertJsonPath('data.delivery_quotes.0.eta_anchor_date', '2028-02-28')
        ->assertJsonPath('data.delivery_quotes.0.eta_timezone', 'Asia/Manila')
        ->assertJsonPath('data.delivery_quotes.0.estimated_delivery_start', '2028-02-29')
        ->assertJsonPath('data.delivery_quotes.11.estimated_delivery_end', '2028-03-02');
});

test('new Negros delivery estimate is shared by web and mobile quotes and order placement', function (string $channel) {
    Storage::fake('local');
    ['customer' => $customer, 'stock' => $stock] = deliveryCheckoutCart();
    $cityCode = '1804502000';
    $barangayCode = app(PsgcDeliveryLocations::class)->cityBarangays($cityCode)[0]['code'];
    $query = [
        'cart_item_ids' => $customer->cart->items->modelKeys(),
        'delivery_city_code' => $cityCode,
    ];
    $token = $customer->createToken('Phone')->plainTextToken;

    $webQuote = $this->actingAs($customer)->getJson(route('checkout.delivery-quote.show', $query))->assertOk();
    $apiQuote = $this->withToken($token)
        ->getJson('/api/v1/checkout/delivery-quote?'.http_build_query($query))->assertOk();

    expect($apiQuote->json('data'))->toBe($webQuote->json('data'));
    $apiQuote->assertJsonPath('data.quote.destination_label', 'City of Bago')
        ->assertJsonPath('data.quote.pricing_configured', true)
        ->assertJsonPath('data.quote.order_placement_allowed', true)
        ->assertJsonPath('data.quote.base_fee', '195.00')
        ->assertJsonPath('data.quote.route_base_fee', '150.00')
        ->assertJsonPath('data.quote.delivery_fee', '195.00')
        ->assertJsonPath('data.quote.total', '213.66')
        ->assertJsonPath('data.quote.payment_methods', ['gcash', 'maya', 'cod'])
        ->assertJsonPath('data.quote.transit_min_days', 2)
        ->assertJsonPath('data.quote.transit_max_days', 3);

    $submission = deliveryCheckoutSubmission($customer->cart->items->modelKeys(), [
        'delivery_destination' => $cityCode,
        'delivery_region_code' => '1800000000',
        'delivery_province_code' => '1804500000',
        'delivery_city_code' => $cityCode,
        'delivery_barangay_code' => $barangayCode,
        'delivery_address_details' => '12 Example Street',
        'payment_method' => 'cod',
    ]);
    unset($submission['payment_proof']);
    $response = $this->withToken($token)->post(
        $channel === 'web' ? route('orders.store') : '/api/v1/orders',
        $submission,
    );
    $order = Order::sole();

    if ($channel === 'web') {
        $response->assertRedirectToRoute('orders.show', $order)->assertSessionHasNoErrors();
    } else {
        $response->assertSuccessful()
            ->assertJsonPath('data.delivery_quote.base_fee', '195.00')
            ->assertJsonPath('data.delivery_quote.transit_min_days', 2);
    }
    expect($order)->delivery_destination->toBe('City of Bago')
        ->product_subtotal->toBe('18.66')
        ->delivery_base_fee->toBe('195.00')
        ->delivery_fee->toBe('195.00')
        ->total_amount->toBe('213.66');
    expect($order->delivery_quote_metadata['city_code'])->toBe($cityCode);
    expect($order->shipment)->carrier->toBe('lbc')
        ->transit_min_days->toBe(2)
        ->transit_max_days->toBe(3);
    expect($stock->refresh()->quantity)->toBe(8);
    $this->assertDatabaseCount('shipments', 1);

    $customerDetail = $this->withToken($token)->getJson('/api/v1/orders/'.$order->id)->assertOk()
        ->assertJsonPath('data.delivery_quote.destination', 'City of Bago')
        ->assertJsonPath('data.delivery_quote.base_fee', '195.00');
    $webDetail = $this->actingAs($customer)->get(route('orders.show', $order))->assertOk();
    expect($customerDetail->json('data.delivery_quote'))->toBe($webDetail->inertiaProps('order.delivery_quote'));
    $administrator = User::factory()->administrator()->create();
    $this->actingAs($administrator)->get(route('administration.orders.show', $order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.delivery_quote.destination', 'City of Bago')
            ->where('order.delivery_quote.base_fee', '195.00')
            ->where('order.delivery_quote.transit_min_days', 2));
})->with(['web', 'api']);

test('delivery placement snapshots each profile through the shared web and mobile boundaries', function (string $channel, ShippingProfile $profile, string $fee, string $total, int $preparation) {
    Storage::fake('local');
    ['customer' => $customer, 'stock' => $stock] = deliveryCheckoutCart($profile);
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);

    $response = $this->post($channel === 'web' ? route('orders.store') : '/api/v1/orders', deliveryCheckoutSubmission($customer->cart->items->modelKeys(), ['hosted_payment_method' => 'paymaya']));
    $order = Order::sole();

    if ($channel === 'web') {
        $response->assertRedirectToRoute('orders.show', $order)->assertSessionHasNoErrors();
    } else {
        $response->assertSuccessful()->assertJsonPath('data.product_subtotal', '18.66')
            ->assertJsonPath('data.delivery_fee', $fee)->assertJsonPath('data.total', $total)
            ->assertJsonPath('data.delivery_quote.shipping_profile', $profile->value)
            ->assertJsonPath('data.delivery_quote.carrier', 'lbc');
    }
    expect($order)->product_subtotal->toBe('18.66')->delivery_fee->toBe($fee)->total_amount->toBe($total)
        ->shipping_profile->toBe($profile)->delivery_destination->toBe('Sagay City');
    expect($order->delivery_quote_metadata['customer_fee_policy'])->toBe('fixed_at_placement_battlefront_absorbs_variance');
    expect($order->shipment)->status->toBe(ShipmentStatus::AwaitingPreparation)->preparation_days->toBe($preparation);
    expect($stock->refresh()->quantity)->toBe(8);
    expect($customer->refresh()->cart)->toBeNull();
    $this->assertDatabaseCount('shipments', 1);
    expect($order->payment_proof_path)->toBeNull();
})->with(['web', 'api'])->with([
    'standard' => [ShippingProfile::Standard, '195.00', '213.66', 1],
    'fragile' => [ShippingProfile::Fragile, '245.00', '263.66', 2],
    'bulky' => [ShippingProfile::Bulky, '295.00', '313.66', 3],
]);

test('mixed cart preview and placement charge the highest handling profile only once', function (string $channel) {
    Storage::fake('local');
    $this->seed(BranchSeeder::class);
    $customer = User::factory()->customer()->create();
    foreach ([ShippingProfile::Standard, ShippingProfile::Fragile, ShippingProfile::Bulky, ShippingProfile::Bulky] as $profile) {
        $product = Product::factory()->create(['price' => '10.00', 'shipping_profile' => $profile]);
        Inventory::factory()->for($product)->create(['quantity' => 10]);
        app(CartService::class)->add($customer, $product->id, 3);
    }
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);
    $this->get('/api/v1/checkout?'.http_build_query(['cart_item_ids' => $customer->cart->items->modelKeys()]))->assertOk()
        ->assertJsonPath('data.delivery_quotes.0.product_subtotal', '120.00')
        ->assertJsonPath('data.delivery_quotes.0.handling_surcharge', '100.00')
        ->assertJsonPath('data.delivery_quotes.0.delivery_fee', '745.00')
        ->assertJsonPath('data.delivery_quotes.0.total', '865.00');

    $response = $this->post($channel === 'web' ? route('orders.store') : '/api/v1/orders', deliveryCheckoutSubmission($customer->cart->items->modelKeys()));

    $channel === 'web' ? $response->assertSessionHasNoErrors() : $response->assertSuccessful();
    expect(Order::sole())->product_subtotal->toBe('120.00')->handling_surcharge->toBe('100.00')
        ->delivery_fee->toBe('745.00')->total_amount->toBe('865.00')->shipping_profile->toBe(ShippingProfile::Bulky);
    expect(Inventory::pluck('quantity')->all())->toBe([7, 7, 7, 7]);
    $this->assertDatabaseCount('shipments', 1);
})->with(['web', 'api']);

test('placement ignores forged quote values and recalculates changed prices profiles fees and ETA', function (string $channel) {
    Storage::fake('local');
    $this->seed(BranchSeeder::class);
    ['customer' => $customer, 'product' => $product, 'stock' => $stock] = deliveryCheckoutCart();
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);
    $this->get('/api/v1/checkout?'.http_build_query(['cart_item_ids' => $customer->cart->items->modelKeys()]))->assertOk()->assertJsonPath('data.delivery_quotes.0.total', '213.66');
    $product->update(['discount_price' => null, 'shipping_profile' => ShippingProfile::Bulky]);
    config([
        'battlefront.delivery.destinations.Sagay City' => ['transit_min_days' => 2, 'transit_max_days' => 3],
        'battlefront.delivery.regional_estimates.fees.visayas' => '80.15',
        'battlefront.delivery.profiles.bulky' => ['surcharge' => '100.25', 'preparation_days' => 4],
    ]);
    $payload = deliveryCheckoutSubmission($customer->cart->items->modelKeys(), [
        'delivery_fee' => '0.01', 'base_fee' => '0.01', 'delivery_base_fee' => '0.01', 'handling_surcharge' => '0.01',
        'shipping_profile' => 'standard', 'preparation_days' => 0, 'transit_min_days' => 0, 'transit_max_days' => 0,
        'eta_min_days' => 0, 'eta_max_days' => 0, 'eta_anchor_date' => '1900-01-01',
        'estimated_delivery_start' => '1900-01-01', 'estimated_delivery_end' => '1900-01-01',
        'product_subtotal' => '0.01', 'subtotal' => '0.01', 'total' => '0.01', 'total_amount' => '0.01',
        'carrier' => 'fake', 'delivery_quote' => ['total' => '0.01'], 'items' => [['quantity' => 99, 'unit_price' => '0.01']],
    ]);

    $response = $this->post($channel === 'web' ? route('orders.store') : '/api/v1/orders', $payload);

    $channel === 'web' ? $response->assertSessionHasNoErrors() : $response->assertSuccessful();
    $order = Order::sole();
    expect($order)->product_subtotal->toBe('20.30')->delivery_base_fee->toBe('125.15')->handling_surcharge->toBe('100.25')
        ->delivery_fee->toBe('225.40')->total_amount->toBe('245.70')->shipping_profile->toBe(ShippingProfile::Bulky);
    expect($order->shipment)->carrier->toBe('lbc')->preparation_days->toBe(4)->eta_min_days->toBe(6)->eta_max_days->toBe(7);
    expect($stock->refresh()->quantity)->toBe(8);
    config(['battlefront.delivery.destinations' => [], 'battlefront.delivery.profiles' => [], 'battlefront.delivery.carrier' => 'changed']);
    $product->update(['shipping_profile' => ShippingProfile::Standard, 'price' => '999.00']);

    $detail = $this->get('/api/v1/orders/'.$order->id)->assertOk()
        ->assertJsonPath('data.total', '245.70')->assertJsonPath('data.delivery_quote.base_fee', '125.15')
        ->assertJsonPath('data.delivery_quote.carrier', 'lbc')->assertJsonPath('data.delivery_quote.eta_max_days', 7)
        ->assertJsonMissingPath('data.delivery_quote.estimated_delivery_start')
        ->assertJsonMissingPath('data.payment_proof_path');
    expect($detail->json('data.delivery_quote'))->toBe(
        $this->get(route('orders.show', $order))->assertOk()->inertiaProps('order.delivery_quote'),
    );
    expect($detail->getContent())->not->toContain('payment-proofs/');
})->with(['web', 'api']);

test('delivery rejects missing unsupported noncanonical and nonscalar destinations without side effects', function (string $channel, mixed $destination, string $message) {
    Storage::fake('local');
    ['customer' => $customer, 'stock' => $stock] = deliveryCheckoutCart();
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);

    $response = $this->post($channel === 'web' ? route('orders.store') : '/api/v1/orders', deliveryCheckoutSubmission($customer->cart->items->modelKeys(), ['delivery_destination' => $destination]));

    if ($channel === 'web') {
        $response->assertSessionHasErrors(['delivery_destination' => $message]);
    } else {
        $response->assertUnprocessable()->assertJsonPath('errors.delivery_destination', [$message]);
    }
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('shipments', 0);
    $this->assertDatabaseCount('cart_items', 1);
    expect($stock->refresh()->quantity)->toBe(10);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['web', 'api'])->with([
    'missing' => [null, 'Select a supported delivery destination.'],
    'unsupported' => ['Guihulngan City', 'Select a supported delivery destination.'],
    'wrong case' => ['sagay city', 'Select a supported delivery destination.'],
    'address as destination' => ['12 Example Street, Sagay City', 'Select a supported delivery destination.'],
    'multiple destinations' => [['Sagay City', 'Bacolod City'], 'Select one supported delivery destination.'],
]);

test('pickup prohibits a destination and preserves zero fee placement', function (string $channel) {
    Storage::fake('local');
    ['customer' => $customer, 'stock' => $stock] = deliveryCheckoutCart(ShippingProfile::Bulky);
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);
    $payload = ['cart_item_ids' => $customer->cart->items->modelKeys(), 'recipient_name' => 'Pickup Customer', 'contact_number' => '09171234567', 'fulfillment_method' => 'pickup', 'payment_method' => 'cash'];
    $url = $channel === 'web' ? route('orders.store') : '/api/v1/orders';

    $invalid = $this->post($url, [...$payload, 'delivery_destination' => 'Sagay City']);
    if ($channel === 'web') {
        $invalid->assertSessionHasErrors(['delivery_destination' => 'A delivery destination is not used for pickup orders.']);
    } else {
        $invalid->assertUnprocessable()->assertJsonPath('errors.delivery_destination', ['A delivery destination is not used for pickup orders.']);
    }
    $this->assertDatabaseCount('orders', 0);
    expect($stock->refresh()->quantity)->toBe(10);
    $response = $this->post($url, [...$payload, 'delivery_fee' => '999.99', 'total' => '999.99']);
    $channel === 'web' ? $response->assertSessionHasNoErrors() : $response->assertSuccessful();

    expect(Order::sole())->product_subtotal->toBe('18.66')->delivery_fee->toBe('0.00')->total_amount->toBe('18.66')
        ->delivery_address->toBeNull()->delivery_destination->toBeNull()->shipment->toBeNull();
    $this->assertDatabaseCount('shipments', 0);
    expect($stock->refresh()->quantity)->toBe(8);
})->with(['web', 'api']);

test('delivery shipment failure rolls back placement and deletes newly uploaded proof', function (string $channel) {
    Storage::fake('local');
    ['customer' => $customer, 'stock' => $stock] = deliveryCheckoutCart();
    $this->actingAs($customer)->withToken($customer->createToken('Phone')->plainTextToken);
    Event::listen('eloquent.creating: '.Shipment::class, fn (): never => throw new RuntimeException('Shipment persistence failed.'));
    config(['app.debug' => false]);

    try {
        $this->post($channel === 'web' ? route('orders.store') : '/api/v1/orders', deliveryCheckoutSubmission($customer->cart->items->modelKeys()))->assertInternalServerError();
    } finally {
        Event::forget('eloquent.creating: '.Shipment::class);
    }

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->assertDatabaseCount('shipments', 0);
    $this->assertDatabaseCount('cart_items', 1);
    expect($stock->refresh()->quantity)->toBe(10);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['web', 'api']);

test('a destination removed before internal placement becomes a validation error with proof cleanup', function () {
    Storage::fake('local');
    ['customer' => $customer, 'stock' => $stock] = deliveryCheckoutCart();
    $payload = deliveryCheckoutSubmission($customer->cart->items->modelKeys());
    config(['battlefront.delivery.destinations' => []]);

    try {
        app(PlaceCustomerOrder::class)->execute($customer, $payload, $payload['payment_proof'] ?? null);
        $this->fail('Unsupported destination placement must fail.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['delivery_destination' => ['Select a supported delivery destination.']]);
    }

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('shipments', 0);
    $this->assertDatabaseCount('cart_items', 1);
    expect($stock->refresh()->quantity)->toBe(10);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

<?php

use App\Actions\Order\AssignOrderHandler;
use App\Actions\Order\ResubmitPaymentProof;
use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRejectionReason;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Enums\ShippingProfile;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Order\OrderPlacementService;
use App\Services\Order\OrderProcessingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** @return array{recipient_name: string, contact_number: string, fulfillment_method: FulfillmentMethod, delivery_address: string|null, payment_method: PaymentMethod, payment_proof_path: string|null} */
function quotedDeliveryCheckoutData(array $cartItemIds): array
{
    return [
        'cart_item_ids' => $cartItemIds,
        'recipient_name' => 'Delivery Customer',
        'contact_number' => '09171234567',
        'fulfillment_method' => FulfillmentMethod::Delivery,
        'delivery_address' => 'Submitted street and barangay address',
        'payment_method' => PaymentMethod::GCash,
        'payment_proof_path' => 'payment-proofs/verified.jpg',
    ];
}

test('delivery placement snapshots each profile and the relative quote in one stock transaction', function (
    ShippingProfile $profile,
    string $surcharge,
    string $fee,
    string $finalTotal,
    int $preparation,
    int $etaMinimum,
    int $etaMaximum,
) {
    Http::preventStrayRequests();
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create(['price' => '10.15', 'discount_price' => '9.33', 'shipping_profile' => $profile]);
    $stock = Inventory::factory()->for($product)->create(['quantity' => 5]);
    app(CartService::class)->add($customer, $product->id, 2);

    $order = app(OrderPlacementService::class)->executeWithDeliveryQuote($customer, quotedDeliveryCheckoutData($customer->cart->items->modelKeys()), 'Bacolod City');

    $this->assertDatabaseHas('orders', [
        'id' => $order->id, 'delivery_destination' => 'Bacolod City',
        'delivery_base_fee' => '195.00', 'shipping_profile' => $profile->value,
        'handling_surcharge' => $surcharge, 'delivery_fee' => $fee,
        'product_subtotal' => '18.66', 'total_amount' => $finalTotal,
    ]);
    expect($order)->delivery_base_fee->toBe('195.00')->shipping_profile->toBe($profile)
        ->handling_surcharge->toBe($surcharge)->delivery_fee->toBe($fee)
        ->product_subtotal->toBe('18.66')->total_amount->toBe($finalTotal)
        ->delivery_origin_city->toBe('Sagay City')->delivery_is_demo->toBeTrue()
        ->delivery_assumption_label->toBe('Battlefront demonstration estimates. Regional base fees apply to all destinations. Negros Occidental transit bands use OSM administrative-boundary centroids as straight-line proxies; they are not official LBC rates or verified production shipping charges.')
        ->status->toBe(OrderStatus::Pending)->payment_status->toBe(PaymentStatus::Pending);
    expect($order->shipment)->carrier->toBe('lbc')->status->toBe(ShipmentStatus::AwaitingPreparation)
        ->preparation_days->toBe($preparation)->transit_min_days->toBe(1)->transit_max_days->toBe(2)
        ->eta_min_days->toBe($etaMinimum)->eta_max_days->toBe($etaMaximum)
        ->tracking_reference->toBeNull()->handed_to_carrier_at->toBeNull()->delivered_at->toBeNull();
    expect($order->shipment->order->is($order))->toBeTrue();
    expect($order->items->sole())->quantity->toBe(2)->price_at_time->toBe('9.33');
    expect($stock->refresh()->quantity)->toBe(3);
    expect($customer->refresh()->cart)->toBeNull();
    Http::assertNothingSent();
})->with([
    'standard' => [ShippingProfile::Standard, '0.00', '195.00', '213.66', 1, 2, 3],
    'fragile' => [ShippingProfile::Fragile, '50.00', '245.00', '263.66', 2, 3, 4],
    'bulky' => [ShippingProfile::Bulky, '100.00', '295.00', '313.66', 3, 4, 5],
]);

test('mixed-cart delivery charges the highest profile once for all purchased quantities', function () {
    $customer = User::factory()->customer()->create();
    $products = [
        Product::factory()->standard()->create(['price' => '10.00']),
        Product::factory()->fragile()->create(['price' => '10.00']),
        Product::factory()->bulky()->create(['price' => '10.00']),
        Product::factory()->bulky()->create(['price' => '10.00']),
    ];
    foreach ($products as $product) {
        Inventory::factory()->for($product)->create(['quantity' => 10]);
        app(CartService::class)->add($customer, $product->id, 3);
    }

    $order = app(OrderPlacementService::class)->executeWithDeliveryQuote($customer, quotedDeliveryCheckoutData($customer->cart->items->modelKeys()), 'Sagay City');

    expect($order)->product_subtotal->toBe('120.00')->shipping_profile->toBe(ShippingProfile::Bulky)
        ->handling_surcharge->toBe('100.00')->delivery_fee->toBe('745.00')->total_amount->toBe('865.00');
    expect($order->shipment)->preparation_days->toBe(3)->eta_min_days->toBe(3)->eta_max_days->toBe(4);
    $this->assertDatabaseCount('shipments', 1);
});

test('pickup snapshots its subtotal with zero delivery fee and no shipment', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->bulky()->create(['price' => '0.10']);
    Inventory::factory()->for($product)->create(['quantity' => 10]);
    app(CartService::class)->add($customer, $product->id, 3);
    $checkout = array_replace(quotedDeliveryCheckoutData($customer->cart->items->modelKeys()), [
        'fulfillment_method' => FulfillmentMethod::Pickup, 'delivery_address' => null,
        'payment_method' => PaymentMethod::Cash, 'payment_proof_path' => null,
    ]);

    $order = app(OrderPlacementService::class)->execute($customer, $checkout);

    expect($order)->product_subtotal->toBe('0.30')->delivery_fee->toBe('0.00')->total_amount->toBe('0.30')
        ->delivery_destination->toBeNull()->delivery_base_fee->toBeNull()->shipping_profile->toBeNull()
        ->handling_surcharge->toBeNull()->shipment->toBeNull();
    $this->assertDatabaseCount('shipments', 0);
});

test('the compatibility delivery path retains current totals without inferring a zone', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->fragile()->create(['price' => '100.00']);
    Inventory::factory()->for($product)->create(['quantity' => 10]);
    app(CartService::class)->add($customer, $product->id, 1);

    $order = app(OrderPlacementService::class)->execute($customer, quotedDeliveryCheckoutData($customer->cart->items->modelKeys()));

    expect($order)->product_subtotal->toBe('100.00')->total_amount->toBe('100.00')
        ->delivery_fee->toBe('0.00')->delivery_destination->toBeNull()->shipment->toBeNull();
});

test('stored commercial and fulfillment snapshots survive configuration and product changes', function () {
    $this->freezeTime();
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->fragile()->create(['price' => '100.00']);
    Inventory::factory()->for($product)->create(['quantity' => 10]);
    app(CartService::class)->add($customer, $product->id, 1);
    $order = app(OrderPlacementService::class)->executeWithDeliveryQuote($customer, quotedDeliveryCheckoutData($customer->cart->items->modelKeys()), 'Sagay City');
    $order->refresh();
    $commercial = $order->getAttributes();
    $fulfillment = $order->shipment->getAttributes();
    config([
        'battlefront.delivery.destinations' => [],
        'battlefront.delivery.profiles' => [],
        'battlefront.delivery.carrier' => 'other-carrier',
        'battlefront.delivery.origin_city' => 'Other origin',
        'battlefront.delivery.is_demo' => false,
        'battlefront.delivery.assumption_label' => 'Changed configuration',
    ]);
    $product->update(['shipping_profile' => ShippingProfile::Bulky, 'price' => '200.00']);

    $order->refresh()->save();
    $order->shipment->save();

    expect($order->getAttributes())->toBe($commercial);
    expect($order->shipment->getAttributes())->toBe($fulfillment);
});

test('a shipment creation failure rolls back the order items stock and cart consumption', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->bulky()->create(['price' => '100.00']);
    $stock = Inventory::factory()->for($product)->create(['quantity' => 10]);
    $cartItem = app(CartService::class)->add($customer, $product->id, 3);
    Shipment::creating(fn (): never => throw new RuntimeException('Shipment persistence failed.'));

    try {
        expect(fn () => app(OrderPlacementService::class)->executeWithDeliveryQuote($customer, quotedDeliveryCheckoutData($customer->cart->items->modelKeys()), 'Sagay City'))
            ->toThrow(RuntimeException::class, 'Shipment persistence failed.');
    } finally {
        Shipment::flushEventListeners();
        Shipment::clearBootedModels();
    }

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->assertDatabaseCount('shipments', 0);
    $this->assertModelExists($cartItem);
    expect($stock->refresh()->quantity)->toBe(10);
});

test('unsupported destinations fail before consuming any order stock or cart', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    $stock = Inventory::factory()->for($product)->create(['quantity' => 10]);
    $cartItem = app(CartService::class)->add($customer, $product->id, 1);

    expect(fn () => app(OrderPlacementService::class)->executeWithDeliveryQuote($customer, quotedDeliveryCheckoutData($customer->cart->items->modelKeys()), 'Guihulngan City'))
        ->toThrow(DomainException::class, 'Unsupported delivery destination.');
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('shipments', 0);
    $this->assertModelExists($cartItem);
    expect($stock->refresh()->quantity)->toBe(10);
});

test('the strict delivery entrypoint rejects pickup fulfillment', function () {
    $customer = User::factory()->customer()->create();
    $checkout = array_replace(quotedDeliveryCheckoutData([]), ['fulfillment_method' => FulfillmentMethod::Pickup]);

    expect(fn () => app(OrderPlacementService::class)->executeWithDeliveryQuote($customer, $checkout, 'Sagay City'))
        ->toThrow(InvalidArgumentException::class, 'Quoted delivery placement requires delivery fulfillment.');
    $this->assertDatabaseCount('orders', 0);
});

test('payment rejection and proof replacement retain stock and delivery snapshots', function () {
    Storage::fake('local');
    Storage::disk('local')->put('payment-proofs/verified.jpg', 'old proof');
    Storage::disk('local')->put('payment-proofs/replacement.jpg', 'new proof');
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->fragile()->create(['price' => '100.00']);
    $stock = Inventory::factory()->for($product)->create(['quantity' => 10]);
    app(CartService::class)->add($customer, $product->id, 2);
    $order = app(OrderPlacementService::class)->executeWithDeliveryQuote($customer, quotedDeliveryCheckoutData($customer->cart->items->modelKeys()), 'Sagay City');
    $shipment = $order->shipment->getAttributes();

    app(OrderProcessingService::class)->updatePaymentStatus($order, PaymentStatus::Rejected, PaymentRejectionReason::ImageUnclear);
    app(ResubmitPaymentProof::class)->execute($customer, $order, 'payment-proofs/replacement.jpg');

    expect($order->refresh())->payment_status->toBe(PaymentStatus::Pending)
        ->payment_rejection_reason->toBeNull()->total_amount->toBe('445.00')->delivery_fee->toBe('245.00');
    expect($order->shipment->getAttributes())->toBe($shipment);
    expect($stock->refresh()->quantity)->toBe(8);
    Storage::disk('local')->assertMissing('payment-proofs/verified.jpg');
    Storage::disk('local')->assertExists('payment-proofs/replacement.jpg');
});

test('verified shipment delivery records the product subtotal while preserving payable total and stock', function () {
    Storage::fake('local');
    Storage::disk('local')->put('payment-proofs/verified.jpg', 'proof');
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->fragile()->create(['price' => '100.00']);
    $stock = Inventory::factory()->for($product)->create(['quantity' => 10]);
    app(CartService::class)->add($customer, $product->id, 2);
    $order = app(OrderPlacementService::class)->executeWithDeliveryQuote($customer, quotedDeliveryCheckoutData($customer->cart->items->modelKeys()), 'Sagay City');
    $handler = User::factory()->administrator()->create();
    app(AssignOrderHandler::class)->execute($order, $handler, $handler->id, claimOnly: true);
    $processing = app(OrderProcessingService::class);

    $processing->updatePaymentStatus($order, PaymentStatus::Verified);
    $processing->updateStatus($order, OrderStatus::Processing);
    $processing->updateShipmentOperations($order, [
        'package_count' => '1',
        'package_weight_kg' => '1.000',
        'package_length_cm' => '10.00',
        'package_width_cm' => '10.00',
        'package_height_cm' => '10.00',
        'declared_value' => $order->total_amount,
        'handoff_mode' => 'branch_dropoff',
    ], $handler);
    foreach ([ShipmentStatus::Preparing, ShipmentStatus::ReadyForDispatch, ShipmentStatus::HandedToLbc, ShipmentStatus::InTransit, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
        $processing->updateShipmentStatus($order, $status);
    }

    expect($order->refresh()->sale->amount)->toBe('200.00');
    expect($order)->product_subtotal->toBe('200.00')->delivery_fee->toBe('245.00')->total_amount->toBe('445.00');
    expect($order->status)->toBe(OrderStatus::Completed);
    expect($order->shipment)->status->toBe(ShipmentStatus::Delivered)->handed_to_carrier_at->not->toBeNull()->delivered_at->not->toBeNull();
    expect($stock->refresh()->quantity)->toBe(8);
    $this->assertDatabaseCount('sales', 1);
});

test('cancellation restores quoted order stock once while retaining its snapshots', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->bulky()->create(['price' => '100.00']);
    $stock = Inventory::factory()->for($product)->create(['quantity' => 10]);
    app(CartService::class)->add($customer, $product->id, 2);
    $order = app(OrderPlacementService::class)->executeWithDeliveryQuote($customer, quotedDeliveryCheckoutData($customer->cart->items->modelKeys()), 'Sagay City');
    $shipment = $order->shipment->getAttributes();
    $processing = app(OrderProcessingService::class);

    $processing->updateStatus($order, OrderStatus::Cancelled);

    expect($stock->refresh()->quantity)->toBe(10);
    expect($order->refresh())->total_amount->toBe('495.00')->delivery_fee->toBe('295.00');
    expect($order->shipment->getAttributes())->toMatchArray([
        ...array_diff_key($shipment, array_flip(['status', 'cancelled_at', 'updated_at'])),
        'status' => ShipmentStatus::Cancelled->value,
    ]);
    expect($order->shipment->cancelled_at)->not->toBeNull();
    expect(fn () => $processing->updateStatus($order, OrderStatus::Cancelled))->toThrow(ValidationException::class);
    expect($stock->refresh()->quantity)->toBe(10);
});

test('decimal-capacity overflow rolls back before stock consumption', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create(['price' => '9999999999.99']);
    $stock = Inventory::factory()->for($product)->create(['quantity' => 10]);
    $cartItem = app(CartService::class)->add($customer, $product->id, 1);

    expect(fn () => app(OrderPlacementService::class)->executeWithDeliveryQuote($customer, quotedDeliveryCheckoutData($customer->cart->items->modelKeys()), 'Sagay City'))
        ->toThrow(InvalidArgumentException::class, 'Order total exceeds the supported monetary limit.');
    $this->assertDatabaseCount('orders', 0);
    $this->assertModelExists($cartItem);
    expect($stock->refresh()->quantity)->toBe(10);
});

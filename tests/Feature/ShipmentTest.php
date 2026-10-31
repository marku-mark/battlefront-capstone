<?php

use App\Enums\ShipmentStatus;
use App\Enums\ShippingProfile;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('shipment factories produce coherent relative snapshots for the order profile', function (ShippingProfile $profile, int $preparation, int $minimum, int $maximum) {
    $order = Order::factory()->withDeliverySnapshot('Bacolod City', $profile)->create();

    $shipment = Shipment::factory()->for($order)->create();

    expect($shipment->refresh())->carrier->toBe('lbc')->status->toBe(ShipmentStatus::AwaitingPreparation)
        ->preparation_days->toBe($preparation)->transit_min_days->toBe(1)->transit_max_days->toBe(2)
        ->eta_min_days->toBe($minimum)->eta_max_days->toBe($maximum)
        ->tracking_reference->toBeNull()->handed_to_carrier_at->toBeNull()->delivered_at->toBeNull();
    expect($shipment->order->shipping_profile)->toBe($profile);
    expect($shipment->order->is($order))->toBeTrue();
    expect($order->refresh()->shipment->is($shipment))->toBeTrue();
    expect($shipment->getAttributes())->not->toHaveKey('shipping_profile');
})->with([
    'standard' => [ShippingProfile::Standard, 1, 2, 3],
    'fragile' => [ShippingProfile::Fragile, 2, 3, 4],
    'bulky' => [ShippingProfile::Bulky, 3, 4, 5],
]);

test('the default shipment factory creates one quoted delivery order', function () {
    $shipment = Shipment::factory()->create();

    $this->assertModelExists($shipment->order);
    expect($shipment->order)->product_subtotal->toBe('100.00')->total_amount->toBe('250.00');
    $this->assertDatabaseCount('shipments', 1);
});

test('shipments cannot be attached to pickup or unquoted legacy delivery orders', function (bool $delivery) {
    $order = $delivery ? Order::factory()->delivery()->create() : Order::factory()->create();

    expect(fn () => Shipment::query()->create([
        'order_id' => $order->id, 'carrier' => 'lbc', 'preparation_days' => 1,
        'transit_min_days' => 1, 'transit_max_days' => 1, 'eta_min_days' => 2, 'eta_max_days' => 2,
    ]))->toThrow(DomainException::class, 'Shipments require a quoted delivery order.');
    $this->assertDatabaseCount('shipments', 0);
})->with(['pickup' => [false], 'legacy delivery' => [true]]);

test('an order cannot have duplicate shipments', function () {
    $shipment = Shipment::factory()->create();

    expect(fn () => Shipment::factory()->for($shipment->order)->create())->toThrow(QueryException::class);
    $this->assertDatabaseCount('shipments', 1);
});

test('shipment foreign keys reject orphan references and protect the owning order', function () {
    $shipment = Shipment::factory()->create();

    expect(fn () => DB::table('shipments')->where('id', $shipment->id)->update(['order_id' => $shipment->order_id + 1000]))
        ->toThrow(QueryException::class);
    expect(fn () => $shipment->order->delete())->toThrow(QueryException::class);
    $this->assertModelExists($shipment->order);
});

test('shipment ownership remains reachable only through its intended customer order relationship', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->withDeliverySnapshot()->create();
    $shipment = Shipment::factory()->for($order)->create();

    expect($customer->orders()->with('shipment')->findOrFail($order->id)->shipment->is($shipment))->toBeTrue();
    expect($otherCustomer->orders()->with('shipment')->find($order->id))->toBeNull();
});

test('shipment ownership carrier and estimate snapshots cannot be edited', function (string $field, mixed $value) {
    $shipment = Shipment::factory()->create()->refresh();
    $before = $shipment->getAttributes();

    expect(fn () => $shipment->update([$field => $value]))
        ->toThrow(DomainException::class, 'Shipment ownership and quote context cannot be changed.');
    expect($shipment->refresh()->getAttributes())->toBe($before);
})->with([
    'owner' => ['order_id', 99999],
    'carrier' => ['carrier', 'other-carrier'],
    'preparation' => ['preparation_days', 2],
    'transit minimum' => ['transit_min_days', 2],
    'transit maximum' => ['transit_max_days', 2],
    'ETA minimum' => ['eta_min_days', 3],
    'ETA maximum' => ['eta_max_days', 3],
]);

test('commercial order snapshots cannot be edited after placement', function (string $field, mixed $value) {
    $order = Order::factory()->withDeliverySnapshot()->create()->refresh();
    $before = $order->getAttributes();

    expect(fn () => $order->update([$field => $value]))
        ->toThrow(DomainException::class, 'Placed order commercial snapshots cannot be changed.');
    expect($order->refresh()->getAttributes())->toBe($before);
})->with([
    'zone' => ['delivery_destination', 'Bacolod City'],
    'base fee' => ['delivery_base_fee', '81.00'],
    'profile' => ['shipping_profile', ShippingProfile::Bulky],
    'surcharge' => ['handling_surcharge', '50.00'],
    'delivery fee' => ['delivery_fee', '130.00'],
    'subtotal' => ['product_subtotal', '101.00'],
    'total' => ['total_amount', '181.00'],
    'origin' => ['delivery_origin_city', 'Other origin'],
    'demo identification' => ['delivery_is_demo', false],
    'assumption label' => ['delivery_assumption_label', 'Changed label'],
    'fulfillment' => ['fulfillment_method', 'pickup'],
    'address' => ['delivery_address', 'Changed address'],
    'clear snapshot marker' => ['product_subtotal', null],
]);

test('explicitly supplied reference and timestamps can be stored without computing any dates', function () {
    $shipment = Shipment::factory()->create();

    $shipment->update([
        'tracking_reference' => 'MANUALLY-PROVIDED-TEST-REFERENCE',
        'handed_to_carrier_at' => '2026-10-08 09:00:00',
        'delivered_at' => '2026-10-10 14:00:00',
    ]);

    expect($shipment->refresh()->tracking_reference)->toBe('MANUALLY-PROVIDED-TEST-REFERENCE');
    expect($shipment->handed_to_carrier_at->toDateTimeString())->toBe('2026-10-08 09:00:00');
    expect($shipment->delivered_at->toDateTimeString())->toBe('2026-10-10 14:00:00');
});

test('shipment constraints reject invalid status and relative ranges', function (string $field, mixed $value) {
    config(['battlefront.delivery.destinations.Sagay City.transit_min_days' => 1]);
    $shipment = Shipment::factory()->create();

    expect(fn () => DB::table('shipments')->where('id', $shipment->id)->update([$field => $value]))
        ->toThrow(QueryException::class);
})->with([
    'unsupported status' => ['status', 'booked'],
    'wrong status case' => ['status', 'AWAITING_PREPARATION'],
    'empty carrier' => ['carrier', ''],
    'zero preparation' => ['preparation_days', 0],
    'negative transit' => ['transit_min_days', -1],
    'reversed transit range' => ['transit_max_days', 0],
    'inconsistent ETA minimum' => ['eta_min_days', 9],
    'inconsistent ETA maximum' => ['eta_max_days', 9],
]);

<?php

use App\Enums\FulfillmentMethod;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('the snapshot migration preserves pre-revision orders without reconstructing quotes', function (string $fulfillment) {
    $order = Order::factory()->create([
        'fulfillment_method' => $fulfillment,
        'delivery_address' => $fulfillment === 'delivery' ? 'Original free-text address' : null,
        'total_amount' => '123.45',
    ]);
    $migration = require database_path('migrations/2026_10_07_160342_add_delivery_snapshots_to_orders_table.php');
    $migration->down();
    $before = (array) DB::table('orders')->where('id', $order->id)->first();

    $migration->up();

    $after = (array) DB::table('orders')->where('id', $order->id)->first();
    expect(array_intersect_key($after, $before))->toBe($before);
    expect($order->refresh())->total_amount->toBe('123.45')->product_subtotal->toBeNull()
        ->delivery_fee->toBe('0.00')->delivery_destination->toBeNull()->shipping_profile->toBeNull()->shipment->toBeNull();
    $this->assertDatabaseCount('shipments', 0);
})->with(['pickup', 'delivery']);

test('shipment persistence stores manual milestones and ETA without copied handling profile or location columns', function () {
    expect(Schema::getColumnListing('shipments'))->toEqualCanonicalizing([
        'id', 'order_id', 'carrier', 'arrangement_type', 'status', 'preparation_days',
        'transit_min_days', 'transit_max_days', 'eta_min_days', 'eta_max_days',
        'tracking_reference', 'handed_to_carrier_at', 'delivered_at', 'created_at', 'updated_at',
        'booking_source', 'booking_reference', 'carrier_cod_collected_amount', 'carrier_cod_collection_reference',
        'carrier_cod_collected_at', 'carrier_cod_remitted_amount', 'carrier_cod_remittance_reference', 'carrier_cod_remitted_at',
        'package_count', 'package_weight_kg', 'package_length_cm', 'package_width_cm', 'package_height_cm',
        'declared_value', 'handoff_mode', 'actual_carrier_cost', 'carrier_cost_reference', 'carrier_cost_recorded_at',
        'shipment_exception_code', 'shipment_exception_note', 'shipment_exception_reported_at', 'shipment_exception_resolved_at',
        'preparing_at', 'ready_for_dispatch_at', 'in_transit_at', 'out_for_delivery_at', 'cancelled_at',
        'eta_anchor_date', 'eta_timezone', 'estimated_delivery_start', 'estimated_delivery_end',
    ]);
});

test('pickup fee and incomplete quote values are rejected at the database boundary', function (string $field, mixed $value) {
    $order = Order::factory()->create();

    expect(fn () => DB::table('orders')->where('id', $order->id)->update([$field => $value]))
        ->toThrow(QueryException::class);
})->with([
    'pickup fee' => ['delivery_fee', '80.00'],
    'pickup zone' => ['delivery_destination', 'Sagay City'],
    'partial base fee' => ['delivery_base_fee', '80.00'],
    'partial profile' => ['shipping_profile', 'fragile'],
    'partial surcharge' => ['handling_surcharge', '50.00'],
    'negative subtotal' => ['product_subtotal', '-0.01'],
]);

test('quoted order constraints reject invalid money or incomplete snapshots', function (string $field, mixed $value) {
    $order = Order::factory()->withDeliverySnapshot()->create();

    expect(fn () => DB::table('orders')->where('id', $order->id)->update([$field => $value]))
        ->toThrow(QueryException::class);
})->with([
    'negative base fee' => ['delivery_base_fee', '-0.01'],
    'negative surcharge' => ['handling_surcharge', '-0.01'],
    'negative delivery fee' => ['delivery_fee', '-0.01'],
    'mismatched final total' => ['total_amount', '181.00'],
    'missing profile' => ['shipping_profile', null],
    'unsupported profile' => ['shipping_profile', 'oversized'],
    'wrong profile case' => ['shipping_profile', 'Standard'],
    'missing subtotal' => ['product_subtotal', null],
    'missing origin' => ['delivery_origin_city', null],
    'missing provenance' => ['delivery_is_demo', null],
    'missing base fee' => ['delivery_base_fee', null],
    'pickup conversion' => ['fulfillment_method', FulfillmentMethod::Pickup->value],
]);

test('snapshot constraints accept exact decimal sums rather than integer-only fees', function () {
    config([
        'battlefront.delivery.regional_estimates.fees.visayas' => '0.10',
        'battlefront.delivery.profiles.standard.surcharge' => '0.20',
    ]);

    $order = Order::factory()->withDeliverySnapshot(subtotal: '0.30')->create();

    expect($order->refresh())->delivery_fee->toBe('0.30')->total_amount->toBe('0.60');
});

test('rollback refuses to discard saved delivery quotes or shipments', function () {
    $shipment = Shipment::factory()->create();
    $orderMigration = require database_path('migrations/2026_10_07_160342_add_delivery_snapshots_to_orders_table.php');
    $shipmentMigration = require database_path('migrations/2026_10_07_160343_create_shipments_table.php');

    expect(fn () => $shipmentMigration->down())->toThrow(RuntimeException::class, 'Cannot discard persisted shipments.');
    expect(fn () => $orderMigration->down())->toThrow(RuntimeException::class, 'Cannot discard persisted delivery snapshots.');
    $this->assertModelExists($shipment);
    $this->assertModelExists($shipment->order);
});

test('empty shipment migration rolls back cleanly and can be reapplied', function () {
    config(['battlefront.delivery.destinations.Sagay City.transit_min_days' => 1]);
    $migration = require database_path('migrations/2026_10_07_160343_create_shipments_table.php');
    $manualShipmentMigration = require database_path('migrations/2026_10_13_102101_allow_manual_shipments_for_unconfigured_delivery_orders.php');

    $manualShipmentMigration->down();
    $migration->down();
    expect(Schema::hasTable('shipments'))->toBeFalse();
    $migration->up();
    $manualShipmentMigration->up();

    $shipment = Shipment::factory()->create();
    expect($shipment)->status->value->toBe('awaiting_preparation')
        ->arrangement_type->value->toBe('configured_rate');
});

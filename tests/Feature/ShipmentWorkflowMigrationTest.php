<?php

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('workflow migration upgrades existing shipments and normalizes cancelled orders without invented dates', function () {
    config(['battlefront.delivery.destinations.Sagay City.transit_min_days' => 1]);
    config(['battlefront.delivery.regional_estimates.fees.visayas' => '80.00']);
    $activeOrder = Order::factory()->withDeliverySnapshot()->create();
    $cancelledOrder = Order::factory()->withDeliverySnapshot()->create(['status' => OrderStatus::Cancelled]);
    $active = Shipment::factory()->for($activeOrder)->create();
    $cancelled = Shipment::factory()->for($cancelledOrder)->create();
    $migration = require database_path('migrations/2026_10_08_042940_extend_shipments_for_manual_workflow.php');
    $migration->down();

    config(['battlefront.delivery.regional_estimates.fees.visayas' => '150.00']);
    $migration->up();

    expect($active->refresh())->status->toBe(ShipmentStatus::AwaitingPreparation)->preparing_at->toBeNull()->eta_anchor_date->toBeNull();
    expect($cancelled->refresh())->status->toBe(ShipmentStatus::Cancelled)->cancelled_at->toBeNull()->delivered_at->toBeNull();
    expect($activeOrder->refresh())->total_amount->toBe('180.00')->delivery_destination->toBe('Sagay City');
    $this->assertDatabaseCount('shipments', 2);
    $this->assertDatabaseCount('sales', 0);
});

test('workflow rollback refuses to discard recorded milestones or an operational ETA', function (array $changes) {
    $shipment = Shipment::factory()->create();
    $shipment->update($changes);
    $migration = require database_path('migrations/2026_10_08_042940_extend_shipments_for_manual_workflow.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot discard shipment workflow history. Use a forward migration.');

    expect(Schema::hasColumn('shipments', 'preparing_at'))->toBeTrue();
    expect($shipment->refresh()->getAttributes())->toMatchArray($changes);
})->with([
    'milestone' => [['status' => 'preparing']],
    'ETA' => [['eta_timezone' => 'UTC']],
]);

test('an unused workflow migration can be reversed without losing shipment ownership or relative snapshots', function () {
    config(['battlefront.delivery.destinations.Sagay City.transit_min_days' => 1]);
    $shipment = Shipment::factory()->create();
    $migration = require database_path('migrations/2026_10_08_042940_extend_shipments_for_manual_workflow.php');

    $migration->down();

    expect(Schema::hasColumn('shipments', 'preparing_at'))->toBeFalse();
    expect($shipment->refresh())->status->toBe(ShipmentStatus::AwaitingPreparation)->eta_min_days->toBe(2)->eta_max_days->toBe(2);
    expect(fn () => DB::table('shipments')->where('id', $shipment->id)->update(['status' => 'preparing']))
        ->toThrow(QueryException::class);
    $migration->up();
});

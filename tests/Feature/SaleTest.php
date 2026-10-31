<?php

use App\Actions\Order\RecordCompletedOrderSale;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Sale;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('the sale schema follows the approved ERD decisions', function () {
    expect(Schema::getColumnListing('sales'))->toEqualCanonicalizing([
        'id',
        'order_id',
        'amount',
        'handler_id',
        'sale_date',
    ]);
});

test('a sale persists approved values through reciprocal order relationships', function () {
    $order = Order::factory()->create([
        'total_amount' => '38999.90',
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
    ]);
    $sale = Sale::factory()->for($order)->create([
        'amount' => '38999.90',
        'sale_date' => '2026-09-17',
    ]);

    $this->assertModelExists($sale);
    expect($sale->amount)->toBe('38999.90')
        ->and($sale->sale_date->toDateString())->toBe('2026-09-17')
        ->and($sale->order->is($order))->toBeTrue()
        ->and($order->sale->is($sale))->toBeTrue();
});

test('an order cannot have duplicate sales', function () {
    $sale = Sale::factory()->create();

    expect(fn () => Sale::factory()->for($sale->order)->create())
        ->toThrow(QueryException::class);
    expect(Sale::query()->count())->toBe(1);
});

test('legacy order completion records purchase time item revenue instead of a fee inclusive total', function () {
    $this->travelTo('2026-10-13 10:00:00');
    $order = Order::factory()->create(['total_amount' => '230.00', 'status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified]);
    OrderItem::factory()->for($order)->create(['quantity' => 2, 'price_at_time' => '100.00']);

    app(RecordCompletedOrderSale::class)->execute($order);

    expect($order->refresh()->sale)->amount->toBe('200.00')->sale_date->toDateString()->toBe('2026-10-13');
    expect($order->total_amount)->toBe('230.00');
});

test('recording itemless legacy orders uses the saved net payable amount', function () {
    $order = Order::factory()->delivery()->create([
        'total_amount' => '100.50', 'status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified,
    ]);

    app(RecordCompletedOrderSale::class)->execute($order);

    expect($order->refresh()->sale->amount)->toBe('100.50');
    expect($order->total_amount)->toBe('100.50');
});

test('zero product subtotals record zero revenue even when delivery has a fee', function () {
    $order = Order::factory()->withDeliverySnapshot(subtotal: '0.00')
        ->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified]);
    OrderItem::factory()->for($order)->create(['quantity' => 2, 'price_at_time' => '0.00']);

    app(RecordCompletedOrderSale::class)->execute($order);

    expect($order->refresh()->sale->amount)->toBe('0.00');
    expect($order)->product_subtotal->toBe('0.00')->delivery_fee->toBe('150.00')->total_amount->toBe('150.00');
});

test('negative sale amounts are rejected on insert and update', function () {
    expect(fn () => Sale::factory()->create(['amount' => '-0.01']))
        ->toThrow(QueryException::class);

    $sale = Sale::factory()->create(['amount' => '100.00']);

    expect(fn () => $sale->update(['amount' => '-0.01']))
        ->toThrow(QueryException::class);
    expect($sale->refresh()->amount)->toBe('100.00');
});

test('required sale fields cannot be omitted', function (string $missingField) {
    $order = Order::factory()->create();
    $attributes = [
        'order_id' => $order->id,
        'amount' => '100.00',
        'sale_date' => '2026-09-17',
    ];
    unset($attributes[$missingField]);

    expect(fn () => DB::table('sales')->insert($attributes))
        ->toThrow(QueryException::class);
})->with([
    'order' => 'order_id',
    'amount' => 'amount',
    'sale date' => 'sale_date',
]);

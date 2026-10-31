<?php

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRejectionReason;
use App\Enums\PaymentStatus;
use App\Enums\ShippingProfile;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Order\OrderProcessingService;
use Database\Seeders\DevelopmentHistoricalSalesSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\HistoricalSalesCatalogFixtures;

test('completed transaction history identifies demonstration orders and their assigned customer', function () {
    Storage::fake('local');
    config(['app.timezone' => 'UTC']);
    $this->travelTo('2026-10-15 12:00:00');
    HistoricalSalesCatalogFixtures::createProducts();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $lastOrder = Order::with('items', 'user')->where('development_fixture_key', 'historical-sales:V4:2022-10:04')->sole();

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.orders.index', ['status' => 'completed', 'q' => $lastOrder->reference]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('orders.total', 1)
            ->where('orders.data.0.recipient_name', 'Miguel Santos')
            ->where('orders.data.0.customer.name', 'Miguel Santos')
            ->where('orders.data.0.customer.email', 'demo.customer01@example.test')
            ->where('orders.data.0.status.value', 'completed')
            ->where('orders.data.0.payment.status.value', 'verified')
            ->where('orders.data.0.item_count', $lastOrder->items->count())
            ->where('orders.data.0.total_quantity', $lastOrder->items->sum('quantity'))
            ->where('orders.data.0.total', '6570.00'));
});

test('guests are redirected from administrator order workflows', function () {
    $order = Order::factory()->create();

    $this->get(route('administration.orders.index'))
        ->assertRedirectToRoute('login');
    $this->get(route('administration.orders.show', $order))
        ->assertRedirectToRoute('login');
    $this->patch(route('administration.orders.status.update', $order), [
        'status' => OrderStatus::Processing->value,
    ])->assertRedirectToRoute('login');
    $this->patch(route('administration.orders.payment-status.update', $order), [
        'payment_status' => PaymentStatus::Rejected->value,
    ])->assertRedirectToRoute('login');
    $this->get(route('administration.orders.payment-proof.show', $order))
        ->assertRedirectToRoute('login');
});

test('customers are forbidden from administrator order workflows', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 4]);
    $order = Order::factory()->create();
    OrderItem::factory()->for($order)->for($product)->create(['quantity' => 2]);

    $this->actingAs($customer)
        ->get(route('administration.orders.index'))
        ->assertForbidden();
    $this->get(route('administration.orders.show', $order))
        ->assertForbidden();
    $this->patch(route('administration.orders.status.update', $order), [
        'status' => OrderStatus::Cancelled->value,
    ])->assertForbidden();
    $this->patch(route('administration.orders.payment-status.update', $order), [
        'payment_status' => PaymentStatus::Rejected->value,
    ])->assertForbidden();
    $this->get(route('administration.orders.payment-proof.show', $order))
        ->assertForbidden();

    expect($order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($inventory->refresh()->quantity)->toBe(4);
});

test('administrators can review authoritative order processing details', function () {
    Storage::fake('local');
    Storage::disk('local')->put('payment-proofs/order-proof.png', 'proof');
    $administrator = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create([
        'name' => 'Jamie Customer',
        'email' => 'jamie@example.com',
    ]);
    $product = Product::factory()->create([
        'name' => 'Battlefront Graphics Card',
        'brand' => 'NVIDIA',
    ]);
    $order = Order::factory()->for($customer)->delivery()->paidWithGCash()->create([
        'id' => 42,
        'recipient_name' => 'Alex Recipient',
        'contact_number' => '09171234567',
        'delivery_address' => 'Sagay City, Negros Occidental',
        'total_amount' => '2500.00',
        'payment_proof_path' => 'payment-proofs/order-proof.png',
        'created_at' => '2026-09-14 08:30:00',
    ]);
    OrderItem::factory()->for($order)->for($product)->create([
        'quantity' => 2,
        'price_at_time' => '1250.00',
    ]);

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $response = $this->actingAs($administrator)
        ->get(route('administration.orders.show', $order));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Administration/Orders/Show')
        ->where('order.reference', 'BF-000042')
        ->where('order.customer', [
            'id' => $customer->id,
            'name' => 'Jamie Customer',
            'email' => 'jamie@example.com',
        ])
        ->where('order.recipient', [
            'name' => 'Alex Recipient',
            'contact_number' => '09171234567',
        ])
        ->where('order.fulfillment', [
            'value' => FulfillmentMethod::Delivery->value,
            'label' => 'Delivery',
            'delivery_address' => 'Sagay City, Negros Occidental',
            'delivery_destination' => null,
        ])
        ->where('order.status', ['value' => 'pending', 'label' => 'Pending'])
        ->where('order.allowed_status_transitions', [
            ['value' => 'cancelled', 'label' => 'Cancelled'],
        ])
        ->where('order.payment.method', [
            'value' => PaymentMethod::GCash->value,
            'label' => 'GCash',
            'requires_proof' => true,
        ])
        ->where('order.payment.proof_submitted', true)
        ->where('order.payment.proof_available', true)
        ->where('order.payment.rejection_reasons', [
            ['value' => 'image_unclear', 'label' => 'Image is unclear'],
            ['value' => 'amount_mismatch', 'label' => 'Payment amount does not match'],
            ['value' => 'transaction_unverified', 'label' => 'Transaction could not be verified'],
            ['value' => 'wrong_account_or_reference', 'label' => 'Wrong account or reference'],
            ['value' => 'other', 'label' => 'Other'],
        ])
        ->where('order.items.0.product.name', 'Battlefront Graphics Card')
        ->where('order.items.0.quantity', 2)
        ->where('order.items.0.unit_price', '1250.00')
        ->where('order.items.0.line_total', '2500.00')
        ->where('order.product_subtotal', null)
        ->where('order.delivery_fee', '0.00')
        ->where('order.total', '2500.00')
        ->missing('order.payment_proof_path'));
});

test('administrator and customer order histories retain deleted product details', function () {
    $administrator = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create(['name' => 'Retired Graphics Card']);
    $order = Order::factory()->for($customer)->create();
    OrderItem::factory()->for($order)->for($product)->create([
        'quantity' => 2,
        'price_at_time' => '1250.00',
    ]);
    $product->delete();

    $this->actingAs($administrator)
        ->get(route('administration.orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.items.0.product.id', $product->id)
            ->where('order.items.0.product.name', 'Retired Graphics Card'));

    $this->actingAs($customer)
        ->get(route('orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.items.0.product.id', $product->id)
            ->where('order.items.0.product.name', 'Retired Graphics Card'));
});

test('administrator processing details expose the saved delivery destination without inferring legacy or pickup destinations', function (string $scenario, ?string $destination, ?string $address) {
    $factory = match ($scenario) {
        'quoted delivery' => Order::factory()->withDeliverySnapshot('Calatrava'),
        'legacy delivery' => Order::factory()->delivery(),
        default => Order::factory(),
    };
    $order = $factory->create(['delivery_address' => 'Amihan 1, Bridge Area']);
    config(['battlefront.delivery.destinations' => []]);

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.fulfillment.delivery_address', $address)
            ->where('order.fulfillment.delivery_destination', $destination));
})->with([
    'quoted delivery' => ['quoted delivery', 'Calatrava', 'Amihan 1, Bridge Area'],
    'legacy delivery' => ['legacy delivery', null, 'Amihan 1, Bridge Area'],
    'pickup' => ['pickup', null, null],
]);

test('administrator item ledger exposes saved product subtotal delivery fee and final total', function (bool $delivery, string $fee, string $total) {
    $administrator = User::factory()->administrator()->create();
    $factory = $delivery
        ? Order::factory()->withDeliverySnapshot(profile: ShippingProfile::Bulky, subtotal: '280.00')
        : Order::factory()->state(['product_subtotal' => '280.00', 'total_amount' => '280.00']);
    $order = $factory->create();
    OrderItem::factory()->for($order)->create(['quantity' => 1, 'price_at_time' => '250.00']);
    OrderItem::factory()->for($order)->create(['quantity' => 2, 'price_at_time' => '15.00']);
    config(['battlefront.delivery.destinations' => [], 'battlefront.delivery.profiles' => []]);

    $this->actingAs($administrator)
        ->get(route('administration.orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Administration/Orders/Show')
            ->where('order.product_subtotal', '280.00')
            ->where('order.delivery_fee', $fee)
            ->where('order.total', $total)
            ->where('order.items.0.line_total', '250.00')
            ->where('order.items.1.line_total', '30.00'));
})->with([
    'delivery' => [true, '250.00', '530.00'],
    'pickup' => [false, '0.00', '280.00'],
]);

test('administrator order directory defaults to active orders newest first and paginated', function () {
    $administrator = User::factory()->administrator()->create();
    $activeOrders = Order::factory()->count(21)->sequence(
        fn ($sequence): array => [
            'created_at' => now()->subDays(20 - $sequence->index),
            'status' => $sequence->index % 2 === 0
                ? OrderStatus::Pending
                : OrderStatus::Processing,
        ],
    )->create();
    $completedOrder = Order::factory()->create(['status' => OrderStatus::Completed]);
    $cancelledOrder = Order::factory()->create(['status' => OrderStatus::Cancelled]);

    $response = $this->actingAs($administrator)
        ->get(route('administration.orders.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Administration/Orders/Index')
        ->where('orders.total', 21)
        ->where('orders.per_page', 20)
        ->has('orders.data', 20)
        ->where('orders.data.0.id', $activeOrders->last()->id)
        ->where('filters.status', 'active')
        ->where('status_counts', [
            'active' => 21,
            'completed' => 1,
            'cancelled' => 1,
        ])
        ->where('orders.data', fn ($orders): bool => $orders
            ->pluck('id')
            ->doesntContain($completedOrder->id)
            && $orders->pluck('id')->doesntContain($cancelledOrder->id))
        ->missing('orders.data.0.payment_proof_path'));
});

test('administrator completed and cancelled views contain only their historical records', function (
    string $view,
    OrderStatus $includedStatus,
) {
    $administrator = User::factory()->administrator()->create();
    $includedOrder = Order::factory()->create(['status' => $includedStatus]);
    $otherOrders = collect(OrderStatus::cases())
        ->reject(fn (OrderStatus $status): bool => $status === $includedStatus)
        ->map(fn (OrderStatus $status): Order => Order::factory()->create(['status' => $status]));

    $response = $this->actingAs($administrator)
        ->get(route('administration.orders.index', ['status' => $view]));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Administration/Orders/Index')
        ->where('filters.status', $view)
        ->where('orders.total', 1)
        ->where('orders.data.0.id', $includedOrder->id)
        ->where('orders.data.0.status.value', $includedStatus->value));

    $this->assertModelExists($includedOrder);
    $otherOrders->each(fn (Order $order) => $this->assertModelExists($order));
})->with([
    'completed history' => ['completed', OrderStatus::Completed],
    'cancelled history' => ['cancelled', OrderStatus::Cancelled],
]);

test('administrator order search matches references customers and recipients', function () {
    $administrator = User::factory()->administrator()->create();
    $referenceOrder = Order::factory()->create();
    $customer = User::factory()->customer()->create(['name' => 'Searchable Customer']);
    $customerOrder = Order::factory()->for($customer)->create();
    $recipientOrder = Order::factory()->create(['recipient_name' => 'Searchable Recipient']);
    Order::factory()->create([
        'recipient_name' => 'Unrelated Person',
    ]);

    $searches = [
        $referenceOrder->reference => $referenceOrder->id,
        'Searchable Customer' => $customerOrder->id,
        'Searchable Recipient' => $recipientOrder->id,
    ];

    foreach ($searches as $search => $orderId) {
        $this->actingAs($administrator)
            ->get(route('administration.orders.index', ['q' => $search]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.q', $search)
                ->where('orders.total', 1)
                ->where('orders.data.0.id', $orderId));
    }
});

test('administrator order filters narrow payment and fulfillment details', function () {
    $administrator = User::factory()->administrator()->create();
    $matchingOrder = Order::factory()->delivery()->paidWithGCash()->create([
        'payment_status' => PaymentStatus::Verified,
    ]);
    Order::factory()->delivery()->paidWithMaya()->create([
        'payment_status' => PaymentStatus::Verified,
    ]);
    Order::factory()->delivery()->paidWithGCash()->create([
        'payment_status' => PaymentStatus::Pending,
    ]);
    Order::factory()->paidWithGCash()->create([
        'payment_status' => PaymentStatus::Verified,
    ]);

    $response = $this->actingAs($administrator)
        ->get(route('administration.orders.index', [
            'payment_status' => PaymentStatus::Verified->value,
            'payment_method' => PaymentMethod::GCash->value,
            'fulfillment_method' => FulfillmentMethod::Delivery->value,
        ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('orders.total', 1)
        ->where('orders.data.0.id', $matchingOrder->id)
        ->where('filters.payment_status', PaymentStatus::Verified->value)
        ->where('filters.payment_method', PaymentMethod::GCash->value)
        ->where('filters.fulfillment_method', FulfillmentMethod::Delivery->value));
});

test('administrator order pagination preserves directory filters', function () {
    $administrator = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create(['name' => 'Queue Customer']);
    Order::factory()->count(21)->for($customer)->delivery()->paidWithGCash()->create([
        'payment_status' => PaymentStatus::Verified,
    ]);

    $response = $this->actingAs($administrator)
        ->get(route('administration.orders.index', [
            'q' => 'Queue Customer',
            'status' => 'active',
            'payment_status' => PaymentStatus::Verified->value,
            'payment_method' => PaymentMethod::GCash->value,
            'fulfillment_method' => FulfillmentMethod::Delivery->value,
        ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('orders.total', 21)
        ->where('orders.next_page_url', function (?string $url): bool {
            parse_str((string) parse_url($url ?? '', PHP_URL_QUERY), $query);

            return ($query['page'] ?? null) === '2'
                && ($query['q'] ?? null) === 'Queue Customer'
                && ($query['status'] ?? null) === 'active'
                && ($query['payment_status'] ?? null) === PaymentStatus::Verified->value
                && ($query['payment_method'] ?? null) === PaymentMethod::GCash->value
                && ($query['fulfillment_method'] ?? null) === FulfillmentMethod::Delivery->value;
        }));
});

test('processing orders use context-aware fulfillment wording in the directory', function (
    FulfillmentMethod $fulfillmentMethod,
    string $operationalLabel,
) {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->create([
        'status' => OrderStatus::Processing,
        'fulfillment_method' => $fulfillmentMethod,
        'delivery_address' => $fulfillmentMethod === FulfillmentMethod::Delivery
            ? 'Sagay City, Negros Occidental'
            : null,
    ]);

    $this->actingAs($administrator)
        ->get(route('administration.orders.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('orders.data.0.id', $order->id)
            ->where('orders.data.0.fulfillment.operational_label', $operationalLabel));
})->with([
    'pickup' => [FulfillmentMethod::Pickup, 'Preparing for pickup'],
    'delivery' => [FulfillmentMethod::Delivery, 'Preparing for delivery'],
]);

test('administrators can apply approved order status transitions', function (
    OrderStatus $currentStatus,
    OrderStatus $nextStatus,
    PaymentStatus $paymentStatus,
    int $expectedSaleCount,
) {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->paidWithGCash()->create([
        'status' => $currentStatus,
        'payment_status' => $paymentStatus,
    ]);

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $response = $this->actingAs($administrator)
        ->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.status.update', $order), [
            'status' => $nextStatus->value,
            'products_handed_over_confirmed' => $nextStatus === OrderStatus::Completed,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.orders.show', $order))
        ->assertInertiaFlash('toast.message', 'Order status updated.');
    expect($order->refresh()->status)->toBe($nextStatus)
        ->and(Sale::query()->count())->toBe($expectedSaleCount);
})->with([
    'verified pending to processing' => [
        OrderStatus::Pending,
        OrderStatus::Processing,
        PaymentStatus::Verified,
        0,
    ],
    'pending payment does not block cancellation' => [
        OrderStatus::Pending,
        OrderStatus::Cancelled,
        PaymentStatus::Pending,
        0,
    ],
    'verified processing to completed' => [
        OrderStatus::Processing,
        OrderStatus::Completed,
        PaymentStatus::Verified,
        1,
    ],
    'rejected payment does not block cancellation' => [
        OrderStatus::Processing,
        OrderStatus::Cancelled,
        PaymentStatus::Rejected,
        0,
    ],
]);

test('completing an eligible order records one authoritative sale without changing inventory', function () {
    $this->travelTo('2026-09-17 08:30:00');
    $administrator = User::factory()->administrator()->create();
    $firstProduct = Product::factory()->create(['price' => '1500.00']);
    $secondProduct = Product::factory()->create(['price' => '750.00']);
    $firstInventory = Inventory::factory()->for($firstProduct)->create(['quantity' => 3]);
    $secondInventory = Inventory::factory()->for($secondProduct)->create(['quantity' => 7]);
    $order = Order::factory()->paidWithGCash()->create([
        'total_amount' => '3200.00',
        'status' => OrderStatus::Processing,
        'payment_status' => PaymentStatus::Verified,
    ]);
    OrderItem::factory()->for($order)->for($firstProduct)->create([
        'quantity' => 2,
        'price_at_time' => '1000.00',
    ]);
    OrderItem::factory()->for($order)->for($secondProduct)->create([
        'quantity' => 3,
        'price_at_time' => '400.00',
    ]);

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $response = $this->actingAs($administrator)
        ->patch(route('administration.orders.status.update', $order), [
            'status' => OrderStatus::Completed->value,
            'products_handed_over_confirmed' => true,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Order status updated.');
    $sale = Sale::query()->sole();
    expect($order->refresh()->status)->toBe(OrderStatus::Completed)
        ->and($sale->amount)->toBe('3200.00')
        ->and($sale->sale_date->toDateString())->toBe('2026-09-17')
        ->and($sale->order->is($order))->toBeTrue()
        ->and($order->sale->is($sale))->toBeTrue()
        ->and($sale->order->items()->orderBy('id')->pluck('quantity')->all())->toBe([2, 3])
        ->and($sale->order->items()->orderBy('id')->pluck('price_at_time')->all())->toBe(['1000.00', '400.00'])
        ->and($firstInventory->refresh()->quantity)->toBe(3)
        ->and($secondInventory->refresh()->quantity)->toBe(7);
});

test('repeated completion processing cannot create duplicate sales', function () {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->paidWithGCash()->create([
        'status' => OrderStatus::Processing,
        'payment_status' => PaymentStatus::Verified,
    ]);

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->patch(route('administration.orders.status.update', $order), [
            'status' => OrderStatus::Completed->value,
            'products_handed_over_confirmed' => true,
        ])
        ->assertSessionHasNoErrors();
    $this->patch(route('administration.orders.status.update', $order), [
        'status' => OrderStatus::Completed->value,
        'products_handed_over_confirmed' => true,
    ])->assertSessionHasErrors([
        'status' => 'This order cannot move to the selected status.',
    ]);

    expect($order->refresh()->status)->toBe(OrderStatus::Completed)
        ->and(Sale::query()->where('order_id', $order->id)->count())->toBe(1);
});

test('completion failure rolls back both the order status and sale', function () {
    $product = Product::factory()->create();
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 4]);
    $order = Order::factory()->paidWithGCash()->create([
        'status' => OrderStatus::Processing,
        'payment_status' => PaymentStatus::Verified,
    ]);
    OrderItem::factory()->for($order)->for($product)->create(['quantity' => 2]);
    Event::listen(
        'eloquent.created: '.Sale::class,
        fn (): never => throw new RuntimeException('Forced sale recording failure.'),
    );

    try {
        expect(fn () => app(OrderProcessingService::class)->updateStatus($order, OrderStatus::Completed, productsHandedOver: true))
            ->toThrow(RuntimeException::class, 'Forced sale recording failure.');
    } finally {
        Event::forget('eloquent.created: '.Sale::class);
    }

    expect($order->refresh()->status)->toBe(OrderStatus::Processing)
        ->and(Sale::query()->count())->toBe(0)
        ->and($inventory->refresh()->quantity)->toBe(4);
});

test('cancelling an eligible order restores its exact purchased quantities', function (OrderStatus $status) {
    $administrator = User::factory()->administrator()->create();
    $firstProduct = Product::factory()->create();
    $secondProduct = Product::factory()->create();
    $firstInventory = Inventory::factory()->for($firstProduct)->create(['quantity' => 3]);
    $secondInventory = Inventory::factory()->for($secondProduct)->create(['quantity' => 7]);
    $order = Order::factory()->create(['status' => $status]);
    OrderItem::factory()->for($order)->for($firstProduct)->create(['quantity' => 2]);
    OrderItem::factory()->for($order)->for($secondProduct)->create(['quantity' => 3]);

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->patch(route('administration.orders.status.update', $order), [
            'status' => OrderStatus::Cancelled->value,
        ])
        ->assertSessionHasNoErrors();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($firstInventory->refresh()->quantity)->toBe(5)
        ->and($secondInventory->refresh()->quantity)->toBe(10);
})->with([
    'pending order' => OrderStatus::Pending,
    'processing order' => OrderStatus::Processing,
]);

test('repeated cancellation cannot restore inventory more than once', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create();
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 4]);
    $order = Order::factory()->create();
    OrderItem::factory()->for($order)->for($product)->create(['quantity' => 2]);

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->patch(route('administration.orders.status.update', $order), [
            'status' => OrderStatus::Cancelled->value,
        ])
        ->assertSessionHasNoErrors();
    $this->patch(route('administration.orders.status.update', $order), [
        'status' => OrderStatus::Cancelled->value,
    ])->assertSessionHasErrors([
        'status' => 'This order cannot move to the selected status.',
    ]);

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($inventory->refresh()->quantity)->toBe(6);
});

test('completed orders cannot be cancelled or restore inventory', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create();
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 4]);
    $order = Order::factory()->create(['status' => OrderStatus::Completed]);
    OrderItem::factory()->for($order)->for($product)->create(['quantity' => 2]);

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->patch(route('administration.orders.status.update', $order), [
            'status' => OrderStatus::Cancelled->value,
        ])
        ->assertSessionHasErrors([
            'status' => 'This order cannot move to the selected status.',
        ]);

    expect($order->refresh()->status)->toBe(OrderStatus::Completed)
        ->and($inventory->refresh()->quantity)->toBe(4);
});

test('cancellation failure rolls back the status and restored inventory', function () {
    $product = Product::factory()->create();
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 4]);
    $order = Order::factory()->create();
    OrderItem::factory()->for($order)->for($product)->create(['quantity' => 2]);
    Event::listen(
        'eloquent.updated: '.Order::class,
        function (Order $updatedOrder): void {
            if ($updatedOrder->status === OrderStatus::Cancelled) {
                throw new RuntimeException('Forced cancellation failure.');
            }
        },
    );

    try {
        expect(fn () => app(OrderProcessingService::class)->updateStatus($order, OrderStatus::Cancelled))
            ->toThrow(RuntimeException::class, 'Forced cancellation failure.');
    } finally {
        Event::forget('eloquent.updated: '.Order::class);
    }

    expect($order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($inventory->refresh()->quantity)->toBe(4);
});

test('pending or rejected prepaid payment blocks order processing and completion', function (
    OrderStatus $currentStatus,
    OrderStatus $nextStatus,
    PaymentStatus $paymentStatus,
) {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->paidWithGCash()->create([
        'status' => $currentStatus,
        'payment_status' => $paymentStatus,
    ]);

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $response = $this->actingAs($administrator)
        ->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.status.update', $order), [
            'status' => $nextStatus->value,
            'products_handed_over_confirmed' => $nextStatus === OrderStatus::Completed,
        ]);

    $response
        ->assertRedirect(route('administration.orders.show', $order))
        ->assertSessionHasErrors([
            'status' => 'Verify payment before processing or completing this order.',
        ]);
    expect($order->refresh()->status)->toBe($currentStatus)
        ->and(Sale::query()->count())->toBe(0);
})->with([
    'pending payment before processing' => [
        OrderStatus::Pending,
        OrderStatus::Processing,
        PaymentStatus::Pending,
    ],
    'rejected payment before processing' => [
        OrderStatus::Pending,
        OrderStatus::Processing,
        PaymentStatus::Rejected,
    ],
    'pending payment before completion' => [
        OrderStatus::Processing,
        OrderStatus::Completed,
        PaymentStatus::Pending,
    ],
    'rejected payment before completion' => [
        OrderStatus::Processing,
        OrderStatus::Completed,
        PaymentStatus::Rejected,
    ],
]);

test('administrators cannot apply unapproved order status transitions', function (
    OrderStatus $currentStatus,
    OrderStatus $nextStatus,
) {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->paidWithGCash()->create(['status' => $currentStatus]);

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $response = $this->actingAs($administrator)
        ->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.status.update', $order), [
            'status' => $nextStatus->value,
            'products_handed_over_confirmed' => $nextStatus === OrderStatus::Completed,
        ]);

    $response
        ->assertRedirect(route('administration.orders.show', $order))
        ->assertSessionHasErrors([
            'status' => 'This order cannot move to the selected status.',
        ]);
    expect($order->refresh()->status)->toBe($currentStatus);
})->with([
    'pending cannot skip processing' => [OrderStatus::Pending, OrderStatus::Completed],
    'processing cannot return to pending' => [OrderStatus::Processing, OrderStatus::Pending],
    'completed is terminal' => [OrderStatus::Completed, OrderStatus::Cancelled],
    'cancelled is terminal' => [OrderStatus::Cancelled, OrderStatus::Processing],
]);

test('invalid order statuses are rejected without changing the order', function () {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->create();

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $response = $this->actingAs($administrator)
        ->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.status.update', $order), [
            'status' => 'shipped',
        ]);

    $response
        ->assertRedirect(route('administration.orders.show', $order))
        ->assertSessionHasErrors(['status' => 'Select a valid order status.']);
    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
});

test('wallet verification requires manual confirmation and accessible proof', function () {
    Storage::fake('local');
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->paidWithGCash()->create([
        'payment_proof_path' => 'payment-proofs/missing.png',
    ]);

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.payment-status.update', $order), [
            'payment_status' => PaymentStatus::Verified->value,
        ])
        ->assertSessionHasErrors([
            'manual_verification_confirmed' => 'Confirm the manual payment check before marking this payment as verified.',
        ]);

    $this->patch(route('administration.orders.payment-status.update', $order), [
        'payment_status' => PaymentStatus::Verified->value,
        'manual_verification_confirmed' => '1',
    ])->assertSessionHasErrors([
        'payment_status' => 'The submitted payment proof is unavailable and cannot be verified.',
    ]);

    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Pending);
});

test('administrators can manually verify wallet payment evidence', function () {
    Storage::fake('local');
    Storage::disk('local')->put('payment-proofs/verified.png', 'proof');
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->paidWithMaya()->create([
        'payment_proof_path' => 'payment-proofs/verified.png',
    ]);

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $response = $this->actingAs($administrator)
        ->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.payment-status.update', $order), [
            'payment_status' => PaymentStatus::Verified->value,
            'manual_verification_confirmed' => '1',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.orders.show', $order))
        ->assertInertiaFlash('toast.message', 'Payment status updated.');
    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Verified);
});

test('administrators can reject missing or unverifiable payment evidence', function () {
    Storage::fake('local');
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create();
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 4]);
    $order = Order::factory()->paidWithGCash()->create([
        'payment_proof_path' => null,
        'status' => OrderStatus::Processing,
    ]);
    OrderItem::factory()->for($order)->for($product)->create(['quantity' => 2]);

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->patch(route('administration.orders.payment-status.update', $order), [
            'payment_status' => PaymentStatus::Rejected->value,
            'rejection_reason' => PaymentRejectionReason::TransactionUnverified->value,
        ])
        ->assertSessionHasNoErrors();

    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Rejected)
        ->and($order->payment_rejection_reason)->toBe(PaymentRejectionReason::TransactionUnverified)
        ->and($order->payment_rejection_note)->toBeNull()
        ->and($order->status)->toBe(OrderStatus::Processing)
        ->and($inventory->refresh()->quantity)->toBe(4);
});

test('wallet rejection requires a predefined reason', function () {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->paidWithGCash()->create();

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.payment-status.update', $order), [
            'payment_status' => PaymentStatus::Rejected->value,
        ])
        ->assertSessionHasErrors([
            'rejection_reason' => 'Select why the payment proof was rejected.',
        ]);

    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Pending)
        ->and($order->payment_rejection_reason)->toBeNull();
});

test('other wallet rejection requires a short explanation', function () {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->paidWithMaya()->create();

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.payment-status.update', $order), [
            'payment_status' => PaymentStatus::Rejected->value,
            'rejection_reason' => PaymentRejectionReason::Other->value,
        ])
        ->assertSessionHasErrors([
            'rejection_note' => 'Explain why the payment proof was rejected when selecting Other.',
        ]);

    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Pending);
});

test('wallet rejection rejects invalid feedback', function (array $feedback, string $field, string $message) {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->paidWithGCash()->create();

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.payment-status.update', $order), [
            'payment_status' => PaymentStatus::Rejected->value,
            ...$feedback,
        ])
        ->assertSessionHasErrors([$field => $message]);

    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Pending);
})->with([
    'unknown reason' => [
        ['rejection_reason' => 'customer_changed_mind'],
        'rejection_reason',
        'Select a valid payment-proof rejection reason.',
    ],
    'note over 255 characters' => [
        [
            'rejection_reason' => PaymentRejectionReason::ImageUnclear->value,
            'rejection_note' => str_repeat('a', 256),
        ],
        'rejection_note',
        'The rejection note may not exceed 255 characters.',
    ],
]);

test('wallet rejection persists an optional short administrator note', function () {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->paidWithMaya()->create([
        'status' => OrderStatus::Processing,
    ]);

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->patch(route('administration.orders.payment-status.update', $order), [
            'payment_status' => PaymentStatus::Rejected->value,
            'rejection_reason' => PaymentRejectionReason::WrongAccountOrReference->value,
            'rejection_note' => 'The reference belongs to a different receiver.',
        ])
        ->assertSessionHasNoErrors();

    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Rejected)
        ->and($order->payment_rejection_reason)->toBe(PaymentRejectionReason::WrongAccountOrReference)
        ->and($order->payment_rejection_note)->toBe('The reference belongs to a different receiver.')
        ->and($order->status)->toBe(OrderStatus::Processing);
});

test('payment cannot be rejected after order completion', function () {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->paidWithGCash()->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Pending,
    ]);

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $response = $this->actingAs($administrator)
        ->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.payment-status.update', $order), [
            'payment_status' => PaymentStatus::Rejected->value,
            'rejection_reason' => PaymentRejectionReason::TransactionUnverified->value,
        ]);

    $response
        ->assertRedirect(route('administration.orders.show', $order))
        ->assertSessionHasErrors([
            'payment_status' => 'Payment cannot be rejected after the order is completed.',
        ]);
    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Pending)
        ->and($order->status)->toBe(OrderStatus::Completed);
});

test('invalid payment decisions are rejected without changing payment status', function (string $paymentStatus) {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->create();

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $response = $this->actingAs($administrator)
        ->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.payment-status.update', $order), [
            'payment_status' => $paymentStatus,
        ]);

    $response
        ->assertRedirect(route('administration.orders.show', $order))
        ->assertSessionHasErrors([
            'payment_status' => 'Select verified or rejected for the payment decision.',
        ]);
    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Pending);
})->with([
    'pending is not a decision' => PaymentStatus::Pending->value,
    'unknown status' => 'paid',
]);

test('cash and card at store require combined pickup confirmation instead of separate verification', function (PaymentMethod $paymentMethod) {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->create(['payment_method' => $paymentMethod]);

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->patch(route('administration.orders.payment-status.update', $order), [
            'payment_status' => PaymentStatus::Verified->value,
            'manual_verification_confirmed' => '1',
        ])
        ->assertSessionHasErrors('payment_status');

    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Pending);
})->with([
    'cash' => PaymentMethod::Cash,
    'card at store' => PaymentMethod::CardAtStore,
]);

test('final payment decisions cannot be changed', function () {
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->paidWithGCash()->create([
        'payment_status' => PaymentStatus::Rejected,
    ]);

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $response = $this->actingAs($administrator)
        ->from(route('administration.orders.show', $order))
        ->patch(route('administration.orders.payment-status.update', $order), [
            'payment_status' => PaymentStatus::Verified->value,
            'manual_verification_confirmed' => '1',
        ]);

    $response->assertSessionHasErrors([
        'payment_status' => 'This payment already has a final decision.',
    ]);
    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Rejected);
});

test('only administrators can stream private payment proof', function () {
    Storage::fake('local');
    Storage::disk('local')->put('payment-proofs/private.png', 'private-proof');
    $administrator = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->paidWithGCash()->create([
        'payment_proof_path' => 'payment-proofs/private.png',
    ]);

    $this->get(route('administration.orders.payment-proof.show', $order))
        ->assertRedirectToRoute('login');
    $this->actingAs($customer)
        ->get(route('administration.orders.payment-proof.show', $order))
        ->assertForbidden();
    $this->actingAs($administrator)
        ->get(route('administration.orders.payment-proof.show', $order))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertStreamedContent('private-proof');
});

test('unavailable or out of directory proof references return not found', function (string $path) {
    Storage::fake('local');
    Storage::disk('local')->put('other/private.png', 'private-proof');
    $administrator = User::factory()->administrator()->create();
    $order = Order::factory()->paidWithGCash()->create([
        'payment_proof_path' => $path,
    ]);

    $this->actingAs($administrator)
        ->get(route('administration.orders.payment-proof.show', $order))
        ->assertNotFound();
})->with([
    'missing file' => 'payment-proofs/missing.png',
    'outside proof directory' => 'other/private.png',
]);

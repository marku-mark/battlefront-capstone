<?php

use App\Actions\Chatbot\Context\ResolveOrderContext;
use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRejectionReason;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;

test('returns only minimal customer-facing status fields for an owned order reference', function () {
    config(['app.timezone' => 'UTC', 'battlefront.timezone' => 'Asia/Manila']);
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->delivery()->paidWithGCash()->for($customer)->create([
        'status' => OrderStatus::Processing,
        'payment_rejection_reason' => PaymentRejectionReason::Other,
        'payment_rejection_note' => 'Private administrator feedback.',
        'created_at' => '2026-09-20 10:00:00',
    ]);

    $context = app(ResolveOrderContext::class)->execute($customer, "Status of {$order->reference}?");

    expect($context)->toBe([
        'orders' => [[
            'reference' => sprintf('BF-%06d', $order->id),
            'created_at' => 'September 20, 2026 at 6:00 PM (UTC+08:00)',
            'status' => [
                'value' => 'processing',
                'label' => 'Processing',
            ],
            'fulfillment' => [
                'value' => 'delivery',
                'label' => 'Delivery',
            ],
        ]],
    ]);
});

test('cannot retrieve another customers order by reference', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $foreignOrder = Order::factory()->for($otherCustomer)->create();

    $context = app(ResolveOrderContext::class)->execute($customer, "Track {$foreignOrder->reference}");

    expect($context)->toBe(['orders' => []]);
});

test('normalizes shorthand and padded references to the owned canonical order reference', function (string $messageFormat) {
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create();

    $context = app(ResolveOrderContext::class)->execute($customer, sprintf($messageFormat, $order->id));

    expect($context['orders'])->toHaveCount(1)
        ->and($context['orders'][0]['reference'])->toBe($order->reference);
})->with([
    'short reference' => ['BF-%d'],
    'padded reference' => ['BF-%06d'],
    'embedded short reference' => ['my BF-%d order'],
    'punctuated reference' => ['status of BF/%d'],
]);

test('includes current payment method and status only for a specific owned payment inquiry', function () {
    config(['app.timezone' => 'UTC', 'battlefront.timezone' => 'Asia/Manila']);
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->delivery()->paidWithGCash()->for($customer)->create([
        'payment_status' => PaymentStatus::Verified,
        'payment_proof_path' => 'payment-proofs/private-proof.jpg',
        'payment_rejection_note' => 'Private administrator note.',
        'created_at' => '2026-09-20 10:00:00',
    ]);

    $context = app(ResolveOrderContext::class)->execute($customer, "Payment for BF-{$order->id}?");

    expect($context)->toBe([
        'orders' => [[
            'reference' => $order->reference,
            'created_at' => 'September 20, 2026 at 6:00 PM (UTC+08:00)',
            'status' => [
                'value' => 'pending',
                'label' => 'Pending',
            ],
            'fulfillment' => [
                'value' => 'delivery',
                'label' => 'Delivery',
            ],
            'payment' => [
                'method' => ['value' => 'gcash', 'label' => 'GCash'],
                'status' => ['value' => 'verified', 'label' => 'Verified'],
            ],
        ]],
    ]);
});

test('does not disclose payment context for a foreign reference or a guest', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $foreignOrder = Order::factory()->paidWithMaya()->for($otherCustomer)->create();
    $resolver = app(ResolveOrderContext::class);

    $foreignContext = $resolver->execute($customer, "Payment for BF-{$foreignOrder->id}?");
    $guestContext = $resolver->execute(null, "Payment for BF-{$foreignOrder->id}?");

    expect($foreignContext)->toBe(['orders' => []])
        ->and($guestContext)->toBe(['orders' => []]);
});

test('returns no protected order data for guests and administrators', function () {
    $customer = User::factory()->customer()->create();
    Order::factory()->for($customer)->create();
    $administrator = User::factory()->administrator()->create();
    $resolver = app(ResolveOrderContext::class);

    $guestContext = $resolver->execute(null, 'Show my order status');
    $administratorContext = $resolver->execute($administrator, 'Show my order status');

    expect($guestContext)->toBe(['orders' => []])
        ->and($administratorContext)->toBe(['orders' => []]);
});

test('uses the persisted customer role when a supplied user model is stale', function () {
    $customer = User::factory()->customer()->create();
    Order::factory()->for($customer)->create();
    User::query()->whereKey($customer->id)->update([
        'role' => 'administrator',
    ]);

    $context = app(ResolveOrderContext::class)->execute($customer, 'Show my order status');

    expect($context)->toBe(['orders' => []]);
});

test('returns the five newest owned orders when no reference is supplied', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $orders = collect();

    for ($day = 1; $day <= 6; $day++) {
        $orders->push(Order::factory()->for($customer)->create([
            'created_at' => "2026-09-0{$day} 08:00:00",
        ]));
    }

    Order::factory()->for($otherCustomer)->create(['created_at' => '2026-09-30 08:00:00']);

    $context = app(ResolveOrderContext::class)->execute($customer, 'What is my order status?');

    expect(array_column($context['orders'], 'reference'))->toBe(
        $orders
            ->reverse()
            ->take(5)
            ->map(fn (Order $order): string => sprintf('BF-%06d', $order->id))
            ->values()
            ->all(),
    );
});

test('an unknown reference returns an explicit empty order context', function () {
    $customer = User::factory()->customer()->create();
    Order::factory()->for($customer)->create([
        'fulfillment_method' => FulfillmentMethod::Pickup,
        'payment_method' => PaymentMethod::Cash,
    ]);

    $context = app(ResolveOrderContext::class)->execute($customer, 'Track BF-999999');

    expect($context)->toBe(['orders' => []]);
});

<?php

use App\Models\Order;
use App\Models\PayMongoFlow;
use App\Models\User;
use App\Services\PayMongo\RetryDirectOnlineBanking;
use App\Services\PayMongo\StartDirectQrPh;
use App\Services\PayMongo\StartHostedCheckout;

test('payment retry preserves web and mobile channel contracts', function (bool $mobile, string $channel, bool $ready) {
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create(['payment_method' => 'online', 'payment_origin' => 'paymongo']);
    PayMongoFlow::query()->create([
        'order_id' => $order->id, 'mode' => 'test', 'channel' => $channel,
        'status' => 'open', 'amount_minor' => 10000, 'currency' => 'PHP',
        'merchant_cutoff_at' => now()->addHour(),
        'qr_code_image_url' => $ready ? 'data:image/png;base64,cXI=' : null,
        'qr_expires_at' => $ready ? now()->addMinutes(10) : null,
    ]);
    $url = 'https://payments.example/continue';
    $this->mock(StartHostedCheckout::class)->shouldReceive('execute')->times($channel === 'hosted_checkout' ? 1 : 0)->andReturn($url);
    $this->mock(StartDirectQrPh::class)->shouldReceive('execute')->times($channel === 'direct_qrph' ? 1 : 0);
    $this->mock(RetryDirectOnlineBanking::class)->shouldReceive('execute')->times(in_array($channel, ['hosted_checkout', 'direct_qrph']) ? 0 : 1)->andReturn($url);
    if ($mobile) {
        $this->withToken($customer->createToken('retry')->plainTextToken);
    } else {
        $this->actingAs($customer);
    }

    $response = $this->postJson(route($mobile ? 'api.v1.orders.paymongo.retry' : 'orders.paymongo.retry', $order));

    if ($channel === 'direct_qrph') {
        $response->assertStatus($ready ? 200 : 202)->assertJsonPath('payment.status', $ready ? 'qrph_ready' : 'reconciliation_pending')->assertJsonPath('data.id', $order->id);
    } elseif ($channel === 'hosted_checkout') {
        $response->assertOk()->assertJsonPath('payment.status', 'checkout_ready')->assertJsonPath('payment.'.($mobile ? 'redirect_url' : 'checkout_url'), $url)->assertJsonMissingPath('payment.'.($mobile ? 'checkout_url' : 'redirect_url'));
    } elseif ($mobile) {
        $response->assertExactJson(['payment' => ['redirect_url' => $url]]);
    } else {
        $response->assertRedirect($url);
    }
    expect($order->fresh()->payment_status->value)->toBe('pending');
})->with([false, true])->with([
    'hosted' => ['hosted_checkout', false],
    'QR ready' => ['direct_qrph', true],
    'QR pending' => ['direct_qrph', false],
    'bank' => ['direct_online_banking', false],
    'fallback' => ['legacy_channel', false],
]);

test('payment retry preserves missing flow and provider failure responses', function (bool $mobile, bool $missingFlow) {
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create();
    if (! $missingFlow) {
        PayMongoFlow::query()->create([
            'order_id' => $order->id, 'mode' => 'test', 'channel' => 'hosted_checkout',
            'status' => 'open', 'amount_minor' => 10000, 'currency' => 'PHP', 'merchant_cutoff_at' => now()->addHour(),
        ]);
    }
    $this->mock(StartHostedCheckout::class)->shouldReceive('execute')->times($missingFlow ? 0 : 1)->andThrow(new RuntimeException('Unavailable'));
    if ($mobile) {
        $this->withToken($customer->createToken('retry')->plainTextToken);
    } else {
        $this->actingAs($customer);
    }

    $this->postJson(route($mobile ? 'api.v1.orders.paymongo.retry' : 'orders.paymongo.retry', $order))
        ->assertAccepted()->assertExactJson(['message' => 'The online payment is being reconciled. Check this order again shortly.']);
})->with([false, true])->with([false, true]);

test('payment retry conceals foreign orders before invoking payment operations', function (bool $mobile) {
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->create();
    $this->mock(StartHostedCheckout::class)->shouldNotReceive('execute');
    $this->mock(StartDirectQrPh::class)->shouldNotReceive('execute');
    $this->mock(RetryDirectOnlineBanking::class)->shouldNotReceive('execute');
    if ($mobile) {
        $this->withToken($customer->createToken('retry')->plainTextToken);
    } else {
        $this->actingAs($customer);
    }

    $this->postJson(route($mobile ? 'api.v1.orders.paymongo.retry' : 'orders.paymongo.retry', $order))->assertNotFound();
})->with([false, true]);

test('payment retry requires authentication', function (bool $mobile) {
    $this->postJson(route($mobile ? 'api.v1.orders.paymongo.retry' : 'orders.paymongo.retry', 1))->assertUnauthorized();
})->with([false, true]);

test('browser retries preserve QR order redirects and banking Inertia locations', function (bool $qrPh) {
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create();
    PayMongoFlow::query()->create([
        'order_id' => $order->id, 'mode' => 'test', 'channel' => $qrPh ? 'direct_qrph' : 'direct_online_banking',
        'status' => 'open', 'amount_minor' => 10000, 'currency' => 'PHP', 'merchant_cutoff_at' => now()->addHour(),
    ]);
    if ($qrPh) {
        $this->mock(StartDirectQrPh::class)->shouldReceive('execute')->once();
    } else {
        $this->mock(RetryDirectOnlineBanking::class)->shouldReceive('execute')->once()->andReturn('https://payments.example/bank');
    }

    $response = $this->actingAs($customer)->withHeader('X-Inertia', 'true')->post(route('orders.paymongo.retry', $order));

    if ($qrPh) {
        $response->assertRedirect(route('orders.show', $order))->assertInertiaFlashMissing('toast');
    } else {
        $response->assertConflict()->assertHeader('X-Inertia-Location', 'https://payments.example/bank');
    }
})->with([false, true]);

test('browser payment retry keeps the saved order redirect', function (bool $failure) {
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create();
    PayMongoFlow::query()->create([
        'order_id' => $order->id, 'mode' => 'test', 'channel' => 'hosted_checkout',
        'status' => 'open', 'amount_minor' => 10000, 'currency' => 'PHP', 'merchant_cutoff_at' => now()->addHour(),
    ]);
    $mock = $this->mock(StartHostedCheckout::class)->shouldReceive('execute')->once();
    if ($failure) {
        $mock->andThrow(new RuntimeException('Unavailable'));
    } else {
        $mock->andReturn('https://payments.example/continue');
    }

    $this->actingAs($customer)->post(route('orders.paymongo.retry', $order))
        ->assertRedirect(route('orders.show', $order))
        ->assertInertiaFlash('toast', $failure
            ? ['type' => 'info', 'message' => 'The online payment is being reconciled. Check this order again shortly.']
            : ['type' => 'success', 'message' => 'Your secure payment is ready. Continue in the payment tab.']);
})->with([false, true]);

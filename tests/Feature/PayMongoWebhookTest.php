<?php

use App\Actions\PayMongo\RecordPayMongoWebhook;
use App\Enums\FulfillmentMethod;
use App\Enums\PaymentMethod;
use App\Events\OrderNotificationOccurred;
use App\Jobs\ProcessPayMongoWebhook;
use App\Jobs\ReconcilePayMongoOrder;
use App\Models\Order;
use App\Models\PayMongoFlow;
use App\Models\PayMongoOperation;
use App\Models\PayMongoWebhookInbox;
use App\Models\User;
use App\Services\Notifications\OrderNotificationPublisher;
use App\Services\Order\OrderProcessingService;
use App\Services\PayMongo\CloseHostedCheckout;
use App\Services\PayMongo\RecordPayMongoPayment;
use App\Services\PayMongo\RetryDirectOnlineBanking;
use App\Services\PayMongo\StartDirectOnlineBanking;
use App\Services\PayMongo\StartHostedCheckout;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('a valid test webhook is durably recorded and queued once', function () {
    Bus::fake();
    config(['services.paymongo.mode' => 'test', 'services.paymongo.webhook_secret' => 'whsk_test_fake']);

    $timestamp = (string) now()->timestamp;
    $payload = json_encode([
        'data' => [
            'id' => 'evt_test_unique',
            'type' => 'event',
            'attributes' => [
                'type' => 'checkout_session.payment.paid',
                'livemode' => false,
                'data' => [
                    'id' => 'cs_test_session',
                    'type' => 'checkout_session',
                    'attributes' => [
                        'payments' => [[
                            'id' => 'pay_test_payment',
                            'attributes' => [
                                'status' => 'paid',
                                'amount' => 10300,
                                'net_amount' => 10000,
                                'currency' => 'PHP',
                                'source' => ['type' => 'gcash'],
                            ],
                        ]],
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsk_test_fake');

    $this->call('POST', '/api/paymongo/webhook', [], [], [], [
        'HTTP_PAYMONGO_SIGNATURE' => "t={$timestamp},te={$signature},li=",
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertAccepted();

    $this->assertDatabaseHas('paymongo_webhook_inbox', [
        'event_id' => 'evt_test_unique',
        'resource_id' => 'cs_test_session',
        'status' => 'received',
    ]);
    $this->call('POST', '/api/paymongo/webhook', [], [], [], [
        'HTTP_PAYMONGO_SIGNATURE' => "t={$timestamp},te={$signature},li=",
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk()->assertExactJson(['received' => true]);
    $this->assertDatabaseCount('paymongo_webhook_inbox', 1);
    Bus::assertDispatchedTimes(ProcessPayMongoWebhook::class, 1);
});

test('webhook recording propagates unrelated persistence failures without queuing work', function () {
    Bus::fake([ProcessPayMongoWebhook::class]);

    expect(fn () => app(RecordPayMongoWebhook::class)->execute([
        'mode' => 'test', 'event_id' => 'evt_test_invalid', 'event_type' => null,
    ]))->toThrow(QueryException::class);

    $this->assertDatabaseCount('paymongo_webhook_inbox', 0);
    Bus::assertNotDispatched(ProcessPayMongoWebhook::class);
});

test('a signed direct bank payment webhook confirms payment without completing fulfillment', function () {
    Bus::fake();
    config(['services.paymongo.mode' => 'test', 'services.paymongo.webhook_secret' => 'whsk_test_fake']);
    $timestamp = (string) now()->timestamp;
    $payload = json_encode(['data' => [
        'id' => 'evt_test_bank_paid',
        'type' => 'event',
        'attributes' => [
            'type' => 'payment.paid',
            'livemode' => false,
            'data' => [
                'id' => 'pay_test_bank_paid',
                'type' => 'payment',
                'attributes' => [
                    'status' => 'paid',
                    'amount' => 25000,
                    'net_amount' => 25000,
                    'currency' => 'PHP',
                    'payment_intent_id' => 'pi_test_bank_paid',
                    'source' => ['type' => 'dob'],
                ],
            ],
        ],
    ]], JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsk_test_fake');

    $this->call('POST', '/api/paymongo/webhook', [], [], [], [
        'HTTP_PAYMONGO_SIGNATURE' => "t={$timestamp},te={$signature},li=",
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertAccepted();

    $this->assertDatabaseHas('paymongo_webhook_inbox', [
        'event_id' => 'evt_test_bank_paid',
        'resource_id' => 'pay_test_bank_paid',
        'event_type' => 'payment.paid',
    ]);
    $inbox = PayMongoWebhookInbox::query()->where('event_id', 'evt_test_bank_paid')->firstOrFail();
    expect(data_get($inbox->verified_data, 'attributes.payment_intent_id'))->toBe('pi_test_bank_paid');
    Bus::assertDispatched(ProcessPayMongoWebhook::class);
});

test('processing an authenticated paid bank event verifies payment but leaves order fulfillment unchanged', function () {
    Event::fake([OrderNotificationOccurred::class]);
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create([
        'payment_method' => PaymentMethod::Online,
        'payment_origin' => 'paymongo',
        'fulfillment_method' => FulfillmentMethod::Pickup,
        'total_amount' => '250.00',
    ]);
    $flow = PayMongoFlow::query()->create([
        'order_id' => $order->id,
        'mode' => 'test',
        'channel' => 'direct_online_banking',
        'bank_code' => 'bpi',
        'status' => 'open',
        'payment_intent_id' => 'pi_test_bank_job',
        'amount_minor' => 25000,
        'currency' => 'PHP',
        'merchant_cutoff_at' => now()->addDay(),
    ]);
    $flow->attempts()->create([
        'payment_intent_id' => 'pi_test_bank_job',
        'attempt_number' => 1,
        'bank_code' => 'bpi',
        'payment_id' => 'pay_test_bank_job',
        'status' => 'awaiting_next_action',
    ]);
    $inbox = PayMongoWebhookInbox::query()->create([
        'mode' => 'test',
        'event_id' => 'evt_test_bank_job',
        'event_type' => 'payment.paid',
        'resource_id' => 'pay_test_bank_job',
        'verified_data' => ['attributes' => [
            'payment_intent_id' => 'pi_test_bank_job',
            'payments' => [[
                'id' => 'pay_test_bank_job',
                'status' => 'paid',
                'amount' => 25000,
                'net_amount' => 24000,
                'currency' => 'PHP',
                'method' => 'dob',
            ]],
        ]],
        'received_at' => now(),
    ]);

    app(ProcessPayMongoWebhook::class, ['inboxId' => $inbox->id])->handle(
        app(OrderNotificationPublisher::class),
        app(RecordPayMongoPayment::class),
    );

    expect($order->fresh()->payment_status->value)->toBe('verified')
        ->and($order->fresh()->status->value)->toBe('pending')
        ->and($flow->fresh()->status)->toBe('paid')
        ->and($inbox->fresh()->status)->toBe('processed');
    Event::assertDispatchedTimes(OrderNotificationOccurred::class, 1);
});

test('an invalid signature is rejected without persisting the event', function () {
    config(['services.paymongo.mode' => 'test', 'services.paymongo.webhook_secret' => 'whsk_test_fake']);

    $this->call('POST', '/api/paymongo/webhook', [], [], [], [
        'HTTP_PAYMONGO_SIGNATURE' => 't='.now()->timestamp.',te=invalid,li=',
        'CONTENT_TYPE' => 'application/json',
    ], '{"data":{}}')->assertBadRequest();

    $this->assertDatabaseCount('paymongo_webhook_inbox', 0);
});

test('hosted checkout persists an operation before sending the exact saved total', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.paymongo.com/v2/checkout_sessions' => Http::response([
            'data' => [
                'id' => 'cs_test_session',
                'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_test_session#key'],
            ],
        ]),
    ]);
    config([
        'services.paymongo.enabled' => true,
        'services.paymongo.mode' => 'test',
        'services.paymongo.secret_key' => 'sk_test_fake',
        'services.paymongo.hosted_methods' => ['card', 'gcash', 'paymaya', 'qrph'],
    ]);

    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create([
        'payment_method' => PaymentMethod::Online,
        'payment_origin' => 'paymongo',
        'fulfillment_method' => FulfillmentMethod::Pickup,
        'total_amount' => '100.00',
    ]);

    $url = app(StartHostedCheckout::class)->execute($order);

    expect($url)->toBe('https://checkout.paymongo.com/cs_test_session#key')
        ->and($order->user_id)->toBe($customer->id);
    $this->assertDatabaseHas('paymongo_flows', [
        'order_id' => $order->id,
        'checkout_session_id' => 'cs_test_session',
        'amount_minor' => 10000,
        'status' => 'open',
    ]);
    $this->assertDatabaseHas('paymongo_operations', [
        'kind' => 'create_checkout_session',
        'status' => 'completed',
        'provider_reference' => 'cs_test_session',
    ]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.paymongo.com/v2/checkout_sessions'
        && $request['data']['attributes']['line_items'][0]['amount'] === 10000
        && ! array_key_exists('pass_on_fees', $request['data']['attributes'])
        && $request->hasHeader('Idempotency-Key'));
});

test('an uncertain hosted session can be resumed from the customer order with the same idempotency key', function () {
    Http::preventStrayRequests();
    $endpoint = 'https://api.paymongo.com/v2/checkout_sessions';
    Http::fake([$endpoint => Http::sequence()
        ->push(['errors' => [['code' => 'server_error']]], 500)
        ->push(['data' => [
            'id' => 'cs_test_recovered',
            'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_test_recovered#key'],
        ]])]);
    config([
        'services.paymongo.enabled' => true,
        'services.paymongo.mode' => 'test',
        'services.paymongo.secret_key' => 'sk_test_fake',
    ]);
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create([
        'payment_method' => PaymentMethod::Online,
        'payment_origin' => 'paymongo',
        'fulfillment_method' => FulfillmentMethod::Pickup,
        'total_amount' => '100.00',
    ]);

    expect(fn () => app(StartHostedCheckout::class)->execute($order))->toThrow(RuntimeException::class);
    $operation = PayMongoOperation::query()->where('kind', 'create_checkout_session')->firstOrFail();
    $idempotencyKey = $operation->idempotency_key;
    $this->assertDatabaseHas('paymongo_flows', ['order_id' => $order->id, 'status' => 'reconciliation_required']);

    $this->actingAs($customer)->postJson(route('orders.paymongo.retry', $order))
        ->assertOk()
        ->assertJsonPath('payment.status', 'checkout_ready')
        ->assertJsonPath('payment.checkout_url', 'https://checkout.paymongo.com/cs_test_recovered#key');
    Http::assertSent(fn ($request): bool => $request->url() === $endpoint && $request->hasHeader('Idempotency-Key', $idempotencyKey));
    $this->assertDatabaseHas('paymongo_operations', [
        'id' => $operation->id,
        'idempotency_key' => $idempotencyKey,
        'status' => 'completed',
        'provider_reference' => 'cs_test_recovered',
    ]);
});

test('an overdue hosted checkout releases inventory only after PayMongo confirms expiry', function () {
    Http::preventStrayRequests();
    $sessionUrl = 'https://api.paymongo.com/v1/checkout_sessions/cs_test_expire';
    Http::fake([
        $sessionUrl => Http::sequence()
            ->push(['data' => ['id' => 'cs_test_expire', 'attributes' => ['livemode' => false, 'status' => 'active', 'payments' => []]]])
            ->push(['data' => ['id' => 'cs_test_expire', 'attributes' => ['livemode' => false, 'status' => 'expired', 'payments' => []]]]),
        $sessionUrl.'/expire' => Http::response(['data' => ['id' => 'cs_test_expire', 'attributes' => ['status' => 'expired']]]),
    ]);
    config([
        'services.paymongo.enabled' => true,
        'services.paymongo.mode' => 'test',
        'services.paymongo.secret_key' => 'sk_test_fake',
    ]);
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create([
        'payment_method' => PaymentMethod::Online,
        'payment_origin' => 'paymongo',
        'fulfillment_method' => FulfillmentMethod::Pickup,
        'total_amount' => '100.00',
    ]);
    $flow = PayMongoFlow::query()->create([
        'order_id' => $order->id,
        'mode' => 'test',
        'channel' => 'hosted_checkout',
        'status' => 'open',
        'checkout_session_id' => 'cs_test_expire',
        'amount_minor' => 10000,
        'currency' => 'PHP',
        'merchant_cutoff_at' => now()->subMinute(),
    ]);

    expect(app(CloseHostedCheckout::class)->execute($order->id))->toBeTrue();
    expect($flow->fresh()->status)->toBe('closed');
    Http::assertSent(fn ($request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/expire'));
});

test('overdue reconciliation dispatches QR Ph and excludes cancelled historical flows', function () {
    Bus::fake();
    $customer = User::factory()->customer()->create();

    foreach (range(1, 101) as $index) {
        $cancelledOrder = Order::factory()->for($customer)->create([
            'payment_method' => PaymentMethod::Online,
            'payment_origin' => 'paymongo',
            'status' => 'cancelled',
        ]);
        PayMongoFlow::query()->create([
            'order_id' => $cancelledOrder->id,
            'mode' => 'test',
            'channel' => 'hosted_checkout',
            'status' => 'closed',
            'amount_minor' => 10000,
            'currency' => 'PHP',
            'merchant_cutoff_at' => now()->subDay(),
        ]);
    }

    $qrOrder = Order::factory()->for($customer)->create([
        'payment_method' => PaymentMethod::Online,
        'payment_origin' => 'paymongo',
        'hosted_payment_method' => 'qrph',
    ]);
    $qrFlow = PayMongoFlow::query()->create([
        'order_id' => $qrOrder->id,
        'mode' => 'test',
        'channel' => 'direct_qrph',
        'status' => 'open',
        'payment_intent_id' => 'pi_test_overdue_qr',
        'amount_minor' => 10000,
        'currency' => 'PHP',
        'merchant_cutoff_at' => now()->subMinute(),
    ]);

    $this->artisan('paymongo:reconcile-overdue')->assertSuccessful();

    Bus::assertDispatchedTimes(ReconcilePayMongoOrder::class, 1);
    Bus::assertDispatched(ReconcilePayMongoOrder::class, fn (ReconcilePayMongoOrder $job): bool => $job->flowId === $qrFlow->id);
});

test('cancelled PayMongo orders show review guidance instead of saying stock remains reserved', function () {
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create([
        'payment_method' => PaymentMethod::Online,
        'payment_origin' => 'paymongo',
        'status' => 'cancelled',
    ]);

    $this->actingAs($customer)->get(route('orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.payment.notice', 'This order is cancelled. Any payment received after cancellation requires Battlefront review; cancellation does not refund a payment.'));
});

test('a hosted checkout with a processing payment is not expired', function () {
    Http::preventStrayRequests();
    $sessionUrl = 'https://api.paymongo.com/v1/checkout_sessions/cs_test_processing';
    Http::fake([
        $sessionUrl => Http::response(['data' => ['id' => 'cs_test_processing', 'attributes' => [
            'livemode' => false,
            'status' => 'active',
            'payments' => [['attributes' => ['status' => 'processing']]],
        ]]]),
    ]);
    config([
        'services.paymongo.enabled' => true,
        'services.paymongo.mode' => 'test',
        'services.paymongo.secret_key' => 'sk_test_fake',
    ]);
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create([
        'payment_method' => PaymentMethod::Online,
        'payment_origin' => 'paymongo',
        'fulfillment_method' => FulfillmentMethod::Pickup,
    ]);
    PayMongoFlow::query()->create([
        'order_id' => $order->id,
        'mode' => 'test',
        'channel' => 'hosted_checkout',
        'status' => 'open',
        'checkout_session_id' => 'cs_test_processing',
        'amount_minor' => 10000,
        'currency' => 'PHP',
        'merchant_cutoff_at' => now()->subMinute(),
    ]);

    expect(app(CloseHostedCheckout::class)->execute($order->id))->toBeFalse();
    Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/expire'));
});

test('hosted checkout return retrieves PayMongo payment state before showing the order', function () {
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.paymongo.com/v1/checkout_sessions/cs_test_return_paid' => Http::response(['data' => [
            'id' => 'cs_test_return_paid',
            'attributes' => [
                'livemode' => false,
                'status' => 'active',
                'payments' => [[
                    'id' => 'pay_test_return_paid',
                    'attributes' => [
                        'status' => 'paid',
                        'amount' => 25000,
                        'net_amount' => 24000,
                        'currency' => 'PHP',
                        'source' => ['type' => 'gcash'],
                    ],
                ]],
            ],
        ]]),
    ]);
    config([
        'services.paymongo.enabled' => true,
        'services.paymongo.mode' => 'test',
        'services.paymongo.secret_key' => 'sk_test_fake',
    ]);
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create([
        'payment_method' => PaymentMethod::Online,
        'payment_origin' => 'paymongo',
        'fulfillment_method' => FulfillmentMethod::Pickup,
        'total_amount' => '250.00',
    ]);
    $flow = PayMongoFlow::query()->create([
        'order_id' => $order->id,
        'mode' => 'test',
        'channel' => 'hosted_checkout',
        'status' => 'open',
        'checkout_session_id' => 'cs_test_return_paid',
        'amount_minor' => 25000,
        'currency' => 'PHP',
        'merchant_cutoff_at' => now()->addDay(),
    ]);

    $this->actingAs($customer)
        ->get(route('orders.show', $order).'?paymongo_return=1')
        ->assertRedirect(route('orders.show', ['order' => $order, 'paymongo_returned' => 1]));

    expect($flow->fresh()->status)->toBe('paid');
    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.paymongo.com/v1/checkout_sessions/cs_test_return_paid');
    expect($order->fresh()->payment_status->value)->toBe('verified')
        ->and($order->fresh()->status->value)->toBe('pending')
        ->and($flow->fresh()->status)->toBe('paid');
    $this->assertDatabaseHas('paymongo_operations', [
        'paymongo_flow_id' => $flow->id,
        'kind' => 'verify_return_status',
        'status' => 'completed',
        'provider_reference' => 'cs_test_return_paid',
    ]);

    $this->actingAs($customer)
        ->get(route('orders.show', ['order' => $order, 'paymongo_returned' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Orders/Show')
            ->where('isPayMongoReturn', true));
});

test('hosted checkout return alone cannot verify an unpaid payment', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.paymongo.com/v1/checkout_sessions/cs_test_return_unpaid' => Http::response(['data' => [
            'id' => 'cs_test_return_unpaid',
            'attributes' => ['livemode' => false, 'status' => 'active', 'payments' => []],
        ]]),
    ]);
    config([
        'services.paymongo.enabled' => true,
        'services.paymongo.mode' => 'test',
        'services.paymongo.secret_key' => 'sk_test_fake',
    ]);
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create([
        'payment_method' => PaymentMethod::Online,
        'payment_origin' => 'paymongo',
        'total_amount' => '100.00',
    ]);
    $flow = PayMongoFlow::query()->create([
        'order_id' => $order->id,
        'mode' => 'test',
        'channel' => 'hosted_checkout',
        'status' => 'open',
        'checkout_session_id' => 'cs_test_return_unpaid',
        'amount_minor' => 10000,
        'currency' => 'PHP',
        'merchant_cutoff_at' => now()->addDay(),
    ]);

    $this->actingAs($customer)
        ->get(route('orders.show', $order).'?paymongo_return=1')
        ->assertRedirect(route('orders.show', ['order' => $order, 'paymongo_returned' => 1]));

    expect($order->fresh()->payment_status->value)->toBe('pending')
        ->and($flow->fresh()->status)->toBe('open');
});

test('customer cancellation of a bank order requires PayMongo to confirm intent cancellation', function () {
    Http::preventStrayRequests();
    $intentUrl = 'https://orders-api.paymongo.com/v1/payment_intents/pi_test_cancel';
    Http::fake([
        $intentUrl => Http::sequence()
            ->push(['data' => ['id' => 'pi_test_cancel', 'attributes' => ['livemode' => false, 'status' => 'awaiting_next_action', 'payments' => []]]])
            ->push(['data' => ['id' => 'pi_test_cancel', 'attributes' => ['livemode' => false, 'status' => 'cancelled', 'payments' => []]]]),
        $intentUrl.'/cancel' => Http::response(['data' => ['id' => 'pi_test_cancel', 'attributes' => ['status' => 'cancelled']]]),
    ]);
    config([
        'services.paymongo.enabled' => true,
        'services.paymongo.mode' => 'test',
        'services.paymongo.secret_key' => 'sk_test_fake',
    ]);
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create([
        'payment_method' => PaymentMethod::Online,
        'payment_origin' => 'paymongo',
        'fulfillment_method' => FulfillmentMethod::Pickup,
    ]);
    $flow = PayMongoFlow::query()->create([
        'order_id' => $order->id,
        'mode' => 'test',
        'channel' => 'direct_online_banking',
        'bank_code' => 'bpi',
        'status' => 'open',
        'payment_intent_id' => 'pi_test_cancel',
        'amount_minor' => 10000,
        'currency' => 'PHP',
        'merchant_cutoff_at' => now()->addDay(),
    ]);
    $flow->attempts()->create([
        'payment_intent_id' => 'pi_test_cancel',
        'attempt_number' => 1,
        'bank_code' => 'bpi',
        'status' => 'awaiting_next_action',
    ]);

    $cancelled = app(OrderProcessingService::class)->cancelForCustomer($customer, $order->id);

    expect($cancelled->status->value)->toBe('cancelled')
        ->and($flow->fresh()->status)->toBe('closed');
    Http::assertSent(fn ($request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/cancel'));
});

test('direct online banking persists intent and attempt references before redirecting to the bank', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://orders-api.paymongo.com/v1/payment_intents' => Http::response(['data' => [
            'id' => 'pi_test_bank',
            'attributes' => ['client_key' => 'pi_test_bank_client_test', 'status' => 'awaiting_payment_method'],
        ]]),
        'https://orders-api.paymongo.com/v1/payment_methods' => Http::response(['data' => ['id' => 'pm_test_bank']]),
        'https://orders-api.paymongo.com/v1/payment_intents/pi_test_bank/attach' => Http::response(['data' => [
            'id' => 'pi_test_bank',
            'attributes' => [
                'status' => 'awaiting_next_action',
                'next_action' => ['redirect' => ['url' => 'https://bank.paymongo.test/authorize']],
            ],
        ]]),
    ]);
    config([
        'services.paymongo.enabled' => true,
        'services.paymongo.mode' => 'test',
        'services.paymongo.secret_key' => 'sk_test_fake',
        'services.paymongo.public_key' => 'pk_test_fake',
    ]);
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create([
        'payment_method' => PaymentMethod::Online,
        'payment_origin' => 'paymongo',
        'fulfillment_method' => FulfillmentMethod::Pickup,
        'total_amount' => '250.00',
    ]);

    $url = app(StartDirectOnlineBanking::class)->execute($order, [
        'payment_channel' => 'direct_online_banking',
        'payment_bank' => 'bpi',
    ]);

    expect($url)->toBe('https://bank.paymongo.test/authorize');
    $this->assertDatabaseHas('paymongo_flows', [
        'order_id' => $order->id,
        'channel' => 'direct_online_banking',
        'bank_code' => 'bpi',
        'payment_intent_id' => 'pi_test_bank',
        'amount_minor' => 25000,
        'status' => 'open',
    ]);
    $this->assertDatabaseHas('paymongo_attempts', [
        'payment_intent_id' => 'pi_test_bank',
        'payment_method_id' => 'pm_test_bank',
        'bank_code' => 'bpi',
        'status' => 'awaiting_next_action',
    ]);
});

test('an uncertain bank intent creation retries with its existing idempotency key', function () {
    Http::preventStrayRequests();
    $intentEndpoint = 'https://orders-api.paymongo.com/v1/payment_intents';
    Http::fake([
        $intentEndpoint => Http::sequence()
            ->push(['errors' => [['code' => 'server_error']]], 500)
            ->push(['data' => [
                'id' => 'pi_test_recovered_bank',
                'attributes' => ['client_key' => 'pi_test_recovered_bank_client', 'status' => 'awaiting_payment_method'],
            ]]),
        'https://orders-api.paymongo.com/v1/payment_methods' => Http::response(['data' => ['id' => 'pm_test_recovered_bank']]),
        'https://orders-api.paymongo.com/v1/payment_intents/pi_test_recovered_bank/attach' => Http::response(['data' => [
            'id' => 'pi_test_recovered_bank',
            'attributes' => [
                'status' => 'awaiting_next_action',
                'next_action' => ['redirect' => ['url' => 'https://bank.paymongo.test/recovered']],
            ],
        ]]),
    ]);
    config([
        'services.paymongo.enabled' => true,
        'services.paymongo.mode' => 'test',
        'services.paymongo.secret_key' => 'sk_test_fake',
        'services.paymongo.public_key' => 'pk_test_fake',
    ]);
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create([
        'payment_method' => PaymentMethod::Online,
        'payment_origin' => 'paymongo',
        'fulfillment_method' => FulfillmentMethod::Pickup,
        'total_amount' => '250.00',
    ]);
    $selection = ['payment_channel' => 'direct_online_banking', 'payment_bank' => 'bpi'];

    expect(fn () => app(StartDirectOnlineBanking::class)->execute($order, $selection))->toThrow(RuntimeException::class);
    $operation = PayMongoOperation::query()->where('kind', 'create_payment_intent')->firstOrFail();
    $idempotencyKey = $operation->idempotency_key;

    expect(app(StartDirectOnlineBanking::class)->execute($order, $selection))->toBe('https://bank.paymongo.test/recovered');
    Http::assertSent(fn ($request): bool => $request->url() === $intentEndpoint && $request->hasHeader('Idempotency-Key', $idempotencyKey));
    expect(PayMongoFlow::query()->where('order_id', $order->id)->value('payment_intent_id'))->toBe('pi_test_recovered_bank');
});

test('a failed direct bank attempt can be retried only after PayMongo returns the intent to awaiting payment method', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://orders-api.paymongo.com/v1/payment_intents/pi_test_retry' => Http::response(['data' => [
            'id' => 'pi_test_retry',
            'attributes' => [
                'livemode' => false,
                'status' => 'awaiting_payment_method',
                'amount' => 25000,
                'currency' => 'PHP',
                'client_key' => 'pi_test_retry_client',
            ],
        ]]),
        'https://orders-api.paymongo.com/v1/payment_methods' => Http::response(['data' => ['id' => 'pm_test_retry']]),
        'https://orders-api.paymongo.com/v1/payment_intents/pi_test_retry/attach' => Http::response(['data' => [
            'id' => 'pi_test_retry',
            'attributes' => [
                'status' => 'awaiting_next_action',
                'next_action' => ['redirect' => ['url' => 'https://bank.paymongo.test/retry']],
            ],
        ]]),
    ]);
    config([
        'services.paymongo.enabled' => true,
        'services.paymongo.mode' => 'test',
        'services.paymongo.secret_key' => 'sk_test_fake',
        'services.paymongo.public_key' => 'pk_test_fake',
    ]);
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create([
        'payment_method' => PaymentMethod::Online,
        'payment_origin' => 'paymongo',
        'fulfillment_method' => FulfillmentMethod::Pickup,
        'total_amount' => '250.00',
    ]);
    $flow = PayMongoFlow::query()->create([
        'order_id' => $order->id,
        'mode' => 'test',
        'channel' => 'direct_online_banking',
        'bank_code' => 'bpi',
        'status' => 'awaiting_payment_method',
        'payment_intent_id' => 'pi_test_retry',
        'amount_minor' => 25000,
        'currency' => 'PHP',
        'merchant_cutoff_at' => now()->addHour(),
    ]);
    $flow->attempts()->create([
        'payment_intent_id' => 'pi_test_retry',
        'attempt_number' => 1,
        'bank_code' => 'bpi',
        'payment_id' => 'pay_test_failed',
        'status' => 'failed',
        'completed_at' => now(),
    ]);

    $url = app(RetryDirectOnlineBanking::class)->execute($order);

    expect($url)->toBe('https://bank.paymongo.test/retry');
    $this->assertDatabaseHas('paymongo_attempts', [
        'paymongo_flow_id' => $flow->id,
        'payment_intent_id' => 'pi_test_retry',
        'attempt_number' => 2,
        'payment_method_id' => 'pm_test_retry',
        'status' => 'awaiting_next_action',
    ]);
    expect($flow->fresh()->status)->toBe('open');
});

<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Events\OrderNotificationOccurred;
use App\Jobs\CheckExpoReceipt;
use App\Jobs\SendExpoNotification;
use App\Listeners\PersistOrderNotifications;
use App\Models\Inventory;
use App\Models\NotificationPushDelivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PushDevice;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Notifications\NotificationHistory;
use App\Services\Order\OrderProcessingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

test('customers register multiple devices with encrypted tokens and cannot set ownership fields', function () {
    $customer = User::factory()->customer()->create();
    $other = User::factory()->customer()->create();
    $session = $customer->createToken('Phone', ['*'], now()->addDay());
    $this->withToken($session->plainTextToken);
    $deviceId = (string) Str::uuid();
    $token = 'ExpoPushToken[test_phone]';

    $this->putJson('/api/v1/push-devices/'.$deviceId, [
        'expo_push_token' => $token, 'platform' => 'android', 'user_id' => $other->id,
        'personal_access_token_id' => $other->createToken('Other')->accessToken->id,
    ])->assertOk()->assertJsonPath('data.device_id', $deviceId)->assertJsonMissing(['expo_push_token' => $token]);
    $this->putJson('/api/v1/push-devices/'.Str::uuid(), ['expo_push_token' => 'ExponentPushToken[test_tablet]', 'platform' => 'ios'])->assertOk();

    $device = PushDevice::query()->where('device_id', $deviceId)->sole();
    expect($device->user_id)->toBe($customer->id);
    expect($device->personal_access_token_id)->toBe($session->accessToken->id);
    expect(DB::table('push_devices')->where('id', $device->id)->value('expo_push_token'))->not->toBe($token);
    $version = $device->registration_version;
    $this->putJson('/api/v1/push-devices/'.$deviceId, ['expo_push_token' => $token, 'platform' => 'android'])->assertOk();
    expect($device->refresh()->registration_version)->toBe($version);
    $this->assertDatabaseCount('push_devices', 2);
});

test('push device requests reject invalid credentials and web session fallback', function (string $auth) {
    $customer = User::factory()->customer()->create();
    if ($auth === 'web') {
        $this->actingAs($customer);
    } elseif ($auth !== 'missing') {
        $session = $customer->createToken('Phone', ['*'], $auth === 'expired' ? now()->subMinute() : now()->addDay());
        if ($auth === 'revoked') {
            $session->accessToken->delete();
        }
        $this->withToken($auth === 'invalid' ? 'bad-token' : $session->plainTextToken);
    }

    $path = '/api/v1/push-devices/'.Str::uuid();
    $this->putJson($path, ['expo_push_token' => 'ExpoPushToken[test]', 'platform' => 'android'])->assertUnauthorized();
    $this->deleteJson($path)->assertUnauthorized();
    $this->assertDatabaseCount('push_devices', 0);
})->with(['missing', 'invalid', 'expired', 'revoked', 'web']);

test('push token registration validates token format and platform', function (array $data, array $fields) {
    $customer = User::factory()->customer()->create();
    $this->withToken($customer->createToken('Phone')->plainTextToken);

    $this->putJson('/api/v1/push-devices/'.Str::uuid(), $data)->assertUnprocessable()->assertJsonValidationErrors($fields);
})->with([
    'missing' => [[], ['expo_push_token', 'platform']],
    'bad format' => [['expo_push_token' => 'fcm-token', 'platform' => 'android'], ['expo_push_token']],
    'array' => [['expo_push_token' => ['ExpoPushToken[test]'], 'platform' => 'android'], ['expo_push_token']],
    'too long' => [['expo_push_token' => 'ExpoPushToken['.str_repeat('a', 255).']', 'platform' => 'android'], ['expo_push_token']],
    'bad platform' => [['expo_push_token' => 'ExpoPushToken[test]', 'platform' => 'browser'], ['platform']],
]);

test('customers cannot revoke or claim another accounts active push registration', function () {
    $device = PushDevice::factory()->create();
    $customer = User::factory()->customer()->create();
    $this->withToken($customer->createToken('Phone')->plainTextToken);

    $this->deleteJson('/api/v1/push-devices/'.$device->device_id)->assertNotFound();
    $this->putJson('/api/v1/push-devices/'.Str::uuid(), ['expo_push_token' => $device->expo_push_token, 'platform' => 'android'])
        ->assertUnprocessable()->assertJsonValidationErrors('expo_push_token');
    expect($device->refresh()->is_active)->toBeTrue();
    expect($device->user_id)->not->toBe($customer->id);
});

test('revocation is idempotent and permits a subsequent explicit account registration', function () {
    $device = PushDevice::factory()->create();
    $token = $device->expo_push_token;
    $this->withToken($device->user->createToken('Phone')->plainTextToken);

    $this->deleteJson('/api/v1/push-devices/'.$device->device_id)->assertNoContent();
    $this->deleteJson('/api/v1/push-devices/'.$device->device_id)->assertNoContent();
    expect($device->refresh()->is_active)->toBeFalse();
    expect($device->expo_push_token)->toBeNull();

    $other = User::factory()->customer()->create();
    Auth::forgetGuards();
    $this->withToken($other->createToken('Other')->plainTextToken);
    $this->putJson('/api/v1/push-devices/'.Str::uuid(), ['expo_push_token' => $token, 'platform' => 'android'])->assertOk();
    expect(PushDevice::query()->whereBelongsTo($other)->sole()->is_active)->toBeTrue();
});

test('mobile logout disables only devices registered with the presented session', function () {
    $device = PushDevice::factory()->create();
    $otherDevice = PushDevice::factory()->for($device->user)->create();
    $session = $device->user->createToken('Logout', ['*'], now()->addDay());
    $device->update(['personal_access_token_id' => $session->accessToken->id]);
    $this->withToken($session->plainTextToken);

    $this->postJson('/api/v1/auth/logout')->assertNoContent();

    expect($device->refresh()->is_active)->toBeFalse();
    expect($otherDevice->refresh()->is_active)->toBeTrue();
    Auth::forgetGuards();
    $this->deleteJson('/api/v1/push-devices/'.$device->device_id)->assertUnauthorized();
});

test('persisted customer notification queues delivery for each eligible device only once per registration', function () {
    config(['services.expo.enabled' => true]);
    Bus::fake([SendExpoNotification::class]);
    $customer = User::factory()->customer()->create();
    PushDevice::factory()->for($customer)->count(2)->create();
    $inactive = PushDevice::factory()->for($customer)->create(['is_active' => false]);
    $order = Order::factory()->for($customer)->create();
    $event = new OrderNotificationOccurred((string) Str::uuid(), 'payment.verified', $order->id, $customer->id, $order->reference, now()->toIso8601String());

    app(PersistOrderNotifications::class)->handle($event);
    app(PersistOrderNotifications::class)->handle($event);

    $this->assertDatabaseCount('notifications', 1);
    $this->assertDatabaseCount('notification_push_deliveries', 2);
    Bus::assertDispatchedTimes(SendExpoNotification::class, 4);
    Bus::assertDispatched(SendExpoNotification::class, fn ($job) => $job->connection === null && $job->queue === null);
});

test('accepted push tickets are not resent and schedule receipt lookup without sensitive payload fields', function () {
    config(['services.expo.enabled' => true]);
    Http::preventStrayRequests();
    Http::fake(['exp.host/--/api/v2/push/send' => Http::response(['data' => ['status' => 'ok', 'id' => 'ticket-one']])]);
    Bus::fake([CheckExpoReceipt::class]);
    $delivery = NotificationPushDelivery::factory()->create();

    app()->call([new SendExpoNotification($delivery->id), 'handle']);
    app()->call([new SendExpoNotification($delivery->id), 'handle']);

    expect($delivery->refresh()->status)->toBe('accepted');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request['body'] === 'An update is available. Open Battlefront to view your order.'
        && $request['data']['notification_id'] === $delivery->notification_id
        && $request['data']['deep_link']['screen'] === 'order_detail'
        && ! str_contains($request->body(), 'payment-proofs/')
        && ! str_contains($request->body(), $delivery->device->user->email));
    Bus::assertDispatched(CheckExpoReceipt::class, fn ($job) => $job->deliveryId === $delivery->id && $job->delay !== null);
});

test('cancellation fanout preserves customer-only push deliveries and replay cannot resend accepted pushes', function (UserRole $initiator) {
    config(['services.expo.enabled' => true]);
    Http::preventStrayRequests();
    Http::fake(['exp.host/--/api/v2/push/send' => Http::sequence()
        ->push(['data' => ['status' => 'ok', 'id' => 'cancellation-ticket-one']])
        ->push(['data' => ['status' => 'ok', 'id' => 'cancellation-ticket-two']])]);
    Bus::fake([SendExpoNotification::class, CheckExpoReceipt::class]);
    $customer = User::factory()->customer()->create();
    PushDevice::factory()->for($customer)->count(2)->create();
    PushDevice::factory()->create();
    $administrators = User::factory()->administrator()->count(2)->create();
    $order = Order::factory()->for($customer)->create(['status' => OrderStatus::Cancelled]);
    $event = new OrderNotificationOccurred(
        (string) Str::uuid(), 'order.cancelled', $order->id, $customer->id,
        $order->reference, now()->toIso8601String(), $initiator,
    );
    $listener = app(PersistOrderNotifications::class);

    $listener->handle($event);
    foreach (Bus::dispatched(SendExpoNotification::class) as $job) {
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
    }
    $listener->handle($event);

    $this->assertDatabaseCount('notifications', $initiator === UserRole::Customer ? 3 : 1);
    $this->assertDatabaseCount('notification_push_deliveries', 2);
    expect(NotificationPushDelivery::query()->where('status', 'accepted')->count())->toBe(2);
    expect($customer->notifications()->sole()->data['cancellation_initiator'])->toBe($initiator->value);
    foreach ($administrators as $administrator) {
        expect($administrator->notifications()->count())->toBe($initiator === UserRole::Customer ? 1 : 0);
    }
    Bus::assertDispatchedTimes(SendExpoNotification::class, 2);
    Bus::assertDispatchedTimes(CheckExpoReceipt::class, 2);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request['body'] === 'An update is available. Open Battlefront to view your order.'
        && $request['data']['order_id'] === $order->id
        && $request['data']['deep_link'] === ['screen' => 'order_detail', 'order_id' => $order->id]
        && ! str_contains($request->body(), 'cancellation_initiator'));
})->with([UserRole::Customer, UserRole::Administrator]);

test('unregistered provider feedback disables only the matching registration', function (string $phase) {
    config(['services.expo.enabled' => true]);
    Http::preventStrayRequests();
    $response = ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered'], 'message' => 'Sensitive provider text'];
    $delivery = NotificationPushDelivery::factory()->create($phase === 'receipt' ? ['status' => 'accepted', 'ticket_id' => 'receipt-one'] : []);
    Http::fake([
        'exp.host/--/api/v2/push/send' => Http::response(['data' => $response]),
        'exp.host/--/api/v2/push/getReceipts' => Http::response(['data' => ['receipt-one' => $response]]),
    ]);

    app()->call([$phase === 'receipt' ? new CheckExpoReceipt($delivery->id) : new SendExpoNotification($delivery->id), 'handle']);

    expect($delivery->refresh()->status)->toBe('failed');
    expect($delivery->error_code)->toBe('DeviceNotRegistered');
    expect($delivery->device->refresh()->is_active)->toBeFalse();
    expect($delivery->device->expo_push_token)->toBeNull();
})->with(['ticket', 'receipt']);

test('stale receipts cannot disable a refreshed registration', function () {
    config(['services.expo.enabled' => true]);
    Http::preventStrayRequests();
    $delivery = NotificationPushDelivery::factory()->create(['status' => 'accepted', 'ticket_id' => 'old-ticket']);
    $device = $delivery->device;
    $this->withToken($device->user->createToken('New session')->plainTextToken);
    $this->putJson('/api/v1/push-devices/'.$device->device_id, ['expo_push_token' => 'ExpoPushToken[refreshed]', 'platform' => 'ios'])->assertOk();
    Http::fake(['exp.host/--/api/v2/push/getReceipts' => Http::response(['data' => [
        'old-ticket' => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
    ]])]);

    app()->call([new CheckExpoReceipt($delivery->id), 'handle']);

    expect($device->refresh()->is_active)->toBeTrue();
    expect($device->expo_push_token)->toBe('ExpoPushToken[refreshed]');
});

test('revoked expired and replaced sessions prevent queued push delivery', function (string $state) {
    config(['services.expo.enabled' => true]);
    Http::preventStrayRequests();
    Http::fake(['exp.host/--/api/v2/push/send' => Http::response(['data' => ['status' => 'ok', 'id' => 'unused']])]);
    $delivery = NotificationPushDelivery::factory()->create();
    $device = $delivery->device;
    if ($state === 'expired') {
        $device->accessToken->update(['expires_at' => now()->subMinute()]);
    } elseif ($state === 'revoked') {
        $device->accessToken->delete();
    } else {
        $device->update(['registration_version' => (string) Str::uuid()]);
    }

    app()->call([new SendExpoNotification($delivery->id), 'handle']);

    expect($delivery->refresh()->status)->toBe('skipped');
    Http::assertNothingSent();
})->with(['revoked', 'expired', 'replaced']);

test('missing receipts are retried and successful receipts record provider acceptance', function () {
    config(['services.expo.enabled' => true]);
    Http::preventStrayRequests();
    Bus::fake([CheckExpoReceipt::class]);
    $delivery = NotificationPushDelivery::factory()->create(['status' => 'accepted', 'ticket_id' => 'receipt-one']);
    Http::fake(['exp.host/--/api/v2/push/getReceipts' => Http::sequence()
        ->push(['data' => []])->push(['data' => ['receipt-one' => ['status' => 'ok']]])]);

    app()->call([new CheckExpoReceipt($delivery->id), 'handle']);
    Bus::assertDispatched(CheckExpoReceipt::class);
    app()->call([new CheckExpoReceipt($delivery->id), 'handle']);

    expect($delivery->refresh()->status)->toBe('provider_received');
});

test('provider errors retain usable tokens and expose only sanitized failures', function (string $failure) {
    config(['services.expo.enabled' => true, 'services.expo.access_token' => 'private-provider-credential']);
    Http::preventStrayRequests();
    $delivery = NotificationPushDelivery::factory()->create();
    $response = match ($failure) {
        'connection' => Http::failedConnection(),
        'temporary' => Http::response(['message' => $delivery->device->expo_push_token], 503),
        'malformed' => Http::response(['data' => 'invalid']),
        'credentials' => Http::response(['message' => 'private-provider-credential'], 401),
        'payload' => Http::response(['data' => ['status' => 'error', 'details' => ['error' => 'MessageTooBig']]]),
    };
    Http::fake(['exp.host/--/api/v2/push/send' => $response]);

    if (in_array($failure, ['connection', 'temporary', 'malformed'], true)) {
        expect(fn () => app()->call([new SendExpoNotification($delivery->id), 'handle']))->toThrow(RuntimeException::class);
        expect($delivery->refresh()->status)->toBe('pending');
    } else {
        app()->call([new SendExpoNotification($delivery->id), 'handle']);
        expect($delivery->refresh()->status)->toBe('failed');
    }
    expect($delivery->device->refresh()->is_active)->toBeTrue();
})->with(['connection', 'temporary', 'malformed', 'credentials', 'payload']);

test('push preparation failures reset the claim so the queued job can retry', function () {
    config(['services.expo.enabled' => true]);
    Http::preventStrayRequests();
    Http::fake(['exp.host/--/api/v2/push/send' => Http::response(['data' => ['status' => 'ok', 'id' => 'retry-ticket']])]);
    Bus::fake([CheckExpoReceipt::class]);
    $delivery = NotificationPushDelivery::factory()->create();
    $history = Mockery::mock(NotificationHistory::class);
    $history->shouldReceive('presentMany')->once()->andThrow(new RuntimeException('Preparation failed.'));
    app()->instance(NotificationHistory::class, $history);

    expect(fn () => app()->call([new SendExpoNotification($delivery->id), 'handle']))
        ->toThrow(RuntimeException::class);
    expect($delivery->refresh()->status)->toBe('pending');

    app()->forgetInstance(NotificationHistory::class);
    app()->call([new SendExpoNotification($delivery->id), 'handle']);

    expect($delivery->refresh()->status)->toBe('accepted');
    Http::assertSentCount(1);
});

test('receipt enqueue retries from the saved ticket without sending another push', function () {
    config(['services.expo.enabled' => true]);
    Http::preventStrayRequests();
    Http::fake(['exp.host/--/api/v2/push/send' => Http::response(['data' => ['status' => 'ok', 'id' => 'receipt-retry-ticket']])]);
    $delivery = NotificationPushDelivery::factory()->create();
    $attempts = 0;
    Bus::shouldReceive('dispatch')->twice()->andReturnUsing(function (CheckExpoReceipt $job) use (&$attempts, $delivery): null {
        expect($job->deliveryId)->toBe($delivery->id);
        $attempts++;
        if ($attempts === 1) {
            throw new RuntimeException('Queue unavailable.');
        }

        return null;
    });

    expect(fn () => app()->call([new SendExpoNotification($delivery->id), 'handle']))
        ->toThrow(RuntimeException::class);
    expect($delivery->refresh()->status)->toBe('accepted');
    expect($delivery->error_code)->toBe('ReceiptQueuePending');

    app()->call([new SendExpoNotification($delivery->id), 'handle']);

    expect($delivery->refresh()->status)->toBe('accepted');
    expect($delivery->error_code)->toBeNull();
    Http::assertSentCount(1);
});

test('exhausted push and receipt jobs persist explicit failure states without overwriting terminal states', function () {
    $pending = NotificationPushDelivery::factory()->create(['status' => 'pending']);
    (new SendExpoNotification($pending->id))->failed(new RuntimeException('Retries exhausted.'));
    expect($pending->refresh()->status)->toBe('failed');
    expect($pending->error_code)->toBe('RetriesExhausted');

    $accepted = NotificationPushDelivery::factory()->create([
        'status' => 'accepted',
        'ticket_id' => 'receipt-exhausted-ticket',
    ]);
    (new CheckExpoReceipt($accepted->id))->failed(new RuntimeException('Retries exhausted.'));
    expect($accepted->refresh()->status)->toBe('receipt_unavailable');
    expect($accepted->error_code)->toBe('ReceiptRetriesExhausted');

    $received = NotificationPushDelivery::factory()->create(['status' => 'provider_received']);
    (new SendExpoNotification($received->id))->failed(new RuntimeException('Delayed callback.'));
    expect($received->refresh()->status)->toBe('provider_received');
});

test('push provider failure cannot change completed payment cancellation or shipment business outcomes', function (string $action) {
    config(['services.expo.enabled' => true]);
    Bus::fake([SendExpoNotification::class]);
    Http::preventStrayRequests();
    Http::fake(['exp.host/--/api/v2/push/send' => Http::response([], 503)]);
    $customer = User::factory()->customer()->create();
    PushDevice::factory()->for($customer)->create();
    $inventory = Inventory::factory()->create(['quantity' => 5]);
    $order = $action === 'delivered' ? Order::factory()->for($customer)->withDeliverySnapshot()->create(['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Verified])
        : Order::factory()->for($customer)->create(['status' => $action === 'payment' ? OrderStatus::Processing : OrderStatus::Pending]);
    OrderItem::factory()->for($order)->create(['product_id' => $inventory->product_id, 'quantity' => 2]);
    if ($action === 'delivered') {
        Shipment::factory()->for($order)->create(['status' => ShipmentStatus::OutForDelivery]);
    }

    match ($action) {
        'payment' => app(OrderProcessingService::class)->confirmPickup($order, true, true),
        'cancelled' => app(OrderProcessingService::class)->updateStatus($order, OrderStatus::Cancelled),
        'delivered' => app(OrderProcessingService::class)->updateShipmentStatus($order, ShipmentStatus::Delivered),
    };
    Http::assertNothingSent();
    $attributes = $order->refresh()->getAttributes();
    foreach (Bus::dispatched(SendExpoNotification::class) as $job) {
        expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);
    }

    expect($order->refresh()->getAttributes())->toBe($attributes);
    expect($customer->notifications()->count())->toBe(1);
    expect($inventory->refresh()->quantity)->toBe($action === 'cancelled' ? 7 : 5);
    $this->assertDatabaseCount('sales', $action === 'cancelled' ? 0 : 1);
})->with(['payment', 'cancelled', 'delivered']);

test('push queue failure does not escape a committed pickup payment and completion', function () {
    config(['services.expo.enabled' => true]);
    $customer = User::factory()->customer()->create();
    PushDevice::factory()->for($customer)->create();
    $order = Order::factory()->for($customer)->create(['status' => OrderStatus::Processing]);
    Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('Queue unavailable'));

    app(OrderProcessingService::class)->confirmPickup($order, true, true);

    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Verified);
    expect($order->status)->toBe(OrderStatus::Completed);
    $this->assertDatabaseCount('sales', 1);
    $this->assertDatabaseCount('notifications', 1);
});

test('push cannot navigate to another customers order even with corrupted notification metadata', function () {
    config(['services.expo.enabled' => true]);
    Http::preventStrayRequests();
    $delivery = NotificationPushDelivery::factory()->create();
    $data = $delivery->notification->data;
    $data['order_id'] = Order::factory()->create()->id;
    $delivery->notification->update(['data' => $data]);

    app()->call([new SendExpoNotification($delivery->id), 'handle']);

    expect($delivery->refresh()->status)->toBe('skipped');
    Http::assertNothingSent();
});

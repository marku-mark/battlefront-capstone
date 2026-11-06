<?php

use App\Events\OrderNotificationOccurred;
use App\Jobs\CheckExpoReceipt;
use App\Jobs\ProcessPayMongoWebhook;
use App\Jobs\RetryOrderNotifications;
use App\Jobs\SendExpoNotification;
use App\Jobs\SyncCatalogProjection;
use App\Jobs\SyncFaqProjection;
use Illuminate\Support\Facades\Queue;

test('background jobs inherit the environment queue connection and default queue', function () {
    config(['queue.default' => 'cloud', 'queue.connections.cloud' => ['driver' => 'database', 'queue' => 'default']]);
    Queue::fake();
    $event = new OrderNotificationOccurred('queue-test', 'order.placed', 1, 1, 'ORDER-1', now()->toIso8601String());
    $jobs = [
        new CheckExpoReceipt(1),
        new SendExpoNotification(1),
        new RetryOrderNotifications($event),
        new SyncCatalogProjection(1),
        new SyncFaqProjection(1),
        new ProcessPayMongoWebhook(1),
    ];

    foreach ($jobs as $job) {
        dispatch($job);
    }

    foreach ($jobs as $job) {
        Queue::assertPushed($job::class, fn ($queuedJob) => $queuedJob->connection === null && $queuedJob->queue === null);
    }
});

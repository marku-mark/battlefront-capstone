<?php

namespace App\Jobs;

use App\Events\OrderNotificationOccurred;
use App\Listeners\PersistOrderNotifications;
use App\Services\Notifications\NotificationFailureReporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RetryOrderNotifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly OrderNotificationOccurred $event) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(PersistOrderNotifications $listener): void
    {
        $listener->handle($this->event);
    }

    public function failed(?Throwable $exception): void
    {
        NotificationFailureReporter::report('persistence_exhausted', $this->event->id);
    }
}

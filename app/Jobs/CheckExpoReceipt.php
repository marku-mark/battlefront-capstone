<?php

namespace App\Jobs;

use App\Models\NotificationPushDelivery;
use App\Services\Notifications\ExpoPushAdapter;
use App\Services\Notifications\NotificationFailureReporter;
use App\Services\Notifications\PushDeviceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

class CheckExpoReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $deliveryId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ExpoPushAdapter $expo, PushDeviceService $devices): void
    {
        if (! config('services.expo.enabled')) {
            return;
        }
        $delivery = NotificationPushDelivery::query()->with('device')->find($this->deliveryId);
        if ($delivery === null || $delivery->status !== 'accepted' || $delivery->ticket_id === null) {
            return;
        }

        if (($delivery->accepted_at ?? $delivery->created_at)->lte(now()->subHours(24))) {
            NotificationPushDelivery::query()->whereKey($delivery->id)->where('status', 'accepted')
                ->update(['status' => 'receipt_unavailable', 'error_code' => 'ReceiptExpired']);

            return;
        }
        $result = $expo->receipt($delivery->ticket_id);
        if ($result['status'] === 'pending') {
            Bus::dispatch((new self($delivery->id))->delay(now()->addMinutes(5)));

            return;
        }

        $updated = NotificationPushDelivery::query()->whereKey($delivery->id)->where('status', 'accepted')->update([
            'status' => $result['status'] === 'ok' ? 'provider_received' : 'failed',
            'error_code' => $result['error_code'] ?? null,
        ]);
        if ($updated > 0 && ($result['error_code'] ?? null) === 'DeviceNotRegistered') {
            $devices->deactivate($delivery->device, $delivery->registration_version);
        }
    }

    public function failed(?Throwable $exception): void
    {
        NotificationPushDelivery::query()->whereKey($this->deliveryId)->where('status', 'accepted')
            ->whereNotNull('ticket_id')->update(['status' => 'receipt_unavailable', 'error_code' => 'ReceiptRetriesExhausted']);
        NotificationFailureReporter::report('receipt_exhausted', (string) $this->deliveryId);
    }
}

<?php

namespace App\Jobs;

use App\Models\NotificationPushDelivery;
use App\Services\Notifications\ExpoPushAdapter;
use App\Services\Notifications\NotificationFailureReporter;
use App\Services\Notifications\NotificationHistory;
use App\Services\Notifications\PushDeviceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SendExpoNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $deliveryId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ExpoPushAdapter $expo, PushDeviceService $devices, NotificationHistory $history): void
    {
        if (! config('services.expo.enabled')) {
            return;
        }
        $delivery = DB::transaction(function (): ?NotificationPushDelivery {
            $delivery = NotificationPushDelivery::query()->with(['device.user', 'device.accessToken', 'notification'])
                ->whereKey($this->deliveryId)->lockForUpdate()->first();
            if ($delivery === null || ! in_array($delivery->status, ['pending', 'accepted'], true)) {
                return null;
            }
            if ($delivery->status === 'accepted' && $delivery->ticket_id !== null) {
                return $delivery;
            }
            if ($delivery->status !== 'pending') {
                return null;
            }
            $delivery->update(['status' => 'sending']);

            return $delivery;
        });
        if ($delivery === null) {
            return;
        }

        if ($delivery->status === 'accepted' && $delivery->ticket_id !== null
            && $delivery->error_code === 'ReceiptQueuePending') {
            $this->enqueueReceiptCheck($delivery->id);

            return;
        }
        if ($delivery->status === 'accepted') {
            return;
        }

        $device = $delivery->device;
        $notification = $delivery->notification;
        try {
            if ($device->registration_version !== $delivery->registration_version || ! $devices->eligible($device)
                || (int) $notification->notifiable_id !== $device->user_id
                || $notification->notifiable_type !== $device->user->getMorphClass()
                || ($notification->data['audience'] ?? null) !== 'customer') {
                $delivery->update(['status' => 'skipped']);
                if ($device->registration_version === $delivery->registration_version && ! $devices->eligible($device)) {
                    $devices->deactivate($device, $delivery->registration_version);
                }

                return;
            }
            $presented = $history->presentMany($device->user, collect([$notification]))->sole();
            if ($presented['order'] === null) {
                $delivery->update(['status' => 'skipped']);

                return;
            }

            $result = $expo->send((string) $device->expo_push_token, [
                'notification_id' => $notification->id, 'order_id' => $presented['order']['id'],
                'deep_link' => $presented['order']['deep_link'],
            ], $device->platform === 'android' ? $device->notification_channel : null);
        } catch (Throwable $exception) {
            try {
                NotificationPushDelivery::query()->whereKey($delivery->id)->where('status', 'sending')
                    ->update(['status' => 'pending']);
            } catch (Throwable) {
                NotificationFailureReporter::report('push_reset', (string) $delivery->id);
            }

            throw $exception;
        }

        if ($result['status'] === 'error') {
            $delivery->update(['status' => 'failed', 'error_code' => $result['error_code']]);
            if ($result['error_code'] === 'DeviceNotRegistered') {
                $devices->deactivate($device, $delivery->registration_version);
            }

            return;
        }
        $delivery->update(['status' => 'accepted', 'ticket_id' => $result['id'], 'accepted_at' => now()]);
        $this->enqueueReceiptCheck($delivery->id);
    }

    private function enqueueReceiptCheck(int $deliveryId): void
    {
        try {
            Bus::dispatch((new CheckExpoReceipt($deliveryId))->delay(now()->addMinutes(15)));
            NotificationPushDelivery::query()->whereKey($deliveryId)->where('status', 'accepted')
                ->where('error_code', 'ReceiptQueuePending')->update(['error_code' => null]);
        } catch (Throwable $exception) {
            NotificationPushDelivery::query()->whereKey($deliveryId)->where('status', 'accepted')
                ->update(['error_code' => 'ReceiptQueuePending']);
            NotificationFailureReporter::report('receipt_enqueue', (string) $deliveryId);

            throw new RuntimeException('Expo receipt scheduling unavailable.', previous: $exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        NotificationPushDelivery::query()->whereKey($this->deliveryId)->whereIn('status', ['pending', 'sending'])
            ->update(['status' => 'failed', 'error_code' => 'RetriesExhausted']);
        NotificationPushDelivery::query()->whereKey($this->deliveryId)->where('status', 'accepted')
            ->where('error_code', 'ReceiptQueuePending')
            ->whereNotNull('ticket_id')->update(['status' => 'receipt_unavailable', 'error_code' => 'ReceiptQueueExhausted']);
        NotificationFailureReporter::report('push_exhausted', (string) $this->deliveryId);
    }
}

<?php

namespace App\Actions\PayMongo;

use App\Jobs\ProcessPayMongoWebhook;
use App\Models\PayMongoWebhookInbox;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class RecordPayMongoWebhook
{
    /** @param array<string, mixed> $event */
    public function execute(array $event): bool
    {
        try {
            $inbox = DB::transaction(fn (): PayMongoWebhookInbox => PayMongoWebhookInbox::query()->create([
                ...$event,
                'received_at' => now(),
                'next_check_at' => now(),
            ]));
        } catch (QueryException $exception) {
            if (PayMongoWebhookInbox::query()->where('mode', $event['mode'])->where('event_id', $event['event_id'])->exists()) {
                return false;
            }

            throw $exception;
        }

        ProcessPayMongoWebhook::dispatch($inbox->id)->onQueue('payments')->afterCommit();

        return true;
    }
}

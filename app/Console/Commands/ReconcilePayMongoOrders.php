<?php

namespace App\Console\Commands;

use App\Jobs\ReconcilePayMongoOrder;
use App\Models\PayMongoFlow;
use Illuminate\Console\Command;

class ReconcilePayMongoOrders extends Command
{
    protected $signature = 'paymongo:reconcile-overdue';

    protected $description = 'Recover due PayMongo payments and safely close overdue unpaid orders';

    public function handle(): int
    {
        $flows = PayMongoFlow::query()
            ->whereIn('channel', ['hosted_checkout', 'direct_online_banking', 'direct_qrph'])
            ->where(fn ($query) => $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            ->where(function ($query): void {
                $query->where('requires_attention', true)
                    ->orWhere(function ($pending): void {
                        $pending->where('status', '!=', 'paid')
                            ->whereHas('order', fn ($order) => $order->where('status', 'pending')->where('payment_status', 'pending'));
                    });
            })
            ->orderBy('next_check_at')->orderBy('id')->limit(100)->get();

        foreach ($flows as $flow) {
            $flow->update(['next_check_at' => now()->addMinute()]);
            ReconcilePayMongoOrder::dispatch($flow->id);
        }

        return self::SUCCESS;
    }
}

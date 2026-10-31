<?php

namespace App\Actions\PayMongo;

use App\Models\Order;
use App\Models\PayMongoFlow;
use App\Services\PayMongo\RetryDirectOnlineBanking;
use App\Services\PayMongo\StartDirectQrPh;
use App\Services\PayMongo\StartHostedCheckout;

class RetryPayMongoPayment
{
    public function __construct(
        private readonly StartHostedCheckout $hostedCheckout,
        private readonly RetryDirectOnlineBanking $bank,
        private readonly StartDirectQrPh $qrPh,
    ) {}

    /** @return array{channel: 'direct_qrph', redirect_url: null}|array{channel: string, redirect_url: string} */
    public function execute(Order $order): array
    {
        $flow = PayMongoFlow::query()->where('order_id', $order->id)->where('active_slot', 'active')->firstOrFail();

        if ($flow->channel === 'direct_qrph') {
            $this->qrPh->execute($order);

            return ['channel' => $flow->channel, 'redirect_url' => null];
        }

        if ($flow->channel === 'hosted_checkout') {
            return ['channel' => $flow->channel, 'redirect_url' => $this->hostedCheckout->execute($order)];
        }

        return ['channel' => $flow->channel, 'redirect_url' => $this->bank->execute($order)];
    }
}

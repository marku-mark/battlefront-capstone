<?php

namespace Database\Seeders;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Repositories\Reporting\SalesHistoryCoverageRepository;
use App\Services\Order\DeliveryRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class DevelopmentHistoricalSalesSeeder extends Seeder
{
    /** @var list<string> */
    public const PRODUCT_CODES = ['10003', '43720093', '10035', '6939084200089', '6940056180506', '87399741', '10196', '19986', '10468', '10121', '10986', '10214', '10350', '10463', '28909657'];

    private const LEGACY_CUSTOMER_EMAIL = 'historical-sales@example.test';

    private const LABEL_PREFIX = 'Demonstration Historical Sales ';

    private const FIXTURE_PREFIX = 'historical-sales:';

    private const REMEDIATION = 'Separate approval is required for development-only remediation. No existing records were changed.';

    private const DELIVERY_NOTE = 'Synthetic historical delivery; no payment evidence or carrier milestones retained.';

    /** @var list<string> */
    private const DESTINATIONS = ['Sagay City', 'Escalante City', 'Cadiz City', 'Bacolod City'];

    /**
     * Retail assumptions: component purchases are small; inexpensive accessories
     * appear more frequently. Keep profiles independent of mutable current prices
     * so existing snapshots remain reproducible after catalog price changes.
     *
     * @var array<int, array{visits: int, max_quantity: int, school: bool}>
     */
    private const DEMAND = [
        10003 => ['visits' => 1, 'max_quantity' => 1, 'school' => true],
        43720093 => ['visits' => 1, 'max_quantity' => 1, 'school' => false],
        10035 => ['visits' => 1, 'max_quantity' => 2, 'school' => true],
        6939084200089 => ['visits' => 1, 'max_quantity' => 2, 'school' => false],
        6940056180506 => ['visits' => 2, 'max_quantity' => 2, 'school' => true],
        87399741 => ['visits' => 2, 'max_quantity' => 2, 'school' => true],
        10196 => ['visits' => 2, 'max_quantity' => 3, 'school' => true],
        19986 => ['visits' => 2, 'max_quantity' => 3, 'school' => false],
        10468 => ['visits' => 2, 'max_quantity' => 3, 'school' => false],
        10121 => ['visits' => 2, 'max_quantity' => 2, 'school' => true],
        10986 => ['visits' => 1, 'max_quantity' => 2, 'school' => true],
        10214 => ['visits' => 1, 'max_quantity' => 1, 'school' => true],
        10350 => ['visits' => 1, 'max_quantity' => 1, 'school' => true],
        10463 => ['visits' => 2, 'max_quantity' => 2, 'school' => false],
        28909657 => ['visits' => 2, 'max_quantity' => 2, 'school' => false],
    ];

    /** Seed demonstration snapshots directly, without live order services or inventory writes. */
    public function run(SalesHistoryCoverageRepository $coverage, DeliveryRules $deliveryRules): void
    {
        if (! App::environment(['local', 'testing'])) {
            throw new RuntimeException('Historical sales development data is only allowed in local and testing environments.');
        }

        $this->ensureNoLegacyHistory();
        if (! Schema::hasColumn('orders', 'development_fixture_key')) {
            throw new RuntimeException('Historical sales requires the reviewed development fixture key schema migration. No existing records were changed.');
        }
        $timezone = Config::string('app.timezone');
        $historyEnd = CarbonImmutable::instance(now($timezone))->startOfMonth();
        $firstMonth = $historyEnd->subMonths(48);
        $coverage->clearDevelopment();

        DB::transaction(function () use ($firstMonth, $deliveryRules): void {
            $this->ensureNoLegacyHistory();
            $products = Product::query()->whereIn('product_code', self::PRODUCT_CODES)
                ->orderBy('product_code')->lockForUpdate()->get()->keyBy('product_code');
            foreach (self::PRODUCT_CODES as $code) {
                if (! isset($products[$code]) || ! $products[$code]->is_catalog_imported) {
                    throw new RuntimeException("Historical sales requires existing imported product $code. No historical transactions were changed.");
                }
            }
            $operational = config('forecasting.operational_coverage');
            if (! is_array($operational) || array_intersect(self::PRODUCT_CODES, array_keys($operational)) !== []) {
                throw new RuntimeException('Historical sales product coverage conflicts with operational coverage.');
            }

            $this->call(DevelopmentCustomerSeeder::class);
            $customers = array_values(User::query()->whereIn('email', array_keys(DevelopmentCustomerSeeder::accounts()))
                ->orderBy('email')->lockForUpdate()->get()->all());
            $ownedOrders = $this->ownedOrders($customers, $products->all());

            foreach (range(0, 47) as $monthIndex) {
                $month = $firstMonth->addMonths($monthIndex);
                foreach ($this->monthlyOrders($month) as $slot => $quantities) {
                    $date = $this->orderDate($month, $slot);
                    $key = $this->fixtureKey($month, $slot);
                    if (! isset($ownedOrders[$key])) {
                        $this->seedOrder($this->customerFor($month, $slot, $customers), $key, $date, $products->all(), $quantities, $deliveryRules);
                    }
                }
            }
        });

        $entries = [];
        foreach (self::PRODUCT_CODES as $code) {
            $entries[$code] = [
                'granularity' => 'month',
                'start' => $firstMonth->toDateString(),
                'end_exclusive' => $historyEnd->toDateString(),
                'unavailable_months' => [],
                'timezone' => $timezone,
                'source_kind' => 'synthetic_development',
                'sales_scope' => 'development_fixture_transactions',
            ];
        }
        try {
            $coverage->writeDevelopment($entries);
        } catch (Throwable $exception) {
            $coverage->clearDevelopment();
            throw $exception;
        }

        $this->command->info('Synthetic development sales history: '.$firstMonth->format('F Y').' - '.$historyEnd->subMonth()->format('F Y').' (48 completed months; 15 imported products; 4-7 orders per month; 30 demonstration customers). Older fixtures retained. Not verified client sales.');
    }

    private function ensureNoLegacyHistory(): void
    {
        if (Order::query()->where('recipient_name', 'like', self::LABEL_PREFIX.'%')->exists()) {
            throw new RuntimeException('An earlier or unrecognized historical demonstration fixture was detected. '.self::REMEDIATION);
        }
        if (Product::query()->where('product_code', 'like', 'DEVHIST40%')->exists()
            || Order::query()->where('recipient_name', 'like', 'Historical Sales %')
                ->whereHas('user', fn (Builder $query): Builder => $query->where('email', self::LEGACY_CUSTOMER_EMAIL))->exists()) {
            throw new RuntimeException('Legacy DEVHIST40 historical demonstration data was detected. '.self::REMEDIATION);
        }
    }

    /** @param list<User> $customers */
    private function customerFor(CarbonImmutable $month, int $slot, array $customers): User
    {
        $absoluteMonth = 12 * ($month->year - 1) + $month->month - 1;

        return $customers[(7 * $absoluteMonth + $slot) % count($customers)];
    }

    /**
     * Validate explicitly marked fixtures without claiming ordinary customer orders.
     *
     * @param  list<User>  $customers
     * @param  array<int|string, Product>  $products
     * @return array<string, Order>
     */
    private function ownedOrders(array $customers, array $products): array
    {
        $owned = [];
        $productsById = collect($products)->keyBy('id');
        $plans = [];
        foreach (Order::query()->where('development_fixture_key', 'like', self::FIXTURE_PREFIX.'%')
            ->with(['items', 'sale', 'shipment'])->lockForUpdate()->get() as $order) {
            $date = CarbonImmutable::instance($order->created_at);
            $monthKey = $date->format('Y-m');
            $plan = $plans[$monthKey] ??= $this->monthlyOrders($date->startOfMonth());
            $slot = null;
            foreach (array_keys($plan) as $candidate) {
                if ($date->equalTo($this->orderDate($date, $candidate))) {
                    $slot = $candidate;
                    break;
                }
            }
            if ($slot === null || $order->development_fixture_key !== $this->fixtureKey($date, $slot)
                || isset($owned[$order->development_fixture_key])) {
                throw new RuntimeException('Historical sales order ownership or identity is ambiguous. '.self::REMEDIATION);
            }
            $customer = $this->customerFor($date, $slot, $customers);
            if ($order->user_id !== $customer->id || $order->recipient_name !== $customer->name) {
                throw new RuntimeException('Historical sales order ownership conflicts with the assigned demonstration customer. '.self::REMEDIATION);
            }

            $expectedIds = array_map(fn (int|string $code): int => $products[$code]->id, array_keys($plan[$slot]));
            $actualIds = $order->items->pluck('product_id')->all();
            if (count($actualIds) !== count($expectedIds) || array_diff($expectedIds, $actualIds) !== []) {
                throw new RuntimeException('Historical sales order ownership contains missing, unrelated or duplicate items. '.self::REMEDIATION);
            }
            $total = '0.00';
            foreach ($order->items as $item) {
                $product = $productsById[$item->product_id];
                $price = $item->price_at_time;
                if ($item->quantity !== $plan[$slot][$product->product_code] || bccomp($price, '0', 2) < 0) {
                    throw new RuntimeException('Historical sales order items conflict with the demonstration history. '.self::REMEDIATION);
                }
                $total = bcadd($total, bcmul($price, (string) $item->quantity, 2), 2);
            }
            $terms = $this->orderTerms($date, $slot);
            $deliveryFee = $order->delivery_fee;
            if (bccomp($deliveryFee, '0', 2) < 0) {
                throw new RuntimeException('Historical sales delivery fee conflicts with the demonstration history. '.self::REMEDIATION);
            }
            $finalTotal = bcadd($total, $deliveryFee, 2);
            if ($order->status !== OrderStatus::Completed || $order->payment_status !== PaymentStatus::Verified
                || $order->payment_method !== $terms['payment'] || $order->fulfillment_method !== $terms['fulfillment']
                || ! $order->payment_method->isAvailableFor($order->fulfillment_method)
                || $order->contact_number !== '09000000000' || ! $this->validDeliverySnapshot($order, $terms['destination'])
                || $order->payment_proof_path !== null || $order->payment_rejection_reason !== null || $order->payment_rejection_note !== null
                || $order->total_amount !== $finalTotal || $order->product_subtotal !== $total
                || $order->sale === null || $order->sale->amount !== $total || $order->sale->sale_date->toDateString() !== $date->toDateString()) {
                throw new RuntimeException('Historical sales order and sale snapshots conflict with the demonstration history. '.self::REMEDIATION);
            }
            $owned[$order->development_fixture_key] = $order;
        }

        return $owned;
    }

    /** @return array{fulfillment: FulfillmentMethod, payment: PaymentMethod, destination: string|null} */
    private function orderTerms(CarbonImmutable $month, int $slot): array
    {
        $key = $month->format('Y-m').':'.$slot;
        $delivery = $this->variation('fulfillment:'.$key, 100) < 30;
        $paymentRoll = $this->variation('payment:'.$key, 100);
        $payment = $delivery
            ? ($paymentRoll < 50 ? PaymentMethod::GCash : PaymentMethod::Maya)
            : match (true) {
                $paymentRoll < 55 => PaymentMethod::Cash,
                $paymentRoll < 75 => PaymentMethod::CardAtStore,
                $paymentRoll < 90 => PaymentMethod::GCash,
                default => PaymentMethod::Maya,
            };

        return [
            'fulfillment' => $delivery ? FulfillmentMethod::Delivery : FulfillmentMethod::Pickup,
            'payment' => $payment,
            'destination' => $delivery ? self::DESTINATIONS[$this->variation('destination:'.$key, count(self::DESTINATIONS))] : null,
        ];
    }

    private function deliveryAddress(string $destination): string
    {
        return 'Demonstration address only: sample residential delivery, '.$destination.'. Not a real customer address.';
    }

    /** Validate immutable saved fees and quote context without reconstructing them from current configuration. */
    private function validDeliverySnapshot(Order $order, ?string $destination): bool
    {
        if ($destination === null) {
            return $order->delivery_address === null && $order->delivery_fee === '0.00' && $order->shipment === null
                && $order->delivery_destination === null && $order->delivery_base_fee === null && $order->shipping_profile === null
                && $order->handling_surcharge === null && $order->delivery_origin_city === null
                && $order->delivery_is_demo === null && $order->delivery_assumption_label === null;
        }

        $shipment = $order->shipment;
        $baseFee = $order->delivery_base_fee;
        $surcharge = $order->handling_surcharge;
        if ($order->delivery_destination !== $destination || $order->delivery_address !== $this->deliveryAddress($destination)
            || ! is_numeric($baseFee) || bccomp($baseFee, '0', 2) < 0
            || ! is_numeric($surcharge) || bccomp($surcharge, '0', 2) < 0
            || $order->delivery_fee !== bcadd($baseFee, $surcharge, 2)
            || $order->shipping_profile === null || $order->delivery_origin_city !== 'Sagay City'
            || $order->delivery_is_demo !== true || ! str_starts_with($order->delivery_assumption_label ?? '', self::DELIVERY_NOTE)
            || $shipment === null || $shipment->status !== ShipmentStatus::Delivered || $shipment->carrier !== 'lbc'
            || $shipment->preparation_days < 0 || $shipment->transit_min_days < 0 || $shipment->transit_max_days < $shipment->transit_min_days
            || $shipment->eta_min_days !== $shipment->preparation_days + $shipment->transit_min_days
            || $shipment->eta_max_days !== $shipment->preparation_days + $shipment->transit_max_days
            || $shipment->getRawOriginal('created_at') === null || $shipment->getRawOriginal('updated_at') === null
            || ! $shipment->created_at->equalTo($order->created_at) || ! $shipment->updated_at->equalTo($order->created_at)) {
            return false;
        }

        foreach (['tracking_reference', 'preparing_at', 'ready_for_dispatch_at', 'handed_to_carrier_at', 'in_transit_at',
            'out_for_delivery_at', 'delivered_at', 'cancelled_at', 'eta_anchor_date', 'eta_timezone',
            'estimated_delivery_start', 'estimated_delivery_end'] as $field) {
            if ($shipment->getAttribute($field) !== null) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, array<int|string, int>> */
    private function monthlyOrders(CarbonImmutable $month): array
    {
        $key = $month->format('Y-m');
        $peak = in_array($month->month, [6, 7, 11, 12], true);
        $count = 4 + $this->variation('orders:'.$key, 3) + (int) $peak;
        $orders = array_fill(0, $count, []);
        $codes = self::PRODUCT_CODES;
        usort($codes, fn (string $left, string $right): int => strcmp(hash('sha256', $key.':'.$left), hash('sha256', $key.':'.$right)));
        $cursor = $this->variation('basket:'.$key, $count);
        foreach ($codes as $code) {
            $profile = self::DEMAND[(int) $code];
            $yearVariation = $this->variation('year:'.$month->year.':'.$code, 3);
            $seasonal = in_array($month->month, [11, 12], true) || ($profile['school'] && in_array($month->month, [6, 7], true));
            $visits = $profile['visits'] + (int) ($this->variation('demand:'.$key.':'.$code, 5) < 1 + $yearVariation)
                + (int) ($seasonal && $this->variation('season:'.$key.':'.$code, 2) === 0);
            for ($visit = 0; $visit < $visits; $visit++) {
                $slot = $cursor++ % $count;
                $quantity = 1;
                if ($profile['max_quantity'] > 1 && $this->variation('units:'.$key.':'.$code.':'.$slot, 3) === 0) {
                    $quantity += 1 + $this->variation('extra:'.$key.':'.$code.':'.$slot, $profile['max_quantity'] - 1);
                }
                $orders[$slot][$code] = $quantity;
            }
        }

        return $orders;
    }

    private function variation(string $key, int $range): int
    {
        return (int) hexdec(substr(hash('sha256', $key), 0, 8)) % $range;
    }

    private function orderDate(CarbonImmutable $month, int $slot): CarbonImmutable
    {
        $key = $month->format('Y-m').':'.$slot;

        return $month->startOfMonth()->day(2 + $slot * 4 + $this->variation('day:'.$key, 3))
            ->setTime(9 + $this->variation('hour:'.$key, 9), $this->variation('minute:'.$key, 60));
    }

    private function fixtureKey(CarbonImmutable $month, int $slot): string
    {
        return self::FIXTURE_PREFIX.'V4:'.$month->format('Y-m').':'.sprintf('%02d', $slot + 1);
    }

    /**
     * @param  array<int|string, Product>  $products
     * @param  array<int|string, int>  $quantities
     */
    private function seedOrder(User $customer, string $key, CarbonImmutable $date, array $products, array $quantities, DeliveryRules $deliveryRules): void
    {
        $items = [];
        $total = '0.00';
        foreach ($quantities as $code => $quantity) {
            $product = $products[$code];
            $price = $product->discount_price ?? $product->price;
            if (! is_numeric($price) || bccomp($price, '0', 2) < 0) {
                throw new RuntimeException('Historical sales product prices must be numeric and nonnegative.');
            }
            $items[] = [
                'product_id' => $product->id,
                'quantity' => $quantity,
                'price_at_time' => $price,
                'acquisition_cost_at_time' => $product->acquisition_cost,
                'acquisition_cost_source' => $product->acquisition_cost_source,
            ];
            $total = bcadd($total, bcmul($price, (string) $quantity, 2), 2);
        }
        $slot = (int) substr($key, -2) - 1;
        $terms = $this->orderTerms($date, $slot);
        $quote = $deliveryRules->quote($terms['fulfillment'], $terms['destination'], array_map(fn (int|string $code): Product => $products[$code], array_keys($quantities)));
        if ($quote !== null && (! $quote['is_demo'] || $quote['origin_city'] !== 'Sagay City' || Config::string('battlefront.delivery.carrier') !== 'lbc')) {
            throw new RuntimeException('Historical delivery requires the approved Sagay demo delivery configuration.');
        }
        $finalTotal = bcadd($total, $quote['delivery_fee'] ?? '0.00', 2);
        $order = new Order;
        $order->forceFill([
            'user_id' => $customer->id,
            'recipient_name' => $customer->name,
            'development_fixture_key' => $key,
            'contact_number' => '09000000000',
            'fulfillment_method' => $terms['fulfillment'],
            'delivery_address' => $terms['destination'] === null ? null : $this->deliveryAddress($terms['destination']),
            'product_subtotal' => $total,
            'delivery_fee' => $quote['delivery_fee'] ?? '0.00',
            'total_amount' => $finalTotal,
            'delivery_destination' => $quote['destination'] ?? null,
            'delivery_base_fee' => $quote['base_fee'] ?? null,
            'shipping_profile' => $quote['shipping_profile'] ?? null,
            'handling_surcharge' => $quote['handling_surcharge'] ?? null,
            'delivery_origin_city' => $quote['origin_city'] ?? null,
            'delivery_is_demo' => $quote['is_demo'] ?? null,
            'delivery_assumption_label' => $quote === null ? null : self::DELIVERY_NOTE.' '.$quote['assumption_label'],
            'status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Verified,
            'payment_method' => $terms['payment'],
            'created_at' => $date->toDateTimeString(),
        ])->save();
        $order->items()->createMany($items);
        if ($quote !== null) {
            $shipment = $order->shipment()->make([
                'carrier' => Config::string('battlefront.delivery.carrier'), 'status' => ShipmentStatus::Delivered,
                'preparation_days' => $quote['preparation_days'], 'transit_min_days' => $quote['transit_min_days'],
                'transit_max_days' => $quote['transit_max_days'], 'eta_min_days' => $quote['eta_min_days'], 'eta_max_days' => $quote['eta_max_days'],
            ]);
            $shipment->forceFill(['created_at' => $date->toDateTimeString(), 'updated_at' => $date->toDateTimeString()])->save();
        }
        $order->sale()->create(['amount' => $total, 'sale_date' => $date->toDateString()]);
    }
}

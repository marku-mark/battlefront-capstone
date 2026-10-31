<?php

namespace App\Services\Order;

use App\Enums\FulfillmentMethod;
use App\Enums\ShippingProfile;
use App\Models\Product;
use DomainException;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * @phpstan-type DeliveryDestination array{base_fee: numeric-string, transit_min_days: int|null, transit_max_days: int|null}
 * @phpstan-type HandlingRules array{surcharge: numeric-string, preparation_days: int}
 * @phpstan-type DeliveryQuote array{
 *     origin_city: string, destination: string, is_demo: bool, assumption_label: string, pricing_configured: bool,
 *     pricing_status: string, delivery_arrangement_required: bool,
 *     shipping_profile: string, base_fee: numeric-string, handling_surcharge: numeric-string,
 *     route_base_fee: numeric-string, weight_surcharge: numeric-string, actual_weight_kg: numeric-string,
 *     volumetric_weight_kg: numeric-string, billable_weight_kg: numeric-string, weight_rate_per_kg: numeric-string,
 *     weight_step_kg: numeric-string, included_weight_kg: numeric-string, volumetric_divisor_cm3_per_kg: int,
 *     estimate_disclosure: string, measurement_sources: array{estimated: int, measured: int}, region_group?: string,
 *     delivery_fee: numeric-string, preparation_days: int|null, transit_min_days: int|null, transit_max_days: int|null,
 *     eta_min_days: int|null, eta_max_days: int|null
 * }
 */
class DeliveryRules
{
    /** @return array<string, DeliveryDestination> */
    public function destinations(): array
    {
        $destinations = [];
        $locations = app(PsgcDeliveryLocations::class);
        foreach (Config::array('battlefront.delivery.destinations') as $name => $route) {
            $city = $locations->city($locations->destination($name)['code']);
            $pricing = $this->regionalPricing($city['region_code']);
            $destinations[$name] = [
                'base_fee' => $pricing['base_fee'],
                'transit_min_days' => $route['transit_min_days'],
                'transit_max_days' => $route['transit_max_days'],
            ];
        }

        return $destinations;
    }

    /** @return DeliveryDestination */
    public function destination(string $destination): array
    {
        $zone = $this->zone($destination);

        return ['base_fee' => $zone['base_fee'], 'transit_min_days' => $zone['transit_min_days'], 'transit_max_days' => $zone['transit_max_days']];
    }

    /** @return array<string, mixed> */
    public function zone(string $destination): array
    {
        $locations = app(PsgcDeliveryLocations::class);
        $city = preg_match('/\A[0-9]{10}\z/', $destination) === 1
            ? $locations->city($destination)
            : $locations->city($locations->destination($destination)['code']);
        $matchedRule = false;
        foreach (['cities' => $city['code'], 'provinces' => $city['province_code'], 'regions' => $city['region_code']] as $level => $code) {
            $zones = Config::array('battlefront.delivery.zones.'.$level);
            if ($code === null || ! array_key_exists($code, $zones)) {
                continue;
            }
            $matchedRule = true;
            $zone = $zones[$code];
            if (! is_array($zone) || ($zone['enabled'] ?? true) !== true) {
                break;
            }
            if (isset($zone['legacy_destination'])) {
                $legacy = Config::array('battlefront.delivery.destinations')[$zone['legacy_destination']] ?? null;
                if ($legacy === null) {
                    break;
                }
                $zone = [...$legacy, ...$zone];
            }
            if (isset($zone['distance_band'])) {
                $distanceBandName = $zone['distance_band'];
                $distanceBands = Config::array('battlefront.delivery.negros_occidental_distance_bands');
                $distanceProxies = Config::array('battlefront.delivery.negros_occidental_distance_proxies');
                $distanceBand = is_string($distanceBandName) ? ($distanceBands[$distanceBandName] ?? null) : null;
                $distanceProxy = $distanceProxies[$city['code']] ?? null;

                if (! is_array($distanceBand) || ! is_array($distanceProxy)
                    || ($distanceProxy['distance_band'] ?? null) !== $distanceBandName
                    || ! is_numeric($distanceProxy['distance_km'] ?? null)
                    || ! is_int($distanceBand['minimum_exclusive_km'] ?? null)
                    || ! is_int($distanceBand['maximum_inclusive_km'] ?? null)
                    || $distanceProxy['distance_km'] <= $distanceBand['minimum_exclusive_km']
                    || $distanceProxy['distance_km'] > $distanceBand['maximum_inclusive_km']) {
                    break;
                }

                $zone = [...$distanceBand, ...$zone];
                unset($zone['distance_band'], $zone['minimum_exclusive_km'], $zone['maximum_inclusive_km']);
            }
            if (! is_int($zone['transit_min_days'] ?? null) || ! is_int($zone['transit_max_days'] ?? null)
                || $zone['transit_min_days'] < 0 || $zone['transit_max_days'] < $zone['transit_min_days']
                || ! is_array($zone['payment_methods'] ?? null) || ! array_is_list($zone['payment_methods']) || $zone['payment_methods'] === []
                || count(array_filter($zone['payment_methods'], is_string(...))) !== count($zone['payment_methods'])
                || array_diff($zone['payment_methods'], ['gcash', 'maya', 'cod']) !== []) {
                break;
            }

            return [...$zone, ...$this->regionalPricing($city['region_code']), 'configured' => true, 'rule_id' => $level.':'.$code, 'city_code' => $city['code'],
                'region_code' => $city['region_code'], 'province_code' => $city['province_code'],
                'destination' => $city['destination'], 'rules_version' => Config::string('battlefront.delivery.rules_version')];
        }

        if ($matchedRule) {
            throw new DomainException('Delivery is unavailable for this location.');
        }

        return [
            ...$this->regionalPricing($city['region_code']),
            'configured' => false,
            'regional_estimate' => true,
            'transit_min_days' => null,
            'transit_max_days' => null,
            'payment_methods' => Config::array('battlefront.delivery.unconfigured_route.payment_methods'),
            'rule_id' => 'regional_estimate:'.$city['region_code'],
            'city_code' => $city['code'],
            'region_code' => $city['region_code'],
            'province_code' => $city['province_code'],
            'destination' => $city['destination'],
            'rules_version' => Config::string('battlefront.delivery.rules_version'),
        ];
    }

    /** @return array{base_fee: numeric-string, region_group: string} */
    private function regionalPricing(string $regionCode): array
    {
        $group = $this->regionGroup($regionCode);
        $fee = Config::array('battlefront.delivery.regional_estimates.fees')[$group] ?? null;
        if (! in_array($group, ['visayas', 'luzon', 'mindanao'], true)
            || ! $this->isValidDecimal($fee, '/\A[0-9]{1,10}\.[0-9]{2}\z/')) {
            throw new DomainException('Delivery pricing is unavailable for this location.');
        }

        return ['base_fee' => $fee, 'region_group' => $group];
    }

    /** @return HandlingRules */
    public function handling(ShippingProfile $profile): array
    {
        /** @var HandlingRules $rules */
        $rules = Config::array("battlefront.delivery.profiles.{$profile->value}");

        return $rules;
    }

    /**
     * Select one handling requirement from current server-loaded products.
     *
     * @param  iterable<Product>  $products
     */
    public function highestProfile(iterable $products): ShippingProfile
    {
        $highest = null;

        foreach ($products as $product) {
            $profile = $product->getAttribute('shipping_profile');

            if (! $profile instanceof ShippingProfile) {
                throw new InvalidArgumentException('Products must have a loaded shipping profile.');
            }

            if ($highest === null || $profile->priority() > $highest->priority()) {
                $highest = $profile;
            }
        }

        if ($highest === null) {
            throw new InvalidArgumentException('Delivery quotes require at least one product.');
        }

        return $highest;
    }

    /**
     * Return a relative delivery estimate; pickup has no delivery-zone quote.
     * Product quantities affect parcel weights; handling remains the highest profile once per order.
     *
     * @param  iterable<Product>  $products
     * @param  array<int, int>  $quantities
     * @return DeliveryQuote|null
     */
    public function quote(FulfillmentMethod $fulfillmentMethod, ?string $destination, iterable $products, array $quantities = []): ?array
    {
        if ($fulfillmentMethod === FulfillmentMethod::Pickup) {
            return null;
        }

        $destination ??= '';
        // All code and legacy-name requests use the same zone precedence and eligibility.
        if (! preg_match('/\A[0-9]{10}\z/', $destination) && ! array_key_exists($destination, Config::array('battlefront.delivery.destinations'))) {
            throw new DomainException('Unsupported delivery destination.');
        }
        $zone = $this->zone($destination);
        $profile = $this->highestProfile($products);
        $weights = $this->parcelWeights($products, $quantities);
        $regionGroup = $zone['region_group'] ?? $this->regionGroup($zone['region_code']);
        if (! is_string($regionGroup)) {
            throw new DomainException('Delivery weight pricing is unavailable for this location.');
        }
        $weightRate = Config::array('battlefront.shipping.extra_weight_rates')[$regionGroup] ?? null;
        if (! $this->isValidDecimal($weightRate, '/\\A[0-9]{1,10}\\.[0-9]{2}\\z/')) {
            throw new DomainException('Delivery weight pricing is unavailable for this location.');
        }
        $includedWeight = (string) Config::get('battlefront.shipping.included_weight_kg', '1.00');
        $weightStep = (string) Config::get('battlefront.shipping.weight_step_kg', '0.50');
        if (! $this->isValidDecimal($includedWeight, '/\\A[0-9]{1,8}\\.[0-9]{1,3}\\z/')
            || ! $this->isValidDecimal($weightStep, '/\\A[0-9]{1,8}\\.[0-9]{1,3}\\z/')
            || bccomp($weightStep, '0.000', 3) <= 0) {
            throw new DomainException('Delivery weight pricing is unavailable for this location.');
        }
        $billableWeight = $this->ceilToStep(
            bccomp($weights['actual'], $weights['volumetric_precise'], 9) >= 0 ? $weights['actual'] : $weights['volumetric_precise'],
            $weightStep,
        );
        $extraWeight = bccomp($billableWeight, $includedWeight, 3) > 0
            ? bcsub($billableWeight, $includedWeight, 3)
            : '0.000';
        $weightSurcharge = bcadd(bcmul($extraWeight, $weightRate, 5), '0.005', 3);
        $weightSurcharge = bcadd($weightSurcharge, '0.00', 2);
        $baseFee = bcadd($zone['base_fee'], $weightSurcharge, 2);
        $weightDetails = [
            'route_base_fee' => $zone['base_fee'], 'weight_surcharge' => $weightSurcharge,
            'actual_weight_kg' => $weights['actual'], 'volumetric_weight_kg' => $weights['volumetric'],
            'billable_weight_kg' => $billableWeight, 'weight_rate_per_kg' => $weightRate,
            'weight_step_kg' => $weightStep, 'included_weight_kg' => $includedWeight,
            'volumetric_divisor_cm3_per_kg' => Config::integer('battlefront.shipping.volumetric_divisor_cm3_per_kg'),
            'estimate_disclosure' => Config::string('battlefront.shipping.estimate_disclosure'),
            'measurement_sources' => $weights['sources'],
        ];

        if ($zone['configured'] === false) {
            if ($zone['regional_estimate'] === true) {
                $handling = $this->handling($profile);

                return [
                    'origin_city' => Config::string('battlefront.delivery.origin_city'),
                    'destination' => $destination,
                    'is_demo' => true,
                    'assumption_label' => Config::string('battlefront.delivery.regional_estimates.assumption_label'),
                    'pricing_configured' => true,
                    'pricing_status' => 'regional_estimate',
                    'delivery_arrangement_required' => true,
                    'region_group' => $regionGroup,
                    'shipping_profile' => $profile->value,
                    'base_fee' => $baseFee,
                    ...$weightDetails,
                    'handling_surcharge' => $handling['surcharge'],
                    'delivery_fee' => bcadd($baseFee, $handling['surcharge'], 2),
                    'preparation_days' => null,
                    'transit_min_days' => null,
                    'transit_max_days' => null,
                    'eta_min_days' => null,
                    'eta_max_days' => null,
                ];
            }

            return [
                'origin_city' => Config::string('battlefront.delivery.origin_city'),
                'destination' => $destination,
                'is_demo' => true,
                'assumption_label' => Config::string('battlefront.delivery.unconfigured_route.assumption_label'),
                'pricing_configured' => false,
                'pricing_status' => 'not_configured',
                'delivery_arrangement_required' => true,
                'shipping_profile' => $profile->value,
                'base_fee' => '0.00',
                ...$weightDetails,
                'handling_surcharge' => '0.00',
                'delivery_fee' => '0.00',
                'preparation_days' => null,
                'transit_min_days' => null,
                'transit_max_days' => null,
                'eta_min_days' => null,
                'eta_max_days' => null,
            ];
        }

        $handling = $this->handling($profile);

        return [
            'origin_city' => Config::string('battlefront.delivery.origin_city'),
            'destination' => $destination,
            'is_demo' => Config::boolean('battlefront.delivery.is_demo'),
            'assumption_label' => Config::string('battlefront.delivery.assumption_label'),
            'pricing_configured' => true,
            'pricing_status' => 'configured_estimate',
            'delivery_arrangement_required' => false,
            'shipping_profile' => $profile->value,
            'base_fee' => $baseFee,
            'region_group' => $regionGroup,
            ...$weightDetails,
            'handling_surcharge' => $handling['surcharge'],
            'delivery_fee' => bcadd($baseFee, $handling['surcharge'], 2),
            'preparation_days' => $handling['preparation_days'],
            'transit_min_days' => $zone['transit_min_days'],
            'transit_max_days' => $zone['transit_max_days'],
            'eta_min_days' => $handling['preparation_days'] + $zone['transit_min_days'],
            'eta_max_days' => $handling['preparation_days'] + $zone['transit_max_days'],
        ];
    }

    /** @param iterable<Product> $products
     * @param  array<int, int>  $quantities
     * @return array{actual: numeric-string, volumetric: numeric-string, volumetric_precise: numeric-string, sources: array{estimated: int, measured: int}}
     */
    private function parcelWeights(iterable $products, array $quantities): array
    {
        $actual = '0.000000';
        $volume = '0.000000000';
        $sources = ['estimated' => 0, 'measured' => 0];
        $divisor = Config::integer('battlefront.shipping.volumetric_divisor_cm3_per_kg');
        foreach ($products as $product) {
            $weight = $product->getAttribute('package_weight_kg');
            $length = $product->getAttribute('package_length_cm');
            $width = $product->getAttribute('package_width_cm');
            $height = $product->getAttribute('package_height_cm');
            $source = $product->getAttribute('package_measurement_source');
            if (! $this->isValidDecimal($weight, '/\\A[0-9]+\\.[0-9]{3}\\z/')
                || ! $this->isValidDecimal($length, '/\\A[0-9]+\\.[0-9]{2}\\z/')
                || ! $this->isValidDecimal($width, '/\\A[0-9]+\\.[0-9]{2}\\z/')
                || ! $this->isValidDecimal($height, '/\\A[0-9]+\\.[0-9]{2}\\z/')
                || bccomp($weight, '0.000', 3) <= 0 || bccomp($length, '0.00', 2) <= 0
                || bccomp($width, '0.00', 2) <= 0 || bccomp($height, '0.00', 2) <= 0
                || ! in_array($source, ['estimated', 'measured'], true) || $divisor <= 0) {
                throw new DomainException('Package weight and dimensions are required to estimate delivery.');
            }
            $quantity = max(1, (int) ($quantities[$product->id] ?? 1));
            $actual = bcadd($actual, bcmul($weight, (string) $quantity, 6), 6);
            $itemVolume = bcmul(bcmul($length, $width, 4), $height, 6);
            $itemVolumetricWeight = bcdiv($itemVolume, (string) $divisor, 9);
            $volume = bcadd($volume, bcmul($itemVolumetricWeight, (string) $quantity, 9), 9);
            $sources[$source] += $quantity;
        }

        $volumetricPrecise = $volume;

        return [
            'actual' => bcadd($actual, '0.000', 3),
            'volumetric' => bcadd($volumetricPrecise, '0.0005', 3),
            'volumetric_precise' => $volumetricPrecise,
            'sources' => $sources,
        ];
    }

    /**
     * @param  numeric-string  $value
     * @param  numeric-string  $step
     * @return numeric-string
     */
    private function ceilToStep(string $value, string $step): string
    {
        $units = bcdiv($value, $step, 0);
        if (bccomp(bcmul($units, $step, 9), $value, 9) < 0) {
            $units = bcadd($units, '1', 0);
        }

        return bcmul($units, $step, 3);
    }

    private function regionGroup(string $regionCode): string
    {
        $regions = Config::array('battlefront.delivery.regional_estimates.regions');
        $group = $regions[$regionCode] ?? null;
        if (! is_string($group)) {
            throw new DomainException('Delivery weight pricing is unavailable for this location.');
        }

        return $group;
    }

    /**
     * @phpstan-assert-if-true numeric-string $value
     */
    private function isValidDecimal(mixed $value, string $pattern): bool
    {
        return is_string($value)
            && preg_match($pattern, $value) === 1;
    }
}

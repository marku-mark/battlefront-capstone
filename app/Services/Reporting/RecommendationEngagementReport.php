<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

class RecommendationEngagementReport
{
    private const PLACEMENTS = [
        'cart' => 'Cart',
        'catalog' => 'Product catalog',
        'dashboard' => 'Customer dashboard',
        'home' => 'Home page',
        'product' => 'Product page',
        'recommendations' => 'Recommendations page',
    ];

    private const REASONS = [
        'bought_with_cart_products' => 'Often bought with cart items',
        'bought_with_purchase_history' => 'Often bought with past purchases',
        'bought_with_viewed_products' => 'Often bought with viewed products',
        'similar_to_viewed_product' => 'Similar to viewed products',
        'spent_time_viewing_product' => 'Time spent viewing similar products',
        'bought_by_similar_customers' => 'Customers with overlapping purchases',
        'featured_fallback' => 'Featured fallback',
        'matched_recent_searches' => 'Matched recent searches',
        'popular_with_customers' => 'Popular with customers',
    ];

    /**
     * Build an anonymous event summary for an inclusive calendar date range.
     *
     * @return array{
     *     summary: array{impressions: int, clicks: int, dismissals: int, wrong_reports: int},
     *     placements: list<array{placement: string, label: string, impressions: int, clicks: int, dismissals: int, wrong_reports: int}>,
     *     reasons: list<array{reason_code: string, label: string, impressions: int, clicks: int, dismissals: int, wrong_reports: int}>,
     *     top_clicked_products: list<array{product_id: int, product_name: string, impressions: int, clicks: int}>
     * }
     */
    public function generate(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $summaryCounts = $this->eventCounts($from, $to)
            ->select('event_type')
            ->selectRaw('COUNT(*) as event_count')
            ->groupBy('event_type')
            ->get();
        $placementCounts = $this->eventCounts($from, $to)
            ->select('placement', 'event_type')
            ->selectRaw('COUNT(*) as event_count')
            ->groupBy('placement', 'event_type')
            ->get();
        $reasonCounts = $this->eventCounts($from, $to)
            ->select('reason_code', 'event_type')
            ->selectRaw('COUNT(*) as event_count')
            ->groupBy('reason_code', 'event_type')
            ->get();
        $topClickedProducts = $this->eventCounts($from, $to)
            ->join('products', 'products.id', '=', 'recommendation_interactions.product_id')
            ->select('products.id as product_id', 'products.name as product_name')
            ->selectRaw("SUM(CASE WHEN recommendation_interactions.event_type = 'impression' THEN 1 ELSE 0 END) as impressions")
            ->selectRaw("SUM(CASE WHEN recommendation_interactions.event_type = 'click' THEN 1 ELSE 0 END) as clicks")
            ->groupBy('products.id', 'products.name')
            ->havingRaw("SUM(CASE WHEN recommendation_interactions.event_type = 'click' THEN 1 ELSE 0 END) > 0")
            ->orderByDesc('clicks')
            ->orderBy('products.name')
            ->limit(10)
            ->get();

        return [
            'summary' => [
                'impressions' => $this->eventTotal($summaryCounts, 'impression'),
                'clicks' => $this->eventTotal($summaryCounts, 'click'),
                'dismissals' => $this->eventTotal($summaryCounts, 'dismiss'),
                'wrong_reports' => $this->eventTotal($summaryCounts, 'report_wrong'),
            ],
            'placements' => $this->placementRows($placementCounts),
            'reasons' => $this->reasonRows($reasonCounts),
            'top_clicked_products' => array_values($topClickedProducts
                ->map(fn (stdClass $product): array => [
                    'product_id' => (int) $product->product_id,
                    'product_name' => (string) $product->product_name,
                    'impressions' => (int) $product->impressions,
                    'clicks' => (int) $product->clicks,
                ])
                ->values()
                ->all()),
        ];
    }

    /**
     * @param  Collection<int, stdClass>  $counts
     * @return list<array{placement: string, label: string, impressions: int, clicks: int, dismissals: int, wrong_reports: int}>
     */
    private function placementRows(Collection $counts): array
    {
        $countsByPlacement = $this->countsByValue($counts, 'placement');
        $rows = [];

        foreach ($countsByPlacement as $placement => $totals) {
            $rows[] = [
                'placement' => $placement,
                'label' => self::PLACEMENTS[$placement] ?? Str::headline($placement),
                ...$totals,
            ];
        }

        usort($rows, static fn (array $left, array $right): int => $left['label'] <=> $right['label']);

        return $rows;
    }

    /**
     * @param  Collection<int, stdClass>  $counts
     * @return list<array{reason_code: string, label: string, impressions: int, clicks: int, dismissals: int, wrong_reports: int}>
     */
    private function reasonRows(Collection $counts): array
    {
        $countsByReason = $this->countsByValue($counts, 'reason_code');
        $rows = [];

        foreach ($countsByReason as $reasonCode => $totals) {
            $rows[] = [
                'reason_code' => $reasonCode,
                'label' => self::REASONS[$reasonCode] ?? Str::headline($reasonCode),
                ...$totals,
            ];
        }

        usort($rows, static fn (array $left, array $right): int => $left['label'] <=> $right['label']);

        return $rows;
    }

    /**
     * @param  Collection<int, stdClass>  $events
     * @return array<string, array{impressions: int, clicks: int, dismissals: int, wrong_reports: int}>
     */
    private function countsByValue(Collection $events, string $valueKey): array
    {
        $counts = [];

        foreach ($events as $event) {
            $value = (string) ($event->{$valueKey} ?? 'unspecified');
            $eventType = (string) $event->event_type;
            $counts[$value] ??= [
                'impressions' => 0,
                'clicks' => 0,
                'dismissals' => 0,
                'wrong_reports' => 0,
            ];
            $eventCountKey = match ($eventType) {
                'impression' => 'impressions',
                'click' => 'clicks',
                'dismiss' => 'dismissals',
                'report_wrong' => 'wrong_reports',
                default => null,
            };

            if ($eventCountKey !== null) {
                $counts[$value][$eventCountKey] = (int) $event->event_count;
            }
        }

        return $counts;
    }

    /**
     * @param  Collection<int, stdClass>  $counts
     */
    private function eventTotal(Collection $counts, string $eventType): int
    {
        $event = $counts->firstWhere('event_type', $eventType);

        return $event instanceof stdClass ? (int) $event->event_count : 0;
    }

    private function eventCounts(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('recommendation_interactions')
            ->where('recommendation_interactions.created_at', '>=', $from->startOfDay())
            ->where('recommendation_interactions.created_at', '<', $to->addDay()->startOfDay())
            ->where('recommendation_interactions.expires_at', '>', now());
    }
}

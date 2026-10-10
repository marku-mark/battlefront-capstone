<?php

namespace App\Http\Controllers;

use App\Actions\Recommendation\BuildRecommendationViewData;
use App\Actions\User\RecordCustomerProductView;
use App\Enums\UserRole;
use App\Http\Requests\ProductCatalogIndexRequest;
use App\Models\GuestRecommendationProfile;
use App\Models\Product;
use App\Models\User;
use App\Repositories\Catalog\ProductCatalogRepository;
use App\Services\CatalogProductPresenter;
use App\Services\Recommendation\RecordCustomerSearch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductCatalogController extends Controller
{
    public function __construct(
        private readonly ProductCatalogRepository $productCatalogRepository,
        private readonly CatalogProductPresenter $catalogProductPresenter,
    ) {}

    /**
     * Display the customer product catalog.
     */
    public function index(
        ProductCatalogIndexRequest $request,
        RecordCustomerSearch $recordCustomerSearch,
        BuildRecommendationViewData $buildRecommendationViewData,
    ): Response {
        $filters = $request->filters();

        if (! $request->prefetch() && $request->integer('page', 1) === 1) {
            $recordCustomerSearch->record(
                $request->user(),
                $filters['q'],
                $request->attributes->get('guest_recommendation_profile'),
            );
        }

        $products = $this->productCatalogRepository->paginate($filters)
            ->through(fn (Product $product): array => $this->catalogProductPresenter->present($product));

        $recommendationViewData = null;
        $resolveRecommendations = function () use (&$recommendationViewData, $filters, $request, $buildRecommendationViewData): array {
            if ($recommendationViewData !== null) {
                return $recommendationViewData;
            }

            if (collect($filters)->contains(static fn (mixed $value): bool => $value !== null)) {
                return $recommendationViewData = [
                    'recommendations' => [],
                    'is_personalized' => false,
                    'has_featured_fallback' => false,
                    'guest_recommendation_scope' => null,
                ];
            }

            $user = $request->user();

            return $recommendationViewData = $buildRecommendationViewData(
                $user instanceof User ? $user : null,
                guestProfile: $request->attributes->get('guest_recommendation_profile'),
                hideWhenPersonalizationDisabled: true,
            );
        };

        return Inertia::render('Products/Index', [
            'products' => Inertia::scroll($products),
            'filters' => $filters,
            'filter_options' => fn (): array => $this->productCatalogRepository->filterOptions(),
            'recommendations' => fn (): array => $resolveRecommendations()['recommendations'],
            'is_personalized' => fn (): bool => $resolveRecommendations()['is_personalized'],
            'has_featured_fallback' => fn (): bool => $resolveRecommendations()['has_featured_fallback'],
            'guest_recommendation_scope' => fn (): ?string => $resolveRecommendations()['guest_recommendation_scope'],
        ]);
    }

    /**
     * Display an eligible product using authoritative catalog data.
     */
    public function show(
        Request $request,
        int $product,
        RecordCustomerProductView $recordCustomerProductView,
        BuildRecommendationViewData $buildRecommendationViewData,
    ): Response {
        $catalogProduct = $this->productCatalogRepository->findAvailableOrFail($product);
        if (! $request->prefetch()) {
            $recordCustomerProductView(
                $request->user(),
                $catalogProduct,
                $request->attributes->get('guest_recommendation_profile'),
            );
        }
        $user = $request->user();
        $customer = $user instanceof User ? $user : null;
        $guestProfile = $request->attributes->get('guest_recommendation_profile');

        return Inertia::render('Products/Show', [
            'product' => $this->catalogProductPresenter->present($catalogProduct),
            'can_record_product_dwell' => $customer instanceof User
                ? $customer->role === UserRole::Customer
                    && $customer->personalized_recommendations_enabled
                    && $customer->product_view_recommendations_enabled
                : $guestProfile instanceof GuestRecommendationProfile,
            ...$buildRecommendationViewData(
                $customer,
                $catalogProduct->id,
                guestProfile: $guestProfile instanceof GuestRecommendationProfile ? $guestProfile : null,
            ),
        ]);
    }
}

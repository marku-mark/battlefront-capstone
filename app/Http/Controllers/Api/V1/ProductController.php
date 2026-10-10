<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\User\RecordCustomerProductView;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductCatalogIndexRequest;
use App\Http\Resources\Api\V1\CatalogFilterOptionsResource;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Repositories\Catalog\ProductCatalogRepository;
use App\Services\CatalogProductPresenter;
use App\Services\Recommendation\RecordCustomerSearch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductCatalogRepository $productCatalogRepository,
        private readonly CatalogProductPresenter $catalogProductPresenter,
    ) {}

    public function index(ProductCatalogIndexRequest $request, RecordCustomerSearch $recordCustomerSearch): AnonymousResourceCollection
    {
        $filters = $request->filters();

        if (! $request->prefetch() && $request->integer('page', 1) === 1) {
            $recordCustomerSearch->record($request->user('sanctum'), $filters['q']);
        }

        $products = $this->productCatalogRepository->paginate($filters)
            ->through(fn (Product $product): array => $this->catalogProductPresenter->present($product));

        return ProductResource::collection($products);
    }

    public function filters(): CatalogFilterOptionsResource
    {
        return new CatalogFilterOptionsResource($this->productCatalogRepository->filterOptions());
    }

    public function show(Request $request, int $product, RecordCustomerProductView $recordCustomerProductView): ProductResource
    {
        $catalogProduct = $this->productCatalogRepository->findAvailableOrFail($product);
        if (! $request->prefetch()) {
            $recordCustomerProductView($request->user('sanctum'), $catalogProduct);
        }

        return new ProductResource($this->catalogProductPresenter->present($catalogProduct));
    }
}

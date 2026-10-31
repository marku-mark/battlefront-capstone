<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Product\CreateProduct;
use App\Actions\Product\DeleteProduct;
use App\Actions\Product\RestoreProduct;
use App\Actions\Product\UpdateProduct;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\ProductIndexRequest;
use App\Http\Requests\Administration\SaveProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use App\Repositories\Administration\ProductRepository;
use App\Services\AdministratorProductPresenter;
use App\Services\CatalogProductPresenter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct(
        private readonly CatalogProductPresenter $presenter,
        private readonly ProductRepository $products,
        private readonly AdministratorProductPresenter $administratorPresenter,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(ProductIndexRequest $request): Response
    {
        $filters = $request->validated();
        if ((bool) ($filters['deleted'] ?? false)) {
            $this->authorize('catalog.delete');

            $products = $this->products->paginateArchived($filters)
                ->through(fn (Product $product): array => $this->administratorPresenter->archived($product));

            return Inertia::render('Administration/Products/Index', [
                'products' => $products,
                'deleted' => true,
                'filters' => ['q' => $filters['q'] ?? null, 'category_id' => null, 'brand' => null, 'tag_id' => null, 'status' => null],
                'filter_options' => ['categories' => [], 'tags' => [], 'brands' => []],
            ]);
        }
        $filters['category_id'] = $request->filled('category_id')
            ? $request->integer('category_id')
            : null;
        $filters['tag_id'] = $request->filled('tag_id')
            ? $request->integer('tag_id')
            : null;

        $products = $this->products->paginate($filters, $request->user()->can('products.cost'))
            ->through(fn (Product $product): array => $this->administratorPresenter->listing($product, $request->user()->can('products.cost')));

        return Inertia::render('Administration/Products/Index', [
            'products' => $products,
            'filters' => [
                'q' => $filters['q'] ?? null,
                'category_id' => $filters['category_id'] ?? null,
                'brand' => $filters['brand'] ?? null,
                'tag_id' => $filters['tag_id'] ?? null,
                'status' => $filters['status'] ?? null,
            ],
            'filter_options' => [
                ...$this->productOptions(),
                'brands' => Product::query()
                    ->whereNotNull('brand')
                    ->select('brand')
                    ->distinct()
                    ->orderBy('brand')
                    ->pluck('brand'),
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        $this->authorize('products.price');

        return Inertia::render('Administration/Products/Create', $this->productOptions());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(SaveProductRequest $request, CreateProduct $createProduct): RedirectResponse
    {
        $validated = $request->validated();
        $tagIds = $validated['tag_ids'] ?? [];
        unset($validated['tag_ids'], $validated['image']);
        $product = $createProduct->execute($validated, $tagIds, $request->file('image'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Product created successfully'),
            'action' => [
                'label' => __('Go to Inventory'),
                'url' => route('administration.inventory.index', [
                    'q' => $product->name,
                ]),
            ],
        ]);

        return to_route('administration.products.index');
    }

    /**
     * Display the specified product and its current stock.
     */
    public function show(Request $request, Product $product): Response
    {
        $product->load(['category:id,name,is_active', 'tags:id,name', 'inventory:id,product_id,quantity,reorder_level']);
        $product->loadExists('lowStockInventory as is_low_stock');

        return Inertia::render('Administration/Products/Show', [
            'product' => [
                ...$product->only([
                    'id',
                    'product_code',
                    'is_catalog_imported',
                    'name',
                    'description',
                    'brand',
                    'price',
                    'discount_price',
                    'is_active',
                    'edit_version',
                    'package_weight_kg',
                    'package_length_cm',
                    'package_width_cm',
                    'package_height_cm',
                    'package_measurement_source',
                ]),
                ...($request->user()->can('products.cost') ? ['acquisition_cost' => $product->acquisition_cost] : []),
                'image_url' => $product->image_url,
                'category' => $product->category->only(['name', 'is_active']),
                'shipping_handling' => $this->presenter->handling($product->shipping_profile),
                'tags' => $product->tags->map(fn (Tag $tag): array => $tag->only(['id', 'name']))->values(),
                'inventory' => $product->inventory?->only(['quantity', 'reorder_level']),
                'stock_status' => match (true) {
                    $product->inventory === null => 'not_initialized',
                    $product->inventory->quantity === 0 => 'out_of_stock',
                    $product->is_low_stock => 'low_stock',
                    default => 'in_stock',
                },
            ],
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Product $product): Response
    {
        $product->load('tags:id,name');

        return Inertia::render('Administration/Products/Edit', [
            ...$this->productOptions(),
            'product' => [
                'shipping_profile' => $product->shipping_profile->value,
                ...($request->user()->can('products.cost') ? ['acquisition_cost' => $product->acquisition_cost] : []),
                ...$product->only([
                    'id',
                    'product_code',
                    'is_catalog_imported',
                    'name',
                    'description',
                    'category_id',
                    'brand',
                    'price',
                    'is_featured',
                    'discount_price',
                    'is_active',
                    'edit_version',
                    'package_weight_kg',
                    'package_length_cm',
                    'package_width_cm',
                    'package_height_cm',
                    'package_measurement_source',
                ]),
                'image_url' => $product->image_url,
                'tag_ids' => $product->tags->pluck('id')->all(),
            ],
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(
        SaveProductRequest $request,
        Product $product,
        UpdateProduct $updateProduct,
    ): RedirectResponse {
        $validated = $request->validated();
        $tagIds = $validated['tag_ids'] ?? [];
        unset($validated['tag_ids'], $validated['image']);
        $updateProduct->execute($product, $validated, $tagIds, $request->file('image'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Product updated.'),
        ]);

        return to_route('administration.products.index');
    }

    public function destroy(Product $product, DeleteProduct $deleteProduct): RedirectResponse
    {
        $this->authorize('catalog.delete');
        $deleteProduct->execute($product);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Product moved to Archived products. An owner can restore it.'),
        ]);

        return to_route('administration.products.index');
    }

    public function restore(int $product, RestoreProduct $restoreProduct): RedirectResponse
    {
        $this->authorize('catalog.delete');
        $restoreProduct->execute($product);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product restored as inactive.')]);

        return to_route('administration.products.index', ['deleted' => 1]);
    }

    /**
     * Get the selectable category and tag options for product administration.
     *
     * @return array{
     *     categories: Collection<int, Category>,
     *     tags: Collection<int, Tag>,
     *     shipping_profiles: list<array{profile: string, label: string, surcharge: string, preparation_days: int}>,
     *     package_estimates: array{fallback: array<string, string>, categories: array<string, array<string, string>>}
     * }
     */
    private function productOptions(): array
    {
        return [
            'shipping_profiles' => $this->presenter->handlingOptions(),
            'package_estimates' => config('battlefront.shipping.package_estimates'),
            'categories' => Category::query()
                ->select(['id', 'name', 'is_active'])
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
            'tags' => Tag::query()
                ->select(['id', 'name'])
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
        ];
    }
}

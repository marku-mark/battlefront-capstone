<?php

namespace App\Models;

use App\Concerns\HasCatalogNameKey;
use App\Enums\ShippingProfile;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $product_code
 * @property bool $is_catalog_imported
 * @property ShippingProfile $shipping_profile
 * @property string $name
 * @property string|null $description
 * @property int $category_id
 * @property string|null $brand
 * @property string $price
 * @property bool $is_featured
 * @property string|null $discount_price
 * @property string|null $image_path
 * @property-read string|null $image_url
 * @property bool $is_active
 * @property Carbon $created_at
 * @property-read Category $category
 * @property-read Inventory|null $inventory
 * @property-read bool $is_low_stock
 * @property-read Inventory|null $lowStockInventory
 * @property-read Collection<int, Tag> $tags
 * @property-read Collection<int, CartItem> $cartItems
 * @property-read Collection<int, OrderItem> $orderItems
 * @property-read Collection<int, Forecast> $forecasts
 */
#[Fillable([
    'product_code',
    'shipping_profile',
    'name',
    'description',
    'category_id',
    'brand',
    'price',
    'is_featured',
    'discount_price',
    'image_path',
    'is_active',
])]
class Product extends Model
{
    use HasCatalogNameKey;

    public const CODE_PATTERN = '/^[A-Za-z0-9]{1,64}$/D';

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * The name of the "updated at" column.
     *
     * @var null
     */
    public const UPDATED_AT = null;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_catalog_imported' => false,
        'shipping_profile' => 'standard',
        'is_featured' => false,
        'is_active' => true,
    ];

    /**
     * Get the public URL for the product's relative image path.
     *
     * @return Attribute<string, never>|Attribute<null, never>
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->image_path === null) {
                return null;
            }

            if (str_starts_with($this->image_path, 'images/demo-products/')) {
                return asset($this->image_path);
            }

            return Storage::disk('public')->url($this->image_path);
        });
    }

    /**
     * Get the category that contains the product.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the product's inventory record.
     *
     * @return HasOne<Inventory, $this>
     */
    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class);
    }

    /**
     * Get the product's positive inventory at or below its reorder level.
     *
     * @return HasOne<Inventory, $this>
     */
    public function lowStockInventory(): HasOne
    {
        $relation = $this->hasOne(Inventory::class);
        $relation->getQuery()->lowStock();

        return $relation;
    }

    /**
     * Get the tags assigned to the product.
     *
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Get the cart items that reference the product.
     *
     * @return HasMany<CartItem, $this>
     */
    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Get the historical order items that reference the product.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get the product's persisted demand forecasts.
     *
     * @return HasMany<Forecast, $this>
     */
    public function forecasts(): HasMany
    {
        return $this->hasMany(Forecast::class);
    }

    /**
     * Scope a query to active products.
     *
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope a query to products whose product and category are active.
     *
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function customerEligible(Builder $query): void
    {
        $query
            ->active()
            ->whereIn(
                'category_id',
                Category::query()->active()->select('id'),
            );
    }

    /**
     * Scope a query to active customer products with positive live inventory.
     *
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function customerAvailable(Builder $query): void
    {
        $query
            ->customerEligible()
            ->whereHas(
                'inventory',
                fn (Builder $inventoryQuery): Builder => $inventoryQuery->where('quantity', '>', 0),
            );
    }

    /**
     * Scope a query to products that may be persisted in a customer cart.
     *
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function cartEligible(Builder $query): void
    {
        $query->customerAvailable();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_catalog_imported' => 'boolean',
            'shipping_profile' => ShippingProfile::class,
            'price' => 'decimal:2',
            'is_featured' => 'boolean',
            'discount_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}

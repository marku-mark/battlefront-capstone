<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\InventoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property int $quantity
 * @property int $reorder_level
 * @property CarbonInterface $last_updated
 * @property-read Product $product
 */
#[Fillable(['product_id', 'quantity', 'reorder_level'])]
class Inventory extends Model
{
    /** @use HasFactory<InventoryFactory> */
    use HasFactory;

    /**
     * The name of the "created at" column.
     *
     * @var null
     */
    public const CREATED_AT = null;

    /**
     * The name of the "updated at" column.
     */
    public const UPDATED_AT = 'last_updated';

    /**
     * Get the product whose stock is tracked.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Scope a query to positive inventory at or below its reorder level.
     *
     * @param  Builder<Inventory>  $query
     */
    #[Scope]
    protected function lowStock(Builder $query): void
    {
        $query->where('quantity', '>', 0)
            ->whereColumn('quantity', '<=', 'reorder_level');
    }

    /**
     * Scope a query to inventory with no remaining stock.
     *
     * @param  Builder<Inventory>  $query
     */
    #[Scope]
    protected function outOfStock(Builder $query): void
    {
        $query->where('quantity', 0);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reorder_level' => 'integer',
            'last_updated' => 'datetime',
        ];
    }
}

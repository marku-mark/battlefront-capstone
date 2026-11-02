<?php

namespace App\Models;

use Database\Factories\ProductComponentProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'product_id',
    'component_type',
    'manufacturer_part_number',
    'hardware_revision',
    'current_revision',
    'review_status',
    'edit_version',
])]
class ProductComponentProfile extends Model
{
    /** @use HasFactory<ProductComponentProfileFactory> */
    use HasFactory;

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<ComponentSpecificationRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(ComponentSpecificationRevision::class);
    }

    /** @return HasOne<ComponentSpecificationRevision, $this> */
    public function currentApprovedRevision(): HasOne
    {
        return $this->hasOne(ComponentSpecificationRevision::class)
            ->where('review_status', 'approved')
            ->where('revision', self::query()->select('current_revision')
                ->whereColumn('product_component_profiles.id', 'component_specification_revisions.product_component_profile_id')
                ->where('review_status', 'approved')->limit(1));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'current_revision' => 'integer',
            'edit_version' => 'integer',
        ];
    }
}

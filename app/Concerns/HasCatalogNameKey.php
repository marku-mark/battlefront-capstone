<?php

namespace App\Concerns;

use App\Actions\Catalog\CatalogName;
use Illuminate\Database\Eloquent\Model;

/** @mixin Model */
trait HasCatalogNameKey
{
    public function initializeHasCatalogNameKey(): void
    {
        $this->makeHidden('name_key');
    }

    protected static function bootHasCatalogNameKey(): void
    {
        static::saving(function (Model $model): void {
            if (! $model->exists || $model->isDirty('name')) {
                $model->setAttribute('name_key', CatalogName::key((string) $model->getAttribute('name')));
            }
        });
    }
}

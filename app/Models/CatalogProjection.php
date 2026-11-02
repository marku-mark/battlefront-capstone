<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogProjection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'qdrant_revision' => 'integer', 'neo4j_revision' => 'integer', 'synced_at' => 'datetime'];
    }
}

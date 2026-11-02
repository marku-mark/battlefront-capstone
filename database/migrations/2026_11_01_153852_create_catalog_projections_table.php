<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_projections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->unique();
            $table->unsignedBigInteger('revision')->default(0);
            $table->unsignedBigInteger('qdrant_revision')->default(0);
            $table->unsignedBigInteger('neo4j_revision')->default(0);
            $table->string('content_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('last_failure', 40)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_projections');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        Schema::create('forecasting_history_coverages', function (Blueprint $table) use ($driver) {
            $productCode = $table->string('product_code', 64);
            if ($driver === 'mysql') {
                $productCode->collation('utf8mb4_bin');
            } elseif ($driver === 'sqlite') {
                $productCode->collation('BINARY');
            }
            $productCode->primary();
            $table->json('coverage');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forecasting_history_coverages');
    }
};

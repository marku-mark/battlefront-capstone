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
        Schema::create('chatbot_failure_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('fingerprint', 64)->unique();
            $table->string('language', 8);
            $table->string('scope', 16);
            $table->string('path', 16);
            $table->string('stage', 24);
            $table->string('reason', 48)->index();
            $table->string('status', 16)->default('open')->index();
            $table->unsignedInteger('occurrences')->default(1);
            $table->longText('sample')->nullable();
            $table->text('product_ids');
            $table->char('prompt_hash', 64);
            $table->string('pipeline_version', 32);
            $table->unsignedInteger('duration_ms');
            $table->string('regression_test')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('delete_after')->index();
            $table->timestamp('reviewed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('chatbot_failure_events') && DB::table('chatbot_failure_events')->exists()) {
            throw new RuntimeException('Refusing to discard stored chatbot failure reviews. Prune them before rollback.');
        }
        Schema::dropIfExists('chatbot_failure_events');
    }
};

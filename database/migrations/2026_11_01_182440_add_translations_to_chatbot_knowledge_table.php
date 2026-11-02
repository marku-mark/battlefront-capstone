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
        Schema::table('chatbot_knowledge', function (Blueprint $table) {
            $table->json('translations')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('chatbot_knowledge')->whereNotNull('translations')->exists()) {
            throw new RuntimeException('Preserve FAQ translations before rolling back this migration.');
        }
        Schema::table('chatbot_knowledge', function (Blueprint $table) {
            $table->dropColumn('translations');
        });
    }
};

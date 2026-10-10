<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $keys = [];
        foreach (['products', 'categories'] as $tableName) {
            $seen = [];
            foreach (DB::table($tableName)->orderBy('id')->lazyById() as $record) {
                $key = hash('sha256', Str::lower(Str::squish($record->name)));
                if (isset($seen[$key])) {
                    throw new RuntimeException("Duplicate normalized names in {$tableName}: records {$seen[$key]} and {$record->id}. Review these records before migrating; no names have been changed.");
                }
                $seen[$key] = $record->id;
                $keys[$tableName][$record->id] = $key;
            }
        }

        foreach (['products', 'categories'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->char('name_key', 64)->nullable();
            });
            DB::transaction(function () use ($tableName, $keys): void {
                foreach ($keys[$tableName] ?? [] as $id => $key) {
                    DB::table($tableName)->where('id', $id)->update(['name_key' => $key]);
                }
            });
            $sqlite = DB::getDriverName() === 'sqlite';
            Schema::table($tableName, function (Blueprint $table) use ($sqlite): void {
                if (! $sqlite) {
                    $table->char('name_key', 64)->nullable(false)->change();
                }
                $table->unique('name_key');
            });
            if ($sqlite) {
                foreach (['insert', 'update'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$tableName}_name_key_{$event}_check
                        BEFORE {$event} ON {$tableName}
                        WHEN NEW.name_key IS NULL OR length(NEW.name_key) != 64
                        BEGIN SELECT RAISE(ABORT, 'A catalog name key is required.'); END");
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['products', 'categories'] as $tableName) {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement("DROP TRIGGER {$tableName}_name_key_insert_check");
                DB::statement("DROP TRIGGER {$tableName}_name_key_update_check");
            }
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropUnique(['name_key']);
                $table->dropColumn('name_key');
            });
        }
    }
};

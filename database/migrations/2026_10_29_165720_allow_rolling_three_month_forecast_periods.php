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
        $this->replacePeriodConstraint(true);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('forecasts')->where('forecast_quarter', 'like', '%/%')->exists()) {
            throw new RuntimeException('Cannot narrow forecast periods while rolling records exist. Preserve these rows and use a forward migration.');
        }

        $this->replacePeriodConstraint(false);
    }

    private function replacePeriodConstraint(bool $rolling): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        if ($sqlite) {
            foreach (['insert', 'update'] as $event) {
                DB::statement("DROP TRIGGER forecasts_valid_{$event}_check");
            }
        } else {
            DB::statement('ALTER TABLE forecasts DROP CHECK forecasts_quarter_format');
        }

        Schema::table('forecasts', function (Blueprint $table) use ($rolling): void {
            $table->string('forecast_quarter', $rolling ? 15 : 7)->change();
        });

        $field = $sqlite ? 'NEW.forecast_quarter' : 'forecast_quarter';
        $legacy = $sqlite
            ? "{$field} GLOB '[0-9][0-9][0-9][0-9]-Q[1-4]' AND length({$field}) = 7"
            : "REGEXP_LIKE({$field}, '^[0-9]{4}-Q[1-4]$', 'c') AND CHAR_LENGTH({$field}) = 7";
        $valid = "(({$legacy}) AND substr({$field}, 1, 4) <> '0000')";
        if ($rolling) {
            $format = $sqlite
                ? "{$field} GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]/[0-9][0-9][0-9][0-9]-[0-9][0-9]' AND length({$field}) = 15"
                : "REGEXP_LIKE({$field}, '^[0-9]{4}-[0-9]{2}/[0-9]{4}-[0-9]{2}$', 'c') AND CHAR_LENGTH({$field}) = 15";
            $valid .= " OR (({$format}) AND substr({$field}, 1, 4) <> '0000' AND substr({$field}, 9, 4) <> '0000'
                AND substr({$field}, 6, 2) BETWEEN '01' AND '12' AND substr({$field}, 14, 2) BETWEEN '01' AND '12'
                AND (substr({$field}, 9, 4) * 12 + substr({$field}, 14, 2)) - (substr({$field}, 1, 4) * 12 + substr({$field}, 6, 2)) = 2)";
        }

        if ($sqlite) {
            foreach (['insert', 'update'] as $event) {
                $operation = $event === 'insert' ? 'INSERT' : 'UPDATE OF method, predicted_demand, forecast_quarter';
                DB::statement(<<<SQL
                    CREATE TRIGGER forecasts_valid_{$event}_check
                    BEFORE {$operation} ON forecasts
                    WHEN NEW.method NOT IN ('moving_average', 'linear_trend', 'additive_holt_winters')
                        OR NEW.predicted_demand < 0 OR NOT ({$valid})
                    BEGIN
                        SELECT RAISE(ABORT, 'Invalid forecast.');
                    END
                SQL);
            }
        } else {
            DB::statement("ALTER TABLE forecasts ADD CONSTRAINT forecasts_quarter_format CHECK ({$valid})");
        }
    }
};

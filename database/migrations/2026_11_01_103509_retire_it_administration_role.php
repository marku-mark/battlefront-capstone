<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $role = DB::table('administration_roles')->where('code', 'it')->lockForUpdate()->first();
            if ($role === null) {
                return;
            }
            if (DB::table('users')->where('administration_role_id', $role->id)->exists()) {
                throw new RuntimeException('Cannot retire IT role: assigned accounts remain. An operator must explicitly resolve every assignment before retrying. No accounts were changed; deactivation alone is insufficient.');
            }
            DB::table('administration_role_permissions')->where('administration_role_id', $role->id)->delete();
            DB::table('administration_roles')->where('id', $role->id)->delete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('IT role retirement is forward-only; rollback must not restore retired privileges.');
    }
};

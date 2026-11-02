<?php

use App\Models\User;
use App\Services\TeamAccessService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function retiredRoleMigration(): Migration
{
    return require database_path('migrations/2026_11_01_103509_retire_it_administration_role.php');
}

test('fresh installations and seeders contain only supported administration roles', function () {
    expect(DB::table('administration_roles')->orderBy('code')->pluck('code')->all())->toBe(['manager', 'owner', 'staff']);
    $this->seed();
    $this->assertDatabaseMissing('users', ['email' => 'it@example.com']);
    retiredRoleMigration()->up();
});

test('retirement refuses assigned accounts without changing data', function (bool $active) {
    $roleId = legacyItRoleId();
    $user = User::factory()->administrator()->create(['administration_role_id' => $roleId, 'is_active' => $active]);
    $before = $user->refresh()->getAttributes();
    $grants = DB::table('administration_role_permissions')->orderBy('administration_role_id')->orderBy('administration_permission_id')->get()->all();
    expect(fn () => retiredRoleMigration()->up())->toThrow(RuntimeException::class, 'assigned accounts remain');
    expect($user->fresh()->getAttributes())->toBe($before);
    expect(DB::table('administration_role_permissions')->orderBy('administration_role_id')->orderBy('administration_permission_id')->get()->all())->toEqual($grants);
    $this->assertDatabaseHas('administration_roles', ['id' => $roleId]);
})->with([true, false]);

test('retirement deletes only an unassigned retired role and its grants', function () {
    legacyItRoleId();
    $permissions = DB::table('administration_permissions')->orderBy('id')->get()->all();
    $surviving = DB::table('administration_role_permissions')->whereIn('administration_role_id', DB::table('administration_roles')->where('code', '!=', 'it')->select('id'))->orderBy('administration_role_id')->orderBy('administration_permission_id')->get()->all();
    retiredRoleMigration()->up();
    $this->assertDatabaseMissing('administration_roles', ['code' => 'it']);
    expect(DB::table('administration_permissions')->orderBy('id')->get()->all())->toEqual($permissions);
    expect(DB::table('administration_role_permissions')->orderBy('administration_role_id')->orderBy('administration_permission_id')->get()->all())->toEqual($surviving);
});

test('legacy IT grants never authorize administration and cannot be assigned', function () {
    $roleId = legacyItRoleId();
    $user = User::factory()->administrator()->create(['administration_role_id' => $roleId]);
    foreach (DB::table('administration_permissions')->pluck('code') as $permission) {
        expect($user->can($permission))->toBeFalse();
    }
    foreach (['dashboard', 'administration.team.index', 'administration.reports.audit', 'administration.orders.index'] as $route) {
        $this->actingAs($user)->get(route($route))->assertForbidden();
    }
    $owner = User::factory()->administrator()->create();
    $this->actingAs($owner)->patch(route('administration.team.update', $user), ['role' => 'it', 'is_active' => true])->assertSessionHasErrors('role');
    expect(fn () => app(TeamAccessService::class)->assignRoleFromConsole($owner, 'it'))->toThrow(ValidationException::class);
    $this->artisan('administration:assign-role', ['email' => $owner->email, 'role' => 'it'])->assertFailed();
    expect($owner->fresh()->administrationRoleCode())->toBe('owner');
});

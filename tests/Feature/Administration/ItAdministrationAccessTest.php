<?php

use App\Mail\VerifyEmailOtp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

test('retired IT administrators cannot manage accounts or review audits', function () {
    $this->withoutVite();

    $it = User::factory()->administrator()->create([
        'administration_role_id' => legacyItRoleId(),
    ]);
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);

    expect($it->can('users.manage'))->toBeFalse()->and($it->can('audit.view'))->toBeFalse();
    foreach (['dashboard', 'administration.team.index', 'administration.reports.audit', 'administration.orders.index', 'administration.reports.audit.export'] as $route) {
        $this->actingAs($it)->get(route($route))->assertForbidden();
    }
    $this->patch(route('administration.team.update', $manager), ['role' => 'staff', 'is_active' => true])->assertForbidden();
    expect($manager->fresh()->administrationRoleCode())->toBe('manager');
});

test('IT administrators cannot create or alter owner and IT accounts', function () {
    $it = User::factory()->administrator()->create([
        'administration_role_id' => legacyItRoleId(),
    ]);
    $owner = User::factory()->administrator()->create();

    $this->actingAs($it)->post(route('administration.team.store'), [
        'name' => 'Another Owner', 'email' => 'another-owner@example.test',
        'role' => 'owner', 'password' => 'CorrectHorseBatteryStaple!1',
        'password_confirmation' => 'CorrectHorseBatteryStaple!1',
    ])->assertForbidden();
    $this->assertDatabaseMissing('users', ['email' => 'another-owner@example.test']);

    $this->actingAs($it)->patch(route('administration.team.update', $owner), [
        'role' => 'manager', 'is_active' => false,
    ])->assertForbidden();
    $this->actingAs($it)->patch(route('administration.team.update', $it), [
        'role' => 'manager', 'is_active' => true,
    ])->assertForbidden();
    expect($owner->fresh()->administrationRoleCode())->toBe('owner')
        ->and($it->fresh()->administrationRoleCode())->toBe('it');
});

test('an owner cannot create a retired IT account or edit its permissions', function () {
    Mail::fake();
    $owner = User::factory()->administrator()->create();
    $this->actingAs($owner)->postJson(route('administration.team.email-verification.store'), ['email' => 'it-operator@example.test'])->assertOk();
    $this->postJson(route('administration.team.email-verification.verify'), [
        'email' => 'it-operator@example.test', 'code' => Mail::sent(VerifyEmailOtp::class)->sole()->code,
    ])->assertOk();
    $this->actingAs($owner)->post(route('administration.team.store'), [
        'name' => 'IT Operator', 'email' => 'it-operator@example.test',
        'role' => 'it', 'password' => 'CorrectHorseBatteryStaple!1',
        'password_confirmation' => 'CorrectHorseBatteryStaple!1',
    ])->assertSessionHasErrors('role');
    $this->assertDatabaseMissing('users', ['email' => 'it-operator@example.test']);

    $this->actingAs($owner)->put(route('administration.team.permissions.update', 'it'), [
        'permissions' => ['orders.view'], 'edit_version' => 1,
    ])->assertSessionHasErrors('role');
    Mail::assertSentCount(1);
});

test('the rollout command rejects the retired IT role', function () {
    $administrator = User::factory()->create(['role' => 'administrator']);

    $this->artisan('administration:assign-role', ['email' => $administrator->email, 'role' => 'it'])
        ->assertExitCode(1);

    expect($administrator->fresh()->administrationRoleCode())->toBeNull();
    $this->assertDatabaseMissing('audit_logs', ['action' => 'team.role.assigned_by_cli', 'subject_id' => $administrator->id]);
});

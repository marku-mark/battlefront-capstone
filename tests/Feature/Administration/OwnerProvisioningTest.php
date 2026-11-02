<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

test('a legacy Administrator gains Owner access only through explicit assignment without losing identity', function () {
    $legacy = User::factory()->create(['role' => 'administrator']);
    $before = $legacy->getAttributes();
    $token = $legacy->createToken('existing')->accessToken;
    DB::table('sessions')->insert(['id' => 'legacy-session', 'user_id' => $legacy->id, 'payload' => '', 'last_activity' => time()]);
    $this->actingAs($legacy)->get(route('administration.team.index'))->assertForbidden();

    $this->artisan('administration:assign-role', ['email' => $legacy->email, 'role' => 'owner', '--no-interaction' => true])->assertSuccessful();

    expect($legacy->refresh()->administrationRoleCode())->toBe('owner')
        ->and($legacy->id)->toBe($before['id'])
        ->and($legacy->password)->toBe($before['password'])
        ->and($legacy->email)->toBe($before['email'])
        ->and($legacy->remember_token)->toBeNull();
    $this->assertDatabaseMissing('sessions', ['id' => 'legacy-session']);
    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'team.role.assigned_by_cli', 'subject_id' => $legacy->id, 'actor_id' => null]);
    $this->actingAs($legacy)->get(route('administration.team.index'))->assertOk();
});

test('the first Owner is created explicitly using a chosen password and audited without credentials', function () {
    $this->artisan('administration:assign-role', ['email' => 'owner@example.test', 'role' => 'owner', '--create' => true, '--name' => 'Store Owner'])
        ->expectsQuestion('Password', 'CorrectHorseBatteryStaple!1')
        ->expectsQuestion('Confirm password', 'CorrectHorseBatteryStaple!1')
        ->assertSuccessful();

    $owner = User::query()->where('email', 'owner@example.test')->sole();
    expect($owner->administrationRoleCode())->toBe('owner')
        ->and($owner->is_active)->toBeTrue()
        ->and(Hash::check('CorrectHorseBatteryStaple!1', $owner->password))->toBeTrue();
    $audit = DB::table('audit_logs')->where('action', 'team.owner.created_by_cli')->sole();
    expect($audit->subject_id)->toBe($owner->id)
        ->and($audit->changes)->not->toContain('password', 'CorrectHorseBatteryStaple!1');
});

test('first Owner creation refuses an existing Owner even when inactive', function (bool $active) {
    $existing = User::factory()->administrator()->create(['is_active' => $active]);

    $this->artisan('administration:assign-role', ['email' => 'new-owner@example.test', 'role' => 'owner', '--create' => true, '--name' => 'Another Owner'])
        ->expectsQuestion('Password', 'CorrectHorseBatteryStaple!1')
        ->expectsQuestion('Confirm password', 'CorrectHorseBatteryStaple!1')
        ->expectsOutputToContain('An Owner account already exists.')
        ->assertFailed();

    $this->assertDatabaseCount('users', 1);
    expect($existing->refresh()->is_active)->toBe($active);
})->with([true, false]);

test('CLI cannot demote the last active Owner', function () {
    $owner = User::factory()->administrator()->create();
    User::factory()->administrator()->create(['is_active' => false]);

    $this->artisan('administration:assign-role', ['email' => $owner->email, 'role' => 'staff'])
        ->expectsOutputToContain('The last active owner cannot be removed.')->assertFailed();

    expect($owner->refresh()->administrationRoleCode())->toBe('owner');
    $this->assertDatabaseCount('audit_logs', 0);
});

test('CLI can demote an Owner when another active Owner remains and revokes remembered access', function () {
    $owner = User::factory()->administrator()->create();
    $remainingOwner = User::factory()->administrator()->create();
    $token = $owner->createToken('existing')->accessToken;
    DB::table('sessions')->insert(['id' => 'owner-session', 'user_id' => $owner->id, 'payload' => '', 'last_activity' => time()]);

    $this->artisan('administration:assign-role', ['email' => $owner->email, 'role' => 'manager'])->assertSuccessful();

    expect($owner->refresh()->administrationRoleCode())->toBe('manager')->and($owner->remember_token)->toBeNull();
    $this->assertDatabaseMissing('sessions', ['id' => 'owner-session']);
    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);

    $this->artisan('administration:assign-role', ['email' => $remainingOwner->email, 'role' => 'manager'])->assertFailed();
    expect($remainingOwner->refresh()->administrationRoleCode())->toBe('owner');
    $this->assertDatabaseCount('audit_logs', 1);
});

test('web role changes revoke sessions tokens and remembered access', function () {
    $owner = User::factory()->administrator()->create();
    $target = User::factory()->administrator()->create();
    $token = $target->createToken('existing')->accessToken;
    DB::table('sessions')->insert(['id' => 'target-session', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($owner)->patch(route('administration.team.update', $target), ['role' => 'staff', 'is_active' => true])->assertSessionHasNoErrors();

    expect($target->refresh()->remember_token)->toBeNull();
    $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
});

test('CLI assignment never reactivates an inactive Administrator', function () {
    $legacy = User::factory()->create(['role' => 'administrator', 'is_active' => false]);

    $this->artisan('administration:assign-role', ['email' => $legacy->email, 'role' => 'owner'])->assertSuccessful();

    expect($legacy->refresh()->is_active)->toBeFalse()->and($legacy->can('access-administration'))->toBeFalse();
});

test('assignment refuses customers and missing accounts', function (bool $exists) {
    if ($exists) {
        User::factory()->customer()->create(['email' => 'customer@example.test']);
    }

    $this->artisan('administration:assign-role', ['email' => 'customer@example.test', 'role' => 'owner'])->assertFailed();

    $this->assertDatabaseCount('audit_logs', 0);
    $this->assertDatabaseMissing('users', ['role' => 'administrator']);
})->with([true, false]);

test('bootstrap refuses account collisions without changing an existing account', function () {
    $customer = User::factory()->customer()->create();
    $before = $customer->refresh()->getAttributes();

    $this->artisan('administration:assign-role', ['email' => $customer->email, 'role' => 'owner', '--create' => true, '--name' => 'Store Owner'])->assertFailed();

    expect($customer->refresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('audit_logs', 0);
});

test('bootstrap refuses noninteractive creation and non Owner creation', function (array $options) {
    $this->artisan('administration:assign-role', ['email' => 'owner@example.test', '--create' => true, '--name' => 'Store Owner', ...$options])->assertFailed();

    $this->assertDatabaseCount('users', 0);
})->with([
    'noninteractive' => [['role' => 'owner', '--no-interaction' => true]],
    'staff creation' => [['role' => 'staff']],
]);

test('bootstrap rejects mismatched passwords without creating an account', function () {
    $this->artisan('administration:assign-role', ['email' => 'owner@example.test', 'role' => 'owner', '--create' => true, '--name' => 'Store Owner'])
        ->expectsQuestion('Password', 'CorrectHorseBatteryStaple!1')
        ->expectsQuestion('Confirm password', 'DifferentPassword!1')
        ->assertFailed();

    $this->assertDatabaseCount('users', 0);
});

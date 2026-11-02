<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

test('new development accounts are seeded once with all administration roles and a customer', function () {
    $this->seed();

    $customer = User::query()->where('email', 'test@example.com')->firstOrFail();
    expect($customer->hasVerifiedEmail())->toBeTrue()
        ->and($customer->role)->toBe(UserRole::Customer);
    foreach (['admin' => 'owner', 'staff' => 'staff', 'manager' => 'manager'] as $email => $role) {
        $administrator = User::query()->where('email', $email.'@example.com')->sole();
        expect($administrator->hasVerifiedEmail())->toBeTrue()
            ->and($administrator->role)->toBe(UserRole::Administrator)
            ->and($administrator->administrationRoleCode())->toBe($role)
            ->and($administrator->is_active)->toBeTrue()
            ->and(Hash::check('password', $administrator->password))->toBeTrue();
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $administrator->id, 'changes' => json_encode(['role' => $role])]);
    }

    $this->seed();
    $this->assertDatabaseCount('users', 4);
    $this->assertDatabaseCount('audit_logs', 3);
});

test('reseeding preserves existing accounts and deliberate access assignments', function (?string $role, string $email) {
    $this->seed();
    $customer = User::query()->where('email', 'test@example.com')->firstOrFail();
    $administrator = User::query()->where('email', $email)->firstOrFail();

    $customer->forceFill([
        'email_verified_at' => null,
        'role' => UserRole::Administrator,
        'name' => 'Deliberate account name',
        'password' => 'ChangedCustomerPassword!1',
    ])->save();
    $administrator->forceFill([
        'email_verified_at' => null,
        'name' => 'Existing administrator',
        'password' => 'ChangedAdministratorPassword!1',
        'administration_role_id' => $role === null ? null : ($role === 'it' ? legacyItRoleId() : DB::table('administration_roles')->where('code', $role)->value('id')),
        'is_active' => false,
    ])->save();
    $beforeCustomer = $customer->refresh()->getAttributes();
    $beforeAdministrator = $administrator->refresh()->getAttributes();

    $this->seed();

    expect($customer->refresh()->getAttributes())->toBe($beforeCustomer)
        ->and($administrator->refresh()->getAttributes())->toBe($beforeAdministrator);
})->with(['owner', 'manager', 'staff', null])
    ->with(['admin@example.com', 'staff@example.com', 'manager@example.com']);

test('development administration emails never promote an existing customer', function (string $email) {
    $customer = User::factory()->customer()->create(['email' => $email]);
    $before = $customer->refresh()->getAttributes();

    $this->seed();

    expect($customer->refresh()->getAttributes())->toBe($before);
    $this->assertDatabaseMissing('audit_logs', ['subject_type' => 'user', 'subject_id' => $customer->id]);
})->with(['admin@example.com', 'staff@example.com', 'manager@example.com']);

test('default initialization does not load historical sales development fixtures', function (string $environment) {
    $disk = Storage::fake('local');
    $this->app->instance('env', $environment);

    $this->artisan('db:seed', ['--force' => true, '--no-interaction' => true])->assertSuccessful();

    $this->assertDatabaseMissing('users', ['email' => 'historical-sales@example.test']);
    $this->assertDatabaseCount('users', in_array($environment, ['local', 'testing'], true) ? 4 : 0);
    expect(User::query()->where('email', 'like', 'demo.customer%@example.test')->exists())->toBeFalse();
    foreach (['products', 'categories', 'inventories', 'orders', 'order_items', 'sales'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    expect(config('forecasting.operational_coverage'))->toBe([]);
    $disk->assertMissing(config('forecasting.development_manifest'));
})->with(['local', 'testing', 'production', 'staging']);

<?php

use App\Enums\UserRole;
use App\Mail\VerifyEmailOtp;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\User;
use App\Notifications\TeamAccessChanged;
use App\Services\Notifications\NotificationHistory;
use App\Services\TeamAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

test('team directory keeps open order counts and the first five integer order identifiers', function () {
    $owner = User::factory()->administrator()->create();
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $orders = Order::factory()->count(7)->create(['assigned_handler_id' => $staff->id, 'created_at' => '2026-10-01 08:00:00']);
    Order::factory()->create(['assigned_handler_id' => $staff->id, 'status' => 'completed']);
    $expectedIds = $orders->take(5)->pluck('id')->all();

    $this->actingAs($owner)->get(route('administration.team.index', ['q' => $staff->email]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('users.total', 1)
            ->where('users.data.0.id', $staff->id)
            ->where('users.data.0.open_order_count', 7)
            ->where('users.data.0.open_order_ids', $expectedIds));
});

test('team access notifications contain the audit event for owners', function () {
    $owner = User::factory()->administrator()->create();
    $notification = new TeamAccessChanged('role.permissions.updated', $owner->name, 7);
    $payload = $notification->toArray($owner);
    $owner->notify($notification);

    expect($payload['audience'])->toBe('administrator')
        ->and($payload['event'])->toBe('role.permissions.updated')
        ->and($payload['body'])->toContain('record #7')
        ->and(app(NotificationHistory::class)->summary($owner)['unread_count'])->toBe(1);
});

test('existing administrator roles are mapped explicitly and the mapping is audited', function () {
    $administrator = User::factory()->create(['role' => UserRole::Administrator->value]);
    expect($administrator->administration_role_id)->toBeNull();

    $this->artisan('administration:assign-role', ['email' => $administrator->email, 'role' => 'manager'])
        ->assertExitCode(0);

    expect($administrator->fresh()->administrationRoleCode())->toBe('manager');
    $this->assertDatabaseHas('audit_logs', ['action' => 'team.role.assigned_by_cli', 'subject_id' => $administrator->id]);
});

test('owner can create an email verified team account with its assigned role', function () {
    Mail::fake();
    $owner = User::factory()->administrator()->create();
    $this->actingAs($owner)->postJson(route('administration.team.email-verification.store'), ['email' => 'stock.clerk@example.test'])->assertOk();
    $this->postJson(route('administration.team.email-verification.verify'), [
        'email' => 'stock.clerk@example.test', 'code' => Mail::sent(VerifyEmailOtp::class)->sole()->code,
    ])->assertOk();
    $this->actingAs($owner)->post(route('administration.team.store'), [
        'name' => 'Stock Clerk',
        'email' => 'stock.clerk@example.test',
        'role' => 'staff',
        'password' => 'CorrectHorseBatteryStaple!1',
        'password_confirmation' => 'CorrectHorseBatteryStaple!1',
    ])->assertSessionHasNoErrors();

    $staff = User::query()->where('email', 'stock.clerk@example.test')->firstOrFail();
    expect($staff->administrationRoleCode())->toBe('staff')
        ->and($staff->email_verified_at)->not->toBeNull()
        ->and($staff->must_change_password)->toBeFalse()
        ->and($staff->can('access-administration'))->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'team.created', 'subject_id' => $staff->id]);
    Mail::assertSentCount(1);
});

test('manager cannot edit permissions and owner cannot deactivate the last owner', function () {
    $owner = User::factory()->administrator()->create();
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);

    $this->actingAs($manager)->put(route('administration.team.permissions.update', 'staff'), [
        'permissions' => ['orders.view'],
    ])->assertForbidden();

    $this->actingAs($owner)->patch(route('administration.team.update', $owner), [
        'role' => 'owner', 'is_active' => false,
    ])->assertSessionHasErrors('user');

    expect($owner->fresh()->is_active)->toBeTrue();
});

test('owner permission changes take effect on the next request', function () {
    $owner = User::factory()->administrator()->create();
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);
    $existing = DB::table('administration_role_permissions as grants')
        ->join('administration_permissions as permissions', 'permissions.id', '=', 'grants.administration_permission_id')
        ->where('grants.administration_role_id', $manager->administration_role_id)
        ->where('permissions.code', '!=', 'reports.view')
        ->pluck('permissions.code')->all();

    $this->actingAs($owner)->put(route('administration.team.permissions.update', 'manager'), [
        'permissions' => $existing,
        'edit_version' => 1,
    ])->assertSessionHasNoErrors();

    $this->actingAs($manager)->get(route('administration.reports.sales'))->assertForbidden();
    $this->assertDatabaseHas('audit_logs', ['action' => 'role.permissions.updated']);
});

test('owner can remove every permission from a role', function () {
    $owner = User::factory()->administrator()->create();
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);

    $this->actingAs($owner)->put(route('administration.team.permissions.update', 'staff'), ['edit_version' => 1])
        ->assertSessionHasNoErrors();

    $this->actingAs($staff)->get(route('administration.orders.index'))->assertForbidden();
});

test('inactive administrator sessions are rejected', function () {
    $owner = User::factory()->administrator()->create();
    $otherOwner = User::factory()->administrator()->create();

    $this->actingAs($otherOwner)->patch(route('administration.team.update', $owner), [
        'role' => 'owner', 'is_active' => false,
    ])->assertSessionHasNoErrors();

    $inactiveResponse = $this->actingAs($owner->fresh())->get(route('administration.orders.index'));
    expect($inactiveResponse->status())->toBe(403);
});

test('inventory-only managers stay on their dashboard with only authorized data', function () {
    $this->withoutVite();

    $roleId = DB::table('administration_roles')->where('code', 'manager')->value('id');
    $administrator = User::factory()->administrator()->create(['administration_role_id' => $roleId]);
    DB::table('administration_role_permissions')->where('administration_role_id', $roleId)->delete();
    $permissionId = DB::table('administration_permissions')->where('code', 'inventory.view')->value('id');
    DB::table('administration_role_permissions')->insert([
        'administration_role_id' => $roleId,
        'administration_permission_id' => $permissionId,
    ]);
    Inventory::factory()->create(['quantity' => 1, 'reorder_level' => 5]);

    $this->actingAs($administrator)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Administration')
            ->where('auth.can.viewInventory', true)
            ->where('auth.can.viewOrders', false)
            ->where('auth.can.viewReports', false)
            ->loadDeferredProps('dashboard', fn (Assert $page) => $page
                ->where('dashboard.kpis.low_stock_products', 1)
                ->where('dashboard.kpis.recorded_revenue', null)
                ->where('dashboard.kpis.recorded_sales', null)
                ->where('dashboard.kpis.recorded_gross_profit', null)
                ->where('dashboard.kpis.pending_orders', null)
                ->where('dashboard.kpis.processing_orders', null)
                ->where('dashboard.needs_attention.pending_payment_reviews', null)
                ->has('dashboard.needs_attention.low_stock_products', 1)
                ->has('dashboard.needs_attention.payment_orders', 0)
                ->has('dashboard.recent_orders', 0)));
});

test('owner role defaults match the full migrated permissions for each editable role', function (string $role, int $count) {
    $owner = User::factory()->administrator()->create();
    $migrated = DB::table('administration_role_permissions as grants')
        ->join('administration_roles as roles', 'roles.id', '=', 'grants.administration_role_id')
        ->join('administration_permissions as permissions', 'permissions.id', '=', 'grants.administration_permission_id')
        ->where('roles.code', $role)->orderBy('permissions.code')->pluck('permissions.code')->all();

    $this->actingAs($owner)->get(route('administration.team.index'))->assertInertia(fn (Assert $page) => $page
        ->where('canManagePermissions', true)->has('defaultPermissions', 2)
        ->has("defaultPermissions.{$role}", $count)
        ->where("defaultPermissions.{$role}", fn ($codes): bool => collect($codes)->sort()->values()->all() === $migrated));
})->with(['Staff' => ['staff', 7], 'Manager' => ['manager', 22]]);

test('default permissions remain separate from customized grants until the owner saves', function (string $role) {
    $owner = User::factory()->administrator()->create();
    $roleId = ($role === 'it' ? legacyItRoleId() : DB::table('administration_roles')->where('code', $role)->value('id'));
    $defaults = config("administration.default_permissions.{$role}");
    $custom = $role === 'staff' ? ['inventory.view', 'orders.view'] : ['inventory.view', 'products.price'];
    $this->actingAs($owner)->put(route('administration.team.permissions.update', $role), [
        'permissions' => $custom, 'edit_version' => 1,
    ])->assertSessionHasNoErrors();

    $this->get(route('administration.team.index'))->assertInertia(fn (Assert $page) => $page
        ->where("defaultPermissions.{$role}", $defaults)
        ->where("roleVersions.{$role}", 2)
        ->where('grants', fn ($grants): bool => collect($grants)->where('role', $role)->pluck('permission')->sort()->values()->all() === $custom));
    $this->assertDatabaseHas('administration_roles', ['id' => $roleId, 'edit_version' => 2]);
    $this->assertDatabaseCount('audit_logs', 1);

    $this->put(route('administration.team.permissions.update', $role), [
        'permissions' => $defaults, 'edit_version' => 2,
    ])->assertSessionHasNoErrors();

    $actual = DB::table('administration_role_permissions as grants')
        ->join('administration_permissions as permissions', 'permissions.id', '=', 'grants.administration_permission_id')
        ->where('administration_role_id', $roleId)->pluck('permissions.code')->sort()->values()->all();
    expect($actual)->toBe(collect($defaults)->sort()->values()->all());
    $this->assertDatabaseHas('administration_roles', ['id' => $roleId, 'edit_version' => 3]);
    $audit = DB::table('audit_logs')->where('action', 'role.permissions.updated')->orderByDesc('id')->first();
    $changes = json_decode($audit->changes, true, flags: JSON_THROW_ON_ERROR);
    expect(collect($changes['before'])->sort()->values()->all())->toBe($custom);
    expect($changes['after'])->toBe($defaults);
})->with(['staff', 'manager']);

test('default permissions can be manually customized before saving', function () {
    $owner = User::factory()->administrator()->create();
    $customizedDefaults = array_values(array_diff(config('administration.default_permissions.staff'), ['orders.process']));

    $this->actingAs($owner)->put(route('administration.team.permissions.update', 'staff'), [
        'permissions' => $customizedDefaults, 'edit_version' => 1,
    ])->assertSessionHasNoErrors();

    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    expect($staff->can('orders.process'))->toBeFalse()
        ->and($staff->can('products.price'))->toBeFalse()
        ->and($staff->can('inventory.view'))->toBeTrue();
});

test('saving default permissions rejects a stale role version without overwriting a newer change', function () {
    $owner = User::factory()->administrator()->create();
    $roleId = DB::table('administration_roles')->where('code', 'staff')->value('id');
    $this->actingAs($owner)->put(route('administration.team.permissions.update', 'staff'), [
        'permissions' => ['inventory.view'], 'edit_version' => 1,
    ])->assertSessionHasNoErrors();

    $this->put(route('administration.team.permissions.update', 'staff'), [
        'permissions' => config('administration.default_permissions.staff'), 'edit_version' => 1,
    ])->assertSessionHasErrors('edit_version');

    $permissions = DB::table('administration_role_permissions as grants')
        ->join('administration_permissions as permissions', 'permissions.id', '=', 'grants.administration_permission_id')
        ->where('administration_role_id', $roleId)->pluck('permissions.code')->all();
    expect($permissions)->toBe(['inventory.view']);
    $this->assertDatabaseHas('administration_roles', ['id' => $roleId, 'edit_version' => 2]);
    $this->assertDatabaseCount('audit_logs', 1);
});

test('default permissions do not make Owner or IT permissions editable', function (string $role) {
    $owner = User::factory()->administrator()->create();
    $before = DB::table('administration_role_permissions')->get()->toArray();

    $this->actingAs($owner)->put(route('administration.team.permissions.update', $role), [
        'permissions' => config('administration.default_permissions.staff'), 'edit_version' => 1,
    ])->assertSessionHasErrors('role');

    expect(DB::table('administration_role_permissions')->get()->toArray())->toEqual($before);
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['owner', 'it']);

test('default permissions are available only to the owner', function (string $role) {
    $user = User::factory()->administrator()->create([
        'administration_role_id' => ($role === 'it' ? legacyItRoleId() : DB::table('administration_roles')->where('code', $role)->value('id')),
    ]);

    $response = $this->actingAs($user)->get(route('administration.team.index'));
    $response->assertForbidden();
    $this->put(route('administration.team.permissions.update', 'staff'), [
        'permissions' => config('administration.default_permissions.staff'), 'edit_version' => 1,
    ])->assertForbidden();
})->with(['staff', 'manager', 'it']);

test('creating a team member does not apply default permissions to a customized role', function () {
    Mail::fake();
    $owner = User::factory()->administrator()->create();
    $roleId = DB::table('administration_roles')->where('code', 'staff')->value('id');
    $this->actingAs($owner)->put(route('administration.team.permissions.update', 'staff'), [
        'permissions' => ['inventory.view'], 'edit_version' => 1,
    ])->assertSessionHasNoErrors();

    $this->postJson(route('administration.team.email-verification.store'), ['email' => 'custom-staff@example.test'])->assertOk();
    $this->postJson(route('administration.team.email-verification.verify'), [
        'email' => 'custom-staff@example.test', 'code' => Mail::sent(VerifyEmailOtp::class)->sole()->code,
    ])->assertOk();
    $this->post(route('administration.team.store'), [
        'name' => 'Custom Staff', 'email' => 'custom-staff@example.test', 'role' => 'staff',
        'password' => 'CorrectHorseBatteryStaple!1', 'password_confirmation' => 'CorrectHorseBatteryStaple!1',
    ])->assertSessionHasNoErrors();

    $staff = User::query()->where('email', 'custom-staff@example.test')->firstOrFail();
    expect($staff->can('inventory.view'))->toBeTrue()->and($staff->can('orders.process'))->toBeFalse();
    $this->assertDatabaseHas('administration_roles', ['id' => $roleId, 'edit_version' => 2]);
    expect(DB::table('audit_logs')->where('action', 'role.permissions.updated')->count())->toBe(1);
    Mail::assertSentCount(1);
});

test('owner receives the exact Staff permission allowlist', function () {
    $owner = User::factory()->administrator()->create();

    $this->actingAs($owner)->get(route('administration.team.index'))->assertInertia(fn (Assert $page) => $page
        ->where('staffAllowedPermissions', [
            'orders.view', 'orders.process', 'inventory.view', 'inventory.request',
            'returns.request', 'returns.record_payment', 'returns.dispatch',
        ]));
});

test('Staff permission submissions reject management grants without changing access or auditing', function (array $codes) {
    $owner = User::factory()->administrator()->create();
    $before = DB::table('administration_role_permissions')->get()->toArray();

    $this->actingAs($owner)->put(route('administration.team.permissions.update', 'staff'), [
        'permissions' => $codes, 'edit_version' => 1,
    ])->assertSessionHasErrors(['permissions' => 'The selected permissions are invalid for this role.']);

    expect(DB::table('administration_role_permissions')->get()->toArray())->toEqual($before);
    $this->assertDatabaseHas('administration_roles', ['code' => 'staff', 'edit_version' => 1]);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    'management only' => [['products.manage']],
    'mixed grants' => [['orders.view', 'products.price']],
]);

test('the permission service rejects every management permission for Staff', function () {
    $owner = User::factory()->administrator()->create();
    $before = DB::table('administration_role_permissions')->get()->toArray();
    $allowed = [
        'orders.view', 'orders.process', 'inventory.view', 'inventory.request',
        'returns.request', 'returns.record_payment', 'returns.dispatch',
    ];
    $forbidden = DB::table('administration_permissions')->whereNotIn('code', $allowed)->pluck('code');

    foreach ($forbidden as $code) {
        expect(fn () => app(TeamAccessService::class)->replacePermissions($owner, 'staff', ['orders.view', $code], 1))
            ->toThrow(ValidationException::class, 'The selected permissions are invalid for this role.');
    }

    expect(DB::table('administration_role_permissions')->get()->toArray())->toEqual($before);
    $this->assertDatabaseHas('administration_roles', ['code' => 'staff', 'edit_version' => 1]);
    $this->assertDatabaseCount('audit_logs', 0);
});

test('stored management grants remain recorded but cannot authorize Staff or navigation', function () {
    $roleId = DB::table('administration_roles')->where('code', 'staff')->value('id');
    $staff = User::factory()->administrator()->create(['administration_role_id' => $roleId]);
    $allowed = [
        'orders.view', 'orders.process', 'inventory.view', 'inventory.request',
        'returns.request', 'returns.record_payment', 'returns.dispatch',
    ];
    $forbidden = DB::table('administration_permissions')->whereNotIn('code', $allowed)->pluck('id', 'code');
    foreach ($forbidden as $permissionId) {
        DB::table('administration_role_permissions')->insert([
            'administration_role_id' => $roleId, 'administration_permission_id' => $permissionId,
        ]);
    }
    $before = DB::table('administration_role_permissions')->get()->toArray();

    foreach ($forbidden as $code => $permissionId) {
        expect($staff->hasAdministrationPermission($code))->toBeFalse()
            ->and($staff->can($code))->toBeFalse();
    }
    foreach ($allowed as $code) {
        expect($staff->can($code))->toBeTrue();
    }
    $this->actingAs($staff)->get(route('products.index'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.can.browseProductCatalog', true)
        ->where('auth.can.manageProducts', false)
        ->where('auth.can.priceProducts', false)
        ->where('auth.can.manageProductCosts', false)
        ->where('auth.can.manageBranches', false)
        ->where('auth.can.viewAudit', false)
        ->where('auth.can.viewReports', false)
        ->where('auth.can.manageUsers', false));
    $this->get(route('administration.products.index'))->assertForbidden();
    $this->post(route('administration.products.store'), [])->assertForbidden();
    $this->get(route('administration.team.index'))->assertForbidden();
    $this->get(route('administration.reports.sales'))->assertForbidden();

    expect(DB::table('administration_role_permissions')->get()->toArray())->toEqual($before);
    $this->assertDatabaseHas('administration_roles', ['code' => 'staff', 'edit_version' => 1]);
    $this->assertDatabaseCount('audit_logs', 0);
});

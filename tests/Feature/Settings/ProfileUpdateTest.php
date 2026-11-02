<?php

use App\Actions\User\UpdateProfile;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('profile page is displayed', function () {
    $user = User::factory()->create([
        'default_delivery_address' => '12 Mabini Street, Sagay City',
        'default_delivery_contact_number' => '+639171234567',
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/Profile')
        ->where('canManageDefaultDeliveryAddress', true)
        ->where('defaultDeliveryAddress', '12 Mabini Street, Sagay City')
        ->where('defaultDeliveryContactNumber', '+639171234567')
        ->where('searchRecommendationsEnabled', true));
});

test('administrator profile excludes the default delivery address', function () {
    $administrator = User::factory()->administrator()->create([
        'default_delivery_address' => '12 Mabini Street, Sagay City',
    ]);

    $this->actingAs($administrator)
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Profile')
            ->where('canManageDefaultDeliveryAddress', false)
            ->where('defaultDeliveryAddress', null));
});

test('profile shares verified email status and the assigned role without changing account data', function (string $role, ?string $administrationRoleCode) {
    $user = User::factory()->create([
        'role' => $role,
        'administration_role_id' => $administrationRoleCode === null
            ? null
            : ($administrationRoleCode === 'it' ? legacyItRoleId() : DB::table('administration_roles')->where('code', $administrationRoleCode)->value('id')),
    ]);
    $original = $user->refresh()->getAttributes();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Profile')
            ->where('emailVerified', true)
            ->where('auth.user.role', $role)
            ->where('administrationRoleCode', $administrationRoleCode));

    expect($user->fresh()->getAttributes())->toBe($original);
})->with([
    'customer' => ['customer', null],
    'owner' => ['administrator', 'owner'],
    'manager' => ['administrator', 'manager'],
    'staff' => ['administrator', 'staff'],
    'IT' => ['administrator', 'it'],
    'administrator without a subrole' => ['administrator', null],
]);

test('all account types can update their name with omitted or unchanged email', function (string $role, bool $includeEmail) {
    $user = User::factory()->create([
        'role' => $role === 'customer' ? UserRole::Customer : UserRole::Administrator,
        'administration_role_id' => $role === 'customer' ? null : ($role === 'it' ? legacyItRoleId() : DB::table('administration_roles')->where('code', $role)->value('id')),
    ]);
    $email = $user->email;
    $verifiedAt = $user->getRawOriginal('email_verified_at');

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            ...($includeEmail ? ['email' => $email] : []),
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe($email);
    expect($user->getRawOriginal('email_verified_at'))->toBe($verifiedAt);
})->with(['customer', 'staff', 'manager', 'owner', 'it'])->with([
    'omitted email' => false,
    'unchanged email' => true,
]);

test('all account types reject email changes without saving accompanying profile changes', function (string $role) {
    $user = User::factory()->create([
        'role' => $role === 'customer' ? UserRole::Customer : UserRole::Administrator,
        'administration_role_id' => $role === 'customer' ? null : ($role === 'it' ? legacyItRoleId() : DB::table('administration_roles')->where('code', $role)->value('id')),
    ]);
    $original = $user->refresh()->getAttributes();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Changed name',
        'email' => 'replacement@example.test',
        'default_delivery_contact_number' => '09171234567',
        'personalized_recommendations_enabled' => false,
    ])->assertSessionHasErrors(['email' => 'Your account email address cannot be changed.']);

    expect($user->fresh()->getAttributes())->toBe($original);
})->with(['customer', 'staff', 'manager', 'owner', 'it']);

test('profile rejects invalid email replacements', function (mixed $email) {
    $user = User::factory()->customer()->create(['email' => 'customer@example.test']);
    User::factory()->customer()->create(['email' => 'taken@example.test']);
    $original = $user->refresh()->getAttributes();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Changed name',
        'email' => $email,
    ])->assertSessionHasErrors(['email' => 'Your account email address cannot be changed.']);

    expect($user->fresh()->getAttributes())->toBe($original);
})->with([
    'another account' => 'taken@example.test',
    'case change' => 'CUSTOMER@example.test',
    'blank' => '',
    'null' => null,
    'array' => [['customer@example.test']],
    'number' => 123,
    'invalid address' => 'invalid',
]);

test('unverified sessions cannot update profiles or mark their email verified', function () {
    $user = User::factory()->customer()->unverified()->create();
    $name = $user->name;
    $email = $user->email;

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Updated name',
        'email_verified_at' => '2026-01-01 00:00:00',
    ])->assertRedirectToRoute('login');

    $this->assertGuest();
    expect($user->refresh()->name)->toBe($name)
        ->and($user->email)->toBe($email)
        ->and($user->email_verified_at)->toBeNull();
});

test('the shared profile action never writes email or its verification timestamp', function (bool $verified) {
    $user = User::factory()->customer()->create([
        'email_verified_at' => $verified ? now() : null,
    ]);
    $email = $user->email;
    $verifiedAt = $user->getRawOriginal('email_verified_at');

    app(UpdateProfile::class)($user, [
        'name' => 'Updated name',
        'email' => 'replacement@example.test',
        'email_verified_at' => $verified ? null : now(),
    ]);

    expect($user->refresh()->name)->toBe('Updated name')
        ->and($user->email)->toBe($email)
        ->and($user->getRawOriginal('email_verified_at'))->toBe($verifiedAt);
})->with(['verified' => true, 'unverified' => false]);

test('customer profile saves and clears a normalized default mobile number independently of its address', function () {
    $user = User::factory()->customer()->create([
        'default_delivery_address' => 'Saved address',
    ]);

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'default_delivery_contact_number' => '0917 123 4567',
    ])->assertSessionHasNoErrors();

    expect($user->refresh()->default_delivery_contact_number)->toBe('+639171234567')
        ->and($user->default_delivery_address)->toBe('Saved address');

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'default_delivery_contact_number' => '',
    ])->assertSessionHasNoErrors();

    expect($user->refresh()->default_delivery_contact_number)->toBeNull()
        ->and($user->default_delivery_address)->toBe('Saved address');
});

test('profile rejects unsupported contact numbers', function (string $number) {
    $user = User::factory()->customer()->create();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'default_delivery_contact_number' => $number,
    ])->assertSessionHasErrors(['default_delivery_contact_number']);

    expect($user->refresh()->default_delivery_contact_number)->toBeNull();
})->with(['landline' => '0347222222', 'invalid prefix' => '08171234567', 'letters' => '0917abc4567']);

test('default delivery address can be saved and updated', function () {
    $user = User::factory()->customer()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'default_delivery_address_action' => 'save',
            'default_delivery_destination' => 'Sagay City',
            'default_delivery_barangay_code' => '1804523018',
            'default_delivery_address_details' => '12 Mabini Street',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->default_delivery_address)
        ->toBe('12 Mabini Street, Poblacion I, Sagay City, Negros Occidental');

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'default_delivery_address_action' => 'save',
            'default_delivery_destination' => 'Escalante City',
            'default_delivery_barangay_code' => '1804509002',
            'default_delivery_address_details' => '45 Rizal Avenue',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->default_delivery_address)
        ->toBe('45 Rizal Avenue, Balintawak, Escalante City, Negros Occidental');
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('customers cannot change their role through profile updates', function () {
    $user = User::factory()->customer()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => UserRole::Administrator->value,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->role)->toBe(UserRole::Customer);
});

test('administrators cannot save a default delivery address', function () {
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)
        ->patch(route('profile.update'), [
            'name' => $administrator->name,
            'email' => $administrator->email,
            'default_delivery_address' => '12 Mabini Street, Sagay City',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($administrator->refresh()->default_delivery_address)->toBeNull();
});

test('customer account deletion is unavailable', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete('/settings/profile', ['password' => 'password'])
        ->assertMethodNotAllowed();

    $this->assertAuthenticatedAs($user);
    $this->assertModelExists($user);
});

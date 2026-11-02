<?php

use App\Enums\UserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DevelopmentCustomerSeeder;
use Database\Seeders\DevelopmentHistoricalSalesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\HistoricalSalesCatalogFixtures;

test('customer demonstration seeding creates exactly thirty distinct verified customers', function (string $environment) {
    $this->app->instance('env', $environment);
    $this->freezeTime();
    Event::fake(['eloquent.created: '.User::class, 'eloquent.updated: '.User::class]);

    $this->seed(DevelopmentCustomerSeeder::class);

    $this->assertDatabaseCount('users', 30);
    $customers = User::query()->orderBy('email')->get();
    expect($customers->pluck('name')->unique())->toHaveCount(30);
    expect($customers->pluck('email')->all())->toBe(array_map(
        fn (int $number): string => sprintf('demo.customer%02d@example.test', $number),
        range(1, 30),
    ));
    expect($customers->first()->name)->toBe('Miguel Santos');
    expect($customers->last()->name)->toBe('Elena Magbanua');
    foreach ($customers as $customer) {
        expect($customer->role)->toBe(UserRole::Customer);
        expect($customer->email_verified_at->toDateTimeString())->toBe(now()->toDateTimeString());
        expect($customer->password)->not->toBe('customer123');
        expect(Hash::check('customer123', $customer->password))->toBeTrue();
        expect($customer->default_delivery_address)->toBeNull();
        expect($customer->toArray())->not->toHaveKey('password');
    }
    foreach (['orders', 'order_items', 'sales', 'shipments', 'products', 'inventories', 'carts', 'customer_searches', 'customer_product_views', 'notifications'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    Event::assertNothingDispatched();
})->with(['local', 'testing']);

test('every demonstration customer can sign in with the shared password', function () {
    $this->seed(DevelopmentCustomerSeeder::class);

    foreach (User::query()->orderBy('email')->get() as $customer) {
        $this->post(route('login.store'), [
            'email' => $customer->email,
            'password' => 'customer123',
        ])->assertRedirect(route('dashboard', absolute: false))->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($customer);
        $this->post(route('logout'))->assertRedirect(route('home'));
        $this->assertGuest();
    }
});

test('demonstration seeding preserves matching accounts and their edited preferences', function () {
    $this->seed(DevelopmentCustomerSeeder::class);
    $customer = User::query()->where('email', 'demo.customer01@example.test')->sole();
    $customer->update([
        'appearance' => 'dark',
        'default_delivery_address' => 'Fictional demonstration address',
        'search_recommendations_enabled' => false,
        'product_view_recommendations_enabled' => false,
        'personalized_recommendations_enabled' => false,
    ]);
    $before = DB::table('users')->orderBy('id')->get()->all();
    $this->travel(1)->days();

    $this->seed(DevelopmentCustomerSeeder::class);

    expect(DB::table('users')->orderBy('id')->get()->all())->toEqual($before);
    $this->assertDatabaseCount('users', 30);
});

test('demonstration seeding completes a partial roster without changing its existing member', function () {
    $customer = User::factory()->customer()->create([
        'email' => 'demo.customer01@example.test',
        'name' => 'Miguel Santos',
        'password' => 'customer123',
    ]);
    $before = $customer->refresh()->getRawOriginal();

    $this->seed(DevelopmentCustomerSeeder::class);

    $this->assertDatabaseCount('users', 30);
    expect($customer->refresh()->getRawOriginal())->toBe($before);
});

test('demonstration seeding leaves administrator test and unrelated customer accounts unchanged', function () {
    $this->seed();
    User::query()->whereIn('email', ['admin@example.com', 'test@example.com'])->get()
        ->each(fn (User $user) => $user->update(['password' => 'original-private-password']));
    User::factory()->customer()->create();
    User::factory()->administrator()->create(['email' => 'demo.customer31@example.test']);
    $before = DB::table('users')->orderBy('id')->get();

    $this->seed(DevelopmentCustomerSeeder::class);
    $this->seed(DevelopmentCustomerSeeder::class);

    $this->assertDatabaseCount('users', 36);
    expect(DB::table('users')->whereIn('id', $before->pluck('id'))->orderBy('id')->get()->all())
        ->toEqual($before->all());
});

test('reserved demonstration identity conflicts preserve all records without a partial roster', function (string $change) {
    $this->seed(DevelopmentCustomerSeeder::class);
    $customer = User::query()->where('email', 'demo.customer30@example.test')->sole();
    $customer->forceFill(match ($change) {
        'name' => ['name' => 'Changed Customer'],
        'role' => ['role' => UserRole::Administrator],
        'password', 'malformed hash' => ['password' => 'changed-password'],
        'email case' => ['email' => 'DEMO.CUSTOMER30@example.test'],
        'verification' => ['email_verified_at' => null],
    })->save();
    if ($change === 'malformed hash') {
        DB::table('users')->where('id', $customer->id)->update(['password' => '$argon2id$invalid']);
    }
    User::query()->where('email', 'demo.customer01@example.test')->delete();
    $before = DB::table('users')->orderBy('id')->get()->all();

    expect(fn () => $this->seed(DevelopmentCustomerSeeder::class))
        ->toThrow(RuntimeException::class, 'Customer demonstration identity conflicts');

    expect(DB::table('users')->orderBy('id')->get()->all())->toEqual($before);
    $this->assertDatabaseMissing('users', ['email' => 'demo.customer01@example.test']);
})->with(['name', 'role', 'password', 'email case', 'verification', 'malformed hash']);

test('demonstration customer seeding rejects other environments without writes', function (string $environment) {
    User::factory()->administrator()->create();
    $before = DB::table('users')->get()->all();
    $this->app->instance('env', $environment);

    expect(fn () => $this->artisan('db:seed', ['--class' => DevelopmentCustomerSeeder::class, '--force' => true, '--no-interaction' => true])->run())
        ->toThrow(RuntimeException::class, 'only allowed in local and testing');

    expect(DB::table('users')->get()->all())->toEqual($before);
})->with(['production', 'staging']);

test('demonstration customer seeding preserves distributed historical ownership transactions and inventory', function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
    HistoricalSalesCatalogFixtures::createProducts();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $accountsBefore = DB::table('users')->orderBy('id')->get()->all();
    $tables = ['categories', 'products', 'inventories', 'orders', 'order_items', 'sales', 'shipments', 'product_tag'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $coveragePath = config('forecasting.development_manifest');
    $coverageBefore = Storage::disk('local')->get($coveragePath);

    $this->seed(DevelopmentCustomerSeeder::class);
    $this->seed(DevelopmentCustomerSeeder::class);

    expect(DB::table('users')->orderBy('id')->get()->all())->toEqual($accountsBefore);
    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($before[$table]);
    }
    expect(Storage::disk('local')->get($coveragePath))->toBe($coverageBefore);
    $this->assertDatabaseCount('users', 30);
    $this->assertDatabaseCount('notifications', 0);
});

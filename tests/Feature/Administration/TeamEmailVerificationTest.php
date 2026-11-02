<?php

use App\Mail\VerifyEmailOtp;
use App\Models\User;
use App\Services\TeamAccessService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function teamEmailAccountInput(array $overrides = []): array
{
    return [...[
        'name' => 'New Store Staff', 'email' => 'new.staff@example.test', 'role' => 'staff',
        'password' => 'CorrectHorseBatteryStaple!1', 'password_confirmation' => 'CorrectHorseBatteryStaple!1',
    ], ...$overrides];
}

test('authorized creators verify email before creating a team account and keep their own session', function (string $actorRole, string $targetRole) {
    Mail::fake();
    $actor = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', $actorRole)->value('id'),
    ]);
    $this->actingAs($actor)->postJson(route('administration.team.email-verification.store'), ['email' => 'NEW.STAFF@example.test'])
        ->assertOk()->assertJsonPath('data.email', 'new.staff@example.test')
        ->assertJsonPath('data.status', 'verification_required')->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonMissingPath('data.code')->assertJsonMissingPath('data.code_hash');
    $mail = Mail::sent(VerifyEmailOtp::class)->sole();
    expect($mail->code)->toMatch('/\A[0-9]{6}\z/');
    expect($mail->forTeamAccount)->toBeTrue();
    $this->assertDatabaseMissing('users', ['email' => 'new.staff@example.test']);

    $this->postJson(route('administration.team.email-verification.verify'), ['email' => 'new.staff@example.test', 'code' => $mail->code])
        ->assertOk()->assertJsonPath('data.status', 'verified');
    $this->assertDatabaseMissing('users', ['email' => 'new.staff@example.test']);
    $this->get(route('administration.team.index'))->assertInertia(fn (Assert $page) => $page
        ->where('teamEmailVerification.status', 'verified')->missing('teamEmailVerification.code_hash'));

    $this->post(route('administration.team.store'), teamEmailAccountInput(['role' => $targetRole]))
        ->assertSessionHasNoErrors();

    $newUser = User::query()->where('email', 'new.staff@example.test')->sole();
    expect($newUser->email_verified_at)->not->toBeNull();
    expect($newUser->administrationRoleCode())->toBe($targetRole);
    $this->assertAuthenticatedAs($actor);
    $this->assertDatabaseHas('audit_logs', ['actor_id' => $actor->id, 'action' => 'team.created', 'subject_id' => $newUser->id]);
    $this->get(route('administration.team.index'))->assertInertia(fn (Assert $page) => $page->where('teamEmailVerification', null));
    $this->assertDatabaseCount('personal_access_tokens', 0);
    Mail::assertSentCount(1);
})->with([
    ['owner', 'staff'], ['owner', 'manager'], ['owner', 'owner'],
]);

test('team creation rejects missing pending and expired verification proofs', function (string $state) {
    Mail::fake();
    $this->freezeTime();
    $actor = User::factory()->administrator()->create();
    $this->actingAs($actor);
    if ($state !== 'missing') {
        $this->postJson(route('administration.team.email-verification.store'), ['email' => 'new.staff@example.test'])->assertOk();
        if ($state === 'expired') {
            $this->postJson(route('administration.team.email-verification.verify'), [
                'email' => 'new.staff@example.test', 'code' => Mail::sent(VerifyEmailOtp::class)->sole()->code,
            ])->assertOk();
            $this->travel(10)->minutes();
        }
    }

    $this->post(route('administration.team.store'), teamEmailAccountInput(['email_verified_at' => now()->toDateTimeString(), 'verified' => true]))
        ->assertSessionHasErrors('email');

    $this->assertDatabaseMissing('users', ['email' => 'new.staff@example.test']);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'team.created']);
    $this->assertAuthenticatedAs($actor);
    Mail::assertSentCount($state === 'missing' ? 0 : 1);
})->with(['missing', 'pending', 'expired']);

test('newly created team administrators can log in without another email OTP', function () {
    Mail::fake();
    $actor = User::factory()->administrator()->create();
    $this->actingAs($actor)->postJson(route('administration.team.email-verification.store'), ['email' => 'new.staff@example.test'])->assertOk();
    $this->postJson(route('administration.team.email-verification.verify'), [
        'email' => 'new.staff@example.test', 'code' => Mail::sent(VerifyEmailOtp::class)->sole()->code,
    ])->assertOk();
    $this->post(route('administration.team.store'), teamEmailAccountInput())->assertSessionHasNoErrors();
    $administrator = User::query()->where('email', 'new.staff@example.test')->sole();
    $this->post(route('logout'));

    $this->post(route('login.store'), ['email' => $administrator->email, 'password' => 'CorrectHorseBatteryStaple!1'])
        ->assertRedirectToRoute('dashboard');

    $this->assertAuthenticatedAs($administrator);
    Mail::assertSentCount(1);
});

test('an address claimed after OTP confirmation cannot be overwritten during team creation', function () {
    Mail::fake();
    $actor = User::factory()->administrator()->create();
    $this->actingAs($actor)->postJson(route('administration.team.email-verification.store'), ['email' => 'new.staff@example.test'])->assertOk();
    $this->postJson(route('administration.team.email-verification.verify'), [
        'email' => 'new.staff@example.test', 'code' => Mail::sent(VerifyEmailOtp::class)->sole()->code,
    ])->assertOk();
    $customer = User::factory()->customer()->unverified()->create(['email' => 'new.staff@example.test']);

    expect(fn () => app(TeamAccessService::class)->create($actor, teamEmailAccountInput(), app('session.store')))
        ->toThrow(ValidationException::class);

    expect($customer->fresh()->email_verified_at)->toBeNull();
    expect($customer->fresh()->isAdministrator())->toBeFalse();
    $this->assertDatabaseCount('users', 2);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'team.created']);
    Mail::assertSentCount(1);
});

test('team verification preserves hashed codes and enforces attempt limits', function () {
    Mail::fake();
    $actor = User::factory()->administrator()->create();
    $this->actingAs($actor)->postJson(route('administration.team.email-verification.store'), ['email' => 'new.staff@example.test'])->assertOk();
    $code = Mail::sent(VerifyEmailOtp::class)->sole()->code;
    $key = 'team-email:context:'.hash('sha256', $actor->id.'|'.session('team_email_verification.scope'));
    $state = Cache::get($key);
    expect($state['code_hash'])->not->toBe($code);
    expect(Hash::check($code, $state['code_hash']))->toBeTrue();
    expect(json_encode($state))->not->toContain('"code":"'.$code.'"');
    $wrongCode = $code === '000000' ? '000001' : '000000';

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson(route('administration.team.email-verification.verify'), ['email' => 'new.staff@example.test', 'code' => $wrongCode])
            ->assertUnprocessable()->assertJsonValidationErrors('code')->assertJsonPath('data.attempts_remaining', 4 - $attempt);
    }
    $this->postJson(route('administration.team.email-verification.verify'), ['email' => 'new.staff@example.test', 'code' => $code])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->post(route('administration.team.store'), teamEmailAccountInput())->assertSessionHasErrors('email');

    $this->assertDatabaseMissing('users', ['email' => 'new.staff@example.test']);
    Mail::assertSentCount(1);
});

test('team OTP accepts leading zeroes and cannot be verified twice', function () {
    Mail::fake();
    $actor = User::factory()->administrator()->create();
    $this->actingAs($actor)->postJson(route('administration.team.email-verification.store'), ['email' => 'new.staff@example.test'])->assertOk();
    $key = 'team-email:context:'.hash('sha256', $actor->id.'|'.session('team_email_verification.scope'));
    $state = Cache::get($key);
    $state['code_hash'] = Hash::make('012345');
    Cache::put($key, $state, 1800);

    $this->postJson(route('administration.team.email-verification.verify'), ['email' => 'new.staff@example.test', 'code' => '012345'])
        ->assertOk()->assertJsonPath('data.status', 'verified');
    expect(Cache::get($key)['code_hash'])->toBeNull();
    $this->postJson(route('administration.team.email-verification.verify'), ['email' => 'new.staff@example.test', 'code' => '012345'])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    Mail::assertSentCount(1);
});

test('team resend enforces cooldown and invalidates the old code', function () {
    Mail::fake();
    $this->freezeTime();
    $actor = User::factory()->administrator()->create();
    $this->actingAs($actor)->postJson(route('administration.team.email-verification.store'), ['email' => 'new.staff@example.test'])->assertOk();
    $oldCode = Mail::sent(VerifyEmailOtp::class)->sole()->code;
    $this->postJson(route('administration.team.email-verification.store'), ['email' => 'new.staff@example.test'])
        ->assertTooManyRequests()->assertHeader('Retry-After', '60');
    $this->travel(60)->seconds();
    $this->postJson(route('administration.team.email-verification.store'), ['email' => 'new.staff@example.test'])->assertOk();
    $this->postJson(route('administration.team.email-verification.verify'), ['email' => 'new.staff@example.test', 'code' => $oldCode])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson(route('administration.team.email-verification.verify'), ['email' => 'new.staff@example.test', 'code' => Mail::sent(VerifyEmailOtp::class)->last()->code])
        ->assertOk()->assertJsonPath('data.status', 'verified');
    Mail::assertSentCount(2);
});

test('expired team codes and abandoned contexts cannot verify', function (int $minutes) {
    Mail::fake();
    $this->freezeTime();
    $actor = User::factory()->administrator()->create();
    $this->actingAs($actor)->postJson(route('administration.team.email-verification.store'), ['email' => 'new.staff@example.test'])->assertOk();
    $this->travel($minutes)->minutes();

    $this->postJson(route('administration.team.email-verification.verify'), ['email' => 'new.staff@example.test', 'code' => Mail::sent(VerifyEmailOtp::class)->sole()->code])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->assertDatabaseMissing('users', ['email' => 'new.staff@example.test']);
    Mail::assertSentCount(1);
})->with([5, 30]);

test('clearing email invalidates proof without resetting email cooldown', function () {
    Mail::fake();
    $actor = User::factory()->administrator()->create();
    $this->actingAs($actor)->postJson(route('administration.team.email-verification.store'), ['email' => 'new.staff@example.test'])->assertOk();
    $code = Mail::sent(VerifyEmailOtp::class)->sole()->code;
    $this->postJson(route('administration.team.email-verification.verify'), ['email' => 'new.staff@example.test', 'code' => $code])->assertOk();
    $this->deleteJson(route('administration.team.email-verification.destroy'), ['email' => 'new.staff@example.test'])->assertOk();

    $this->post(route('administration.team.store'), teamEmailAccountInput())->assertSessionHasErrors('email');
    $this->postJson(route('administration.team.email-verification.store'), ['email' => 'new.staff@example.test'])->assertTooManyRequests();
    $this->assertDatabaseMissing('users', ['email' => 'new.staff@example.test']);
    Mail::assertSentCount(1);
});

test('team verification proof cannot transfer to a different email creator or session', function (string $change) {
    Mail::fake();
    $actor = User::factory()->administrator()->create();
    $this->actingAs($actor)->postJson(route('administration.team.email-verification.store'), ['email' => 'new.staff@example.test'])->assertOk();
    $this->postJson(route('administration.team.email-verification.verify'), ['email' => 'new.staff@example.test', 'code' => Mail::sent(VerifyEmailOtp::class)->sole()->code])->assertOk();
    if ($change === 'creator') {
        $this->actingAs(User::factory()->administrator()->create());
    } elseif ($change === 'session') {
        session()->flush();
    }

    $this->post(route('administration.team.store'), teamEmailAccountInput($change === 'email' ? ['email' => 'different@example.test'] : []))
        ->assertSessionHasErrors('email');
    $this->assertDatabaseMissing('users', ['email' => 'new.staff@example.test']);
    $this->assertDatabaseMissing('users', ['email' => 'different@example.test']);
    Mail::assertSentCount(1);
})->with(['email', 'creator', 'session']);

test('normal account validation preserves an unexpired proof and successful creation consumes it', function () {
    Mail::fake();
    $actor = User::factory()->administrator()->create();
    $this->actingAs($actor)->postJson(route('administration.team.email-verification.store'), ['email' => 'new.staff@example.test'])->assertOk();
    $this->postJson(route('administration.team.email-verification.verify'), ['email' => 'new.staff@example.test', 'code' => Mail::sent(VerifyEmailOtp::class)->sole()->code])->assertOk();
    $this->post(route('administration.team.store'), teamEmailAccountInput(['password_confirmation' => 'different']))->assertSessionHasErrors('password');
    $this->get(route('administration.team.index'))->assertInertia(fn (Assert $page) => $page->where('teamEmailVerification.status', 'verified'));
    $this->post(route('administration.team.store'), teamEmailAccountInput())->assertSessionHasNoErrors();
    $this->post(route('administration.team.store'), teamEmailAccountInput())->assertSessionHasErrors('email');
    $this->post(route('administration.team.store'), teamEmailAccountInput(['email' => 'another@example.test']))->assertSessionHasErrors('email');
    $this->assertDatabaseCount('users', 2);
    $this->assertDatabaseCount('audit_logs', 1);
    Mail::assertSentCount(1);
});

test('delivery failure exposes no private error and cannot verify or create a team account', function () {
    Mail::shouldReceive('to')->once()->with('new.staff@example.test')->andReturnSelf();
    Mail::shouldReceive('send')->once()->with(Mockery::type(VerifyEmailOtp::class))->andThrow(new RuntimeException('private-mail-sentinel'));
    $actor = User::factory()->administrator()->create();

    $this->actingAs($actor)->postJson(route('administration.team.email-verification.store'), ['email' => 'new.staff@example.test'])
        ->assertServiceUnavailable()->assertJsonPath('data.delivery_status', 'failed')->assertJsonPath('data.expires_at', null)->assertDontSee('private-mail-sentinel');
    $this->postJson(route('administration.team.email-verification.verify'), ['email' => 'new.staff@example.test', 'code' => '012345'])->assertUnprocessable();
    $this->post(route('administration.team.store'), teamEmailAccountInput())->assertSessionHasErrors('email');
    $this->postJson(route('administration.team.email-verification.store'), ['email' => 'new.staff@example.test'])->assertTooManyRequests();
    $this->assertDatabaseMissing('users', ['email' => 'new.staff@example.test']);
});

test('email issuance budget survives clearing the form', function () {
    Mail::fake();
    $this->freezeTime();
    $actor = User::factory()->administrator()->create();
    $this->actingAs($actor);
    for ($issued = 0; $issued < 5; $issued++) {
        $this->postJson(route('administration.team.email-verification.store'), ['email' => 'new.staff@example.test'])->assertOk();
        $this->deleteJson(route('administration.team.email-verification.destroy'), ['email' => 'new.staff@example.test'])->assertOk();
        $this->travel(60)->seconds();
    }

    $this->postJson(route('administration.team.email-verification.store'), ['email' => 'new.staff@example.test'])->assertTooManyRequests();
    Mail::assertSentCount(5);
});

test('team sending and checking enforce creator and IP budgets', function (string $budget) {
    Mail::fake();
    $actor = User::factory()->administrator()->create();
    $this->actingAs($actor);
    $key = match ($budget) {
        'actor' => 'team-email:send:actor:'.$actor->id,
        'send IP' => 'team-email:send:ip:'.hash('sha256', '127.0.0.1'),
        'verify IP' => 'team-email:verify:ip:'.hash('sha256', '127.0.0.1'),
        'verify email' => 'team-email:verify:email:'.hash('sha256', 'new.staff@example.test'),
    };
    $maximum = match ($budget) {
        'verify IP' => 30, 'verify email' => 10, default => 20
    };
    RateLimiter::increment($key, 3600, $maximum);

    $route = str_starts_with($budget, 'verify') ? 'verify' : 'store';
    $this->postJson(route('administration.team.email-verification.'.$route), ['email' => 'new.staff@example.test', 'code' => '123456'])
        ->assertTooManyRequests()->assertHeader('Retry-After');
    Mail::assertNothingSent();
})->with(['actor', 'send IP', 'verify IP', 'verify email']);

test('unauthorized roles cannot use team email verification', function (string $role) {
    Mail::fake();
    $user = $role === 'customer' ? User::factory()->customer()->create() : User::factory()->administrator()->create([
        'administration_role_id' => ($role === 'it' ? legacyItRoleId() : DB::table('administration_roles')->where('code', $role)->value('id')),
    ]);
    $this->actingAs($user)->postJson(route('administration.team.email-verification.store'), ['email' => 'new.staff@example.test'])->assertForbidden();
    $this->postJson(route('administration.team.email-verification.verify'), ['email' => 'new.staff@example.test', 'code' => '123456'])->assertForbidden();
    $this->deleteJson(route('administration.team.email-verification.destroy'), ['email' => 'new.staff@example.test'])->assertForbidden();
    Mail::assertNothingSent();
})->with(['customer', 'staff', 'manager', 'it']);

test('the team service requires verification even when called directly', function () {
    $actor = User::factory()->administrator()->create();

    expect(fn () => app(TeamAccessService::class)->create($actor, teamEmailAccountInput(), app('session.store')))->toThrow(ValidationException::class);
    $this->assertDatabaseMissing('users', ['email' => 'new.staff@example.test']);
});

test('the named team request limiter returns JSON retry guidance without sending email', function () {
    Mail::fake();
    $this->freezeTime();
    $actor = User::factory()->administrator()->create();
    $this->actingAs($actor);
    for ($request = 0; $request < 30; $request++) {
        $this->deleteJson(route('administration.team.email-verification.destroy'), ['email' => 'new.staff@example.test'])->assertOk();
    }

    $this->postJson(route('administration.team.email-verification.store'), ['email' => 'new.staff@example.test'])
        ->assertTooManyRequests()->assertJsonPath('retry_after', 60)
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Retry-After', '60');
    Mail::assertNothingSent();
});

test('a role change after verification cannot grant IT permission to create an owner', function () {
    Mail::fake();
    $actor = User::factory()->administrator()->create();
    $this->actingAs($actor)->postJson(route('administration.team.email-verification.store'), ['email' => 'new.staff@example.test'])->assertOk();
    $this->postJson(route('administration.team.email-verification.verify'), ['email' => 'new.staff@example.test', 'code' => Mail::sent(VerifyEmailOtp::class)->sole()->code])->assertOk();
    $actor->forceFill(['administration_role_id' => legacyItRoleId()])->save();

    $this->post(route('administration.team.store'), teamEmailAccountInput(['role' => 'owner']))->assertForbidden();
    $this->assertDatabaseMissing('users', ['email' => 'new.staff@example.test']);
    Mail::assertSentCount(1);
});

test('existing addresses cannot be verified or overwritten through team creation', function () {
    Mail::fake();
    $actor = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->unverified()->create(['email' => 'new.staff@example.test']);

    $this->actingAs($actor)->postJson(route('administration.team.email-verification.store'), ['email' => $customer->email])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->post(route('administration.team.store'), teamEmailAccountInput())->assertSessionHasErrors('email');
    expect($customer->fresh()->email_verified_at)->toBeNull();
    expect($customer->fresh()->isAdministrator())->toBeFalse();
    Mail::assertNothingSent();
});

test('a cache lock held by another request prevents concurrent creation', function () {
    Mail::fake();
    $actor = User::factory()->administrator()->create();
    $this->actingAs($actor)->postJson(route('administration.team.email-verification.store'), ['email' => 'new.staff@example.test'])->assertOk();
    $this->postJson(route('administration.team.email-verification.verify'), ['email' => 'new.staff@example.test', 'code' => Mail::sent(VerifyEmailOtp::class)->sole()->code])->assertOk();
    $key = 'team-email:context:'.hash('sha256', $actor->id.'|'.session('team_email_verification.scope'));
    $lock = Cache::lock('team-email:lock:'.hash('sha256', $key), 60);
    expect($lock->get())->toBeTrue();

    try {
        $this->post(route('administration.team.store'), teamEmailAccountInput())->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'new.staff@example.test']);
    } finally {
        $lock->release();
    }
    $this->post(route('administration.team.store'), teamEmailAccountInput())->assertSessionHasNoErrors();
    Mail::assertSentCount(1);
});

<?php

namespace App\Providers;

use App\Actions\Cart\ResumePendingCartIntent;
use App\Actions\Fortify\AttemptVerifiedAuthentication;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Wishlist\ResumePendingWishlistIntent;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Responses\PendingEmailVerificationResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\RegisteredUserController as FortifyRegisteredUserController;
use Laravel\Fortify\Http\Responses\LoginResponse;
use Symfony\Component\HttpFoundation\Response;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FortifyRegisteredUserController::class, RegisteredUserController::class);
        $this->app->bind(AttemptToAuthenticate::class, AttemptVerifiedAuthentication::class);

        $this->app->singleton(LoginResponseContract::class, fn (Application $app) => new class($app->make(ResumePendingCartIntent::class), $app->make(ResumePendingWishlistIntent::class)) extends LoginResponse
        {
            public function __construct(
                private ResumePendingCartIntent $resumePendingCartIntent,
                private ResumePendingWishlistIntent $resumePendingWishlistIntent,
            ) {}

            /** @param Request $request */
            public function toResponse(mixed $request): Response
            {
                return $this->resumePendingCartIntent->execute($request)
                    ?? $this->resumePendingWishlistIntent->execute($request)
                    ?? parent::toResponse($request);
            }
        });

        $this->app->singleton(RegisterResponseContract::class, PendingEmailVerificationResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', Str::lower((string) $request->input('email')))->first();

            return $user !== null && $user->is_active && Hash::check((string) $request->input('password'), $user->password)
                ? $user : null;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('registration', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('ip:'.$request->ip()));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

    }
}

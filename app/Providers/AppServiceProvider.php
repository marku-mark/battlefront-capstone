<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->monitorRecommendationQueryTime();
        DevCommands::artisan('queue:listen --queue=notifications,default --tries=1 --timeout=0', 'queue');

        Gate::define(
            'access-administration',
            fn (User $user): bool => $user->isAdministrator(),
        );

        Gate::define(
            'use-customer-cart',
            fn (User $user): bool => $user->role === UserRole::Customer,
        );

        Gate::define(
            'use-chatbot',
            fn (?User $user): bool => $user === null || $user->role === UserRole::Customer,
        );

        Gate::define(
            'use-recommendations',
            fn (?User $user): bool => $user === null || $user->role === UserRole::Customer,
        );

        RateLimiter::for('api-v1', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('ip:'.$request->ip()));

        RateLimiter::for('chatbot', function (Request $request): Limit {
            $customer = $request->user();

            return Limit::perMinute($customer === null ? 5 : 10)
                ->by($customer === null ? 'guest:'.$request->ip() : 'customer:'.$customer->getKey())
                ->response(fn (Request $request, array $headers): JsonResponse => response()->json([
                    'message' => 'Too many questions. Please wait a minute and try again.',
                ], 429, $headers));
        });
    }

    /**
     * Log slow cumulative database work for recommendation requests without exposing query data.
     */
    private function monitorRecommendationQueryTime(): void
    {
        $threshold = (int) config('battlefront.recommendations.slow_query_threshold_ms', 750);

        DB::whenQueryingForLongerThan($threshold, function (Connection $connection, QueryExecuted $event) use ($threshold): void {
            $routeName = request()->route()?->getName();

            if (! in_array($routeName, [
                'dashboard',
                'products.index',
                'products.show',
                'cart.index',
                'recommendations.interactions.store',
                'api.v1.recommendations.feed',
                'api.v1.recommendations.personalized',
                'api.v1.recommendations.interactions.store',
            ], true)) {
                return;
            }

            Log::warning('Recommendation request exceeded the database query time threshold.', [
                'route' => $routeName,
                'connection' => $connection->getName(),
                'threshold_ms' => $threshold,
                'last_query_ms' => round($event->time, 2),
            ]);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(
            fn (): ?Password => app()->isProduction()
                ? Password::min(12)
                    ->mixedCase()
                    ->letters()
                    ->numbers()
                    ->symbols()
                    ->uncompromised()
                : null,
        );
    }
}

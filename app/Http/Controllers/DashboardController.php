<?php

namespace App\Http\Controllers;

use App\Actions\Recommendation\BuildRecommendationViewData;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the dashboard for the authenticated account's role.
     */
    public function __invoke(
        Request $request,
        DashboardService $dashboardService,
        BuildRecommendationViewData $buildRecommendationViewData,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        if ($user->isAdministrator()) {
            Gate::authorize('access-administration');

            $component = match ($user->administrationRoleCode()) {
                'staff' => 'Dashboard/Staff',
                'manager', 'owner' => 'Dashboard/Administration',
                default => abort(403),
            };

            return Inertia::render($component, [
                'dashboard' => Inertia::defer(fn (): array => $user->administrationRoleCode() === 'staff'
                    ? $dashboardService->staff($user)
                    : $dashboardService->administration($user), 'dashboard', rescue: true),
            ]);
        }

        $recommendationsEnabled = $user->can('use-recommendations') && $user->personalized_recommendations_enabled;
        $recommendationViewData = null;
        $resolveRecommendations = function () use (&$recommendationViewData, $user, $buildRecommendationViewData): array {
            return $recommendationViewData ??= $buildRecommendationViewData($user, hideWhenPersonalizationDisabled: true);
        };
        $recommendationProps = $recommendationsEnabled ? [] : $resolveRecommendations();

        if ($recommendationsEnabled) {
            foreach (['recommendations', 'is_personalized', 'has_featured_fallback', 'guest_recommendation_scope'] as $key) {
                $recommendationProps[$key] = Inertia::defer(fn (): mixed => $resolveRecommendations()[$key], 'recommendations', rescue: true);
            }
        }

        return Inertia::render('Dashboard/Customer', [
            'is_first_visit' => function () use ($request, $user): bool {
                if ($request->prefetch() || ! $request->isMethod('GET')) {
                    return ! $user->has_visited_dashboard;
                }

                return User::query()->whereKey($user->id)
                    ->where('has_visited_dashboard', false)
                    ->update(['has_visited_dashboard' => true]) === 1;
            },
            'dashboard' => Inertia::defer(fn (): array => $dashboardService->customer($user), 'dashboard', rescue: true),
            'recommendations_enabled' => $recommendationsEnabled,
            ...$recommendationProps,
        ]);
    }
}

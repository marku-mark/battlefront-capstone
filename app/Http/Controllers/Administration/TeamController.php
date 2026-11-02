<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TeamAccessService;
use App\Services\TeamEmailVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(Request $request, TeamAccessService $team, TeamEmailVerification $verification): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        return Inertia::render('Administration/Team', [
            ...$team->page($request->user(), $filters),
            'teamEmailVerification' => $verification->details($request->user(), $request->session()),
        ]);
    }

    public function store(Request $request, TeamAccessService $team): RedirectResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'role' => ['required', Rule::in(['staff', 'manager', 'owner'])],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);
        $team->create($request->user(), $validated, $request->session());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Administrator account created.']);

        return back();
    }

    public function update(Request $request, User $user, TeamAccessService $team): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(['staff', 'manager', 'owner'])],
            'is_active' => ['required', 'boolean'],
        ]);
        $team->update($request->user(), $user, $validated['role'], (bool) $validated['is_active']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Team access updated.']);

        return back();
    }
}

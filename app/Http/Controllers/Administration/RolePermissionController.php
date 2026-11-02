<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Services\TeamAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class RolePermissionController extends Controller
{
    public function update(Request $request, string $role, TeamAccessService $team): RedirectResponse
    {
        if ($role === 'owner') {
            throw ValidationException::withMessages(['role' => 'Owner permissions are fixed.']);
        }

        $validated = $request->validate([
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::exists('administration_permissions', 'code')],
            'edit_version' => ['required', 'integer', 'min:1'],
        ]);
        $team->replacePermissions($request->user(), $role, $validated['permissions'] ?? [], $validated['edit_version']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Role permissions updated.']);

        return back();
    }
}

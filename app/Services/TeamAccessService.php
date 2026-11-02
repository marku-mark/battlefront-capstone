<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\TeamAccessChanged;
use App\Repositories\Administration\TeamRepository;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeamAccessService
{
    public function __construct(private readonly TeamRepository $directory, private TeamEmailVerification $verification) {}

    /**
     * @param  array{name: string, email: string, password: string, role: string}  $attributes
     */
    public function create(User $actor, array $attributes, Session $session): User
    {
        $this->authorizeRoleChange($actor, null, $attributes['role']);

        return $this->verification->create($actor, $session, $attributes['email'], function (CarbonImmutable $verifiedAt) use ($actor, $attributes): User {
            return DB::transaction(function () use ($actor, $attributes, $verifiedAt): User {
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $this->authorizeRoleChange($actor, null, $attributes['role']);
                if (! $actor->is_active || ! $actor->hasVerifiedEmail()) {
                    throw new AuthorizationException;
                }
                if (User::query()->where('email', $attributes['email'])->exists()) {
                    throw ValidationException::withMessages(['email' => 'That email address already belongs to an account.']);
                }
                $roleId = $this->roleId($attributes['role']);
                $user = new User;
                $user->name = $attributes['name'];
                $user->email = $attributes['email'];
                $user->password = $attributes['password'];
                $user->role = UserRole::Administrator;
                $user->administration_role_id = $roleId;
                $user->is_active = true;
                $user->forceFill(['email_verified_at' => $verifiedAt]);
                try {
                    $user->save();
                } catch (UniqueConstraintViolationException) {
                    throw ValidationException::withMessages(['email' => 'That email address already belongs to an account.']);
                }
                $this->audit($actor, 'team.created', $user->id, ['role' => $attributes['role']]);

                return $user;
            }, attempts: 3);
        });
    }

    public function update(User $actor, User $target, string $role, bool $active): void
    {
        $this->changeAccess($actor, $target, $role, $active);
    }

    /**
     * Explicit trusted-console assignment; never exposed through a web route.
     */
    public function assignRoleFromConsole(User $target, string $role): void
    {
        $this->changeAccess(null, $target, $role, null);
    }

    public function createInitialOwner(string $name, string $email, string $password): User
    {
        return DB::transaction(function () use ($name, $email, $password): User {
            $ownerRoleId = $this->roleId('owner');
            DB::table('administration_roles')->where('id', $ownerRoleId)->lockForUpdate()->first();
            if (User::query()->where('administration_role_id', $ownerRoleId)->lockForUpdate()->first(['id']) !== null) {
                throw ValidationException::withMessages(['role' => 'An Owner account already exists. Use the existing Owner or explicitly assign an existing Administrator.']);
            }
            if (User::query()->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => 'That email address already belongs to an account. No account was changed.']);
            }

            $user = new User;
            $user->name = $name;
            $user->email = $email;
            $user->password = $password;
            $user->role = UserRole::Administrator;
            $user->administration_role_id = $ownerRoleId;
            $user->save();
            $this->audit(null, 'team.owner.created_by_cli', $user->id, ['role' => 'owner'], 'Explicit first Owner provisioning');

            return $user;
        }, attempts: 3);
    }

    private function changeAccess(?User $actor, User $target, string $role, ?bool $active): void
    {
        DB::transaction(function () use ($actor, $target, $role, $active): void {
            $ownerRoleId = $this->roleId('owner');
            $roleId = $this->roleId($role);
            DB::table('administration_roles')->where('id', $ownerRoleId)->lockForUpdate()->first();
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if ($actor !== null) {
                $this->authorizeRoleChange($actor, $locked, $role);
            }
            $active ??= $locked->is_active;

            if (! $locked->isAdministrator()) {
                throw ValidationException::withMessages(['user' => 'Only administrator accounts may be changed here.']);
            }
            if ($actor !== null && $locked->id === $actor->id && (! $active || $roleId !== $ownerRoleId)) {
                throw ValidationException::withMessages(['user' => 'You cannot deactivate or demote your own owner account.']);
            }
            if ($locked->is_active && $locked->administration_role_id === $ownerRoleId
                && (! $active || $roleId !== $ownerRoleId)
                && User::query()->where('role', UserRole::Administrator->value)->where('is_active', true)
                    ->where('administration_role_id', $ownerRoleId)->where('id', '!=', $locked->id)
                    ->lockForUpdate()->first(['id']) === null) {
                throw ValidationException::withMessages(['user' => 'The last active owner cannot be removed.']);
            }

            $before = ['role' => $locked->administrationRoleCode(), 'is_active' => $locked->is_active];
            $accessChanged = $locked->administration_role_id !== $roleId || $locked->is_active !== $active;
            $locked->administration_role_id = $roleId;
            $locked->is_active = $active;
            if ($accessChanged) {
                $locked->remember_token = null;
            }
            $locked->save();

            if ($accessChanged) {
                DB::table('sessions')->where('user_id', $locked->id)->delete();
                $locked->tokens()->delete();
            }

            $this->audit($actor, $actor === null ? 'team.role.assigned_by_cli' : 'team.updated', $locked->id,
                ['before' => $before, 'after' => ['role' => $role, 'is_active' => $active]],
                $actor === null ? 'Explicit existing administrator rollout' : null);
        }, attempts: 3);
    }

    /**
     * @param  list<string>  $codes
     */
    public function replacePermissions(User $actor, string $role, array $codes, int $editVersion): void
    {
        if ($role === 'owner') {
            throw ValidationException::withMessages(['role' => 'Owner permissions are fixed.']);
        }

        DB::transaction(function () use ($actor, $role, $codes, $editVersion): void {
            $roleId = $this->roleId($role);
            $lockedRole = DB::table('administration_roles')->where('id', $roleId)->lockForUpdate()->first();
            if ($lockedRole === null || (int) $lockedRole->edit_version !== $editVersion) {
                throw ValidationException::withMessages(['edit_version' => 'These permissions changed while you were editing. Reload and review the latest grants before saving.']);
            }
            $permissions = DB::table('administration_permissions')->whereIn('code', $codes)->pluck('id', 'code');
            if ($permissions->count() !== count(array_unique($codes))
                || ($role === 'staff' && array_diff($codes, config('administration.staff_allowed_permissions', [])) !== [])
                || array_intersect($codes, ['users.manage', 'permissions.manage', 'audit.export']) !== []) {
                throw ValidationException::withMessages(['permissions' => 'The selected permissions are invalid for this role.']);
            }

            $before = DB::table('administration_role_permissions as grants')
                ->join('administration_permissions as permissions', 'permissions.id', '=', 'grants.administration_permission_id')
                ->where('grants.administration_role_id', $roleId)->pluck('permissions.code')->all();

            DB::table('administration_role_permissions')->where('administration_role_id', $roleId)->delete();
            foreach ($permissions as $permissionId) {
                DB::table('administration_role_permissions')->insert([
                    'administration_role_id' => $roleId, 'administration_permission_id' => $permissionId,
                ]);
            }

            DB::table('administration_roles')->where('id', $roleId)->update([
                'edit_version' => $editVersion + 1,
                'updated_at' => now(),
            ]);

            $this->audit($actor, 'role.permissions.updated', $roleId, ['before' => $before, 'after' => $codes]);
        }, attempts: 3);
    }

    /**
     * @return positive-int
     */
    private function roleId(string $code): int
    {
        if (! in_array($code, ['owner', 'manager', 'staff'], true)) {
            throw ValidationException::withMessages(['role' => 'Unknown administrator role.']);
        }

        $id = DB::table('administration_roles')->where('code', $code)->value('id');
        if ($id === null) {
            throw ValidationException::withMessages(['role' => 'Unknown administrator role.']);
        }

        $id = (int) $id;
        if ($id < 1) {
            throw ValidationException::withMessages(['role' => 'Unknown administrator role.']);
        }

        return $id;
    }

    private function authorizeRoleChange(User $actor, ?User $target, string $role): void
    {
        if (! $actor->hasAdministrationPermission('users.manage')) {
            throw new AuthorizationException;
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function audit(?User $actor, string $action, int $subjectId, array $changes, ?string $reason = null): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $actor === null ? 'user' : 'administrator_account',
            'subject_id' => $subjectId,
            'reason' => $reason,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        if ($actor === null) {
            return;
        }

        DB::afterCommit(function () use ($actor, $action, $subjectId): void {
            User::query()->where('role', UserRole::Administrator->value)
                ->where('administration_role_id', $this->roleId('owner'))
                ->where('is_active', true)->get()
                ->each(fn (User $owner) => $owner->notify(new TeamAccessChanged($action, $actor->name, $subjectId)));
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function page(User $actor, array $filters): array
    {
        $directory = $this->directory;
        $isOwner = $actor->administrationRoleCode() === 'owner';
        $users = $directory->users($filters);
        $userIds = array_map('intval', array_values($users->getCollection()->modelKeys()));
        $assignedOrderCounts = $directory->assignedOrderCounts($userIds);
        $assignedOrders = $actor->can('orders.view') ? $directory->assignedOrders($userIds) : collect();
        $users->through(fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->administrationRoleCode(),
            'can_edit' => $isOwner || in_array($user->administrationRoleCode(), ['staff', 'manager'], true),
            'is_active' => $user->is_active,
            'open_order_count' => (int) ($assignedOrderCounts[$user->id] ?? 0),
            'open_order_ids' => $assignedOrders[$user->id] ?? [],
        ]);

        return [
            'users' => $users,
            'filters' => $filters,
            'canViewOrders' => $actor->can('orders.view'),
            'canAssignOrders' => $actor->can('orders.assign'),
            'availableRoles' => $isOwner ? ['staff', 'manager', 'owner'] : ['staff', 'manager'],
            'canManagePermissions' => $actor->can('permissions.manage'),
            'defaultPermissions' => $actor->can('permissions.manage') ? config('administration.default_permissions') : [],
            'staffAllowedPermissions' => $actor->can('permissions.manage') ? config('administration.staff_allowed_permissions') : [],
            'permissions' => $actor->can('permissions.manage') ? $directory->permissions() : [],
            'grants' => $actor->can('permissions.manage') ? $directory->grants() : [],
            'roleVersions' => $actor->can('permissions.manage') ? $directory->roleVersions() : [],
        ];
    }
}

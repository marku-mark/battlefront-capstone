<?php

namespace App\Repositories\Administration;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TeamRepository
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function users(array $filters): LengthAwarePaginator
    {
        return User::query()->where('role', 'administrator')
            ->select(['id', 'name', 'email', 'administration_role_id', 'is_active'])
            ->when($filters['q'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')->orderBy('id')->paginate(25)->withQueryString();
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, int>
     */
    public function assignedOrderCounts(array $userIds): Collection
    {
        return DB::table('orders')->whereIn('status', ['pending', 'processing'])
            ->whereIn('assigned_handler_id', $userIds)->groupBy('assigned_handler_id')
            ->selectRaw('assigned_handler_id, count(*) as total')->pluck('total', 'assigned_handler_id');
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int|string, list<int>>
     */
    public function assignedOrders(array $userIds): Collection
    {
        return DB::table('orders')->whereIn('status', ['pending', 'processing'])
            ->whereIn('assigned_handler_id', $userIds)->orderBy('created_at')->orderBy('id')
            ->get(['id', 'assigned_handler_id'])->groupBy('assigned_handler_id')
            ->map(fn (Collection $orders): array => array_map('intval', array_values($orders->take(5)->pluck('id')->all())));
    }

    /** @return Collection<int, \stdClass> */
    public function permissions(): Collection
    {
        return DB::table('administration_permissions')->orderBy('code')->get(['code', 'name']);
    }

    /** @return Collection<int, \stdClass> */
    public function grants(): Collection
    {
        return DB::table('administration_role_permissions as grants')
            ->join('administration_roles as roles', 'roles.id', '=', 'grants.administration_role_id')
            ->join('administration_permissions as permissions', 'permissions.id', '=', 'grants.administration_permission_id')
            ->whereIn('roles.code', ['owner', 'manager', 'staff'])
            ->get(['roles.code as role', 'permissions.code as permission']);
    }

    /** @return Collection<string, int> */
    public function roleVersions(): Collection
    {
        return DB::table('administration_roles')->whereIn('code', ['staff', 'manager'])->pluck('edit_version', 'code');
    }
}

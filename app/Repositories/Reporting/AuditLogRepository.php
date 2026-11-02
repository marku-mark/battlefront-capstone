<?php

namespace App\Repositories\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AuditLogRepository
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function query(array $filters, bool $canViewCosts = true): Builder
    {
        return DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_id')
            ->select('audit_logs.*', 'users.name as actor_name')
            ->addSelect(DB::raw("CASE WHEN audit_logs.actor_id IS NULL OR users.id IS NULL THEN 'system' WHEN users.role = 'administrator' THEN 'administrator' ELSE 'customer' END as actor_type"))
            ->when($filters['q'] ?? null, function (Builder $query, string $search) use ($canViewCosts): void {
                $query->where(function (Builder $matches) use ($search, $canViewCosts): void {
                    $matches->where('users.name', 'like', '%'.$search.'%')
                        ->orWhere('audit_logs.action', 'like', '%'.$search.'%')
                        ->orWhere('audit_logs.subject_type', 'like', '%'.$search.'%')
                        ->orWhere(function (Builder $textMatches) use ($search, $canViewCosts): void {
                            $textMatches->where(function (Builder $textMatches) use ($search): void {
                                $textMatches->where('audit_logs.reason', 'like', '%'.$search.'%')
                                    ->orWhere('audit_logs.changes', 'like', '%'.$search.'%');
                            });

                            if (! $canViewCosts) {
                                $textMatches->where(function (Builder $visible): void {
                                    $visible->where('audit_logs.subject_type', '!=', 'product')
                                        ->orWhere('audit_logs.action', '!=', 'product.updated')
                                        ->orWhere('audit_logs.changes', 'not like', '%acquisition_cost%');
                                });
                            }
                        });
                });
            })
            ->when($filters['action'] ?? null, fn ($query, $action) => $query->where('audit_logs.action', $action))
            ->when($filters['actor_id'] ?? null, fn ($query, $actor) => $query->where('audit_logs.actor_id', $actor))
            ->when($filters['actor_type'] ?? null, fn ($query, $type) => match ($type) {
                'system' => $query->where(function (Builder $query): void {
                    $query->whereNull('audit_logs.actor_id')
                        ->orWhereNull('users.id');
                }),
                'customer' => $query->where('users.role', 'customer'),
                'administrator' => $query->where('users.role', 'administrator'),
                default => $query,
            })
            ->when($filters['subject_type'] ?? null, fn ($query, $type) => $query->where('audit_logs.subject_type', $type))
            ->when($filters['subject_id'] ?? null, fn ($query, $id) => $query->where('audit_logs.subject_id', $id))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('audit_logs.created_at', '>=', $this->dateBoundary($from)))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('audit_logs.created_at', '<', $this->dateBoundary(CarbonImmutable::parse($to)->addDay()->toDateString())))
            ->orderByDesc('audit_logs.id');
    }

    private function dateBoundary(string $date): string
    {
        return CarbonImmutable::parse($date, config('battlefront.timezone'))
            ->startOfDay()->utc()->toDateTimeString();
    }

    /** @return array<string, mixed> */
    public function lookups(): array
    {
        return [
            'actors' => DB::table('users')->leftJoin('administration_roles', 'administration_roles.id', '=', 'users.administration_role_id')
                ->whereIn('users.id', DB::table('audit_logs')->whereNotNull('actor_id')->select('actor_id'))
                ->orderBy('users.name')->orderBy('users.id')
                ->get(['users.id', 'users.name', 'users.role', 'administration_roles.name as administration_role']),
            'actions' => DB::table('audit_logs')->distinct()->orderBy('action')->pluck('action'),
            'subjectTypes' => DB::table('audit_logs')->distinct()->orderBy('subject_type')->pluck('subject_type'),
        ];
    }
}

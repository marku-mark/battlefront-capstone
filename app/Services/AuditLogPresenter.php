<?php

namespace App\Services;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentArrangement;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

class AuditLogPresenter
{
    /** @return array<string, mixed> */
    public function present(stdClass $entry, User $viewer): array
    {
        $detailsVisible = $this->canViewDetails($viewer, $entry->subject_type, $entry->action);
        $changes = $detailsVisible && is_string($entry->changes)
            ? json_decode($entry->changes, true) : null;
        $containsCostChange = is_array($changes) && $this->containsAcquisitionCost($changes);
        if (is_array($changes) && ! $viewer->can('products.cost')) {
            $changes = $this->withoutAcquisitionCost($changes);
        }

        return [
            'id' => (int) $entry->id,
            'created_at' => CarbonImmutable::parse($entry->created_at, 'UTC')->utc()->toISOString(),
            'actor_id' => $entry->actor_id === null ? null : (int) $entry->actor_id,
            'actor_name' => $entry->actor_name ?? 'System or removed account',
            'actor_type' => $entry->actor_type,
            'action' => $entry->action,
            'action_label' => $entry->action === 'order.status.updated'
                && $entry->actor_type === 'customer'
                && (json_decode($entry->changes, true)['after'] ?? null) === 'cancelled'
                    ? 'Customer cancelled order'
                    : Str::headline(str_replace('.', ' ', $entry->action)),
            'subject_type' => $entry->subject_type,
            'subject_label' => Str::headline(str_replace('_', ' ', $entry->subject_type)),
            'subject_id' => $entry->subject_id === null ? null : (int) $entry->subject_id,
            'subject_url' => $this->subjectUrl($viewer, $entry->subject_type, $entry->subject_id),
            'reason' => $detailsVisible && (! $containsCostChange || $viewer->can('products.cost')) ? $entry->reason : null,
            'details_visible' => $detailsVisible,
            'details' => is_array($changes) ? $this->details($changes, $entry->action) : [],
            'has_changes' => $entry->changes !== null,
        ];
    }

    private function canViewDetails(User $viewer, string $subjectType, string $action): bool
    {
        $permission = match ($subjectType) {
            'order', 'customer_return', 'customer_return_request', 'customer_refund', 'refund_payment' => 'orders.view',
            'inventory', 'inventory_adjustment_request', 'damage_report', 'supplier_return' => 'inventory.view',
            'product', 'chatbot_knowledge' => 'products.manage',
            'supplier' => 'suppliers.manage',
            'administrator_account' => $action === 'role.permissions.updated' ? 'permissions.manage' : 'users.manage',
            'user' => 'staff_reports.view',
            default => 'audit.export',
        };

        return $viewer->can($permission);
    }

    private function subjectUrl(User $viewer, string $subjectType, ?int $subjectId): ?string
    {
        if ($subjectId === null || ! $this->canViewDetails($viewer, $subjectType, '')) {
            return null;
        }

        return match ($subjectType) {
            'order' => route('administration.orders.show', $subjectId),
            'product' => route('administration.products.show', $subjectId),
            'inventory_adjustment_request' => route('administration.inventory-adjustments.index', ['case' => $subjectId]),
            'damage_report' => route('administration.damage-reports.index', ['case' => $subjectId]),
            'supplier_return' => route('administration.supplier-returns.index', ['case' => $subjectId]),
            'customer_return' => route('administration.customer-returns.index', ['case' => $subjectId]),
            'customer_return_request' => route('administration.customer-returns.index', ['request_id' => $subjectId]),
            'refund_payment' => $this->refundPaymentUrl($subjectId),
            default => null,
        };
    }

    private function refundPaymentUrl(int $paymentId): string
    {
        $orderId = DB::table('customer_refund_payments as payments')
            ->join('customer_refunds as refunds', 'refunds.id', '=', 'payments.customer_refund_id')
            ->join('order_cancellation_requests as cancellations', 'cancellations.id', '=', 'refunds.cancellation_request_id')
            ->where('payments.id', $paymentId)->value('cancellations.order_id');

        return $orderId === null ? route('administration.customer-returns.index', ['payment_id' => $paymentId])
            : route('administration.orders.show', $orderId);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return list<array{label: string, before: string|null, after: string}>
     */
    private function details(array $changes, string $action): array
    {
        $before = is_array($changes['before'] ?? null) ? $changes['before'] : [];
        $after = is_array($changes['after'] ?? null) ? $changes['after'] : [];
        $rows = [];

        if (! is_array($changes['before'] ?? null) && ! is_array($changes['after'] ?? null)
            && (array_key_exists('before', $changes) || array_key_exists('after', $changes))) {
            $field = match ($action) {
                'order.status.updated' => 'status',
                'order.payment_status.updated' => 'payment_status',
                default => 'value',
            };
            $rows[] = [
                'label' => match ($action) {
                    'order.status.updated' => 'Order status',
                    'order.payment_status.updated' => 'Payment status',
                    'inventory.reorder_level.updated' => 'Reorder level',
                    'order.handler.assigned' => 'Assigned handler',
                    default => 'Value',
                },
                'before' => array_key_exists('before', $changes) ? $this->value($field, $changes['before']) : null,
                'after' => array_key_exists('after', $changes) ? $this->value($field, $changes['after']) : 'Removed',
            ];
        }

        foreach ($after as $key => $value) {
            $rows[] = [
                'label' => Str::headline((string) $key),
                'before' => array_key_exists($key, $before) ? $this->value((string) $key, $before[$key]) : null,
                'after' => $this->value((string) $key, $value),
            ];
        }
        foreach ($before as $key => $value) {
            if (! array_key_exists($key, $after)) {
                $rows[] = [
                    'label' => Str::headline((string) $key),
                    'before' => $this->value((string) $key, $value),
                    'after' => 'Removed',
                ];
            }
        }
        foreach ($changes as $key => $value) {
            if (in_array($key, ['before', 'after'], true)
                && (is_array($value) || $rows !== [])) {
                continue;
            }
            $rows[] = ['label' => Str::headline((string) $key), 'before' => null,
                'after' => $this->value((string) $key, $value)];
        }

        return $rows;
    }

    private function value(string $key, mixed $value): string
    {
        if (preg_match('/password|secret|token|proof|evidence|(^|_)path$/i', $key) === 1) {
            return '[redacted]';
        }

        if ($value === null) {
            return 'None';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            $value = $this->presentNestedValues($value);
        } elseif (is_string($value)) {
            $value = $this->enumLabel($value) ?? $value;
        }

        return is_scalar($value) ? (string) $value : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[unavailable]');
    }

    /** @param array<string|int, mixed> $values
     * @return array<string|int, mixed>
     */
    private function presentNestedValues(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = preg_match('/password|secret|token|proof|evidence|(^|_)path$/i', (string) $key) === 1
                ? '[redacted]'
                : (is_array($value) ? $this->presentNestedValues($value)
                    : (is_string($value) ? ($this->enumLabel($value) ?? $value) : $value));
        }

        return $values;
    }

    /** @param array<string|int, mixed> $values */
    private function containsAcquisitionCost(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (str_contains(strtolower((string) $key), 'acquisition_cost')
                || (is_array($value) && $this->containsAcquisitionCost($value))) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string|int, mixed> $values
     * @return array<string|int, mixed>
     */
    private function withoutAcquisitionCost(array $values): array
    {
        foreach ($values as $key => $value) {
            if (str_contains(strtolower((string) $key), 'acquisition_cost')) {
                unset($values[$key]);
            } elseif (is_array($value)) {
                $values[$key] = $this->withoutAcquisitionCost($value);
            }
        }

        return $values;
    }

    private function enumLabel(string $value): ?string
    {
        return ShipmentStatus::tryFrom($value)?->label()
            ?? ShipmentArrangement::tryFrom($value)?->label()
            ?? PaymentMethod::tryFrom($value)?->label()
            ?? PaymentStatus::tryFrom($value)?->label()
            ?? OrderStatus::tryFrom($value)?->label()
            ?? FulfillmentMethod::tryFrom($value)?->label()
            ?? (UserRole::tryFrom($value) === null ? null : Str::headline($value));
    }
}

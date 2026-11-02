<?php

namespace App\Models;

use App\Enums\AppearancePreference;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property positive-int $id
 * @property positive-int|null $administration_role_id
 * @property bool $is_active
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property UserRole $role
 * @property AppearancePreference $appearance
 * @property string|null $default_delivery_address
 * @property string|null $default_delivery_contact_number
 * @property array{destination: string, city_code: string, barangay_code: string, details: string}|null $default_delivery_address_components
 * @property bool $search_recommendations_enabled
 * @property bool $product_view_recommendations_enabled
 * @property bool $personalized_recommendations_enabled
 * @property bool $has_visited_dashboard
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Cart|null $cart
 * @property-read Collection<int, Order> $orders
 */
#[Fillable(['name', 'email', 'password', 'default_delivery_address', 'default_delivery_contact_number', 'default_delivery_address_components', 'appearance', 'search_recommendations_enabled', 'product_view_recommendations_enabled', 'personalized_recommendations_enabled'])]
#[Hidden([
    'password',
    'default_delivery_address',
    'default_delivery_contact_number',
    'default_delivery_address_components',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'remember_token',
    'has_visited_dashboard',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    private ?int $cachedAdministrationRoleId = null;

    private ?Request $cachedAdministrationRequest = null;

    /** @var list<string> */
    private array $cachedAdministrationPermissions = [];

    private ?string $cachedAdministrationRoleCode = null;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'appearance' => 'light',
        'role' => 'customer',
        'is_active' => true,
        'must_change_password' => false,
        'search_recommendations_enabled' => true,
        'product_view_recommendations_enabled' => true,
        'personalized_recommendations_enabled' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'default_delivery_address_components' => 'array',
            'appearance' => AppearancePreference::class,
            'role' => UserRole::class,
            'search_recommendations_enabled' => 'boolean',
            'product_view_recommendations_enabled' => 'boolean',
            'personalized_recommendations_enabled' => 'boolean',
            'has_visited_dashboard' => 'boolean',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    /**
     * Determine whether the user may access administrator features.
     */
    public function isAdministrator(): bool
    {
        return $this->role === UserRole::Administrator;
    }

    public function hasAdministrationPermission(string $permission): bool
    {
        if (! $this->isAdministrator() || ! $this->is_active || $this->administration_role_id === null) {
            return false;
        }

        $this->cacheAdministrationRole();
        $roleCode = $this->administrationRoleCode();
        if ($roleCode === 'staff' && ! in_array($permission, config('administration.staff_allowed_permissions', []), true)) {
            return false;
        }
        if (! in_array($roleCode, ['owner', 'manager', 'staff'], true)) {
            return false;
        }
        if ($permission === 'users.manage' && $roleCode !== 'owner') {
            return false;
        }
        if (in_array($permission, ['permissions.manage', 'audit.export'], true)
            && $roleCode !== 'owner') {
            return false;
        }

        return in_array($permission, $this->cachedAdministrationPermissions, true);
    }

    public function administrationRoleCode(): ?string
    {
        if ($this->administration_role_id === null) {
            return null;
        }

        $this->cacheAdministrationRole();

        return $this->cachedAdministrationRoleCode;
    }

    private function cacheAdministrationRole(): void
    {
        $currentRequest = request();

        if ($this->cachedAdministrationRoleId === $this->administration_role_id
            && $this->cachedAdministrationRequest === $currentRequest) {
            return;
        }

        $this->cachedAdministrationRoleId = $this->administration_role_id;
        $this->cachedAdministrationRequest = $currentRequest;
        $cacheKey = 'administration-role-permissions:'.$this->administration_role_id;
        $roleAccess = request()->attributes->get($cacheKey);

        if (! is_array($roleAccess)) {
            $roleAccess = DB::table('administration_roles as roles')
                ->leftJoin('administration_role_permissions as grants', 'grants.administration_role_id', '=', 'roles.id')
                ->leftJoin('administration_permissions as permissions', 'permissions.id', '=', 'grants.administration_permission_id')
                ->where('roles.id', $this->administration_role_id)
                ->get(['roles.code as role_code', 'permissions.code as permission_code']);

            request()->attributes->set($cacheKey, [
                'role_code' => $roleAccess->first()?->role_code,
                'permissions' => $roleAccess->pluck('permission_code')->filter()->values()->all(),
            ]);
            $roleAccess = request()->attributes->get($cacheKey);
        }

        $this->cachedAdministrationRoleCode = $roleAccess['role_code'];
        $this->cachedAdministrationPermissions = $roleAccess['permissions'];
    }

    /**
     * Get the customer's cart.
     *
     * @return HasOne<Cart, $this>
     */
    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    /**
     * Get the customer's orders.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get this customer's retained catalog searches.
     *
     * @return HasMany<CustomerSearch, $this>
     */
    public function searches(): HasMany
    {
        return $this->hasMany(CustomerSearch::class);
    }

    /**
     * Get this customer's retained product views.
     *
     * @return HasMany<CustomerProductView, $this>
     */
    public function productViews(): HasMany
    {
        return $this->hasMany(CustomerProductView::class);
    }

    /**
     * Get this customer's saved products.
     *
     * @return HasMany<WishlistItem, $this>
     */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }
}

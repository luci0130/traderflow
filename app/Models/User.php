<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Documents\Models\Document;
use App\Modules\Emails\Models\Email;
use App\Modules\Producers\Models\Producer;
use App\Modules\Products\Models\Product;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasDefaultTenant, HasTenants, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

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
            'two_factor_confirmed_at' => 'datetime',
            'tutorial_preferences' => 'array',
        ];
    }

    /**
     * The keys of the guided tutorials this user has already completed.
     *
     * @return array<int, string>
     */
    public function completedTutorials(): array
    {
        return $this->tutorial_preferences['completed'] ?? [];
    }

    public function hasCompletedTutorial(string $key): bool
    {
        return in_array($key, $this->completedTutorials(), true);
    }

    public function markTutorialCompleted(string $key): void
    {
        if ($this->hasCompletedTutorial($key)) {
            return;
        }

        $preferences = $this->tutorial_preferences ?? [];
        $preferences['completed'] = [...($preferences['completed'] ?? []), $key];

        $this->tutorial_preferences = $preferences;
        $this->save();
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'producer') {
            return $this->producer_id !== null
                && $this->producer !== null
                && $this->producer->isActive()
                && $this->hasGlobalRole('producer');
        }

        return $this->producer_id === null;
    }

    public function producer(): BelongsTo
    {
        return $this->belongsTo(Producer::class);
    }

    /**
     * Check for a global (tenant-independent) role without relying on the
     * currently active spatie team context.
     */
    public function hasGlobalRole(string $roleName): bool
    {
        return DB::table(config('permission.table_names.model_has_roles'))
            ->join(config('permission.table_names.roles'), config('permission.table_names.roles').'.id', '=', config('permission.table_names.model_has_roles').'.role_id')
            ->where(config('permission.table_names.model_has_roles').'.model_type', self::class)
            ->where(config('permission.table_names.model_has_roles').'.model_id', $this->getKey())
            ->whereNull(config('permission.table_names.roles').'.tenant_id')
            ->where(config('permission.table_names.roles').'.name', $roleName)
            ->exists();
    }

    public function isSalesAgent(): bool
    {
        return $this->hasGlobalRole('sales_agent');
    }

    public function isPurchasingAgent(): bool
    {
        return $this->hasGlobalRole('purchasing_agent');
    }

    /**
     * Every user has global access and may act on behalf of any active tenant
     * (the tenant is chosen per document, not bound to the user).
     *
     * @return array<Model>|Collection<int, Tenant>
     */
    public function getTenants(Panel $panel): array|Collection
    {
        return Tenant::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return Tenant::query()->whereKey($tenant->getKey())->where('is_active', true)->exists();
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        $sessionTenantId = session('tenant_id');

        if ($sessionTenantId !== null) {
            $sessionTenant = Tenant::query()->find($sessionTenantId);

            if (($sessionTenant !== null) && $this->canAccessTenant($sessionTenant)) {
                return $sessionTenant;
            }
        }

        return $this->getTenants($panel)->first();
    }

    public function isSuperAdmin(): bool
    {
        return DB::table(config('permission.table_names.model_has_roles'))
            ->join(config('permission.table_names.roles'), config('permission.table_names.roles').'.id', '=', config('permission.table_names.model_has_roles').'.role_id')
            ->where(config('permission.table_names.model_has_roles').'.model_type', self::class)
            ->where(config('permission.table_names.model_has_roles').'.model_id', $this->getKey())
            ->where(config('permission.table_names.roles').'.name', 'super_admin')
            ->exists();
    }

    public function createdProducts(): HasMany
    {
        return $this->hasMany(Product::class, 'created_by');
    }

    public function createdSupplierOffers(): HasMany
    {
        return $this->hasMany(SupplierOffer::class, 'created_by');
    }

    public function createdCustomerOffers(): HasMany
    {
        return $this->hasMany(CustomerOffer::class, 'created_by');
    }

    public function createdSalesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'created_by');
    }

    public function uploadedDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'uploaded_by');
    }

    public function sentEmails(): HasMany
    {
        return $this->hasMany(Email::class, 'sent_by');
    }
}

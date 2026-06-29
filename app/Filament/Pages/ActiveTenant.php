<?php

namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Modules\Dashboard\Support\DashboardScope;
use App\Support\Tenancy\ActiveTenant as ActiveTenantState;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property-read Schema $form
 */
class ActiveTenant extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.active-tenant';

    /**
     * @var array{tenant?: string}
     */
    public array $data = [];

    public function mount(): void
    {
        $tenantId = app(ActiveTenantState::class)->id();

        $this->form->fill([
            'tenant' => $tenantId === null ? 'global' : (string) $tenantId,
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return __('Active tenant');
    }

    public function getHeading(): string|Htmlable
    {
        return __('Active tenant');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Choose Global to see records across all tenants, or select a tenant to work inside that tenant.');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Scope'))
                    ->schema([
                        Select::make('tenant')
                            ->label(__('Active tenant'))
                            ->options(fn (): array => $this->tenantOptions())
                            ->required()
                            ->searchable(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $selection = (string) ($this->form->getState()['tenant'] ?? 'global');

        if ($selection === 'global') {
            abort_unless(auth()->user()?->isSuperAdmin() === true, 403);

            app(ActiveTenantState::class)->set(null);
            session()->put(DashboardScope::SESSION_KEY, true);
        } else {
            $tenant = Tenant::query()->findOrFail((int) $selection);

            abort_unless($tenant->is_active && $this->userCanAccessTenant($tenant), 403);

            app(ActiveTenantState::class)->set($tenant);
            session()->forget(DashboardScope::SESSION_KEY);
        }

        Notification::make()
            ->success()
            ->title(__('Active tenant updated'))
            ->send();
    }

    /**
     * @return array<string, string>
     */
    protected function tenantOptions(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $options = $user->isSuperAdmin() ? ['global' => __('Global')] : [];

        $tenants = $user->getTenants(filament()->getDefaultPanel());

        foreach ($tenants as $tenant) {
            if ($tenant instanceof Tenant) {
                $options[(string) $tenant->getKey()] = $tenant->name;
            }
        }

        return $options;
    }

    protected function userCanAccessTenant(Model $tenant): bool
    {
        return auth()->user()?->canAccessTenant($tenant) === true;
    }
}

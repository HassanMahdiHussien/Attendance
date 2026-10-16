<?php

namespace App\Providers\Filament;

use App\Filament\Admin\Widgets\SetupChecklist;
use App\Filament\AvatarProviders\IndigoAvatarProvider;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTenantContext;
use App\Http\Middleware\SetTenantFromFilament;
use App\Models\Company;
use App\Support\Access\AdminNav;
use App\Support\Access\Nav;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->default()->id('admin')->path('admin')
            ->navigation(function (): NavigationBuilder {
                $workspace = array_map(fn (array $item): NavigationItem => NavigationItem::make(__($item['label']))
                    ->url($item['url'])
                    ->icon(match ($item['icon']) {
                        'home' => 'heroicon-o-home', 'chart' => 'heroicon-o-chart-bar',
                        'inbox' => 'heroicon-o-inbox', 'users' => 'heroicon-o-users',
                        'check' => 'heroicon-o-check-circle', default => 'heroicon-o-folder',
                    })
                    ->isActiveWhen(fn (): bool => request()->is($item['pattern']))
                    ->badge($item['count'] ? (string) $item['count'] : null), Nav::items(auth()->user()));

                $settings = array_map(fn (array $item): NavigationItem => NavigationItem::make($item['label'])
                    ->url($item['url'])
                    ->icon($item['filamentIcon'])
                    ->isActiveWhen(fn (): bool => $item['active']), AdminNav::items(auth()->user()));

                return (new NavigationBuilder)
                    ->group(NavigationGroup::make(__('Workspace'))->items($workspace))
                    ->group(NavigationGroup::make(__('Settings'))->items($settings));
            })
            ->userMenuItems([
                Action::make('help')->label(fn (): string => __('Help'))->url('/help'),
                Action::make('locale_en')->label(fn (): string => __('English'))->action(function (): RedirectResponse {
                    auth()->user()->forceFill(['locale' => 'en'])->save();

                    return redirect()->to(Livewire::originalUrl());
                }),
                Action::make('locale_ar')->label(fn (): string => __('Arabic'))->action(function (): RedirectResponse {
                    auth()->user()->forceFill(['locale' => 'ar'])->save();

                    return redirect()->to(Livewire::originalUrl());
                }),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                SetLocale::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                ValidateCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetTenantContext::class,
                EnsureAccountIsActive::class,
                EnforceIdleTimeout::class,
            ])
            ->persistentMiddleware([
                SetLocale::class,
                SetTenantContext::class,
                EnsureAccountIsActive::class,
                EnforceIdleTimeout::class,
            ])
            ->tenant(Company::class)
            ->tenantMiddleware([SetTenantFromFilament::class], isPersistent: true)
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\\Filament\\Admin\\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\\Filament\\Admin\\Pages')
            ->pages([Dashboard::class])
            ->widgets([SetupChecklist::class])
            ->colors([
                'primary' => Color::hex('#1D3A63'),
                'warning' => Color::hex('#F2B33D'),
                'danger' => Color::hex('#C2272D'),
                'success' => Color::hex('#1F7A58'),
            ])->font('Readex Pro')->darkMode(false)->topNavigation()->defaultAvatarProvider(IndigoAvatarProvider::class)->brandName(fn (): string => __('Displanery'))->favicon(asset('favicon.svg'))->viteTheme('resources/css/filament/theme.css')
            ->authMiddleware([Authenticate::class]);
    }
}

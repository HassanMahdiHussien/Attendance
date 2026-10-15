<?php

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\IndigoAvatarProvider;
use App\Filament\Platform\Widgets\CompaniesOverview;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTenantContext;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
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

class PlatformPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('platform')->path('platform')
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
            ->discoverResources(in: app_path('Filament/Platform/Resources'), for: 'App\\Filament\\Platform\\Resources')
            ->discoverPages(in: app_path('Filament/Platform/Pages'), for: 'App\\Filament\\Platform\\Pages')
            ->pages([Dashboard::class])
            ->widgets([CompaniesOverview::class])
            ->colors([
                'primary' => Color::hex('#1D3A63'),
                'warning' => Color::hex('#F2B33D'),
                'danger' => Color::hex('#C2272D'),
                'success' => Color::hex('#1F7A58'),
            ])->font('Readex Pro')->darkMode(false)->topNavigation()->defaultAvatarProvider(IndigoAvatarProvider::class)->brandName(fn (): string => __('Displanery'))->favicon(asset('favicon.svg'))->viteTheme('resources/css/filament/theme.css')
            ->authMiddleware([Authenticate::class]);
    }
}

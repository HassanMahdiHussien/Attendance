<?php

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\IndigoAvatarProvider;
use App\Filament\Platform\Widgets\CompaniesOverview;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTenantContext;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class PlatformPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('platform')->path('platform')
            ->topbar(false)->navigation(false)
            ->renderHook(PanelsRenderHook::BODY_START, fn (): string => auth()->check() ? view('layouts.partials.workspace-navigation')->render() : '')
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
            ])->font('Readex Pro')->darkMode(false)->defaultAvatarProvider(IndigoAvatarProvider::class)->brandName(fn (): string => __('Attendance'))->brandLogo(fn () => view('filament.brand'))->brandLogoHeight('2rem')->favicon(asset('favicon.svg'))->viteTheme('resources/css/filament/theme.css')
            ->authMiddleware([Authenticate::class]);
    }
}

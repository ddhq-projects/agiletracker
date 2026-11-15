<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class SettingsPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('settings')
            ->path('settings')
            ->login()
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): View => view('filament.sidebar-state'),
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                fn (): View => view('filament.settings-back'),
            )
            ->maxContentWidth(Width::Full)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                'primary' => '#3b82f6',
            ])
            ->discoverResources(in: app_path('Filament/Settings/Resources'), for: 'App\\Filament\\Settings\\Resources')
            ->discoverPages(in: app_path('Filament/Settings/Pages'), for: 'App\\Filament\\Settings\\Pages')
            ->navigationGroups([
                'Settings',
            ])
            ->navigationItems([
                NavigationItem::make('Back to app')
                    ->url(fn (): string => url('/'))
                    ->icon('heroicon-o-arrow-left')
                    ->sort(-1),
                NavigationItem::make('GitHub')
                    ->url('https://github.com/anatolii-iumashev/agiletracker', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-link')
                    ->sort(999),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}

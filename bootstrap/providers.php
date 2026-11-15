<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\MainPanelProvider;
use App\Providers\Filament\SettingsPanelProvider;

return [
    AppServiceProvider::class,
    MainPanelProvider::class,
    SettingsPanelProvider::class,
];

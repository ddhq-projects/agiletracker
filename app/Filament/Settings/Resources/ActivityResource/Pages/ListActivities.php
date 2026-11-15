<?php

declare(strict_types=1);

namespace App\Filament\Settings\Resources\ActivityResource\Pages;

use App\Filament\Settings\Resources\ActivityResource;
use Filament\Resources\Pages\ListRecords;

class ListActivities extends ListRecords
{
    protected static string $resource = ActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

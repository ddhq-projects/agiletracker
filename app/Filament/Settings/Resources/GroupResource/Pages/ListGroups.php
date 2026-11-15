<?php

declare(strict_types=1);

namespace App\Filament\Settings\Resources\GroupResource\Pages;

use App\Filament\Settings\Resources\GroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGroups extends ListRecords
{
    protected static string $resource = GroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

<?php

namespace App\Filament\Resources\ItemResource\Pages;

use App\Filament\Resources\ItemResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Relaticle\Comments\Filament\Actions\CommentsAction;

class ViewItem extends ViewRecord
{
    protected static string $resource = ItemResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            CommentsAction::make(),
            Actions\EditAction::make(),
        ];
    }
}

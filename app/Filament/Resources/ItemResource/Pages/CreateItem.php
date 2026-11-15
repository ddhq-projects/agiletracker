<?php

declare(strict_types=1);

namespace App\Filament\Resources\ItemResource\Pages;

use App\Filament\Resources\ItemResource;
use App\Filament\Resources\ItemResource\Pages\Concerns\KeepsFormActionsInSchema;
use Filament\Resources\Pages\CreateRecord;

class CreateItem extends CreateRecord
{
    use KeepsFormActionsInSchema;

    protected static string $resource = ItemResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['reporter_id'] = auth()->id();

        return $data;
    }
}

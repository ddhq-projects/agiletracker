<?php

namespace App\Filament\Resources\ItemResource\Pages;

use App\Actions\SyncItemAccessGroups;
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

        if (($data['parent_id'] ?? null) !== null && ItemResource::parentLabel($data['parent_id']) === null) {
            abort(422);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        app(SyncItemAccessGroups::class)(
            $this->getRecord(),
            (array) ($this->data['access_group_ids'] ?? []),
            auth()->user(),
        );
    }
}

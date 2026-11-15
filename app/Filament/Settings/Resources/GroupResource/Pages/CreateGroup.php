<?php

declare(strict_types=1);

namespace App\Filament\Settings\Resources\GroupResource\Pages;

use App\Actions\SyncGroupMembers;
use App\Filament\Settings\Resources\GroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGroup extends CreateRecord
{
    protected static string $resource = GroupResource::class;

    protected function afterCreate(): void
    {
        app(SyncGroupMembers::class)(
            $this->getRecord(),
            (array) ($this->data['member_ids'] ?? []),
            (array) ($this->data['manager_ids'] ?? []),
            auth()->user(),
        );
    }
}

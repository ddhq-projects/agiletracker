<?php

declare(strict_types=1);

namespace App\Filament\Settings\Resources\GroupResource\Pages;

use App\Actions\SyncGroupMembers;
use App\Filament\Settings\Resources\GroupResource;
use App\Models\Group;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGroup extends EditRecord
{
    protected static string $resource = GroupResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->visible(fn (): bool => ! $this->getRecord()->items()->exists())];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Group $group */
        $group = $this->getRecord();

        $data['member_ids'] = $group->members()->pluck('users.id')->all();
        $data['manager_ids'] = $group->managers()->pluck('users.id')->all();

        return $data;
    }

    protected function afterSave(): void
    {
        app(SyncGroupMembers::class)(
            $this->getRecord(),
            (array) ($this->data['member_ids'] ?? []),
            (array) ($this->data['manager_ids'] ?? []),
            auth()->user(),
        );
    }
}

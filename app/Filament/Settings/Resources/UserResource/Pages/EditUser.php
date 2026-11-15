<?php

declare(strict_types=1);

namespace App\Filament\Settings\Resources\UserResource\Pages;

use App\Actions\AssignUserRole;
use App\Enums\RoleName;
use App\Filament\Settings\Resources\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['role'] = $this->baseRoleOf($this->getRecord())?->value ?? RoleName::User->value;

        return $data;
    }

    protected function beforeSave(): void
    {
        $role = RoleName::tryFrom((string) ($this->data['role'] ?? '')) ?? RoleName::User;

        app(AssignUserRole::class)($this->getRecord(), $role, auth()->user());
    }

    private function baseRoleOf(User $user): ?RoleName
    {
        return RoleName::mostPrivileged($user->getRoleNames()->all());
    }
}

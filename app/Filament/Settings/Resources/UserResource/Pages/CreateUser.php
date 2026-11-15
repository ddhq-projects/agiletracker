<?php

declare(strict_types=1);

namespace App\Filament\Settings\Resources\UserResource\Pages;

use App\Actions\AssignUserRole;
use App\Enums\RoleName;
use App\Filament\Settings\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        app(AssignUserRole::class)(
            $this->getRecord(),
            RoleName::tryFrom((string) ($this->data['role'] ?? '')) ?? RoleName::User,
            auth()->user(),
        );
    }
}

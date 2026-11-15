<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * Read-only profile that gives a favorite of type User a real destination.
 * It is deliberately not registered in the navigation.
 */
class UserProfile extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'users/{record}';

    protected string $view = 'filament.pages.user-profile';

    #[Locked]
    public User $profile;

    public function mount(int|string $record): void
    {
        $this->profile = User::query()->findOrFail($record);

        Gate::authorize('view', $this->profile);
    }

    public function getTitle(): string
    {
        return $this->profile->name;
    }
}

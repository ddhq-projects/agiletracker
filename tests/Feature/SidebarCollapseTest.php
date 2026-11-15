<?php

declare(strict_types=1);

use App\Models\User;
use Spatie\Permission\Models\Role;

test('both panels fully collapse their sidebar on desktop', function () {
    expect(filament()->getPanel('main')->isSidebarFullyCollapsibleOnDesktop())->toBeTrue()
        ->and(filament()->getPanel('settings')->isSidebarFullyCollapsibleOnDesktop())->toBeTrue();
});

test('main panel sidebar boots fully collapsed', function () {
    actingAsUser();

    $this->get('/')
        ->assertOk()
        ->assertSee('fi-body-has-sidebar-fully-collapsible-on-desktop', false)
        ->assertDontSee('fi-body-has-sidebar-collapsible-on-desktop', false)
        ->assertSee('fi-topbar-open-sidebar-btn', false)
        ->assertSee("localStorage.setItem('filament:sidebar:open', 'false')", false)
        ->assertSee("localStorage.setItem('filament:sidebar:desktop_open', 'false')", false);
});

test('settings panel sidebar boots fully collapsed', function () {
    Role::findOrCreate('admin');

    $user = User::factory()->create();
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get('/settings')
        ->assertOk()
        ->assertSee('fi-body-has-sidebar-fully-collapsible-on-desktop', false)
        ->assertSee("localStorage.setItem('filament:sidebar:open', 'false')", false);
});

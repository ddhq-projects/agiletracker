<?php

declare(strict_types=1);

use App\Actions\FavoriteOrderService;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Favorites;
use App\Filament\Pages\UserProfile;
use App\Filament\Resources\ItemResource;
use App\Filament\Widgets\FavoritesWidget;
use App\Filament\Widgets\InboxWidget;
use App\Filament\Widgets\SentWidget;
use App\Models\Board;
use App\Models\Favorite;
use App\Models\Item;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('main'));
});

/**
 * @return array<NavigationItem>
 */
function favoritesSidebarItems(): array
{
    return collect(Favorites::getNavigationItems())
        ->filter(fn (NavigationItem $item): bool => $item->getGroup() === 'Favorites')
        ->sortBy(fn (NavigationItem $item): int => $item->getSort())
        ->values()
        ->all();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function addFavoriteFor(User $user, array $attributes = []): Favorite
{
    return Favorite::create([
        'user_id' => $user->id,
        'name' => 'Favorite',
        ...$attributes,
    ]);
}

test('favorites is the first named sidebar group, before my and collections', function () {
    $user = actingAsUser();

    addFavoriteFor($user, ['name' => 'Docs', 'url' => 'https://docs.test']);

    $labels = collect(Filament::getNavigation())
        ->map(fn ($group): ?string => $group->getLabel() === null ? null : (string) $group->getLabel())
        ->filter()
        ->values()
        ->all();

    expect(array_slice($labels, 0, 3))->toBe(['Favorites', 'My', 'Collections']);
});

test('the settings panel never shows the favorites group', function () {
    $user = actingAsUser();
    Role::findOrCreate('admin');
    $user->assignRole('admin');

    addFavoriteFor($user, ['name' => 'Docs', 'url' => 'https://docs.test']);

    Filament::setCurrentPanel(Filament::getPanel('settings'));

    $labels = collect(Filament::getNavigation())
        ->map(fn ($group): ?string => $group->getLabel() === null ? null : (string) $group->getLabel())
        ->all();

    expect($labels)->not->toContain('Favorites');
});

test('the sidebar shows at most five favorites in the saved order with their urls', function () {
    $user = actingAsUser();

    $items = [
        addFavoriteFor($user, ['name' => 'One', 'url' => 'https://one.test']),
        addFavoriteFor($user, ['name' => 'Two', 'url' => 'https://two.test']),
        addFavoriteFor($user, ['name' => 'Three', 'url' => 'https://three.test']),
        addFavoriteFor($user, ['name' => 'Four', 'url' => 'https://four.test']),
        addFavoriteFor($user, ['name' => 'Five', 'url' => 'https://five.test']),
        addFavoriteFor($user, ['name' => 'Six', 'url' => 'https://six.test']),
    ];

    $sidebar = favoritesSidebarItems();

    expect($sidebar)->toHaveCount(5)
        ->and(array_map(fn (NavigationItem $item): string => (string) $item->getLabel(), $sidebar))
        ->toBe(['One', 'Two', 'Three', 'Four', 'Five'])
        ->and($sidebar[0]->getUrl())->toBe($items[0]->url)
        ->and($sidebar[0]->shouldOpenUrlInNewTab())->toBeTrue();
});

test('the group is hidden when the user has no available favorites', function () {
    actingAsUser();

    expect(favoritesSidebarItems())->toBe([]);
});

test('two users never see each other favorites in the sidebar', function () {
    $alice = actingAsUser();
    addFavoriteFor($alice, ['name' => 'Alice bookmark', 'url' => 'https://alice.test']);

    $aliceLabels = array_map(fn (NavigationItem $item): string => (string) $item->getLabel(), favoritesSidebarItems());

    $bob = User::factory()->create();
    $this->actingAs($bob);

    expect(favoritesSidebarItems())->toBe([])
        ->and($aliceLabels)->toBe(['Alice bookmark']);
});

test('moving the sixth favorite into the top five changes the group immediately', function () {
    $user = actingAsUser();

    $first = addFavoriteFor($user, ['name' => 'One', 'url' => 'https://one.test']);
    addFavoriteFor($user, ['name' => 'Two', 'url' => 'https://two.test']);
    addFavoriteFor($user, ['name' => 'Three', 'url' => 'https://three.test']);
    addFavoriteFor($user, ['name' => 'Four', 'url' => 'https://four.test']);
    addFavoriteFor($user, ['name' => 'Five', 'url' => 'https://five.test']);
    $sixth = addFavoriteFor($user, ['name' => 'Six', 'url' => 'https://six.test']);

    app(FavoriteOrderService::class)->moveToPriority($user, $sixth->id, 1);

    $labels = array_map(fn (NavigationItem $item): string => (string) $item->getLabel(), favoritesSidebarItems());

    expect($labels)->toBe(['Six', 'One', 'Two', 'Three', 'Four'])
        ->and($labels)->not->toContain('Five');
});

test('a favorite of type user opens the read-only profile in the main panel', function () {
    $user = actingAsUser();
    $colleague = User::factory()->create(['name' => 'Colleague Name']);

    addFavoriteFor($user, [
        'name' => 'Colleague',
        'favoritable_type' => User::class,
        'favoritable_id' => $colleague->id,
    ]);

    $expectedUrl = UserProfile::getUrl(['record' => $colleague]);

    expect(favoritesSidebarItems()[0]->getUrl())->toBe($expectedUrl)
        ->and(favoritesSidebarItems()[0]->shouldOpenUrlInNewTab())->toBeFalse();

    $this->get($expectedUrl)
        ->assertOk()
        ->assertSee('Colleague Name')
        ->assertDontSee('Save changes');
});

test('the profile of a user the policy denies returns forbidden', function () {
    $user = actingAsUser();
    $colleague = User::factory()->create();

    addFavoriteFor($user, [
        'name' => 'Colleague',
        'favoritable_type' => User::class,
        'favoritable_id' => $colleague->id,
    ]);

    Gate::before(fn (): bool => false);

    $this->get(UserProfile::getUrl(['record' => $colleague]))->assertForbidden();
});

test('a deleted item and an unsupported object type are hidden from the quick views', function () {
    $user = actingAsUser();

    $item = Item::factory()->create(['title' => 'Vanishing item']);
    addFavoriteFor($user, [
        'name' => 'Deleted item',
        'favoritable_type' => Item::class,
        'favoritable_id' => $item->id,
    ]);

    $board = Board::factory()->create();
    addFavoriteFor($user, [
        'name' => 'Board bookmark',
        'favoritable_type' => Board::class,
        'favoritable_id' => $board->id,
    ]);

    $item->delete();

    expect(favoritesSidebarItems())->toBe([]);

    Livewire::test(FavoritesWidget::class)
        ->assertDontSee('Deleted item')
        ->assertDontSee('Board bookmark')
        ->assertSee('No favorites yet');
});

test('external links open in a new tab with a safe rel and internal ones stay in the same tab', function () {
    $user = actingAsUser();

    addFavoriteFor($user, ['name' => 'Docs', 'url' => 'https://docs.test']);

    $item = Item::factory()->create(['title' => 'Internal target']);
    addFavoriteFor($user, [
        'name' => 'Internal bookmark',
        'favoritable_type' => Item::class,
        'favoritable_id' => $item->id,
    ]);

    $this->get('/')->assertSee('rel="noopener noreferrer"', false);

    Livewire::test(FavoritesWidget::class)
        ->assertSeeHtml('target="_blank"')
        ->assertSeeHtml('rel="noopener noreferrer"')
        ->assertSeeHtml('href="'.ItemResource::getUrl('view', ['record' => $item]).'"');
});

test('the dashboard shows favorites as its first widget without the old three card limit', function () {
    $user = actingAsUser();

    foreach (['One', 'Two', 'Three', 'Four'] as $name) {
        addFavoriteFor($user, ['name' => $name, 'url' => 'https://'.strtolower($name).'.test']);
    }

    addFavoriteFor(User::factory()->create(), ['name' => 'Someone else', 'url' => 'https://else.test']);

    expect(app(Dashboard::class)->getWidgets())
        ->toBe([FavoritesWidget::class, InboxWidget::class, SentWidget::class]);

    Livewire::test(FavoritesWidget::class)
        ->assertSee('One')
        ->assertSee('Four')
        ->assertDontSee('Someone else')
        ->assertSee('View all')
        ->assertSeeHtml('href="'.Favorites::getUrl().'"');
});

test('the dashboard grid shows at most twelve favorites in order', function () {
    $user = actingAsUser();

    for ($index = 1; $index <= 14; $index++) {
        addFavoriteFor($user, [
            'name' => 'Card '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'url' => 'https://card'.$index.'.test',
        ]);
    }

    $labels = [];

    for ($index = 1; $index <= 12; $index++) {
        $labels[] = 'Card '.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
    }

    Livewire::test(FavoritesWidget::class)
        ->assertSeeInOrder($labels)
        ->assertDontSee('Card 13')
        ->assertDontSee('Card 14')
        ->assertSee('Show all favorites');
});

test('the empty favorites widget links to the page instead of showing a card', function () {
    actingAsUser();

    Livewire::test(FavoritesWidget::class)
        ->assertSee('No favorites yet')
        ->assertSeeHtml('href="'.Favorites::getUrl().'"');
});

test('the order changed on the dashboard is the same on the page and in the sidebar', function () {
    $user = actingAsUser();

    $one = addFavoriteFor($user, ['name' => 'One', 'url' => 'https://one.test']);
    $two = addFavoriteFor($user, ['name' => 'Two', 'url' => 'https://two.test']);
    $three = addFavoriteFor($user, ['name' => 'Three', 'url' => 'https://three.test']);

    Livewire::test(FavoritesWidget::class)
        ->call('reorderFavorites', [$three->id, $two->id, $one->id]);

    $sidebarLabels = array_map(
        fn (NavigationItem $item): string => (string) $item->getLabel(),
        favoritesSidebarItems(),
    );

    expect($sidebarLabels)->toBe(['Three', 'Two', 'One']);

    Livewire::test(FavoritesWidget::class)->assertSeeInOrder(['Three', 'Two', 'One']);
    Livewire::test(Favorites::class)->assertSeeInOrder(['Three', 'Two', 'One']);
});

test('reordering one user favorites leaves another user order untouched', function () {
    $alice = actingAsUser();

    $aliceFirst = addFavoriteFor($alice, ['name' => 'Alice one', 'url' => 'https://alice-one.test']);
    $aliceSecond = addFavoriteFor($alice, ['name' => 'Alice two', 'url' => 'https://alice-two.test']);

    $bob = User::factory()->create();
    $bobFirst = addFavoriteFor($bob, ['name' => 'Bob one', 'url' => 'https://bob-one.test']);
    $bobSecond = addFavoriteFor($bob, ['name' => 'Bob two', 'url' => 'https://bob-two.test']);

    app(FavoriteOrderService::class)->reorder($alice, [$aliceSecond->id, $aliceFirst->id]);

    expect(Favorite::query()->where('user_id', $alice->id)->ordered()->pluck('name')->all())
        ->toBe(['Alice two', 'Alice one'])
        ->and(Favorite::query()->where('user_id', $bob->id)->ordered()->pluck('id')->all())
        ->toBe([$bobFirst->id, $bobSecond->id]);
});

test('dragging cards on the dashboard persists the order and refreshes the sidebar', function () {
    $user = actingAsUser();

    $one = addFavoriteFor($user, ['name' => 'One', 'url' => 'https://one.test']);
    $two = addFavoriteFor($user, ['name' => 'Two', 'url' => 'https://two.test']);
    $three = addFavoriteFor($user, ['name' => 'Three', 'url' => 'https://three.test']);

    Livewire::test(FavoritesWidget::class)
        ->call('reorderFavorites', [$three->id, $two->id, $one->id])
        ->assertDispatched('refresh-sidebar');

    expect(Favorite::query()->where('user_id', $user->id)->ordered()->pluck('id')->all())
        ->toBe([$three->id, $two->id, $one->id]);
});

test('the move buttons reorder the cards and respect the ends of the list', function () {
    $user = actingAsUser();

    $one = addFavoriteFor($user, ['name' => 'One', 'url' => 'https://one.test']);
    $two = addFavoriteFor($user, ['name' => 'Two', 'url' => 'https://two.test']);
    $three = addFavoriteFor($user, ['name' => 'Three', 'url' => 'https://three.test']);

    Livewire::test(FavoritesWidget::class)
        ->call('moveFavorite', $one->id, 'up')
        ->assertNotDispatched('refresh-sidebar');

    expect(Favorite::query()->where('user_id', $user->id)->ordered()->pluck('name')->all())
        ->toBe(['One', 'Two', 'Three']);

    Livewire::test(FavoritesWidget::class)
        ->call('moveFavorite', $three->id, 'up');

    expect(Favorite::query()->where('user_id', $user->id)->ordered()->pluck('name')->all())
        ->toBe(['One', 'Three', 'Two']);

    Livewire::test(FavoritesWidget::class)
        ->call('moveFavorite', $one->id, 'down');

    expect(Favorite::query()->where('user_id', $user->id)->ordered()->pluck('name')->all())
        ->toBe(['Three', 'One', 'Two']);
});

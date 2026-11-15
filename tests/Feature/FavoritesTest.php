<?php

declare(strict_types=1);

use App\Actions\FavoriteOrderService;
use App\Actions\ResolveFavoriteTarget;
use App\Filament\Pages\Favorites;
use App\Filament\Resources\ItemResource;
use App\Filament\Widgets\FavoritesWidget;
use App\Models\Favorite;
use App\Models\Item;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    actingAsUser();
    Filament::setCurrentPanel(Filament::getPanel('main'));
});

/**
 * @param  array<string, mixed>  $attributes
 */
function favoriteFor(User $user, array $attributes = []): Favorite
{
    return Favorite::create([
        'user_id' => $user->id,
        'name' => 'Favorite',
        ...$attributes,
    ]);
}

test('the favorites page lives at the canonical url and keeps the old one working', function () {
    $this->get('/favorites')
        ->assertOk()
        ->assertSee('Favorites');

    $this->get('/favorite')
        ->assertRedirect('/favorites');

    expect(Route::has('filament.main.pages.favorites'))->toBeTrue();
});

test('a guest is sent to the login page from the favorites page', function () {
    auth()->logout();

    $this->get('/favorites')->assertRedirect('/login');
});

test('the favorites page sits in collections after items boards and labels', function () {
    Filament::setCurrentPanel(Filament::getPanel('main'));

    $collections = collect(Filament::getNavigation())
        ->first(fn ($group): bool => (string) $group->getLabel() === 'Collections');

    expect($collections)->not->toBeNull();

    $labels = collect($collections->getItems())
        ->map(fn ($item): string => (string) $item->getLabel())
        ->all();

    expect($labels)->toBe(['Items', 'Boards', 'Labels', 'Favorites']);
});

test('new favorites are appended to the personal order', function () {
    $user = auth()->user();

    $first = favoriteFor($user, ['name' => 'First', 'url' => 'https://first.test']);
    $second = favoriteFor($user, ['name' => 'Second', 'url' => 'https://second.test']);
    $third = favoriteFor($user, ['name' => 'Third', 'url' => 'https://third.test']);

    expect([$first->position, $second->position, $third->position])->toBe([1, 2, 3]);

    $other = User::factory()->create();
    $otherFavorite = favoriteFor($other, ['name' => 'Other', 'url' => 'https://other.test']);

    expect($otherFavorite->position)->toBe(1);
});

test('the full page lists favorites in the stored order, not the creation order', function () {
    $user = auth()->user();

    $first = favoriteFor($user, ['name' => 'Alpha', 'url' => 'https://alpha.test']);
    $second = favoriteFor($user, ['name' => 'Beta', 'url' => 'https://beta.test']);
    $third = favoriteFor($user, ['name' => 'Gamma', 'url' => 'https://gamma.test']);

    app(FavoriteOrderService::class)->reorder($user, [$third->id, $first->id, $second->id]);

    Livewire::test(Favorites::class)
        ->assertCanSeeTableRecords([$third, $first, $second], inOrder: true);
});

test('dragging is only available on the complete list in the stored order', function () {
    $user = auth()->user();

    favoriteFor($user, ['name' => 'Alpha', 'url' => 'https://alpha.test']);
    favoriteFor($user, ['name' => 'Beta', 'url' => 'https://beta.test']);

    $component = Livewire::test(Favorites::class);

    expect($component->instance()->getTable()->isReorderable())->toBeTrue();

    $component->searchTable('Alpha');

    expect($component->instance()->getTable()->isReorderable())->toBeFalse();
});

test('the same favorite resolves to the same destination on the page, the dashboard and the sidebar', function () {
    $user = auth()->user();

    $item = Item::factory()->create(['title' => 'Shared target']);
    $favorite = favoriteFor($user, [
        'name' => 'Shared favorite',
        'favoritable_type' => Item::class,
        'favoritable_id' => $item->id,
    ]);

    $expected = ItemResource::getUrl('view', ['record' => $item]);

    Livewire::test(Favorites::class)->assertSeeHtml('href="'.$expected.'"');
    Livewire::test(FavoritesWidget::class)->assertSeeHtml('href="'.$expected.'"');

    $sidebarItem = collect(Favorites::getNavigationItems())
        ->first(fn ($navItem): bool => $navItem->getKey() === 'favorite-'.$favorite->id);

    expect($sidebarItem->getUrl())->toBe($expected);
});

test('dragging rows on the full page persists a normalized order', function () {
    $user = auth()->user();

    $first = favoriteFor($user, ['name' => 'Alpha', 'url' => 'https://alpha.test']);
    $second = favoriteFor($user, ['name' => 'Beta', 'url' => 'https://beta.test']);
    $third = favoriteFor($user, ['name' => 'Gamma', 'url' => 'https://gamma.test']);

    Livewire::test(Favorites::class)
        ->call('reorderTable', [$third->id, $second->id, $first->id])
        ->assertDispatched('refresh-sidebar');

    expect(Favorite::query()->where('user_id', $user->id)->ordered()->pluck('id')->all())
        ->toBe([$third->id, $second->id, $first->id])
        ->and(Favorite::query()->where('user_id', $user->id)->ordered()->pluck('position')->all())
        ->toBe([1, 2, 3]);
});

test('a duplicate, foreign or incomplete order is rejected without touching the stored one', function () {
    $user = auth()->user();

    $first = favoriteFor($user, ['name' => 'Alpha', 'url' => 'https://alpha.test']);
    $second = favoriteFor($user, ['name' => 'Beta', 'url' => 'https://beta.test']);

    $foreign = favoriteFor(User::factory()->create(), ['name' => 'Foreign', 'url' => 'https://foreign.test']);

    $service = app(FavoriteOrderService::class);

    $orders = [
        [$first->id, $first->id],
        [$first->id, $foreign->id],
        [$first->id],
    ];

    foreach ($orders as $order) {
        expect(fn () => $service->reorder($user, $order, requireCompleteSet: true))
            ->toThrow(ValidationException::class);
    }

    expect(Favorite::query()->where('user_id', $user->id)->ordered()->pluck('id')->all())
        ->toBe([$first->id, $second->id]);
});

test('reordering only the available favorites keeps the hidden ones in their slots', function () {
    $user = auth()->user();

    $first = favoriteFor($user, ['name' => 'Alpha', 'url' => 'https://alpha.test']);
    $second = favoriteFor($user, ['name' => 'Beta', 'url' => 'https://beta.test']);
    $third = favoriteFor($user, ['name' => 'Gamma', 'url' => 'https://gamma.test']);

    app(FavoriteOrderService::class)->reorder($user, [$third->id, $first->id], requireCompleteSet: false);

    expect(Favorite::query()->where('user_id', $user->id)->ordered()->pluck('id')->all())
        ->toBe([$third->id, $second->id, $first->id]);
});

test('a numeric priority moves a favorite and shifts its neighbours', function () {
    $user = auth()->user();

    $first = favoriteFor($user, ['name' => 'Alpha', 'url' => 'https://alpha.test']);
    $second = favoriteFor($user, ['name' => 'Beta', 'url' => 'https://beta.test']);
    $third = favoriteFor($user, ['name' => 'Gamma', 'url' => 'https://gamma.test']);

    $service = app(FavoriteOrderService::class);

    $service->moveToPriority($user, $third->id, 1);

    expect(Favorite::query()->where('user_id', $user->id)->ordered()->pluck('name')->all())
        ->toBe(['Gamma', 'Alpha', 'Beta']);

    $service->moveToPriority($user, $first->id, 3);

    expect(Favorite::query()->where('user_id', $user->id)->ordered()->pluck('name')->all())
        ->toBe(['Gamma', 'Beta', 'Alpha']);
});

test('a priority outside one to the number of favorites is rejected', function () {
    $user = auth()->user();

    $favorite = favoriteFor($user, ['name' => 'Alpha', 'url' => 'https://alpha.test']);
    favoriteFor($user, ['name' => 'Beta', 'url' => 'https://beta.test']);

    $service = app(FavoriteOrderService::class);

    expect(fn () => $service->moveToPriority($user, $favorite->id, 0))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->moveToPriority($user, $favorite->id, 3))
        ->toThrow(ValidationException::class);

    expect(Favorite::query()->where('user_id', $user->id)->ordered()->pluck('name')->all())
        ->toBe(['Alpha', 'Beta']);
});

test('editing a row updates the name and the numeric priority', function () {
    $user = auth()->user();

    $first = favoriteFor($user, ['name' => 'Alpha', 'url' => 'https://alpha.test']);
    favoriteFor($user, ['name' => 'Beta', 'url' => 'https://beta.test']);
    $third = favoriteFor($user, ['name' => 'Gamma', 'url' => 'https://gamma.test']);

    Livewire::test(Favorites::class)
        ->callAction(TestAction::make('edit')->table($third), [
            'name' => 'Renamed',
            'priority' => 1,
        ])
        ->assertHasNoFormErrors();

    expect(Favorite::query()->where('user_id', $user->id)->ordered()->pluck('name')->all())
        ->toBe(['Renamed', 'Alpha', 'Beta'])
        ->and(Favorite::query()->whereKey($first->id)->value('name'))
        ->toBe('Alpha');
});

test('editing rejects a priority above the number of favorites', function () {
    $user = auth()->user();

    $favorite = favoriteFor($user, ['name' => 'Alpha', 'url' => 'https://alpha.test']);
    favoriteFor($user, ['name' => 'Beta', 'url' => 'https://beta.test']);

    foreach ([0, 3, 'not a number'] as $priority) {
        Livewire::test(Favorites::class)
            ->callAction(TestAction::make('edit')->table($favorite), [
                'name' => 'Alpha',
                'priority' => $priority,
            ])
            ->assertHasFormErrors(['priority']);
    }
});

test('adding and deleting a favorite refreshes the sidebar', function () {
    $user = auth()->user();

    Livewire::test(Favorites::class)
        ->callAction('addFavorite', [
            'kind' => 'link',
            'name' => 'Docs',
            'url' => 'https://docs.test',
        ])
        ->assertDispatched('refresh-sidebar');

    $favorite = Favorite::query()->where('user_id', $user->id)->firstOrFail();

    Livewire::test(Favorites::class)
        ->callAction(TestAction::make('delete')->table($favorite))
        ->assertDispatched('refresh-sidebar');
});

test('a legacy favorite that carries both a url and an object resolves to the object', function () {
    $user = auth()->user();

    $item = Item::factory()->create(['title' => 'Legacy target']);
    $favorite = favoriteFor($user, [
        'name' => 'Legacy bookmark',
        'url' => '/i/'.$item->id,
        'favoritable_type' => Item::class,
        'favoritable_id' => $item->id,
    ]);

    expect(app(ResolveFavoriteTarget::class)($favorite, $user)->url)
        ->toBe(ItemResource::getUrl('view', ['record' => $item]));
});

test('an unavailable target stays visible and manageable on the full page without a link', function () {
    $user = auth()->user();

    $broken = favoriteFor($user, ['name' => 'Broken link', 'url' => 'javascript:alert(1)']);

    $item = Item::factory()->create(['title' => 'Deleted later']);
    $itemFavorite = favoriteFor($user, [
        'name' => 'Deleted item',
        'favoritable_type' => Item::class,
        'favoritable_id' => $item->id,
    ]);
    $item->delete();

    Livewire::test(Favorites::class)
        ->assertSee('Broken link')
        ->assertSee('Deleted item')
        ->assertSee('Unavailable')
        ->assertDontSee('javascript:alert', escape: false);

    expect($broken->fresh())->not->toBeNull()
        ->and($itemFavorite->fresh())->not->toBeNull();
});

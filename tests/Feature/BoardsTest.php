<?php

declare(strict_types=1);

use App\Actions\AddItemsToBoard;
use App\Enums\ItemStatus;
use App\Filament\Pages\BoardView;
use App\Filament\Resources\BoardResource\Pages\CreateBoard;
use App\Filament\Resources\BoardResource\Pages\EditBoard;
use App\Filament\Resources\ItemResource;
use App\Filament\Resources\ItemResource\Pages\ListItems;
use App\Models\Board;
use App\Models\Item;
use App\Models\Label;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    actingAsManager();
    Filament::setCurrentPanel(Filament::getPanel('main'));
});

function makeBoardWithColumns(array $names = ['To Do', 'In Progress', 'Done']): Board
{
    $board = Board::factory()->create();

    foreach ($names as $name) {
        $board->columns()->create(['name' => $name]);
    }

    return $board;
}

test('the migration leaves a default board wired to the workflow states', function () {
    $board = Board::query()->where('name', 'Main board')->firstOrFail();

    expect($board->columns()->pluck('name')->all())
        ->toBe(['To Do', 'In Progress', 'Review', 'Done']);
});

test('the boards list replaces the old kanban page and keeps its url working', function () {
    $board = makeBoardWithColumns(['Backlog']);

    $this->get('/boards')
        ->assertOk()
        ->assertSee('Boards')
        ->assertSee($board->name);

    $this->get('/kanban-board')
        ->assertRedirect('/boards');
});

test('a board shows only its own items, including projects', function () {
    $board = makeBoardWithColumns(['Backlog', 'Done']);
    $otherBoard = makeBoardWithColumns(['Backlog']);

    $item = Item::factory()->create(['title' => 'Board card']);
    $project = Item::factory()->epic()->create(['title' => 'Board project']);
    $otherItem = Item::factory()->create(['title' => 'Other board card']);

    app(AddItemsToBoard::class)($board, [$item->getKey(), $project->getKey()]);
    app(AddItemsToBoard::class)($otherBoard, [$otherItem->getKey()]);

    $this->get(BoardView::getUrl(['board' => $board]))
        ->assertOk()
        ->assertSee('Backlog')
        ->assertSee('Done')
        ->assertSee('Board card')
        ->assertSee('Board project')
        ->assertDontSee('Other board card');
});

test('the same item can be on several boards and keeps a separate column on each', function () {
    $firstBoard = makeBoardWithColumns(['To Do', 'Done']);
    $secondBoard = makeBoardWithColumns(['Backlog', 'Shipped']);

    $item = Item::factory()->epic()->create(['title' => 'Shared project']);

    app(AddItemsToBoard::class)($firstBoard, [$item->getKey()], $firstBoard->columns()->get()->last());
    app(AddItemsToBoard::class)($secondBoard, [$item->getKey()], $secondBoard->columns()->first());

    expect($item->boards()->count())->toBe(2)
        ->and($firstBoard->boardItems()->first()->board_column_id)->toBe($firstBoard->columns()->get()->last()->getKey())
        ->and($secondBoard->boardItems()->first()->board_column_id)->toBe($secondBoard->columns()->first()->getKey());
});

test('moving a card writes this board membership only', function () {
    $board = makeBoardWithColumns(['To Do', 'Done']);
    $columns = $board->columns()->get();
    $item = Item::factory()->create();

    $boardItem = $board->boardItems()->create([
        'item_id' => $item->getKey(),
        'board_column_id' => $columns->first()->getKey(),
    ]);

    Livewire::test(BoardView::class, ['board' => $board])
        ->call('moveCard', (string) $boardItem->getKey(), (string) $columns->last()->getKey());

    $boardItem->refresh();

    expect($boardItem->board_column_id)->toBe($columns->last()->getKey())
        ->and($boardItem->position)->not->toBeNull()
        // A board stage is not the item's native workflow status.
        ->and($item->fresh()->status)->toBe(ItemStatus::Todo);
});

test('a user who may not update the item cannot move its card', function () {
    $board = makeBoardWithColumns(['To Do', 'Done']);
    $columns = $board->columns()->get();
    $boardItem = $board->boardItems()->create([
        'item_id' => Item::factory()->create()->getKey(),
        'board_column_id' => $columns->first()->getKey(),
    ]);

    $component = Livewire::test(BoardView::class, ['board' => $board]);

    Gate::before(fn ($user, string $ability): ?bool => $ability === 'update' ? false : null);

    $component
        ->call('moveCard', (string) $boardItem->getKey(), (string) $columns->last()->getKey())
        ->assertForbidden();

    expect($boardItem->fresh()->board_column_id)->toBe($columns->first()->getKey());
});

test('a card is not moved into another board column', function () {
    $board = makeBoardWithColumns(['To Do', 'Done']);
    $otherBoard = makeBoardWithColumns(['Archive']);
    $boardItem = $board->boardItems()->create([
        'item_id' => Item::factory()->create()->getKey(),
        'board_column_id' => $board->columns()->first()->getKey(),
    ]);

    Livewire::test(BoardView::class, ['board' => $board])
        ->call('moveCard', (string) $boardItem->getKey(), (string) $otherBoard->columns()->first()->getKey());

    expect($boardItem->fresh()->board_column_id)->toBe($board->columns()->first()->getKey());
});

test('items are appended to the chosen column and duplicates are skipped', function () {
    $board = makeBoardWithColumns(['To Do', 'Done']);
    $columns = $board->columns()->get();
    $task = Item::factory()->create();
    $project = Item::factory()->epic()->create();

    $result = app(AddItemsToBoard::class)($board, [$task->getKey(), $project->getKey()]);

    expect($result)->toBe(['added' => 2, 'skipped' => 0])
        ->and($board->boardItems()->where('board_column_id', $columns->first()->getKey())->count())->toBe(2);

    $again = app(AddItemsToBoard::class)($board, [$task->getKey()]);

    expect($again)->toBe(['added' => 0, 'skipped' => 1])
        ->and($board->boardItems()->count())->toBe(2);
});

test('a board can be created with editable default columns', function () {
    Livewire::test(CreateBoard::class)
        ->fillForm(['name' => 'Sprint board'])
        ->call('create')
        ->assertHasNoFormErrors();

    $board = Board::query()->where('name', 'Sprint board')->firstOrFail();

    expect($board->columns()->pluck('name')->all())
        ->toBe(['To Do', 'In Progress', 'Review', 'Done']);
});

test('board columns can be renamed, recoloured, limited and reordered', function () {
    $board = makeBoardWithColumns(['To Do', 'Done']);

    Livewire::test(EditBoard::class, ['record' => $board->getKey()])
        ->fillForm([
            'name' => 'Roadmap',
            'columns' => [
                ['name' => 'Backlog', 'color' => 'info', 'wip_limit' => 5],
                ['name' => 'Shipped', 'color' => 'success', 'wip_limit' => null],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($board->fresh()->name)->toBe('Roadmap')
        ->and($board->columns()->pluck('name')->all())->toBe(['Backlog', 'Shipped'])
        ->and($board->columns()->first()->wip_limit)->toBe(5);
});

test('deleting a column moves its cards to the first remaining column', function () {
    $board = makeBoardWithColumns(['To Do', 'Done']);
    $columns = $board->columns()->get();
    $item = Item::factory()->create();

    $boardItem = $board->boardItems()->create([
        'item_id' => $item->getKey(),
        'board_column_id' => $columns->first()->getKey(),
    ]);

    $columns->first()->delete();

    expect($boardItem->fresh()->board_column_id)->toBe($columns->last()->getKey());
});

test('board column names must be unique', function () {
    $board = makeBoardWithColumns(['To Do', 'Done']);

    Livewire::test(EditBoard::class, ['record' => $board->getKey()])
        ->fillForm([
            'columns' => [
                ['name' => 'Review', 'color' => 'gray', 'wip_limit' => null],
                ['name' => 'review', 'color' => 'gray', 'wip_limit' => null],
            ],
        ])
        ->call('save')
        ->assertHasFormErrors(['columns']);
});

test('the board filters narrow the cards by assignee and label', function () {
    $board = makeBoardWithColumns(['To Do']);
    $column = $board->columns()->first();

    $user = actingAsManager();
    $other = User::factory()->create();

    $label = Label::create(['name' => 'Bug']);

    $mine = Item::factory()->assignedTo($user)->create(['title' => 'Mine']);
    $theirs = Item::factory()->assignedTo($other)->create(['title' => 'Theirs']);
    $theirs->labels()->attach($label);

    app(AddItemsToBoard::class)($board, [$mine->getKey(), $theirs->getKey()], $column);

    Livewire::test(BoardView::class, ['board' => $board])
        ->filterTable('assignee_id', $user->getKey())
        ->assertSee('Mine')
        ->assertDontSee('Theirs');

    Livewire::test(BoardView::class, ['board' => $board])
        ->filterTable('label_id', $label->getKey())
        ->assertSee('Theirs')
        ->assertDontSee('Mine');
});

test('items can be added from the board page', function () {
    $board = makeBoardWithColumns(['To Do', 'Done']);
    $column = $board->columns()->get()->last();
    $item = Item::factory()->create(['title' => 'New card']);

    Livewire::test(BoardView::class, ['board' => $board])
        ->callAction('addItems', [
            'item_ids' => [$item->getKey()],
            'board_column_id' => $column->getKey(),
        ])
        ->assertNotified();

    expect($board->boardItems()->where('item_id', $item->getKey())->value('board_column_id'))
        ->toBe($column->getKey());
});

test('an item can be added to a board from the item table', function () {
    $board = makeBoardWithColumns(['To Do']);
    $item = Item::factory()->create();

    Livewire::test(ListItems::class)
        ->callAction(TestAction::make('addToBoard')->table($item), [
            'board_ids' => [$board->getKey()],
        ])
        ->assertNotified();

    expect($item->boards()->pluck('boards.id')->all())->toBe([$board->getKey()]);
});

test('the item page lists the boards it belongs to', function () {
    $board = makeBoardWithColumns(['Backlog']);
    $item = Item::factory()->create();

    app(AddItemsToBoard::class)($board, [$item->getKey()]);

    $this->get(ItemResource::getUrl('view', ['record' => $item]))
        ->assertOk()
        ->assertSee('Boards')
        ->assertSee($board->name);
});

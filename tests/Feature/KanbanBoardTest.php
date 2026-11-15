<?php

declare(strict_types=1);

use App\Enums\ItemStatus;
use App\Filament\Pages\KanbanBoard;
use App\Models\Item;
use App\Models\Label;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    actingAsUser();
    Filament::setCurrentPanel(Filament::getPanel('main'));
});

test('the board shows the four workflow columns and its cards', function () {
    Item::factory()->create(['title' => 'Ship the board']);

    $this->get('/kanban-board')
        ->assertOk()
        ->assertSeeInOrder(['To Do', 'In Progress', 'Review', 'Done'])
        ->assertSee('Ship the board');
});

test('moving a card writes the status and the position and leaves labels alone', function () {
    $item = Item::factory()->create();
    $label = Label::create(['name' => 'Bug']);
    $item->labels()->attach($label);

    Livewire::test(KanbanBoard::class)
        ->call('moveCard', (string) $item->id, ItemStatus::Done->value);

    $item->refresh();

    expect($item->status)->toBe(ItemStatus::Done)
        ->and($item->position)->not->toBeNull()
        ->and($item->labels()->pluck('name')->all())->toBe(['Bug']);
});

test('a status change is logged while a position-only move is not', function () {
    $item = Item::factory()->create();
    $logged = $item->activitiesAsSubject()->count();

    Livewire::test(KanbanBoard::class)
        ->call('moveCard', (string) $item->id, ItemStatus::Review->value);

    $activity = $item->activitiesAsSubject()->latest('id')->first();

    expect($item->activitiesAsSubject()->count())->toBe($logged + 1)
        ->and($activity->attribute_changes->get('attributes'))->toBe(['status' => 'review']);

    $afterStatusChange = $item->activitiesAsSubject()->count();

    Livewire::test(KanbanBoard::class)
        ->call('moveCard', (string) $item->id, ItemStatus::Review->value);

    expect($item->activitiesAsSubject()->count())->toBe($afterStatusChange);
});

test('a user who may not update the item cannot move its card', function () {
    $item = Item::factory()->create();

    $board = Livewire::test(KanbanBoard::class);

    Gate::before(fn ($user, string $ability): ?bool => $ability === 'update' ? false : null);

    $board->call('moveCard', (string) $item->id, ItemStatus::Done->value)->assertForbidden();

    expect($item->fresh()->status)->toBe(ItemStatus::Todo);
});

test('a card is not moved into a column that is not a workflow state', function () {
    $item = Item::factory()->create();

    Livewire::test(KanbanBoard::class)
        ->call('moveCard', (string) $item->id, 'archived');

    expect($item->fresh()->status)->toBe(ItemStatus::Todo);
});

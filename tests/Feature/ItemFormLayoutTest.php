<?php

declare(strict_types=1);

use App\Enums\ItemStatus;
use App\Filament\Resources\ItemResource\Pages\CreateItem;
use App\Filament\Resources\ItemResource\Pages\EditItem;
use App\Models\Item;
use App\Models\Label;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Livewire\Livewire;

beforeEach(function () {
    actingAsUser();
    Filament::setCurrentPanel(Filament::getPanel('main'));
});

/**
 * @return array{0: Grid, 1: Section[], 2: array<int, mixed>}
 */
function itemFormLayout(): array
{
    $schema = Livewire::test(CreateItem::class)->instance()->getSchema('form');
    $grid = collect($schema->getComponents())->first();

    expect($grid)->toBeInstanceOf(Grid::class);

    [$left, $right] = $grid->getChildComponents();

    return [$grid, $left->getChildComponents(), $right->getChildComponents()];
}

test('the item form spans the whole width in three columns', function () {
    [$grid] = itemFormLayout();

    expect($grid->getColumnSpan('default'))->toBe('full')
        ->and($grid->getColumns('lg'))->toBe(3);
});

test('the main column holds the content, the dates and the meta', function () {
    [, $left] = itemFormLayout();

    $headings = collect($left)
        ->map(fn (Section $section): string => (string) $section->getHeading())
        ->all();

    expect($headings)->toBe(['General', 'Dates', 'Meta']);
});

test('the rail starts with the form actions and then the reference fields', function () {
    [, , $right] = itemFormLayout();

    expect($right[0])->toBeInstanceOf(Actions::class);

    $headings = collect(array_slice($right, 1))
        ->map(fn (Section $section): string => (string) $section->getHeading())
        ->all();

    expect($headings)->toBe(['Workflow', 'Participants']);
});

test('the rail buttons are the page s own form actions', function () {
    Livewire::test(CreateItem::class)
        ->assertSee('create another')
        ->assertSee('Cancel');

    $item = Item::factory()->create(['reporter_id' => auth()->id()]);

    Livewire::test(EditItem::class, ['record' => $item->getKey()])
        ->assertSee('Save and view')
        ->assertSee('Save');
});

test('the split form still saves every field', function () {
    $me = auth()->user();
    $assignee = User::factory()->create();
    $to = User::factory()->create();
    $cc = User::factory()->create();
    $label = Label::create(['name' => 'Bug', 'color' => '#ef4444']);

    Livewire::test(CreateItem::class)
        ->fillForm([
            'title' => 'Refactored layout',
            'description' => 'Body of the item.',
            'status' => ItemStatus::InProgress->value,
            'labels' => [$label->id],
            'assignee_id' => $assignee->id,
            'to' => [$to->id],
            'cc' => [$cc->id],
            'due_date' => '2026-12-05',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $item = Item::query()->where('title', 'Refactored layout')->firstOrFail();

    expect($item->status)->toBe(ItemStatus::InProgress)
        ->and($item->reporter_id)->toBe($me->id)
        ->and($item->assignee_id)->toBe($assignee->id)
        ->and($item->due_date?->toDateString())->toBe('2026-12-05')
        ->and($item->labels->modelKeys())->toBe([$label->id])
        ->and($item->to->modelKeys())->toBe([$to->id])
        ->and($item->cc->modelKeys())->toBe([$cc->id]);
});

test('editing an item uses the same split layout', function () {
    $item = Item::factory()->create(['reporter_id' => auth()->id()]);

    [$grid, $left, $right] = (function () use ($item) {
        $schema = Livewire::test(EditItem::class, ['record' => $item->getKey()])->instance()->getSchema('form');
        $grid = collect($schema->getComponents())->first();
        [$left, $right] = $grid->getChildComponents();

        return [$grid, $left->getChildComponents(), $right->getChildComponents()];
    })();

    expect($grid->getColumnSpan('default'))->toBe('full')
        ->and($grid->getColumns('lg'))->toBe(3)
        ->and($right[0])->toBeInstanceOf(Actions::class)
        ->and(count($left))->toBe(3);

    $this->get('/i/'.$item->getKey().'/edit')->assertOk();
});

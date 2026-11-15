<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\ItemStatus;
use App\Filament\Resources\ItemResource;
use App\Models\Item;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Relaticle\Flowforge\Board;
use Relaticle\Flowforge\BoardPage;
use Relaticle\Flowforge\Column;
use UnitEnum;

class KanbanBoard extends BoardPage
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $navigationLabel = 'Kanban';

    protected static UnitEnum|string|null $navigationGroup = 'Collections';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Kanban';

    public function board(Board $board): Board
    {
        return $board
            ->query(Item::query()->with(['assignee', 'labels']))
            ->columnIdentifier('status')
            ->positionIdentifier('position')
            ->recordTitleAttribute('title')
            ->columns(fn (): array => array_map(
                fn (ItemStatus $status): Column => Column::enum($status),
                ItemStatus::cases(),
            ))
            ->filters([
                SelectFilter::make('labels')
                    ->relationship('labels', 'name')
                    ->label('Label'),

                SelectFilter::make('assignee_id')
                    ->relationship('assignee', 'name')
                    ->label('Assignee'),
            ])
            ->cardSchema(fn (Schema $schema): Schema => $schema->components([
                TextEntry::make('labels.name')
                    ->hiddenLabel()
                    ->badge()
                    ->placeholder(''),

                Flex::make([
                    TextEntry::make('assignee.name')
                        ->hiddenLabel()
                        ->icon('heroicon-m-user')
                        ->placeholder('Unassigned'),

                    TextEntry::make('due_date')
                        ->hiddenLabel()
                        ->icon('heroicon-m-calendar')
                        ->date()
                        ->placeholder(''),
                ])->from('md'),
            ]))
            ->recordActions([
                Action::make('view')
                    ->url(fn (Item $record): string => ItemResource::getUrl('view', ['record' => $record])),
            ])
            ->cardAction('view');
    }

    /**
     * Drag-and-drop writes the column and position together. `parent::moveCard()`
     * runs in its own transaction, so wrapping the call keeps the policy check and
     * the write atomic, and the activity log entry for `status` comes from the
     * model's own LogsActivity configuration (position is not logged).
     */
    public function moveCard(
        string $cardId,
        string $targetColumnId,
        ?string $afterCardId = null,
        ?string $beforeCardId = null,
    ): void {
        $item = Item::query()->findOrFail($cardId);

        Gate::authorize('update', $item);

        if (ItemStatus::tryFrom($targetColumnId) === null) {
            return;
        }

        DB::transaction(function () use ($cardId, $targetColumnId, $afterCardId, $beforeCardId): void {
            parent::moveCard($cardId, $targetColumnId, $afterCardId, $beforeCardId);
        });
    }
}

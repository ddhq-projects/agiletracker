<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Access\ItemAccess;
use App\Actions\AddItemsToBoard;
use App\Filament\Resources\BoardResource;
use App\Filament\Resources\ItemResource;
use App\Models\Board as BoardModel;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\Item;
use App\Models\Label;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\PageConfiguration;
use Filament\Panel;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Relaticle\Flowforge\Board;
use Relaticle\Flowforge\BoardPage;
use Relaticle\Flowforge\Column;

/**
 * One board at /boards/{board}. The Flowforge board is driven by board_items,
 * not by items directly: a card move changes this board's column and position
 * only, so the same item can hold a different stage on every board.
 */
class BoardView extends BoardPage
{
    public static function getRoutePath(Panel $panel): string
    {
        return '/boards/{board}';
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'boards.board';
    }

    /**
     * The board list and create routes live under the same prefix. Constraining
     * the parameter to a number keeps /boards/create out of this page, which is
     * registered before the resource routes.
     */
    public static function routes(Panel $panel, ?PageConfiguration $configuration = null): void
    {
        Route::get(static::getRoutePath($panel), static::class)
            ->middleware(static::getRouteMiddleware($panel))
            ->withoutMiddleware(static::getWithoutRouteMiddleware($panel))
            ->whereNumber('board')
            ->name(static::getRelativeRouteName($panel));
    }

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public int $boardId = 0;

    public function mount(BoardModel $board): void
    {
        Gate::authorize('view', $board);

        $this->boardId = (int) $board->getKey();
    }

    #[Computed]
    public function boardRecord(): BoardModel
    {
        $board = BoardModel::query()->findOrFail($this->boardId);

        Gate::authorize('view', $board);

        return $board;
    }

    public function getTitle(): string
    {
        return $this->boardRecord->name;
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            BoardResource::getUrl('index') => 'Boards',
            $this->boardRecord->name,
        ];
    }

    // ─── Board ────────────────────────────────────────────────────────────────

    public function board(Board $board): Board
    {
        $boardRecord = $this->boardRecord;

        return $board
            ->query(
                BoardItem::query()
                    ->where('board_id', $boardRecord->getKey())
                    ->whereHas('item', function (Builder $query): void {
                        $user = auth()->user();

                        if ($user instanceof User) {
                            app(ItemAccess::class)->scopeVisibleTo($query, $user);
                        }
                    })
                    ->with(['item.assignee', 'item.labels']),
            )
            ->columnIdentifier('board_column_id')
            ->positionIdentifier('position')
            ->recordTitleAttribute('item.title')
            ->stringColumnIds()
            ->columns(fn (): array => $boardRecord->columns
                ->map(fn (BoardColumn $column): Column => Column::make((string) $column->getKey())
                    ->label($column->name)
                    ->color($column->color ?? 'gray')
                    ->limit($column->wip_limit))
                ->all())
            ->filters([
                SelectFilter::make('assignee_id')
                    ->label('Assignee')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query): Builder => $query->whereHas(
                            'item',
                            fn (Builder $query): Builder => $query->where('assignee_id', $data['value']),
                        ),
                    )),

                SelectFilter::make('label_id')
                    ->label('Label')
                    ->options(fn (): array => Label::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query): Builder => $query->whereHas(
                            'item',
                            fn (Builder $query): Builder => $query->whereHas(
                                'labels',
                                fn (Builder $query): Builder => $query->whereKey($data['value']),
                            ),
                        ),
                    )),
            ])
            ->cardSchema(fn (Schema $schema): Schema => $schema->components([
                TextEntry::make('item.is_epic')
                    ->hiddenLabel()
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Epic' : '')
                    ->visible(fn (bool $state): bool => $state),

                TextEntry::make('item.labels.name')
                    ->hiddenLabel()
                    ->badge()
                    ->placeholder(''),

                Flex::make([
                    TextEntry::make('item.assignee.name')
                        ->hiddenLabel()
                        ->icon('heroicon-m-user')
                        ->placeholder('Unassigned'),

                    TextEntry::make('item.due_date')
                        ->hiddenLabel()
                        ->icon('heroicon-m-calendar')
                        ->date()
                        ->placeholder(''),
                ])->from('md'),
            ]))
            ->recordActions([
                Action::make('view')
                    ->label('Open item')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (BoardItem $record): string => ItemResource::getUrl('view', ['record' => $record->item_id])),

                Action::make('remove')
                    ->label('Remove from board')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Remove from board')
                    ->modalDescription(fn (BoardItem $record): string => 'Remove “'.$record->item?->title.'” from this board? The item itself is kept.')
                    ->action(function (BoardItem $record): void {
                        if ($record->item !== null) {
                            Gate::authorize('update', $record->item);
                        }

                        $record->delete();
                    }),
            ])
            ->cardAction('view');
    }

    /**
     * A card move writes the board membership only. The item's native status and
     * its membership on every other board are deliberately left untouched.
     */
    public function moveCard(
        string $cardId,
        string $targetColumnId,
        ?string $afterCardId = null,
        ?string $beforeCardId = null,
    ): void {
        $boardItem = BoardItem::query()
            ->where('board_id', $this->boardId)
            ->whereHas('item')
            ->findOrFail($cardId);

        Gate::authorize('update', $boardItem->item);

        $column = BoardColumn::query()
            ->where('board_id', $this->boardId)
            ->find($targetColumnId);

        if ($column === null) {
            return;
        }

        DB::transaction(function () use ($cardId, $column, $afterCardId, $beforeCardId): void {
            parent::moveCard(
                $cardId,
                (string) $column->getKey(),
                $afterCardId,
                $beforeCardId,
            );
        });
    }

    // ─── Page actions ─────────────────────────────────────────────────────────

    /**
     * The items the user may put on a board: the ones inside their perimeter
     * and the ones they are allowed to change.
     *
     * @return Builder<Item>
     */
    protected function visibleItemsQuery(): Builder
    {
        $query = Item::query();
        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        app(ItemAccess::class)->scopeVisibleTo($query, $user);

        return $query;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addItems')
                ->label('Add items')
                ->icon('heroicon-o-plus')
                ->schema([
                    Select::make('item_ids')
                        ->label('Items')
                        ->helperText('Tasks and projects can be on any number of boards.')
                        ->multiple()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => $this->visibleItemsQuery()
                            ->where('title', 'like', '%'.$search.'%')
                            ->orderBy('title')
                            ->limit(50)
                            ->pluck('title', 'id')
                            ->all())
                        ->getOptionLabelsUsing(fn (array $values): array => $this->visibleItemsQuery()
                            ->whereKey($values)
                            ->pluck('title', 'id')
                            ->all())
                        ->required(),

                    Select::make('board_column_id')
                        ->label('Column')
                        ->options(fn (): array => $this->boardRecord->columns->pluck('name', 'id')->all())
                        ->default(fn (): ?int => $this->boardRecord->columns->first()?->getKey())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $column = BoardColumn::query()
                        ->where('board_id', $this->boardId)
                        ->find($data['board_column_id']);

                    if ($column === null) {
                        return;
                    }

                    $result = app(AddItemsToBoard::class)($this->boardRecord, $data['item_ids'], $column);

                    $body = $result['added'].' added';

                    if ($result['skipped'] > 0) {
                        $body .= ', '.$result['skipped'].' already on the board';
                    }

                    Notification::make()
                        ->success()
                        ->title('Board updated')
                        ->body($body)
                        ->send();
                }),

            Action::make('editBoard')
                ->label('Edit board')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->url(fn (): string => BoardResource::getUrl('edit', ['record' => $this->boardId])),
        ];
    }
}

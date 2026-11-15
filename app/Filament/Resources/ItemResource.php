<?php

namespace App\Filament\Resources;

use App\Actions\AddItemsToBoard;
use App\Enums\ItemStatus;
use App\Filament\Resources\ItemResource\Pages;
use App\Filament\Resources\ItemResource\RelationManagers;
use App\Models\Board;
use App\Models\Item;
use App\Models\Label;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ItemResource extends Resource
{
    protected static ?string $model = Item::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-queue-list';

    protected static UnitEnum|string|null $navigationGroup = 'Collections';

    protected static ?string $slug = 'i';

    protected static ?int $navigationSort = 1;

    // ─── Global search ────────────────────────────────────────────────────────

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->title;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'description'];
    }

    // ─── Form ─────────────────────────────────────────────────────────────────

    /**
     * The description editor, with file attachments on the configured disk.
     * Extracted so the storage wiring is testable without a Livewire round trip.
     */
    public static function descriptionEditor(): Forms\Components\MarkdownEditor
    {
        return Forms\Components\MarkdownEditor::make('description')
            ->columnSpanFull()
            ->fileAttachmentsDisk(config('attachments.disk'))
            ->fileAttachmentsDirectory(config('attachments.directory').'/items')
            ->fileAttachmentsAcceptedFileTypes(config('attachments.allowed_types'))
            ->fileAttachmentsMaxSize(config('attachments.max_size'))
            ->toolbarButtons([
                'bold', 'italic', 'strike',
                'codeBlock', 'bulletList', 'orderedList',
                'link', 'table', 'attachFiles',
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('General')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    static::descriptionEditor(),

                    Forms\Components\Select::make('parent_id')
                        ->label('Parent')
                        ->relationship('parent', 'title')
                        ->searchable()
                        ->nullable(),

                    Forms\Components\Select::make('status')
                        ->options(ItemStatus::class)
                        ->default(ItemStatus::Todo)
                        ->required(),

                    Forms\Components\Select::make('labels')
                        ->label('Labels')
                        ->relationship('labels', 'name')
                        ->multiple()
                        ->preload()
                        ->createOptionForm([
                            Forms\Components\TextInput::make('name')->required(),
                            Forms\Components\ColorPicker::make('color')->default('#6b7280'),
                        ])
                        ->columnSpan(1),
                ]),

            Section::make('Participants')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('assignee_id')
                        ->label('Responsible')
                        ->relationship('assignee', 'name')
                        ->searchable()
                        ->preload()
                        ->nullable(),

                    Forms\Components\Select::make('reporter_id')
                        ->label('From')
                        ->relationship('reporter', 'name')
                        ->searchable()
                        ->preload()
                        ->default(fn () => auth()->id())
                        ->disabled()
                        ->dehydrated(),

                    Forms\Components\Select::make('to')
                        ->label('To')
                        ->relationship('to', 'name')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->columnSpanFull(),

                    Forms\Components\Select::make('cc')
                        ->label('CC')
                        ->relationship('cc', 'name')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->columnSpanFull(),
                ]),

            Section::make('Dates')
                ->columns(3)
                ->schema([
                    Forms\Components\DatePicker::make('start_date')->nullable(),
                    Forms\Components\DatePicker::make('end_date')->nullable(),
                    Forms\Components\DatePicker::make('due_date')->nullable(),

                    Forms\Components\DatePicker::make('etd_date')
                        ->label('ETD')
                        ->nullable(),
                    Forms\Components\DatePicker::make('eta_date')
                        ->label('ETA')
                        ->nullable(),
                ]),

            Section::make('Meta')
                ->columnSpanFull()
                ->schema([
                    Forms\Components\KeyValue::make('meta')
                        ->label('Meta'),
                ]),

        ]);
    }

    // ─── Infolist ─────────────────────────────────────────────────────────────

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Assignees')
                    ->schema([
                        Infolists\Components\TextEntry::make('assignee.name')
                            ->label('Assignee')
                            ->placeholder('Unassigned'),

                        Infolists\Components\TextEntry::make('reporter.name')
                            ->label('Reported by')
                            ->default('—'),

                        Infolists\Components\TextEntry::make('to_names')
                            ->label('To')
                            ->state(fn (?Item $record): string => $record?->to?->pluck('name')?->join(', ') ?? '')
                            ->visible(fn (?Item $record): bool => filled($record?->to)),

                        Infolists\Components\TextEntry::make('cc_names')
                            ->label('CC')
                            ->state(fn (?Item $record): string => $record?->cc?->pluck('name')?->join(', ') ?? '')
                            ->visible(fn (?Item $record): bool => filled($record?->cc)),
                    ]),

                Section::make('Labels')
                    ->schema([
                        Infolists\Components\TextEntry::make('labels.name')
                            ->hiddenLabel()
                            ->badge()
                            ->color(fn ($record) => $record->labels->first()?->color ?? 'gray')
                            ->placeholder('No labels'),
                    ]),

                Section::make('Boards')
                    ->schema([
                        Infolists\Components\TextEntry::make('boards.name')
                            ->hiddenLabel()
                            ->badge()
                            ->placeholder('Not on any board'),
                    ]),

                Section::make('Dates')
                    ->schema([
                        Infolists\Components\TextEntry::make('due_date')
                            ->label('Due date')
                            ->state(fn ($record) => $record->due_date?->format('M j, Y') ?? '—')
                            ->visible(fn ($record) => $record->due_date !== null),

                        Infolists\Components\TextEntry::make('start_date')
                            ->label('Start date')
                            ->state(fn ($record) => $record->start_date?->format('M j, Y') ?? '—')
                            ->visible(fn ($record) => $record->start_date !== null),

                        Infolists\Components\TextEntry::make('end_date')
                            ->label('End date')
                            ->state(fn ($record) => $record->end_date?->format('M j, Y') ?? '—')
                            ->visible(fn ($record) => $record->end_date !== null),

                        Infolists\Components\TextEntry::make('etd_date')
                            ->label('ETD')
                            ->state(fn ($record) => $record->etd_date?->format('M j, Y') ?? '—')
                            ->visible(fn ($record) => $record->etd_date !== null),

                        Infolists\Components\TextEntry::make('eta_date')
                            ->label('ETA')
                            ->state(fn ($record) => $record->eta_date?->format('M j, Y') ?? '—')
                            ->visible(fn ($record) => $record->eta_date !== null),
                    ])
                    ->visible(fn (?Item $record): bool => $record !== null && (
                        $record->due_date !== null
                        || $record->start_date !== null
                        || $record->end_date !== null
                        || $record->etd_date !== null
                        || $record->eta_date !== null
                    )),

                Section::make('Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('parent.title')
                            ->label('Parent')
                            ->url(fn (?Item $record): ?string => $record?->parent
                                ? ItemResource::getUrl('view', ['record' => $record->parent])
                                : null
                            )
                            ->placeholder('—'),

                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Created')
                            ->dateTime('M j, Y H:i'),

                        Infolists\Components\TextEntry::make('updated_at')
                            ->label('Updated')
                            ->dateTime('M j, Y H:i'),
                    ]),

                Section::make('Meta')
                    ->collapsed()
                    ->schema([
                        Infolists\Components\KeyValueEntry::make('meta')
                            ->hiddenLabel(),
                    ])
                    ->visible(fn (?Item $record): bool => filled($record?->meta)),
            ]);
    }

    // ─── Table ────────────────────────────────────────────────────────────────

    /**
     * The fast lane: the few fields people touch while triaging, in one modal,
     * without leaving the list they found the item on.
     */
    public static function quickChangeAction(): Action
    {
        return Action::make('quickChange')
            ->label('Quick change')
            ->icon('heroicon-o-bolt')
            ->fillForm(fn (Item $record): array => [
                'status' => $record->status,
                'assignee_id' => $record->assignee_id,
                'labels' => $record->labels()->pluck('labels.id')->all(),
                'due_date' => $record->due_date,
            ])
            ->schema([
                Forms\Components\Select::make('status')
                    ->options(ItemStatus::class)
                    ->required(),

                Forms\Components\Select::make('assignee_id')
                    ->label('Assignee')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->nullable(),

                Forms\Components\Select::make('labels')
                    ->label('Labels')
                    ->options(fn (): array => Label::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->multiple()
                    ->searchable()
                    ->preload(),

                Forms\Components\DatePicker::make('due_date')
                    ->nullable(),
            ])
            ->action(function (Item $record, array $data): void {
                $record->update([
                    'status' => $data['status'],
                    'assignee_id' => $data['assignee_id'] ?? null,
                    'due_date' => $data['due_date'] ?? null,
                ]);

                $record->labels()->sync($data['labels'] ?? []);

                Notification::make()
                    ->success()
                    ->title('Item updated')
                    ->send();
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(60)
                    ->url(fn (?Item $record): string => $record ? ItemResource::getUrl('view', ['record' => $record]) : '#'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('labels.name')
                    ->label('Labels')
                    ->badge()
                    ->color(fn ($record) => $record->labels->first()?->color ?? 'gray'),

                Tables\Columns\TextColumn::make('boards_count')
                    ->counts('boards')
                    ->label('Boards')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('reporter.name')
                    ->label('From')
                    ->sortable(),

                Tables\Columns\TextColumn::make('to_names')
                    ->label('To')
                    ->state(fn (?Item $record): string => $record?->to?->pluck('name')?->join(', ') ?? '')
                    ->visible(fn (?Item $record): bool => filled($record?->to)),

                Tables\Columns\TextColumn::make('due_date')
                    ->date()
                    ->sortable()
                    ->color(fn ($record) => $record->due_date?->isPast() ? 'danger' : null),

                Tables\Columns\TextColumn::make('start_date')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('end_date')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('etd_date')
                    ->label('ETD')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('eta_date')
                    ->label('ETA')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('parent.title')
                    ->label('Parent')
                    ->limit(30),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(ItemStatus::class),

                Tables\Filters\SelectFilter::make('labels')
                    ->relationship('labels', 'name')
                    ->label('Label'),

                Tables\Filters\SelectFilter::make('reporter')
                    ->relationship('reporter', 'name')
                    ->label('From'),
            ])
            ->actions([
                EditAction::make(),

                Action::make('addToBoard')
                    ->label('Add to board')
                    ->icon('heroicon-o-view-columns')
                    ->schema([
                        Forms\Components\Select::make('board_ids')
                            ->label('Boards')
                            ->options(fn (): array => Board::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->multiple()
                            ->required(),
                    ])
                    ->action(function (Item $record, array $data): void {
                        $added = 0;
                        $skipped = 0;

                        foreach (Board::query()->whereKey($data['board_ids'])->get() as $board) {
                            if ($board->columns()->doesntExist()) {
                                continue;
                            }

                            $result = app(AddItemsToBoard::class)($board, [$record->getKey()]);
                            $added += $result['added'];
                            $skipped += $result['skipped'];
                        }

                        Notification::make()
                            ->success()
                            ->title($added > 0 ? 'Boards updated' : 'Already on the selected boards')
                            ->body($added.' new, '.$skipped.' already present')
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkAction::make('assign')
                    ->label('Assign to…')
                    ->form([
                        Forms\Components\Select::make('assignee_id')
                            ->label('Assignee')
                            ->options(User::pluck('name', 'id'))
                            ->required(),
                    ])
                    ->action(fn ($records, array $data) => $records->each->update(['assignee_id' => $data['assignee_id']])
                    ),

                BulkAction::make('addToBoard')
                    ->label('Add to board')
                    ->icon('heroicon-o-view-columns')
                    ->schema([
                        Forms\Components\Select::make('board_ids')
                            ->label('Boards')
                            ->options(fn (): array => Board::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->multiple()
                            ->required(),
                    ])
                    ->action(function ($records, array $data): void {
                        $itemIds = $records->modelKeys();

                        foreach (Board::query()->whereKey($data['board_ids'])->get() as $board) {
                            if ($board->columns()->doesntExist()) {
                                continue;
                            }

                            app(AddItemsToBoard::class)($board, $itemIds);
                        }

                        Notification::make()
                            ->success()
                            ->title('Added to the board')
                            ->send();
                    }),

                DeleteBulkAction::make(),
            ])
            ->defaultSort('position');
    }

    // ─── Pages & Relations ────────────────────────────────────────────────────

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListItems::route('/'),
            'create' => Pages\CreateItem::route('/create'),
            'view' => Pages\ViewItem::route('/{record}'),
            'edit' => Pages\EditItem::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ChildrenRelationManager::class,
        ];
    }
}

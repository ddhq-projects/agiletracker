<?php

namespace App\Filament\Resources;

use App\Access\ItemAccess;
use App\Actions\AddItemsToBoard;
use App\Enums\ItemStatus;
use App\Enums\ItemVisibility;
use App\Enums\PermissionName;
use App\Filament\Resources\ItemResource\Pages;
use App\Filament\Resources\ItemResource\RelationManagers;
use App\Models\Board;
use App\Models\Group as GroupModel;
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
use Filament\Pages\BasePage;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class ItemResource extends Resource
{
    protected static ?string $model = Item::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-queue-list';

    protected static UnitEnum|string|null $navigationGroup = 'Collections';

    protected static ?string $slug = 'i';

    protected static ?int $navigationSort = 1;

    /**
     * Cached per user and item: the policy check runs once per rendered form
     * instead of once per field.
     *
     * @var array<string, list<string>|null>
     */
    protected static array $updatableAttributesCache = [];

    // ─── Global search ────────────────────────────────────────────────────────

    /**
     * Everything below reads items through this query: the list, the record
     * routes, global search and relation selects. An item the user cannot see
     * simply does not exist for the UI and answers 404 on a direct URL.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['to', 'cc', 'accessGroups']);

        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return app(ItemAccess::class)->scopeVisibleTo($query, $user);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

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
            Grid::make(['default' => 1, 'lg' => 3])
                ->columnSpanFull()
                ->schema([
                    Group::make([
                        Section::make('General')
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->required()
                                    ->maxLength(255)
                                    ->disabled(fn (?Item $record): bool => static::isLimitedUpdater($record)),

                                static::descriptionEditor()
                                    ->disabled(fn (?Item $record): bool => static::isLimitedUpdater($record)),
                            ]),

                        Section::make('Dates')
                            ->columns(3)
                            ->schema([
                                Forms\Components\DatePicker::make('due_date')->nullable(),
                                Forms\Components\DatePicker::make('start_date')->nullable(),
                                Forms\Components\DatePicker::make('end_date')->nullable(),

                                Forms\Components\DatePicker::make('etd_date')
                                    ->label('ETD')
                                    ->nullable(),
                                Forms\Components\DatePicker::make('eta_date')
                                    ->label('ETA')
                                    ->nullable(),
                            ])
                            ->disabled(fn (?Item $record): bool => static::isLimitedUpdater($record)),

                        Section::make('Meta')
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                Forms\Components\KeyValue::make('meta')
                                    ->label('Meta'),
                            ])
                            ->disabled(fn (?Item $record): bool => static::isLimitedUpdater($record)),
                    ])
                        ->columnSpan(['default' => 1, 'lg' => 2])
                        ->extraAttributes(['class' => 'min-w-0']),

                    Group::make([
                        static::formActions(),

                        Section::make('Workflow')
                            ->schema([
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
                                    ->disabled(fn (?Item $record): bool => static::isLimitedUpdater($record)),

                                Forms\Components\Select::make('parent_id')
                                    ->label('Parent')
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (string $search, ?Item $record): array => static::parentOptions($search, $record))
                                    ->getOptionLabelUsing(fn (mixed $value): ?string => static::parentLabel($value))
                                    ->nullable()
                                    ->disabled(fn (?Item $record): bool => static::isLimitedUpdater($record)),
                            ]),

                        Section::make('Participants')
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
                                    ->disabled(fn (?Item $record): bool => static::isLimitedUpdater($record)),

                                Forms\Components\Select::make('cc')
                                    ->label('CC')
                                    ->relationship('cc', 'name')
                                    ->multiple()
                                    ->searchable()
                                    ->preload()
                                    ->disabled(fn (?Item $record): bool => static::isLimitedUpdater($record)),
                            ]),

                        static::accessSection(),
                    ])
                        ->columnSpan(['default' => 1, 'lg' => 1]),
                ]),
        ]);
    }

    /**
     * Who sees the item and who may change that. Visible only to the people
     * allowed to manage access, so it never hints at a wider sharing model.
     */
    protected static function accessSection(): Section
    {
        return Section::make('Access')
            ->collapsible()
            ->schema([
                Forms\Components\Select::make('project_group_id')
                    ->label('Project')
                    ->options(fn (): array => GroupModel::query()->projects()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->nullable(),

                Forms\Components\Select::make('visibility')
                    ->options(ItemVisibility::options())
                    ->default(ItemVisibility::Restricted->value)
                    ->required()
                    ->helperText('A public item is visible to everyone; a restricted one only to its participants and their groups.'),

                Forms\Components\Select::make('access_group_ids')
                    ->label('Shared with groups')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => GroupModel::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->dehydrated(false),
            ])
            ->visible(fn (?Item $record): bool => static::canManageAccess($record));
    }

    public static function canManageAccess(?Item $record): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($record === null) {
            return $user->can(PermissionName::ItemsCreate->value);
        }

        return Gate::allows('manageAccess', $record);
    }

    /**
     * The possible parents: only items the user can see, never the item
     * itself. A hidden item cannot be discovered as a parent option.
     *
     * @return array<string, string>
     */
    public static function parentOptions(string $search, ?Item $record): array
    {
        return static::getEloquentQuery()
            ->when($record instanceof Item, fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()))
            ->where('title', 'like', '%'.$search.'%')
            ->orderBy('title')
            ->limit(50)
            ->pluck('title', 'id')
            ->all();
    }

    public static function parentLabel(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return static::getEloquentQuery()->whereKey($value)->value('title');
    }

    /**
     * The assignee of a restricted item moves it along but does not rewrite it.
     */
    /**
     * The assignee of a restricted item moves it along but does not rewrite it.
     */
    public static function isLimitedUpdater(?Item $record): bool
    {
        $attributes = static::updatableAttributes($record);

        return $attributes !== false && $attributes !== null;
    }

    /**
     * True when the user may change every field of the item.
     */
    public static function isFullyEditable(?Item $record): bool
    {
        return static::updatableAttributes($record) === null;
    }

    /**
     * @return list<string>|null|false false when there is nothing to evaluate
     */
    protected static function updatableAttributes(?Item $record): array|false|null
    {
        $user = auth()->user();

        if (! $record instanceof Item || ! $user instanceof User) {
            return false;
        }

        $key = $user->getKey().':'.$record->getKey();

        if (! array_key_exists($key, self::$updatableAttributesCache)) {
            self::$updatableAttributesCache[$key] = app(ItemAccess::class)->updatableAttributes($user, $record);
        }

        return self::$updatableAttributesCache[$key];
    }

    /**
     * The page owns the actions (save, save and view, create another, cancel);
     * the layout only decides that they sit above the reference fields instead
     * of in a footer below the whole form.
     */
    protected static function formActions(): Actions
    {
        return Actions::make(fn (BasePage $livewire): array => method_exists($livewire, 'getRailFormActions')
            ? $livewire->getRailFormActions()
            : [])
            ->alignment(Alignment::Start);
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

                Section::make('Access')
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('visibility')
                            ->badge()
                            ->color(fn (Item $record): string => $record->isPublic() ? 'success' : 'warning'),

                        Infolists\Components\TextEntry::make('projectGroup.name')
                            ->label('Project')
                            ->placeholder('—'),

                        Infolists\Components\TextEntry::make('access_group_names')
                            ->label('Shared with')
                            ->state(fn (Item $record): string => $record->accessGroups->pluck('name')->join(', '))
                            ->placeholder('—')
                            ->columnSpanFull(),
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
            ->visible(fn (Item $record): bool => static::isFullyEditable($record))
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
                Gate::authorize('update', $record);

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
                    ->visible(fn (Item $record): bool => Gate::allows('update', $record))
                    ->schema([
                        Forms\Components\Select::make('board_ids')
                            ->label('Boards')
                            ->options(fn (): array => Board::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->multiple()
                            ->required(),
                    ])
                    ->action(function (Item $record, array $data): void {
                        Gate::authorize('update', $record);

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
                    ->authorizeIndividualRecords('update')
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
                    ->authorizeIndividualRecords('update')
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

                            app(AddItemsToBoard::class)($board, $itemIds, user: auth()->user());
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

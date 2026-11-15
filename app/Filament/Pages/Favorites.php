<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\FavoriteOrderService;
use App\Actions\ResolveFavoriteTarget;
use App\Models\Favorite as FavoriteModel;
use App\Models\Item;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class Favorites extends Page implements HasTable
{
    use InteractsWithTable;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-star';

    protected static UnitEnum|string|null $navigationGroup = 'Collections';

    protected static ?string $navigationLabel = 'Favorites';

    protected static ?string $slug = 'favorites';

    protected static ?string $title = 'Favorites';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament-panels::pages.page';

    public function table(Table $table): Table
    {
        $resolver = app(ResolveFavoriteTarget::class);

        return $table
            ->query(fn (): Builder => FavoriteModel::query()
                ->where('user_id', auth()->id())
                ->ordered()
                ->with('favoritable'),
            )
            ->paginated(false)
            ->reorderable('position', fn (): bool => $this->canReorder())
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->limit(60)
                    ->url(fn (FavoriteModel $record): ?string => $resolver($record)->url)
                    ->openUrlInNewTab(fn (FavoriteModel $record): bool => $resolver($record)->isExternal),

                Tables\Columns\TextColumn::make('position')
                    ->label('Priority')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('type_badge')
                    ->label('Type')
                    ->badge()
                    ->state(fn (FavoriteModel $record): string => match (true) {
                        $record->url !== null => 'Link',
                        $record->favoritable_type !== null => class_basename($record->favoritable_type),
                        default => 'Unknown',
                    })
                    ->colors([
                        'success' => 'Link',
                        'primary' => 'Item',
                        'warning' => 'User',
                    ]),

                Tables\Columns\TextColumn::make('details')
                    ->label('Details')
                    ->state(function (FavoriteModel $record) use ($resolver): string {
                        $target = $resolver($record);

                        if (! $target->isAvailable()) {
                            return 'Unavailable';
                        }

                        return $record->url !== null
                            ? (parse_url($record->url, PHP_URL_HOST) ?? $record->url)
                            : (string) ($record->favoritable?->title ?? $record->favoritable?->name ?? '—');
                    })
                    ->url(fn (FavoriteModel $record): ?string => $resolver($record)->url)
                    ->openUrlInNewTab(fn (FavoriteModel $record): bool => $resolver($record)->isExternal),
            ])
            ->actions([
                EditAction::make()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('priority')
                            ->label('Priority')
                            ->required()
                            ->numeric()
                            ->rule('integer')
                            ->minValue(1)
                            ->maxValue(fn (): int => FavoriteModel::query()
                                ->where('user_id', auth()->id())
                                ->count()),
                    ])
                    ->fillForm(fn (FavoriteModel $record): array => [
                        'name' => $record->name,
                        'priority' => $record->position,
                    ])
                    ->action(function (FavoriteModel $record, array $data): void {
                        $record->update(['name' => $data['name']]);

                        app(FavoriteOrderService::class)->moveToPriority(
                            auth()->user(),
                            $record->id,
                            (int) $data['priority'],
                        );

                        $this->flushCachedTableRecords();

                        $this->dispatch('refresh-sidebar');
                    }),

                DeleteAction::make()
                    ->after(function (): void {
                        $this->flushCachedTableRecords();

                        $this->dispatch('refresh-sidebar');
                    }),
            ]);
    }

    /**
     * Reordering only makes sense while the full, unfiltered list is shown in
     * the stored order, so search and a temporary sort disable it.
     */
    public function canReorder(): bool
    {
        return blank($this->getTableSearch()) && blank($this->getTableSortColumn());
    }

    /**
     * Persist the dragged order through the domain service instead of writing
     * the column directly, so a partial or tampered list never lands.
     *
     * @param  array<int, int|string>  $order
     */
    #[Exposed]
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        if (! $this->getTable()->isReorderable()) {
            return;
        }

        $this->getTable()->callBeforeReordering($order);

        app(FavoriteOrderService::class)->reorder(
            auth()->user(),
            $order,
            requireCompleteSet: true,
        );

        $this->flushCachedTableRecords();

        $this->getTable()->callAfterReordering($order);

        $this->dispatch('refresh-sidebar');
    }

    /**
     * The page itself plus the personal top five, so the sidebar group is built
     * for the current user on every navigation render.
     *
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        return [
            ...parent::getNavigationItems(),
            ...static::favoriteNavigationItems(),
        ];
    }

    /**
     * @return array<NavigationItem>
     */
    protected static function favoriteNavigationItems(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $resolver = app(ResolveFavoriteTarget::class);

        $favorites = FavoriteModel::query()
            ->where('user_id', $user->id)
            ->ordered()
            ->with('favoritable')
            ->get()
            ->map(fn (FavoriteModel $favorite): array => [$favorite, $resolver($favorite, $user)])
            ->filter(fn (array $pair): bool => $pair[1]->isAvailable())
            ->take(5)
            ->values();

        return $favorites
            ->map(fn (array $pair, int $index): NavigationItem => NavigationItem::make($pair[0]->name)
                ->key('favorite-'.$pair[0]->id)
                ->group('Favorites')
                ->sort($index)
                ->url($pair[1]->url)
                ->openUrlInNewTab($pair[1]->isExternal))
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addFavorite')
                ->label('Add to favorites')
                ->icon('heroicon-o-plus')
                ->schema([
                    Select::make('kind')
                        ->label('Type')
                        ->options([
                            'link' => 'External link',
                            'model' => 'Internal object',
                        ])
                        ->required()
                        ->live()
                        ->default('link'),

                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('url')
                        ->label('URL')
                        ->rule('url:http,https')
                        ->required()
                        ->visible(fn (Get $get): bool => $get('kind') === 'link'),

                    Select::make('favoritable_type')
                        ->label('Object type')
                        ->options([
                            Item::class => 'Item',
                            User::class => 'User',
                        ])
                        ->required()
                        ->visible(fn (Get $get): bool => $get('kind') === 'model')
                        ->live(),

                    Select::make('favoritable_id')
                        ->label('Object')
                        ->required()
                        ->visible(fn (Get $get): bool => $get('kind') === 'model')
                        ->searchable()
                        ->getSearchResultsUsing(function (string $search, Get $get): array {
                            $type = $get('favoritable_type');

                            if (! $type || ! class_exists($type)) {
                                return [];
                            }

                            $field = $type === Item::class ? 'title' : 'name';

                            return $type::where($field, 'like', "%{$search}%")
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn ($model): array => [
                                    $model->id => ($model->title ?? $model->name).' ['.class_basename($type).']',
                                ])
                                ->toArray();
                        })
                        ->getOptionLabelUsing(function (?int $value, Get $get): ?string {
                            if (! $value) {
                                return null;
                            }

                            $type = $get('favoritable_type');

                            if (! $type || ! class_exists($type)) {
                                return null;
                            }

                            $model = $type::find($value);

                            return $model ? (string) ($model->title ?? $model->name) : null;
                        }),
                ])
                ->action(function (array $data): void {
                    $favorite = new FavoriteModel;
                    $favorite->user_id = auth()->id();
                    $favorite->name = $data['name'];

                    if ($data['kind'] === 'link') {
                        $favorite->url = $data['url'];
                    } else {
                        $favorite->favoritable_type = $data['favoritable_type'];
                        $favorite->favoritable_id = $data['favoritable_id'];
                    }

                    $favorite->save();

                    $this->flushCachedTableRecords();

                    $this->dispatch('refresh-sidebar');
                })
                ->successNotificationTitle('Added to favorites'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Pages\BoardView;
use App\Filament\Resources\BoardResource\Pages;
use App\Models\Board;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class BoardResource extends Resource
{
    protected static ?string $model = Board::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-view-columns';

    protected static UnitEnum|string|null $navigationGroup = 'Collections';

    protected static ?string $navigationLabel = 'Boards';

    protected static ?string $modelLabel = 'board';

    protected static ?string $pluralModelLabel = 'boards';

    protected static ?int $navigationSort = 2;

    /**
     * Filament colour names, so a column header keeps the admin panel palette.
     *
     * @return array<string, string>
     */
    public static function colorOptions(): array
    {
        return [
            'gray' => 'Gray',
            'primary' => 'Blue',
            'info' => 'Sky',
            'success' => 'Green',
            'warning' => 'Amber',
            'danger' => 'Red',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Board')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(100)
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('description')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),

            Section::make('Columns')
                ->description('Cards move between these columns. Drag a column to reorder it.')
                ->hiddenOn('create')
                ->schema([
                    Forms\Components\Repeater::make('columns')
                        ->relationship()
                        ->hiddenLabel()
                        ->schema([
                            Forms\Components\TextInput::make('name')
                                ->required()
                                ->maxLength(50),

                            Forms\Components\Select::make('color')
                                ->options(self::colorOptions())
                                ->default('gray')
                                ->selectablePlaceholder(false),

                            Forms\Components\TextInput::make('wip_limit')
                                ->label('WIP limit')
                                ->numeric()
                                ->integer()
                                ->minValue(1)
                                ->placeholder('No limit'),
                        ])
                        ->columns(3)
                        ->orderColumn('position')
                        ->reorderableWithButtons()
                        ->minItems(1)
                        ->addActionLabel('Add column')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Board $record): string => BoardView::getUrl(['board' => $record])),

                Tables\Columns\TextColumn::make('description')
                    ->limit(60)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('columns_count')
                    ->counts('columns')
                    ->label('Columns'),

                Tables\Columns\TextColumn::make('items_count')
                    ->counts('items')
                    ->label('Items'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('position')
            ->reorderable('position')
            ->actions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Board $record): string => BoardView::getUrl(['board' => $record])),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->recordUrl(fn (Board $record): string => BoardView::getUrl(['board' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBoards::route('/'),
            'create' => Pages\CreateBoard::route('/create'),
            'edit' => Pages\EditBoard::route('/{record}/edit'),
        ];
    }
}

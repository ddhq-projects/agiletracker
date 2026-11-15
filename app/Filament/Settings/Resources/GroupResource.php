<?php

declare(strict_types=1);

namespace App\Filament\Settings\Resources;

use App\Enums\GroupType;
use App\Filament\Settings\Resources\GroupResource\Pages\CreateGroup;
use App\Filament\Settings\Resources\GroupResource\Pages\EditGroup;
use App\Filament\Settings\Resources\GroupResource\Pages\ListGroups;
use App\Models\Group;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class GroupResource extends Resource
{
    protected static ?string $model = Group::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-user-group';

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 8;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Group')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),

                    Forms\Components\Select::make('type')
                        ->options(GroupType::options())
                        ->default(GroupType::Other->value)
                        ->required(),

                    Forms\Components\Select::make('parent_id')
                        ->label('Parent group')
                        ->options(fn (?Group $record): array => static::parentOptions($record))
                        ->nullable()
                        ->searchable(),

                    Forms\Components\Textarea::make('description')
                        ->rows(2),
                ]),

            Section::make('Composition')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('member_ids')
                        ->label('Members')
                        ->multiple()
                        ->searchable()
                        ->options(fn (): array => static::userOptions())
                        ->dehydrated(false),

                    Forms\Components\Select::make('manager_ids')
                        ->label('Managers')
                        ->multiple()
                        ->searchable()
                        ->options(fn (): array => static::userOptions())
                        ->helperText('A manager also belongs to the group and inherits the rights of its members.')
                        ->dehydrated(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('type')->badge()->sortable(),
                Tables\Columns\TextColumn::make('parent.name')->label('Parent')->placeholder('—'),
                Tables\Columns\TextColumn::make('users_count')
                    ->counts('users')
                    ->label('Members')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('items_count')
                    ->counts('items')
                    ->label('Items')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('created_at')->date()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(GroupType::options()),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (Group $record): bool => ! $record->items()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGroups::route('/'),
            'create' => CreateGroup::route('/create'),
            'edit' => EditGroup::route('/{record}/edit'),
        ];
    }

    /**
     * A group cannot be its own ancestor, so the group and everything below it
     * are left out of the parent options.
     *
     * @return array<string, string>
     */
    public static function parentOptions(?Group $record): array
    {
        $excluded = $record === null ? [] : [(int) $record->getKey(), ...$record->descendantIds()];

        return Group::query()
            ->when($excluded !== [], fn ($query) => $query->whereNotIn('id', $excluded))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function userOptions(): array
    {
        return User::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}

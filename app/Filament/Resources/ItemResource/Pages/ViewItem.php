<?php

declare(strict_types=1);

namespace App\Filament\Resources\ItemResource\Pages;

use App\Filament\Resources\ItemResource;
use App\Filament\Resources\ItemResource\RelationManagers\ChildrenRelationManager;
use Filament\Actions;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Relaticle\Comments\Livewire\Comments;

class ViewItem extends ViewRecord
{
    protected static string $resource = ItemResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->title;
    }

    /**
     * Two columns: the item and its discussion on the left, the reference data
     * on the right — the layout an issue tracker's issue page uses.
     */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->record($this->getRecord())
            ->components([
                Grid::make(['default' => 1, 'lg' => 3])
                    ->schema([
                        Group::make([
                            Section::make()
                                ->schema([
                                    Flex::make([
                                        TextEntry::make('status')
                                            ->badge(),

                                        TextEntry::make('is_epic')
                                            ->badge()
                                            ->color('warning')
                                            ->formatStateUsing(fn (bool $state): string => $state ? 'Epic' : '')
                                            ->visible(fn (bool $state): bool => $state),
                                    ]),

                                    TextEntry::make('description')
                                        ->hiddenLabel()
                                        ->markdown()
                                        ->default('No description.'),
                                ]),

                            Section::make('Sub-items')
                                ->collapsible()
                                ->persistCollapsed()
                                ->schema([
                                    Livewire::make(ChildrenRelationManager::class, [
                                        'ownerRecord' => $this->getRecord(),
                                        'pageClass' => static::class,
                                    ])->key(ChildrenRelationManager::class),
                                ]),

                            Livewire::make(Comments::class, fn (): array => [
                                'model' => $this->getRecord(),
                            ]),
                        ])
                            ->columnSpan(['default' => 1, 'lg' => 2])
                            ->extraAttributes(['class' => 'min-w-0']),

                        Group::make([
                            EmbeddedSchema::make('infolist'),
                        ])
                            ->columnSpan(['default' => 1, 'lg' => 1]),
                    ]),
            ]);
    }
}

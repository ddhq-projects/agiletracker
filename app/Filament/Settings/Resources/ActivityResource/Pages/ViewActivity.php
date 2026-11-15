<?php

declare(strict_types=1);

namespace App\Filament\Settings\Resources\ActivityResource\Pages;

use App\Filament\Settings\Resources\ActivityResource;
use App\Models\Activity;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewActivity extends ViewRecord
{
    protected static string $resource = ActivityResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Entry')
                ->columns(2)
                ->schema([
                    TextEntry::make('created_at')
                        ->label('When')
                        ->dateTime('M j, Y H:i:s'),

                    TextEntry::make('causer.name')
                        ->label('Who')
                        ->placeholder('System'),

                    TextEntry::make('event')
                        ->badge()
                        ->placeholder('—'),

                    TextEntry::make('log_name')
                        ->label('Log')
                        ->placeholder('—'),

                    TextEntry::make('subject')
                        ->label('Subject')
                        ->state(fn (Activity $record): string => $record->subject_type === null
                            ? '—'
                            : class_basename($record->subject_type).' #'.$record->subject_id),

                    TextEntry::make('description'),
                ]),

            Section::make('Changes')
                ->schema([
                    ViewEntry::make('attribute_changes')
                        ->hiddenLabel()
                        ->view('filament.activity-diff')
                        ->viewData(fn (Activity $record): array => ['activity' => $record]),
                ]),

            Section::make('Details')
                ->collapsed()
                ->schema([
                    TextEntry::make('id')->label('Activity ID'),
                    TextEntry::make('subject_type')->label('Subject type')->placeholder('—'),
                    TextEntry::make('subject_id')->label('Subject ID')->placeholder('—'),
                    TextEntry::make('properties')
                        ->label('Properties')
                        ->state(fn (Activity $record): string => (string) json_encode($record->properties?->all() ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                        ->columnSpanFull(),
                ]),
        ]);
    }
}

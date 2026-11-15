<?php

declare(strict_types=1);

namespace App\Filament\Resources\BoardResource\Pages;

use App\Filament\Pages\BoardView;
use App\Filament\Resources\BoardResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBoard extends EditRecord
{
    protected static string $resource = BoardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('open')
                ->label('Open board')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (): string => BoardView::getUrl(['board' => $this->getRecord()])),

            Actions\DeleteAction::make(),
        ];
    }
}

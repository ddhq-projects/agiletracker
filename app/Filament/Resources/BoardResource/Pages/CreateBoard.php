<?php

declare(strict_types=1);

namespace App\Filament\Resources\BoardResource\Pages;

use App\Filament\Resources\BoardResource;
use App\Models\Board;
use Filament\Resources\Pages\CreateRecord;

class CreateBoard extends CreateRecord
{
    protected static string $resource = BoardResource::class;

    /**
     * A new board is usable right away: its columns start as the four workflow
     * states and can be renamed, recoloured, reordered or extended afterwards.
     */
    protected function afterCreate(): void
    {
        /** @var Board $board */
        $board = $this->getRecord();

        $board->columns()->createMany(Board::defaultColumns());
    }
}

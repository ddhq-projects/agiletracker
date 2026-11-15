<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ItemStatus;
use Illuminate\Support\Facades\DB;
use Relaticle\Flowforge\Services\DecimalPosition;

/**
 * The old Kanban page showed every item in one status-driven board. Replacing
 * it with first-class boards must not hide that work: this creates a default
 * "Main board" whose columns mirror the four workflow states and puts every
 * existing item on it, keeping its order inside the matching column.
 */
final class BackfillDefaultBoard
{
    public function __invoke(): void
    {
        if (DB::table('boards')->exists()) {
            return;
        }

        $boardId = DB::table('boards')->insertGetId([
            'name' => 'Main board',
            'description' => 'Every item, grouped by its workflow status.',
            'position' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var array<string, int> $columnIds */
        $columnIds = [];

        foreach (ItemStatus::cases() as $index => $status) {
            $columnIds[$status->value] = DB::table('board_columns')->insertGetId([
                'board_id' => $boardId,
                'name' => $status->getLabel(),
                'color' => $status->getColor(),
                'position' => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($columnIds as $status => $columnId) {
            $position = DecimalPosition::forEmptyColumn();

            $itemIds = DB::table('items')
                ->where('status', $status)
                ->whereNull('deleted_at')
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('id');

            foreach ($itemIds as $itemId) {
                DB::table('board_items')->insert([
                    'board_id' => $boardId,
                    'item_id' => $itemId,
                    'board_column_id' => $columnId,
                    'position' => $position,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $position = DecimalPosition::after($position);
            }
        }
    }
}

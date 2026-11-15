<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Facades\DB;
use Relaticle\Flowforge\Services\DecimalPosition;

/**
 * Rewrites every item's board position in deterministic ID order, one sequence
 * per workflow status, so position data inherited from the old integer column
 * never collides once the unique `(status, position)` index exists.
 *
 * Soft-deleted rows are included: the unique index covers them too.
 */
final class RegenerateItemPositions
{
    public function __invoke(): void
    {
        $statuses = DB::table('items')->distinct()->orderBy('status')->pluck('status');

        foreach ($statuses as $status) {
            $position = DecimalPosition::forEmptyColumn();

            $ids = DB::table('items')
                ->where('status', $status)
                ->orderBy('id')
                ->pluck('id');

            foreach ($ids as $id) {
                DB::table('items')->where('id', $id)->update(['position' => $position]);

                $position = DecimalPosition::after($position);
            }
        }
    }
}

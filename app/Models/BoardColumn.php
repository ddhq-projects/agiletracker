<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Relaticle\Flowforge\Services\DecimalPosition;

/**
 * One stage of a board. Columns are editable per board, so two boards can
 * describe the same work differently; deleting a column keeps its cards by
 * moving them to the board's first remaining column.
 */
class BoardColumn extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'board_id',
        'name',
        'color',
        'position',
        'wip_limit',
    ];

    protected $casts = [
        'position' => 'integer',
        'wip_limit' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (BoardColumn $column): void {
            if ($column->position !== null) {
                return;
            }

            $column->position = (int) static::query()
                ->where('board_id', $column->board_id)
                ->max('position') + 1;
        });

        static::deleting(function (BoardColumn $column): void {
            $fallback = static::query()
                ->where('board_id', $column->board_id)
                ->whereKeyNot($column->getKey())
                ->orderBy('position')
                ->orderBy('id')
                ->first();

            if ($fallback === null) {
                // The last column cannot be removed through the UI; keep the
                // cards rather than letting the foreign key null them.
                return;
            }

            DB::transaction(function () use ($column, $fallback): void {
                $lastPosition = BoardItem::query()
                    ->where('board_column_id', $fallback->getKey())
                    ->max('position');

                $position = $lastPosition === null
                    ? DecimalPosition::forEmptyColumn()
                    : DecimalPosition::after((string) $lastPosition);

                $boardItems = BoardItem::query()
                    ->where('board_column_id', $column->getKey())
                    ->orderBy('position')
                    ->orderBy('id')
                    ->get();

                foreach ($boardItems as $boardItem) {
                    $boardItem->update([
                        'board_column_id' => $fallback->getKey(),
                        'position' => $position,
                    ]);

                    $position = DecimalPosition::after($position);
                }
            });
        });
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function boardItems(): HasMany
    {
        return $this->hasMany(BoardItem::class);
    }
}

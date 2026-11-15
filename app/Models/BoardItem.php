<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Relaticle\Flowforge\Services\DecimalPosition;

/**
 * The membership of one item on one board: its column there and its position
 * inside that column. Flowforge moves this row, never the item itself, so a
 * drag on one board leaves the item's workflow status and other boards alone.
 */
class BoardItem extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'board_id',
        'item_id',
        'board_column_id',
        'position',
    ];

    protected static function booted(): void
    {
        static::creating(function (BoardItem $boardItem): void {
            if ($boardItem->position !== null || $boardItem->board_column_id === null) {
                return;
            }

            $lastPosition = static::query()
                ->where('board_column_id', $boardItem->board_column_id)
                ->max('position');

            $boardItem->position = $lastPosition === null
                ? DecimalPosition::forEmptyColumn()
                : DecimalPosition::after((string) $lastPosition);
        });
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'board_column_id');
    }
}

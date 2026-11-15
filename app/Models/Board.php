<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ItemStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A collection of items with its own columns. Boards are independent of the
 * item's native workflow status: the same item can sit in different columns on
 * different boards, and any item — including a project/epic — can join any
 * number of boards.
 */
class Board extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Board $board): void {
            if ($board->position !== null) {
                return;
            }

            $board->position = (int) static::query()->max('position') + 1;
        });
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function columns(): HasMany
    {
        return $this->hasMany(BoardColumn::class)->orderBy('position')->orderBy('id');
    }

    public function boardItems(): HasMany
    {
        return $this->hasMany(BoardItem::class);
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'board_items')
            ->withPivot(['board_column_id', 'position'])
            ->withTimestamps();
    }

    // ─── Defaults ─────────────────────────────────────────────────────────────

    /**
     * The columns a board starts with when it is created through the UI.
     *
     * @return list<array{name: string, color: string, position: int}>
     */
    public static function defaultColumns(): array
    {
        return array_map(
            fn (ItemStatus $status, int $index): array => [
                'name' => $status->getLabel(),
                'color' => $status->getColor(),
                'position' => $index,
            ],
            ItemStatus::cases(),
            array_keys(ItemStatus::cases()),
        );
    }
}

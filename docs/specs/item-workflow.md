---
title: "Item workflow state and Kanban board"
status: done
completed: 2026-11-15
rfc: ../rfc/2026-11-14-relaticle-item-features.md
---

# Spec: Item workflow state and Kanban board

## What was done

Items carry a native workflow status again, and the Kanban page is a real board.

- `items.status` is a string column, not null, default `todo`, cast to `App\Enums\ItemStatus`
  (`todo`, `in_progress`, `review`, `done`). The enum supplies the label and Filament colour of every
  state, so the board, the table, the infolist and the API all read one definition.
- `items.position` is `DECIMAL(20,10)` and nullable, with a unique index on `(status, position)`.
  `Item` assigns the next position in its column on create (`DecimalPosition`), so a new card lands at
  the bottom of its column without a move.
- `app/Filament/Pages/KanbanBoard.php` is a `Relaticle\Flowforge\BoardPage`. Four columns come from
  `ItemStatus::cases()`; cards show title, labels, assignee and due date; label and assignee filters
  come from ordinary Filament table filters; the card opens the Item view page.
- `moveCard()` authorizes `ItemPolicy::update`, runs inside a transaction and lets the model's own
  activity log record the status change. Position-only moves write no activity.
- The Item Filament table no longer has drag reordering (`->reorderable('position')` removed), so the
  board and the table cannot be competing position writers.
- Labels stay simple many-to-many tags; a card move never attaches or detaches a label.

The backfill (one-off, part of the migrations, re-runnable through `App\Actions\BackfillItemStatus`):

- the old workflow labels `To Do`, `In Progress`, `Review`, `Done` (and the lowercase/underscore keys
  `todo`, `in_progress` the placeholder handler could write) were copied onto `items.status`, taking
  the furthest state when an item carried several (`done > review > in_progress > todo`);
- those labels were detached from items afterwards; the `Label` rows themselves are kept and every
  other label is untouched;
- `items.position` was regenerated in ID order, one sequence per status, including soft-deleted rows
  (`App\Actions\RegenerateItemPositions`), before the unique index was added.

The demo seeder now assigns a status and no longer seeds workflow labels.

## Key decisions

- **Status is a column, not a label or an EAV value.** Flowforge filters cards by the column
  identifier directly; a label- or custom-field-backed column would need joins and custom move hooks.
- **One source of truth for the states.** `ItemStatus` is used by the enum-backed Filament `Select`
  and `SelectFilter`, by `Column::enum()`, and by the API rule `Rule::in(ItemStatus::values())`.
- **The API keeps `type`.** `status` is an additive Item field. `type` (virtual over `meta.type`) is
  unchanged until the custom-fields stage.
- **Ordering is per column.** `DecimalPosition` sequences are independent per status, and regeneration
  starts every column at `65535`, so two columns can share a position value without a collision.

## Deviations from RFC

- **`Item` carries a default attribute for `status`.** Without it, an item created without an explicit
  status would have the DB default but no attribute in PHP, and the first unrelated update would be
  logged as a status change. The default attribute keeps the activity log honest.
- **A card that is dragged into an unknown column id is ignored.** `moveCard()` returns early when the
  target is not an `ItemStatus` value, rather than persisting an out-of-enum string the board can no
  longer display.
- **Tests cover SQLite only.** The suite runs on SQLite `:memory:`; the MySQL/PostgreSQL paths of the
  migration (column change, unique index) are not executed in CI.

## Known limitations / next steps

- Only one board over all items. Multiple boards, per-board columns, WIP limits, swimlanes and card
  ordering across boards are out of scope.
- The board shows every item regardless of type or parent; a project-scoped board is a separate
  feature.
- `items.meta` is still present and `type` is still stored there until the custom-fields stage lands.

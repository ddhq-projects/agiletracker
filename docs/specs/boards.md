---
title: "Boards"
status: done
completed: 2026-11-15
rfc: ../rfc/archive/2026-05-30-kanban-board.md
---

# Spec: Boards

## What was done

A board is a collection of items with its own columns. Any item — a task or a
whole project/epic — can be on any number of boards, and its stage is stored per
board, so the same item can be `To Do` on one board and `Done` on another
without touching its native workflow status.

- `boards` holds the name, description and list position. `board_columns` holds
  a board's stages: name, colour, `position` and an optional `wip_limit`.
  `board_items` is the membership row: `board_id`, `item_id`,
  `board_column_id`, and a `DECIMAL(20,10)` position inside that column.
- `board_items` is unique on `(board_id, item_id)` and on
  `(board_column_id, position)`. A card move is a write to this row only.
- `app/Filament/Resources/BoardResource.php` lists the boards
  (`/boards`), creates and renames them, and edits their columns through a
  relationship `Repeater`: rename, recolour, set a WIP limit, add, remove and
  reorder. A new board starts with the four workflow columns; column names
  must be unique on a board.
- `app/Filament/Pages/BoardView.php` at `/boards/{board}` is the Flowforge
  board. It queries `board_items` for that board, maps every `BoardColumn` to a
  Flowforge column and renders the card title, labels, assignee, due date and
  an Epic badge. The header offers “Add items” (search any items and choose the
  target column) and “Edit board”; card actions open the item and remove the
  card from the board.
- Items can also be added from the other side: the item table has a row action
  and a bulk action, and the item view page has a header action. Adding an item
  that is already on the board skips it instead of moving it.
- `/kanban-board` redirects to `/boards`. The old `KanbanBoard` page, its test
  and its status-column board are gone.

The old single board is preserved as a default board:

- `App\Actions\BackfillDefaultBoard` runs in the boards migration when the
  `boards` table is empty. It creates “Main board” with the four workflow
  columns and puts every non-deleted item on it, in its existing per-status
  order (`items.position`).
- The default board is a normal board: it can be renamed, recoloured, extended,
  emptied or deleted.

## Key decisions

- **Membership is a pivot, not a column on `items`.** The same item needs a
  different column on each board, so `board_items` carries
  `board_column_id` and `position`. `items.status` and `items.position` remain
  the item's own workflow state and list order.
- **A board move never writes `items.status`.** This is the GitHub Projects
  model: a project has its own status field, while the issue has its own state.
  Moving a card keeps its labels, activity history and native status untouched.
- **Per-column decimal positions.** `board_items.position` is scoped by
  `board_column_id`, so columns have independent sequences. `BoardItem`
  appends new cards at the bottom of their column, and Flowforge keeps
  bisecting positions on a move.
- **Deleting a column keeps its cards.** `BoardColumn::deleting` moves them to
  the first remaining column before the row is removed; the form also requires
  at least one column, so a board cannot be emptied into a state with nowhere
  to put cards.
- **The page route is numeric.** Filament registers pages before resources, so
  `/boards/{board}` is constrained to a number; otherwise it would swallow
  `/boards/create`. Board URLs are therefore `/boards/1`, not slugs.
- **No board is auto-selected for new items.** A board is a deliberate
  collection; an item joins one when someone adds it. The default board is a
  one-off backfill, not a rule.

## Deviations from RFC

- The archived Kanban RFC expected a single board; this replaces it with
  multiple boards and per-board columns, and keeps the native status only for
  the item's own workflow.
- The archived RFC listed “multiple boards” as out of scope; it is now the
  feature itself.

## Known limitations / next steps

- Boards are not exposed through REST API v1; only items are.
- Board access is flat: every signed-in user may view and manage every board
  (`BoardPolicy` mirrors the permissive item and label policies). Per-board
  membership rules are a later feature.
- No swimlanes, board templates, saved views, filters beyond assignee/label,
  or real-time collaboration.
- A board is a manual collection; there is no rule yet such as “all items with
  label X join this board”.
- A card with a WIP limit shows the limit and the counter, but the limit is not
  enforced on a drag.
- The default board is created only when the boards table is empty; a fresh
  database with no items still gets “Main board”, and later items must be
  added explicitly.

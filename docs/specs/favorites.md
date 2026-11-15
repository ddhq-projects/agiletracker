---
title: "Favorites"
status: done
completed: 2026-11-15
rfc: ../rfc/archive/2026-11-15-favorites-dashboard-and-sidebar.md
---

# Spec: Favorites

## What was done

Favorites are the personal one-click navigator: a full page, sortable cards on
the dashboard and the first five of them in the sidebar. Every representation
reads the same stored per-user order and resolves a destination through one
component.

- `favorites.position` is a per-user, non-null integer with an index on
  `(user_id, position)`. The migration backfills existing rows inside each
  `user_id` in `created_at`, then `id` order. New favorites append after the
  user's current last position (`App\Models\Favorite::booted()`); deleting a
  favorite leaves gaps, which are harmless because every query orders by
  `position`, then `id` (`Favorite::scopeOrdered()`).
- `app/Actions/FavoriteOrderService.php` is the only writer of positions. It
  exposes `reorder(User $user, array $orderedIds, bool $requireCompleteSet)`
  and `moveToPriority(User $user, int $favoriteId, int $priority)`. Both run in
  a transaction, reject duplicate ids, ids owned by another user and — for the
  full page — an incomplete set (`ValidationException`), and normalize the
  affected sequence to `1..N`. A partial (dashboard) reorder keeps favorites
  the user cannot currently link to in their existing slots.
- `app/Filament/Pages/Favorites.php` (canonical URL `/favorites`, group
  `Collections`, sort 4) renders a non-paginated table in stored order with the
  standard `reorderable('position')` toggle. The page overrides `reorderTable()`
  so drag-and-drop goes through the domain service instead of writing the
  column directly. Reordering is only enabled while the list is neither
  searched nor temporarily sorted. A row `Edit` action edits the name and a
  required numeric `Priority` (1..N); delete and create dispatch
  `refresh-sidebar`.
- `/favorite` permanently redirects to `/favorites` (`routes/web.php`) and the
  old smoke checks use the canonical URL.
- `app/Actions/ResolveFavoriteTarget.php` plus `App\Actions\FavoriteTarget`
  return `url`, `isExternal` and availability for a favorite. Only `http`/
  `https` URLs are clickable and open in a new tab; `Item` links go to the
  `ItemResource` view route and `User` links go to the read-only
  `app/Filament/Pages/UserProfile.php` at `/users/{record}` (not in the
  navigation, authorized by `UserPolicy::view`, no forms). When a favorite
  carries both `favoritable_type` and a `url` (legacy and demo rows), the
  object wins and the URL is only a fallback. Deleted targets, denied targets
  and unsupported morph types resolve to "no URL". The page keeps such a row
  visible and manageable; the dashboard and the sidebar hide it. Callers
  eager-load `favoritable`, so availability never costs an N+1.
- `app/Filament/Widgets/FavoritesWidget.php` is the first dashboard widget
  (`Dashboard::getWidgets()`), full width, rendering the first twelve available
  favorites as cards in the stored order — the cap lives in
  `FavoritesWidget::CARD_LIMIT`, the rest is one click away on the page. Cards
  use Filament's Alpine `x-sortable`/`x-sortable-item`/`x-sortable-handle`
  directives with the handle separated from the link, and labelled up/down
  buttons as the keyboard and touch alternative. Reordering dispatches
  `refresh-sidebar`. An empty state and a `View all` action both point at
  `/favorites`.
- The main panel registers `NavigationGroup::make('Favorites')` with a star
  icon before `My` and `Collections`. `Favorites::getNavigationItems()`
  appends the first five available favorites of the current user as
  `NavigationItem`s in that group, so the group is built per user on every
  navigation render, has the same order as the cards, is hidden when empty and
  never appears in the settings panel.

## Key decisions

- The order belongs to the user, not the view: the page, the dashboard and the
  sidebar all read `position` and all writes go through one service, so the
  three views can never disagree.
- Partial reorders are allowed on the dashboard by merging the submitted
  sequence into the slots of the favorites the user can currently move; the
  full page requires the complete set because it shows every favorite.
- Unavailability is resolved in one place (`ResolveFavoriteTarget`) rather than
  per view, so a deleted item or a denied user yields the same "no link"
  everywhere.
- Striped `Edit` action moves the row by priority instead of editing a weight,
  which keeps the stored list a plain normalized sequence.
- Sidebar items are generated in `Favorites::getNavigationItems()` instead of a
  static list in the panel provider, because only that method runs for the
  current user on each navigation build.
- The dashboard is a quick-launch strip: it renders the first twelve favorites
  in a responsive card grid, and `/favorites` is the complete, reorderable
  list.

## Deviations from RFC

- Sidebar items have no icons of their own. Filament's sidebar throws when a
  navigation group has an icon and any of its items also has one; the star
  group icon was kept, so the item icons described in the RFC were dropped.
- The Favorites table keeps Filament's native column link
  (`TextColumn::url()->openUrlInNewTab()`), which renders `target="_blank"`
  without an explicit `rel`. `rel="noopener noreferrer"` is emitted where the
  markup is ours (dashboard cards) and in the published sidebar item view;
  modern browsers also imply `noopener` for `target="_blank"`.
- `Favorite` gained a `creating` hook and an `ordered` scope; the RFC described
  the behavior but not these names.

## Known limitations / next steps

- Reordering is only enabled on the unfiltered list; there is no server-side
  move API outside the two service methods.
- Dragging was verified through Livewire tests and Filament's standard
  `x-sortable` markup; a manual pass in a real browser is listed in the archived
  RFC's test instructions.
- A `favoritable_type` pointing at a class that no longer exists would break
  eager loading; only unsupported but existing morph types are handled. No code
  path in the app writes such a value.
- Adding new bookmarkable entity types still means extending
  `ResolveFavoriteTarget`, the create form options and the type badge.

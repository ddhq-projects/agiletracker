# CHANGELOG

Use date format for releases: `YYYY.MM.I` (e.g. `2026.05.1-alpha`).
Use flat lists for changes
If changes many - use categories (Added, Changed, Fixed, etc.)

## [Unreleased]

### Added
- Global roles and permissions: `RoleName` / `PermissionName` catalogues, idempotent `RolesAndPermissionsSeeder` (mandatory on upgrade), `Gate::before` for `admin`, and one base role per user assigned only through `AssignUserRole`
- Typed groups (`project`, `team`, `department`, `other`) with a `member`/`manager` pivot and an optional hierarchy; admin-only `/settings/groups`
- Row-level item access (Planfix-like): `items.visibility` (`public`/`restricted`), `items.project_group_id` and `item_group`; `App\Access\ItemAccess` is the single rule behind both the SQL perimeter and the policies
- Access section on the item form (project, visibility, groups), restricted to whoever manages the item's access, with status/assignee-only editing for the assignee
- `relaticle/activity-log` item timeline (history slide-over on the item page) and a read-only `/settings/activity-log` journal with date/event/log/causer/subject filters
- Activity allowlists for Item, User, Group, Label, Board, BoardColumn and Comment, plus `roles_updated`, `group_members_updated`, `access_updated` and `settings_updated` events, with secrets masked on read
- Tests: role × permission matrix, group management, item access matrix, API perimeter, activity journal
- Boards: `Board`, `BoardColumn` and `BoardItem` — any item or project can be on any number of boards, with its own column and card order on each
- Boards resource (`/boards`) to create, reorder, rename and delete boards and to edit their columns (name, colour, WIP limit, order)
- Board page (`/boards/{board}`) with Flowforge drag & drop, label/assignee filters, “Add items”, and open/remove card actions
- “Add to board” from the item table (row and bulk) and the item view page; item view lists the boards an item belongs to
- Default “Main board” backfilled from the four workflow states so existing items stay visible
- File attachments: item description editor and comment composer upload to the disk set by `ATTACHMENTS_DISK` (`public` locally, `bunny` in production)
- `config/attachments.php` — one disk, directory, size limit and MIME list for both uploaders
- `bunny` filesystem disk and `league/flysystem-aws-s3-v3`, plus a Bunny Storage Zone with S3 compatibility and a CDN Pull Zone for public file URLs

### Changed
- `ItemPolicy`, `LabelPolicy`, `BoardPolicy`, `CommentPolicy` and `UserPolicy` ask for permissions instead of allowing every authenticated user; `/settings` and its user-menu item use `settings.manage`
- The settings user form assigns exactly one role and refuses to demote the last administrator
- Items, boards, comments, inbox/sent, widgets, favorites and REST API v1 all read the same item perimeter; hidden records answer 404, forbidden actions 403
- New items are created as `restricted`; historical items stay `public`, so the upgrade hides nothing
- Comment editing now leaves an activity entry on the Comment subject
- The single status Kanban page is replaced by Boards; `/kanban-board` redirects to `/boards`
- Moving a board card writes only that board's membership (`board_items`); the item's native `status` and other boards are untouched
- Comment attachments are enabled; they were migrated but switched off in the previous release

### Fixed
- `php artisan migrate:fresh --seed` resolves the permission catalogue during seeding (the cached permission list is reset before the roles are synced)

## [2026.11.1-alpha] — 2026-11-13

Dependency refresh: every Composer and npm package is on its latest release.

### Changed
- Pest `4.7` → `5.3`, `pestphp/pest-plugin-laravel` `4.1` → `5.0`, PHPUnit `12.5` → `13.4` (Pest 5 caps PHPUnit at `13.4.5`)
- `spatie/laravel-activitylog` `4.12` → `5.1`, `spatie/laravel-permission` `7.4` → `8.5`
- PHP requirement `^8.3` → `^8.4` (Pest 5 and activitylog 5 require it)
- npm: Vite `8.0` → `8.3`, Tailwind CSS `4.3.0` → `4.3.5`, `laravel-vite-plugin` `3.1` → `3.2`, `concurrently` `9` → `10`

### Added
- `config/activitylog.php` published from v5, plus the legacy `table_name`/`database_connection` keys the v4 migrations still read
- Migration `upgrade_activity_log_to_v5_schema` — adds `attribute_changes`, drops `batch_uuid`, moves recorded changes out of `properties`, and creates the consolidated v5 table on fresh installs
- `ActivityLoggingTest`/`ItemActivityLogOptionsTest` — tracked changes land in `attribute_changes`, untracked attributes are not logged, item log options stay dirty-only

### Fixed
- Item activity logging adapted to the v5 API: `Models\Concerns\LogsActivity`, `Support\LogOptions`, `dontLogEmptyChanges()`

## [2026.06.2-alpha] — 2026-06-01

Second alpha. Form layout restructuring, settings management, meta field, and UI polish.

### Added
- `ManageSettings` page for general settings management
- `meta` JSON field on Item model (form + infolist)

### Changed
- ItemResource slug changed from `i` to `items`
- ItemResource form reorganized into sections: General, Participants, Dates, Meta
- Items table: Labels column moved after Title
- Items table: Assignee column replaced with From (reporter) and To columns
- README and development guide refactored for clarity

### Removed
- `estimated_minutes` and `spent_minutes` fields from Item

## [2026.05.1-alpha] — 2026-05-30

First alpha release. Core project scaffolding, Filament admin panel with item management, Kanban board, and settings system.

- Laravel 13 project scaffolding with FilamentPHP 5, Livewire 4, Tailwind CSS 4
- `.agents/` protocol configuration: agents, skills, memories, MCP config, models
- AI agent skills: `code-review`, `laravel-best-practices`, `laravel-filament-dev`, `laravel-permission-development`, `rfc-writer`, `tailwindcss-development`
- Project documentation: `AGENTS.md`, `MARKETING.md`, `PRODUCT.md`, `README.md`, `ROADMAP.md`
- `Makefile` for common development tasks, `boost.json` — Laravel Boost configuration
- Database: `users`, `items` (core entity with type/status/priority/subtasks/assignee/time tracking), `comments`, `labels`, `item_label` (pivot), `activity_log`, Spatie Permissions tables
- `AdminPanelProvider` — Filament panel with navigation groups, theme, branding, global search, sidebar, topbar, user menu, theme switcher
- `ItemResource` — CRUD + View page (`/i/{id}`) with rich editor, comments, subtasks, labels, filters, sorting
- `LabelResource` — list/create/edit with name + color picker
- `UserResource` — list/create/edit with name, email, password, roles
- Kanban board: drag-and-drop via `mokhosh/filament-kanban`, `ItemStatus` enum, Spatie Sortable on `position` column (RFC: `docs/rfc/2026-05-30-kanban-board.md`)
- Settings system: central page via `filament/spatie-laravel-settings-plugin`, `GeneralSettings` class, admin-only access (RFC: `docs/rfc/2026-05-30-settings-system.md`)
- Spatie Permissions: `admin`/`manager`/`user` roles, policies for Item/Label/User, permission-gated navigation
- Spatie Activity Log: `created`/`updated`/`deleted`/`restored` events on Item
- `MyTasksWidget` — assigned items on dashboard
- Factories: `ItemFactory` (all field types), `UserFactory` (admin/manager/user)
- `DatabaseSeeder` — roles, permissions, admin user, sample data
- `composer run setup` / `composer run dev` — one-command setup and dev server
- `./vendor/bin/pint` (PSR-12), `php artisan test` (Unit + Feature), custom Filament stubs

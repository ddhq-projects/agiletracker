---
title: "Relaticle Kanban, Comments, and Item Custom Fields"
type: rfc
status: draft
created: 2026-11-14
---

# Relaticle Kanban, Comments, and Item Custom Fields

## TL DR

Replace the placeholder Kanban and the basic comments relation manager with `relaticle/flowforge` 4.x and `relaticle/comments` 1.x, then add `relaticle/custom-fields` 3.x after its license is approved. Items receive a native workflow `status` for reliable board queries, while labels remain non-exclusive tags and Custom Fields takes over `meta`, including the API's virtual `type`. Existing comments, metadata, REST routes and field names, authorization rules, and activity history are preserved through explicit migrations and adapters.

## Context

**Situation.** `Item` currently uses labels and free-form JSON `meta`; `type`, `status`, and `priority` database columns were removed. The Kanban page renders only “coming soon”, its unused move handler attaches labels instead of changing a status, and comments are a simple `item_id`/`user_id` relation manager. API v1 also depends on this model: Item exposes `meta.type` as `type`, and Comment exposes `item_id` and `user_id`.

**Complication.** Flowforge requires a scalar column identifier and decimal positions, Relaticle Comments requires polymorphic commenter/commentable columns, and Custom Fields stores values in EAV tables. Publishing their migrations unchanged would collide with the existing `comments` table and `items.position`; dropping `meta` or switching model classes without adapters would break API v1. The earlier `2026-05-30-kanban-board.md` selects another package and assumes removed columns, while `2026-05-30-label-based-tags.md` treats status labels as the workflow. `AGENTS.md` still describes those removed columns and must be corrected during delivery. `docs/specs/` does not exist, so code and migrations are the current source of truth.

**Question and answer.** Adopt the three Relaticle packages in independent stages, but make workflow state a native `items.status` again rather than encoding it in labels or EAV. Adapt the current schema and REST resources instead of replacing public contracts. This RFC supersedes the earlier Kanban RFC and only the status-as-label part of the label RFC; labels otherwise remain unchanged.

## Purpose and goals

- Provide a working drag-and-drop Kanban board with durable status transitions and card ordering.
- Upgrade item discussions with threads, mentions, reactions, and consistent author/admin moderation.
- Let administrators define item fields without deployments while preserving the public `type` field and existing metadata.
- Keep API v1, policies, soft deletes, and activity logging working across all three replacements.
- Make each stage independently releasable; only Custom Fields waits for a licensing decision.

Stakeholders are item users, administrators who manage fields and moderate comments, API clients, maintainers, and architecture/security reviewers.

## Components and specifics

### 1. Flowforge Kanban and native workflow state

Install a stable `relaticle/flowforge:^4.0` release and require its `ext-bcmath` platform dependency. Replace `app/Filament/Pages/KanbanBoard.php` with a `Relaticle\Flowforge\BoardPage`, retaining the current navigation label, group, and sort. Remove the placeholder Blade view and label-mutating event handler. This stage does not use `mokhosh/filament-kanban` or Spatie Sortable.

**Workflow and data migration.** Add an `ItemStatus` string-backed enum: `todo`, `in_progress`, `review`, `done`. Add a non-null `items.status` string defaulting to `todo`, cast it to the enum, and include it in Item forms, infolists, filters, `$fillable`, and the activity log. Labels remain many-to-many tags and are never added or removed by a card move.

Backfill status from the existing `To Do`, `In Progress`, `Review`, and `Done` labels, accepting the lowercase/underscore variants created by the old handler. If several are present, use `done > review > in_progress > todo`; otherwise default to `todo`. Detach only those recognized workflow labels after their values are copied, retain the Label records themselves, and leave all other labels untouched. New seed data assigns native status instead of workflow labels.

Change `items.position` from integer to nullable `DECIMAL(20,10)`. Regenerate positions in deterministic ID order with Flowforge's `DecimalPosition`, separately for every status and including soft-deleted items, then add the recommended unique `(status, position)` index. This avoids duplicates inherited from the current integer data and enables Flowforge's retry mechanism. Disable Filament table reordering through `ItemResource` so the table and board do not become competing position writers. Use new migrations only; do not edit or republish old application migrations.

**Board behavior.** Configure four columns from `ItemStatus`, `columnIdentifier('status')`, and `positionIdentifier('position')`. Cards show title, labels, assignee, and due date, eager-load those relations, and open the existing item view page. Support label and assignee filters. Moving a card checks `ItemPolicy::update`, runs in a transaction, changes status and position atomically, and logs the status change without logging position-only moves. Existing authenticated-user item permissions remain unchanged.

Expose `status` as an additive Item API field with the same four values; creation defaults to `todo`, and supplied values are validated. Existing `type` behavior remains unchanged until stage 3. Regenerate the OpenAPI snapshot and cover both UI movement and API status reads/writes.

### 2. Relaticle Comments with schema and API adapters

Install `relaticle/comments:^1.0`, register `CommentsPlugin`, and add the package views to a new Filament custom theme. `Item` implements `Commentable` with `HasComments`; `User` implements `Commentator` with `CanComment`. Replace `CommentsRelationManager` with `CommentsAction` on `ViewItem`. Enable threading, mentions, reactions, and pinning. Disable attachments, broadcasting, mail/database notifications, and automatic subscriptions in this stage; file storage and notification delivery are separate roadmap topics.

**Non-destructive schema migration.** Do not run the package's `create_comments_table` migration against the existing table. Publish its auxiliary migrations except `create_comments_table` and `add_pinned_at_to_comments_table`; write an application migration that adds nullable `commentable_type`, `commentable_id`, `commenter_type`, `commenter_id`, `parent_id`, `edited_at`, and `pinned_at` columns. Backfill every existing row with the Item and User morph classes and the old `item_id`/`user_id` values, preserving IDs, timestamps, and soft-delete state. Convert legacy Markdown bodies to HTML with Laravel's Markdown support and sanitize the result with the package sanitizer, preserving the content rather than storing two competing body formats. Add the package's indexes and foreign keys, make required columns non-null, then remove the legacy columns and their constraints. Publish the reactions, mentions, subscriptions, and attachments tables even when their corresponding optional UI features are disabled.

Keep `App\Models\Comment` as the configured comments model, but extend `Relaticle\Comments\Models\Comment` instead of `Model`. It provides compatibility accessors/mutators for `item_id` and `user_id`, plus `item()` and `author()` relations backed by the polymorphic IDs. `User::comments()` becomes a `MorphMany` through `commenter`. Historical plain-text mention strings remain unchanged; no historical notifications are generated.

**Authorization and REST compatibility.** Configure the existing `App\Policies\CommentPolicy` for the package and add its reply/depth rule. Authenticated users may view and create; only the author or an `admin` may edit/delete, matching the current policy. The package HTML sanitizer remains enabled.

`App\Rest\Resources\CommentResource` continues to use `App\Models\Comment` and keeps the public fields `item_id`, `user_id`, `body`, and timestamps. Virtual field and filter mappings translate to the polymorphic columns and constrain commentable/commenter types to Item/User. Public `item` and `author` includes remain available. Creating still requires a non-trashed Item and fills the current user; item and author are immutable after creation. The perimeter excludes comments whose Item is soft-deleted, and deleting a comment remains soft. No public Comment API field or endpoint is renamed. The documented `body` format intentionally changes from Markdown to sanitized HTML for both API reads and writes; this content-format migration and XSS rejection are covered by HTTP tests and the OpenAPI field description.

### 3. Admin-managed Custom Fields and metadata migration

Adopt stable `relaticle/custom-fields:^3.0` only after the maintainer approves a license and supplies the private Composer repository credentials. Use a commercial license to retain AgileTracker's MIT distribution model; do not commit credentials. If AGPL-3.0 is selected instead, its distribution implications require separate review before installation. Register `CustomFieldsPlugin` with `authorize()` restricted to the `admin` role and place its management UI in Settings. Multi-tenancy, import/export, and file-upload fields are disabled or out of scope.

`Item` implements `HasCustomFields` with `UsesCustomFields`. Add `CustomFields::form()`, `::table()`, and `::infolist()` builders to `ItemResource`, including generated columns and filters. Administrators manage definitions; users with Item update permission manage values. System-defined fields cannot be deleted through the UI. Custom-field value changes, including API changes to `type`, create activity records; position changes remain excluded.

Create a preset, system-defined `type` select field with string options `task`, `bug`, and `feature`. The Item API keeps its public virtual `type` field and validation, but reads/writes this custom field after the Item exists and within the same mutation transaction. Filtering uses the typed `custom_field_values.string_value` through the field relation rather than JSON `meta`. Other custom fields are not exposed through API v1.

Migrate all other `meta` keys to text fields in an “Additional information” section. Generate stable snake_case codes, append a deterministic hash for empty/colliding names, preserve the original key as the field name, and copy every value without changing unrelated values. Abort with the Item ID and key on unsupported non-scalar data rather than silently coercing it. Preserve any legacy scalar `type` outside the three standard options as an additional legacy option so old API reads do not lose data; new API writes still accept only the standard values.

After backfill, remove the Meta KeyValue form/infolist entries and all runtime reads/writes of `items.meta`. Keep the old column untouched for one release as a rollback backup, but it is no longer a source of truth and is excluded from fillable, casts, activity configuration, factories, and seeders. A later migration/RFC may drop it after production verification.

### Shared boundaries and verification

- Do not introduce multiple boards, per-board custom columns, WIP limits, swimlanes, real-time collaboration, comment attachments, or custom-field API CRUD in this RFC.
- Create and register `resources/css/filament/admin/theme.css`, add each package's view source when its stage installs it, and verify the production Vite build.
- Each stage adds focused Pest tests for migrations, policies, real Livewire/HTTP writes, and rollback/error paths. Run `rest:check`, the full test suite, Pint, and OpenAPI export; a stage is not complete with red existing tests.

## Acceptance criteria (checklist)

### 1. Flowforge Kanban and native workflow state

- [ ] Fresh and upgraded databases have native status, decimal positions, and unique positions per status; status-label backfill and soft-deleted records are covered on SQLite, MySQL, and PostgreSQL.
- [ ] The board displays the correct cards in four columns, filters them, opens an Item, and moves/reorders cards transactionally without changing labels.
- [ ] Unauthorized moves are rejected; status changes appear in the activity log and position-only changes do not.
- [ ] Item table drag reordering is disabled; existing list, inbox, sent, and widget queries work with native status.
- [ ] Item API reads/writes validated status without regressions to `type`; board/API tests and the refreshed OpenAPI snapshot pass.

### 2. Relaticle Comments with schema and API adapters

- [ ] Migration preserves every legacy comment's ID, content, ownership, Item, timestamps, and deletion state; Markdown-to-HTML conversion and sanitization are covered, no create-table collision occurs, and auxiliary package tables exist.
- [ ] The Item view supports threads, mentions, reactions, and pinning; attachments, broadcasting, and notifications are off.
- [ ] Authors/admins retain their current moderation rights, other users cannot edit/delete another user's comment, and reply depth is enforced.
- [ ] Existing Comment API routes, fields, filters, includes, perimeter behavior, and soft deletes pass against the polymorphic schema; OpenAPI documents sanitized HTML bodies and has no other unintended Comment contract change.

### 3. Admin-managed Custom Fields and metadata migration

- [ ] The approved licensing mode is recorded, credentials are absent from Git, and only admins can access Custom Fields management.
- [ ] Admins can define Item fields and users can edit/view/filter their values in the Item resource; system fields are protected and activity logging covers value changes.
- [ ] Every supported legacy `meta` value is migrated exactly once with stable field codes; unsupported data aborts visibly, and runtime code no longer depends on `items.meta`.
- [ ] Item API `type` creation, update, filtering, and legacy reads pass against Custom Fields without losing other custom values; arbitrary `meta` and other custom-field API writes remain rejected.
- [ ] Focused tests, the full suite, `rest:check`, Pint, OpenAPI export, and `npm run build` pass for the completed stage.

## Roadmap

1. **Flowforge Kanban and native workflow state.** Install Flowforge and the shared theme, migrate status/positions and labels, replace the board, update Item UI/API/activity behavior, and verify stage 1. Archive the superseded Kanban RFC and correct stale architecture notes on delivery.
2. **Relaticle Comments with schema and API adapters.** Install Comments, adapt the existing table and model, replace the relation manager, preserve policies and REST mappings, and verify stage 2 independently.
3. **Admin-managed Custom Fields and metadata migration.** After license approval, install Custom Fields, add the preset and metadata migrations, replace Meta UI/API storage, and run the complete contract and frontend verification.

Stages 1 and 2 have no dependency on stage 3 and may be released separately. Stage 3 must not begin by installing the AGPL/commercial package before the licensing decision.

## Summary and recommendations

Use Flowforge with a native status and decimal ordering, migrate Comments in place while retaining an application model and REST compatibility, and move `meta`/`type` to Custom Fields only under an approved license. Labels remain tags, public API names remain stable, and each package is delivered as a separately verifiable replacement rather than a parallel implementation.

## Test instructions

<!-- Filled by dd-delivery after implementation. -->

### Open questions

- **Custom Fields licensing:** should the maintainer purchase the commercial license recommended for the MIT project, or explicitly accept AGPL-3.0 and its distribution obligations? Stage 3 is blocked until this is decided; stages 1 and 2 are not.

### Alternative options

- **Keep and finish native implementations.** This avoids new dependencies and license fees but requires building concurrent drag ordering, threads, reactions, mentions, field-definition storage, validation, and administration ourselves. It is slower and creates a long-term maintenance burden.
- **Drive Kanban from labels or a Custom Fields EAV value.** This avoids restoring `items.status` but prevents Flowforge's normal column queries and direct persistence. Custom joins and move hooks would be more fragile and would lose the simple `(status, position)` concurrency constraint.
- **Use Custom Fields under AGPL-3.0.** This avoids the commercial fee but changes distribution obligations for an application currently declared MIT. The commercial package is the recommended default; legal review is required before choosing AGPL.

### References

- [Flowforge repository](https://github.com/relaticle/flowforge), [installation](https://relaticle.github.io/flowforge/getting-started/installation), and [database schema](https://relaticle.github.io/flowforge/essentials/database-schema).
- [Comments repository](https://github.com/relaticle/comments), [installation](https://relaticle.github.io/comments/getting-started/installation), and [database schema](https://relaticle.github.io/comments/essentials/database-schema).
- [Custom Fields repository](https://github.com/relaticle/custom-fields), [installation and licensing](https://relaticle.github.io/custom-fields/getting-started/installation), and [quickstart](https://relaticle.github.io/custom-fields/getting-started/quickstart).

## Notes

  - [ ] add for kanban and issues/items  [https://github.com/relaticle/flowforge](https://github.com/relaticle/flowforge)
  - [ ] add for comments for issues/items  [https://github.com/relaticle/comments](https://github.com/relaticle/comments)
  - [ ] add as settings for items custom fields  [https://github.com/relaticle/custom-fields](https://github.com/relaticle/custom-fields)

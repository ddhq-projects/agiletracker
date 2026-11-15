---
title: "Roles, groups, item access and the activity journal"
status: done
completed: 2026-11-15
rfc: ../rfc/archive/2026-11-15-groups-and-item-access.md
---

# Spec: Roles, groups, item access and the activity journal

The access model has three layers, always applied together:

1. a **global role** (`admin`, `manager`, `user`) that holds Spatie permissions
   and caps what anyone may do;
2. a **row-level perimeter** for items: a public item is visible to everyone,
   a restricted one only to its participants, its groups and their leaders;
3. the **token abilities** of the REST API, which stay an independent barrier.

An operation is allowed only when every applicable layer allows it.

## What was done

### Roles and permissions

- `App\Enums\RoleName` and `App\Enums\PermissionName` are the single source of
  the catalogue. `PermissionName::forRole()` holds the role → permission matrix.
- `Database\Seeders\RolesAndPermissionsSeeder` is idempotent: it creates the
  three roles and every permission, syncs `manager` and `user`, collapses a
  user that ended up with several base roles into the most privileged one and
  gives `user` to anyone without a role. It is the mandatory upgrade step of an
  existing installation and runs first in `DatabaseSeeder`.
- `AppServiceProvider` registers `Gate::before`, which returns `true` for
  `admin` and `null` for everyone else — except for the API abilities
  (`items:read` …), which are skipped so a token's abilities cannot be widened
  by a role.
- A user holds exactly one base role. `App\Actions\AssignUserRole` is the only
  writer: it syncs the role, refuses to remove the last administrator inside a
  locked transaction and records a `roles_updated` event with the old and the
  new role.
- `UserFactory` and `User::booted()` give a freshly created user the `user`
  role, so no path can create a roleless account.

Matrix shipped (`✗` = denied):

| Ability | admin | manager | user |
|---|---|---|---|
| Items / Labels / Boards / Comments / profiles: read | ✓ | ✓ | ✓ |
| Create items and comments | ✓ | ✓ | ✓ |
| Update an item the user reported or is assigned | ✓ | ✓ | ✓ |
| Update any available item | ✓ | ✓ | ✗ |
| Delete available items | ✓ | ✓ | ✗ |
| Manage labels and board structure | ✓ | ✓ | ✗ |
| Moderate other users' comments | ✓ | ✓ | ✗ |
| Manage users, their roles and groups | ✓ | ✗ | ✗ |
| Change General settings | ✓ | ✗ | ✗ |
| Read the activity journal | ✓ | ✗ | ✗ |

### Groups

- `groups` (`name`, `description`, `parent_id`, `type`) and `group_user`
  (`group_id`, `user_id`, `role` = `member` / `manager`, unique pair).
- `App\Enums\GroupType`: `project`, `team`, `department`, `other`. A `project`
  group names the project of an item (`items.project_group_id`); every other
  type is an organisational unit.
- The hierarchy is optional and guarded in the model: a group cannot be its own
  parent or a descendant of itself. Deleting a group is refused while items
  still name it as their project; deleting a user only removes the membership.
- `App\Filament\Settings\Resources\GroupResource` (`/settings/groups`) is the
  only surface; only `groups.manage` (admin) reaches it. Composition is written
  through `App\Actions\SyncGroupMembers`, which also records
  `group_members_updated` with the added/removed ids and their group roles.

### Item access: the Planfix-like model

The chosen variant is Planfix-like: the participants of an item decide who
reaches it. The rejected Jira-like variant (security schemes and levels) is
recorded in the archived RFC.

- `items.visibility` = `public` | `restricted`, and `items.project_group_id`.
  Historical rows keep the column default (`public`), so nothing was hidden by
  the deploy. New items are created as `restricted` by the model itself.
- `item_group` (`item_id`, `group_id`) attaches groups as access participants.
  The group is stored, not its members: a new member gains access immediately
  and a removed one loses that path, with no backfill.
- `App\Access\ItemAccess` is the only implementation of the rule. It exposes a
  SQL scope (`scopeVisibleTo`) and the per-record decisions
  (`view`, `update`, `delete`, `manageAccess`, `updatableAttributes`); the scope
  and the decisions are two faces of the same logic and cannot drift.
  `Item::scopeVisibleTo()` delegates to it.

Bases of access to a restricted item (`App\Enums\ItemAccessBasis`):

| Basis | View + comment | Change fields | Delete | Manage access |
|---|---|---|---|---|
| `admin` | ✓ | all | ✓ | ✓ |
| `reporter` | ✓ | all | ✓ | ✓ |
| `assignee` | ✓ | `status`, `assignee_id` | ✗ | ✗ |
| `to`, `cc`, member of an attached group | ✓ | ✗ | ✗ | ✗ |
| manager of the project group (auditor) | ✓ | all | ✓ | ✗ |
| leader of a participant (group manager) | inherits that participant | inherits | inherits | ✗ |

The leader relation is computed from `group_user.role = manager` over the led
group and every group below it. Bases combine by maximum.

The result is always intersected with the role: `items.update.all` covers every
visible item, `items.update.related` only the ones the user reported, was
assigned, or leads the reporter/assignee of; `items.delete` is required for any
delete; `manageAccess` needs the reporter or an administrator. A grant therefore
never adds an action the role lacks, and the role never widens the perimeter.

### Perimeter across the application

Every surface reads items through the same scope or the same policy:

- `ItemResource::getEloquentQuery()` (used by the list, the record routes,
  global search and relation selects), `ChildrenRelationManager`, `Inbox`,
  `Sent`, `FavoritesWidget`, `StatsOverviewWidget` and `BoardView`;
- `AddItemsToBoard` links only items the user may see **and** change, so a
  board is never a side door;
- `CommentPolicy` checks the item before the author/moderator rules;
- `ItemResource` list/global search use the scope; bulk actions declare
  `authorizeIndividualRecords('update')`; card moves and removals call
  `Gate::authorize('update', $item)`.

A record outside the perimeter answers 404 on its own URL (it is absent from
`getEloquentQuery()`), while a visible record with a forbidden action answers
403. Lists, global search, boards, widgets and aggregates never count hidden
rows: there is no PHP post-filtering and no N+1.

Details:

- Parent and children are independent: a visible child does not reveal its
  hidden parent, hidden children are absent from the relation manager and the
  API includes, and an invisible item is never offered or accepted as a parent.
- The Access section of the item form (project, visibility, groups) is visible
  only through `manageAccess`; `EditItem` strips the fields from the payload
  when the user does not have it, and the API returns 403 for the same case.
- The assignee's form disables every field except status and assignee.

### Activity journal

- `relaticle/activity-log` 1.x renders the item timeline; `Item` implements
  `HasTimeline` with `InteractsWithTimeline` and `timeline()` from
  `fromActivityLog()`. The `ActivityLogAction` on the item page is gated by
  `ItemPolicy::view`, so the history cannot become a second door to an item.
- Logging is an allowlist per model with `logOnlyDirty()` and empty-change
  suppression: Item (`title`, `status`, `assignee_id`, `parent_id`, `meta`,
  `is_epic`, `project_group_id`, `visibility`), User (`name`, `email`), Group
  (`name`, `description`, `parent_id`, `type`), Label (`name`, `color`), Board
  (`name`, `description`), BoardColumn (`name`, `color`, `wip_limit`), Comment
  (`body`, `edited_at`, `pinned_at`). Position-only writes and `board_items`
  moves are not tracked.
- Three manual events complement the model logs: `roles_updated`,
  `group_members_updated` and `access_updated` (the groups attached to an item),
  plus `settings_updated` for the safe keys of the General settings form.
- `App\Models\Activity` (configured as `activitylog.activity_model`) reads
  `attribute_changes` and `properties` through
  `App\Casts\RedactedActivityData`, which masks `password*`, `*_token`,
  `*_secret`, `*_key`, `authorization` and attachment contents on the way out.
  Rows written by older versions or third-party sources are therefore masked too.
- `App\Filament\Settings\Resources\ActivityResource` (`/settings/activity-log`)
  is List + View only, requires `activity.view`, filters by date range, event,
  log name, subject type and causer, eager-loads causer/subject and renders the
  old/new diff with a fallback for a deleted subject.

## Key decisions

- **Planfix-like over Jira-like.** The participants of the item are the unit of
  configuration; reusable security schemes were judged unnecessary for the
  product and would have added a second configuration surface.
- **One access service, two faces.** The SQL scope and the per-record checks
  live in `ItemAccess`; any surface that needs "what can this user see" asks it
  instead of writing its own `whereHas`.
- **`admin` bypasses every gate except token abilities.** `Gate::before` keeps
  the API barrier independent (`in_array($ability, API_ABILITIES)` → `null`).
- **Groups are stored, not expanded.** Membership changes take effect without
  touching the items, which is what makes the model workable.
- **Historical items stay public.** The migration only adds the column; no item
  became invisible on deploy.
- **Redaction happens on read.** The cast masks secrets for every consumer,
  including rows that predate the rule.

## Deviations from RFC

- **The manager of a participant needed the global permission to change an
  item.** The RFC's own definition of "related" mentions only the reporter and
  the assignee, but its matrix says the leader of the assignee may change the
  status. `items.update.related` therefore also counts a
  `ManagedReporter`/`ManagedAssignee` basis; the field restriction still comes
  from the participant basis, so a manager who is only an assignee still gets
  the assignee's two fields.
- **The API exposes no group management.** `project_group_id` and `visibility`
  are plain fields (validated against a `project` group), and item groups are
  not an API relation: generic group CRUD stays out of REST as the RFC requires.
  The access fields answer 403 when changed by someone without `manageAccess`.
- **`ItemResource::$updatableAttributesCache`** was added so the policy check
  runs once per rendered form rather than once per disabled field.
- **The settings panel's user menu item and page now use `settings.manage`**
  instead of `hasRole('admin')`; `canAccessPanel()` still keeps the whole panel
  admin-only.

## Known limitations / next steps

- **Attachments are not covered.** Item description and comment attachments
  still live on a public disk, so row-level access does not protect a file
  reached by its known URL. Signed URLs / a private disk need their own RFC.
- **Projects have no surface of their own.** A project is a `project` group
  without project pages, open/hidden project lists or default participants.
- **The journal is not scheduled or exported.** `activitylog.clean_after_days`
  stays 365 and no `activitylog:clean` schedule, export or audit API exists.
- **`activity_log` has no retention decision for production**; the composite
  index `(subject_type, subject_id, created_at)` was added for the read paths.
- **Group changes do not notify anyone**, and the leader relation is computed
  per request (a couple of queries per user, cached inside the service for the
  request).

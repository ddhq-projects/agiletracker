---
title: "Item comments on relaticle/comments"
status: done
completed: 2026-11-15
rfc: ../rfc/2026-11-14-relaticle-item-features.md
---

# Spec: Item comments

## What was done

Item discussions run on `relaticle/comments` 1.x over the application's existing `comments` table.

- The table was adapted in place, in one migration: `commentable_type` / `commentable_id`,
  `commenter_type` / `commenter_id`, `parent_id` (FK to `comments`, cascade delete), `edited_at` and
  `pinned_at` were added; every existing row was backfilled with `App\Models\Item` /
  `App\Models\User` and its old `item_id` / `user_id`; Markdown bodies were converted to HTML with
  Laravel's Markdown renderer and sanitized; the columns became non-null, the package's indexes and
  the parent foreign key were added, and only then were `item_id` / `user_id` and their foreign keys
  dropped. The package's own create-table migration never runs against the existing table.
- The auxiliary package migrations were published: `comment_mentions`, `comment_reactions`,
  `comment_subscriptions`, `comment_attachments` (all four have a working `down()`).
- `App\Models\Comment` extends the package model and keeps `item_id` / `user_id` as accessors/
  mutators over the polymorphic columns, plus `item()` and `author()` relations.
  `User::comments()` is a `MorphMany` through `commenter`.
- `Item` implements `Commentable` with `HasComments`, `User` implements `Commentator` with
  `CanComment`.
- `CommentsPlugin` is registered on the main panel, and `ViewItem` has `CommentsAction` in its header
  actions; the old `CommentsRelationManager` was removed.
- `App\Policies\CommentPolicy` is the configured package policy: authenticated users may view and
  create; only the author or an `admin` may edit, delete or pin; `reply` follows the package's depth
  limit.
- Threading, mentions, reactions and pinning are on. Attachments, broadcasting, notifications and
  automatic subscriptions are off.

## Key decisions

- **Adapt, don't recreate.** Publishing `create_comments_table` would have collided with the table and
  lost data. The application migration keeps IDs, timestamps, soft-delete state and content.
- **HTML is the stored format.** The package sanitizes on every save and renders HTML. Legacy bodies
  were converted; the API documents `body` as sanitized HTML, so the format change is explicit rather
  than silent.
- **The public API contract is untouched.** `App\Rest\Resources\CommentResource` maps the virtual
  `item_id` / `user_id` fields onto the polymorphic columns, constrains filters (and the perimeter) to
  the Item/User morph classes, and keeps the `item` / `author` includes. Author and item stay
  immutable after creation.
- **Moderation is unchanged.** Author-or-admin continues to be the rule for edit, delete and pin, which
  matches the pre-package behaviour and its API tests.

## Deviations from RFC

- **`pin` is granted to the author or an admin.** The RFC says pinning is enabled but names no rule;
  the package default is "nobody", which would have made the UI dead. Author-or-admin keeps one
  moderation rule for edit, delete and pin.
- **`item()` and `author()` are named `morphTo` relations.** A `morphTo('commentable')` relation named
  `item()` cannot be eager-loaded under the name `item` (Laravel keys the eager load by the morph
  relation name), which broke the REST include. Naming the morph relation after the method fixes
  eager loading and keeps the includes working.
- **The published package migrations gained `down()`.** The vendor stubs have no rollback; a rollback
  would have silently left four tables behind.

## Known limitations / next steps

- No attachments, real-time broadcasting, subscriptions or notifications: they are off by design and
  need their own storage/notification decisions.
- Threads are capped at the package default of depth 2 (a root comment plus one reply level).
- Plain-text `@name` mentions written before the package are stored as text and generate no
  notification; there is no historical notification backfill.
- Comment editing does not yet record an entry in the item activity log.

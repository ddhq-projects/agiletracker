---
title: "Bunny Storage for Item and Comment Files"
type: rfc
status: delivery
created: 2026-11-15
---

# Bunny Storage for Item and Comment Files

## TL DR

Store every user-uploaded file on Bunny Storage through its S3-compatible API instead of the repository: one `bunny` disk, one `attachments` config shared by both uploaders, and file support in the item description editor and the comment composer. The disk stays a plain Laravel filesystem disk, so local development keeps working on the `public` disk with no Bunny credentials.

## Context

**Situation.** `ROADMAP.md` asks for files saved "on local and S3". `config/filesystems.php` already had an `s3` disk for AWS, but no S3 adapter was installed, `.env` carried an unused `BUNNY_API_KEY`, and both uploaders were inert: the item description `MarkdownEditor` had no `attachFiles` button, and `comments.attachments.enabled` was `false` even though the `comment_attachments` table was migrated in the previous RFC. Any file a user picked had nowhere to go.

**Complication.** Bunny's S3 API is in public preview and S3 compatibility can only be enabled when a storage zone is created — an existing zone cannot be converted, and a request against a non-S3 zone fails with `ServiceUnavailable: S3 API is not enabled for this storage zone`. Bunny also does not issue per-zone S3 secrets: the access key ID and the bucket are both the Storage Zone name and the secret is the Storage Zone password, which is not the account API key. Both uploaders need a publicly readable URL (`MarkdownEditor` renders static content, and the comments package builds attachment URLs with `Storage::disk($disk)->url()`), so a Pull Zone in front of the zone is required.

**Question and answer.** Introduce a dedicated `bunny` disk and a shared `attachments` config, default both uploaders to `ATTACHMENTS_DISK` with `public` as the safe default, and keep `BUNNY_API_KEY` as a provisioning-only key that the application never reads.

## Purpose and goals

- Users can attach files to a comment and to an item description, and see them again after a reload.
- Files live in Bunny Storage in production and on the `public` disk locally; nothing is committed to the repository.
- The S3 credentials in `.env` are the Storage Zone credentials, not the account API key.
- A missing or wrong Bunny configuration degrades to local storage rather than breaking the editors.

Stakeholders are item users, administrators, maintainers, and anyone who reviews where user data is stored.

## Components and specifics

### 1. Storage zone and CDN Pull Zone

The zone was provisioned through the Bunny API, not the dashboard:

| Setting | Value |
| --- | --- |
| Storage Zone | `agiletracker-files` (id `2060068`) |
| Region | `DE` (Frankfurt) |
| Zone tier | Standard |
| `StorageZoneType` | `1` (S3 supported) |
| S3 endpoint | `https://de-s3.storage.bunnycdn.com` |
| Pull Zone | `agiletracker-files` (id `7018058`) |
| Public hostname | `https://agiletracker-files.b-cdn.net` |

`StorageZoneType: 1` is the whole point: it is only accepted at creation time, so re-provisioning an S3 zone means creating a new zone (and a new Pull Zone) rather than editing the old one. The Pull Zone makes uploaded files readable at `https://agiletracker-files.b-cdn.net/<path>`, which is what both uploaders need for their URLs.

`BUNNY_API_KEY` is the account key used for these provisioning calls. It is never read at runtime and must not be confused with the Storage Zone password that the `bunny` disk uses.

### 2. `bunny` disk and shared attachments config

`config/filesystems.php` gains a `bunny` disk next to the existing `s3` disk. It uses the `s3` driver with path-style URLs, `bucket` and `key` both set to `BUNNY_STORAGE_ZONE`, `secret` set to `BUNNY_STORAGE_PASSWORD`, `endpoint` set to `BUNNY_STORAGE_ENDPOINT` and `url` set to `BUNNY_CDN_URL`. The AWS `s3` disk is untouched.

`config/attachments.php` is the single source of truth for both uploaders:

- `disk` — `ATTACHMENTS_DISK`, default `public`. Both uploaders need a disk that serves public URLs, so `public` locally and `bunny` in production.
- `directory` — `ATTACHMENTS_DIRECTORY`, default `attachments`.
- `max_size` — `ATTACHMENTS_MAX_SIZE` in kilobytes, default `10240`.
- `allowed_types` — images, PDF, plain text and Word documents.

`config/comments.php` keeps its own `attachments` block, because the package reads it directly, but its `enabled` flag is now true and its `disk` / `max_size` follow the same environment values. `phpunit.xml` pins `ATTACHMENTS_DISK=public` so the suite never talks to Bunny.

### 3. Item editor attachments

The description field is extracted to `ItemResource::descriptionEditor()` and configured with the attachments disk, the `attachments/items` directory, the configured size limit and accepted types, plus the `attachFiles` toolbar button. Filament's markdown editor only supports public file attachments — it refuses other visibilities because static markdown content cannot generate temporary URLs — which is why the disk must be public.

Uploads land under `attachments/items` on the configured disk and are referenced from the markdown body. Nothing is stored in the database: the file URL lives in the description, exactly like a pasted image.

### 4. Comment attachments

`relaticle/comments` already ships the composer UI and the `comment_attachments` table; enabling `comments.attachments.enabled` is enough. Each upload is validated against `max_size` and `allowed_types`, stored under `comments/attachments/{comment_id}` on the same disk, and recorded with its disk, path, original name, MIME type and size. Attachment URLs are built with `Storage::disk($disk)->url()`, so a comment image renders through the Pull Zone.

### Shared boundaries and verification

- Everything is public-by-URL. Private attachments (signed URLs, per-user download routes) are out of scope; the existing CDN hostname is not secret and any file URL can be shared.
- No standalone item attachment list, no virus scanning, no per-user quotas, no MIME sniffing beyond Laravel's validation, and no cleanup job for orphaned files in this RFC.
- Attachments are not exposed through API v1 and are not part of `ItemResource`'s infolist.
- Deleting an item or comment does not delete its files; Bunny objects stay until a later lifecycle RFC.
- The file-storage wiring gets focused tests: disk resolution, the item editor's disk/directory/limits/toolbar, a real comment upload into `Storage::fake()`, and rejection of a disallowed type.

## Acceptance criteria (checklist)

- [x] The `bunny` disk stores, reads, sizes and deletes an object against Bunny, and `Storage::disk('bunny')->url($path)` returns the Pull Zone URL.
- [x] The Pull Zone serves an uploaded object with HTTP 200 at its `b-cdn.net` hostname.
- [x] `ATTACHMENTS_DISK` switches both uploaders; `public` works without any Bunny credentials and is what the test suite uses.
- [x] The item description editor offers the `attachFiles` button and uploads to `<directory>/items` with the configured size and type limits.
- [x] The comment composer accepts an image, stores it on the configured disk, records it in `comment_attachments`, and renders it after a reload.
- [x] A disallowed comment attachment is rejected and no comment is created.
- [x] `.env.example` documents the four Bunny Storage values, `BUNNY_API_KEY` as provisioning-only, and the attachments settings, with no real secret committed.
- [x] The full suite, Pint and `npm run build` pass.

## Roadmap

1. Install the S3 adapter and add the `bunny` disk plus `config/attachments.php`. Provision the S3-enabled zone and Pull Zone through the Bunny API.
2. Enable comment attachments and wire the item description editor to the attachments disk.
3. Cover the storage wiring with tests, then document the provisioning and verification steps in the wiki.

All three steps are delivered together; there is no staged release.

## Summary and recommendations

Use Bunny Storage behind Laravel's ordinary S3 driver rather than a Bunny-specific SDK, so the uploaders stay framework code and local development is unchanged. Keep one `attachments` config as the switch, keep the account API key out of runtime configuration, and accept public attachment URLs until private delivery is its own design.

## Test instructions

### 1. Automated tests

- [tests/Feature/FileStorageTest.php](../../tests/Feature/FileStorageTest.php) — the configured disk is shared by both uploaders; the item editor resolves the disk, directory, size limit, accepted types and `attachFiles` button and follows a config override; a comment image is stored on the configured disk and recorded with its disk, name and MIME type; an `.exe` upload is rejected and creates no comment.
- [tests/Feature/ItemCommentsTest.php](../../tests/Feature/ItemCommentsTest.php) — the feature flags now assert attachments on while notifications, subscriptions and broadcasting stay off.
- Run: `php artisan test --compact` (the whole suite is green).
- Also run: `php vendor/bin/pint --dirty --format agent` and `npm run build`.

### 2. Item description editor

- `/i/{id}/edit` — the description toolbar shows the attach button; attach a PNG and save.
- Re-open the item (`/i/{id}`): the image renders in the description, and its URL starts with `https://agiletracker-files.b-cdn.net/attachments/items/` when `ATTACHMENTS_DISK=bunny`.
- [app/Filament/Resources/ItemResource.php](../../app/Filament/Resources/ItemResource.php) — `descriptionEditor()` and its attachments wiring.

### 3. Comment attachments

- `/i/{id}` — the comment composer shows the attach button; add two files, write a body and submit.
- The comment shows both attachments with their sizes and names; an image renders inline.
- Upload a `.exe` through the composer: the comment is not created and a validation error appears.
- [config/comments.php](../../config/comments.php) — `attachments.enabled`, `disk` and `max_size`.

### 4. Storage

- `php artisan tinker --execute '$s = Storage::disk("bunny"); $s->put("examples/check.txt", "ok"); echo $s->get("examples/check.txt"); $s->delete("examples/check.txt");'` prints `ok`.
- `curl https://agiletracker-files.b-cdn.net/examples/check.txt` returns the same content while the object exists.
- [config/filesystems.php](../../config/filesystems.php) — the `bunny` disk; [config/attachments.php](../../config/attachments.php) — the shared settings.
- [.env.example](../../.env.example) — the documented environment block.

### 5. Rollback

- Set `ATTACHMENTS_DISK=public` and run `php artisan config:clear`: both uploaders fall back to `storage/app/public` without touching Bunny.
- Files already written to Bunny stay there; nothing in the database points at the disk, so switching back changes only newly generated URLs.

### Open questions

- **Private attachments:** comment attachments are public-by-URL today. Should a later RFC add a signed-URL download route and keep only non-secret files on the Pull Zone?
- **Retention:** should deleting an item or comment delete its Bunny objects, and should an orphan sweep run on a schedule?

### Alternative options

- **Keep the account API key in the `s3` disk config.** Tempting because `BUNNY_API_KEY` was already present, but Bunny rejects it as an S3 secret (`AccessDenied`) and it would put an account-wide key into the filesystem config.
- **Use Bunny's HTTP Storage API instead of S3.** Removes the AWS SDK dependency but loses Laravel's `Storage` facade, `Storage::fake()` in tests, and the editor integrations; a custom adapter would be needed for both uploaders.
- **Serve files through the application with signed routes.** Supports private attachments immediately but adds a PHP request per file and bypasses the CDN; it is the natural follow-up if attachments must be private.

### References

- [Bunny S3 API documentation](https://docs.bunny.net/storage/s3) — supported operations, limits, and the `StorageZoneType` / S3-not-enabled error.
- [Bunny Add Storage Zone](https://docs.bunny.net/api-reference/core/storage-zone/add-storage-zone) — the `StorageZoneType` field.
- [Laravel 13 filesystem documentation](https://laravel.com/docs/13.x/filesystem) and [Filament markdown editor](https://filamentphp.com/docs/forms/markdown-editor).
- [docs/wiki/bunny-s3-storage.md](../wiki/bunny-s3-storage.md) — the operator-facing setup guide.

**Delivery status (2026-11-15):** implemented and green. The zone `agiletracker-files` and Pull Zone `agiletracker-files` are live in the account; `.env` points at them, `.env.example` documents placeholders.

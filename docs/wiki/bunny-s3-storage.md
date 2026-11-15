# Bunny.net S3 Storage

AgileTracker uploads user files to Bunny Storage through Laravel's S3 filesystem disk. The `bunny` disk and the shared `attachments` settings are already in the repository; you only need a Storage Zone with S3 compatibility, its credentials, and one environment switch.

## 1. Install the S3 adapter

The adapter is already required. On a fresh clone:

```bash
composer install
```

If the disk reports a missing adapter, install it again:

```bash
composer require league/flysystem-aws-s3-v3 "^3.0" --with-all-dependencies
```

## 2. Create a Storage Zone with S3 compatibility

Bunny's S3 API is in public preview and **S3 compatibility can only be enabled when a zone is created**. It cannot be turned on later: an existing zone answers `ServiceUnavailable: S3 API is not enabled for this storage zone`, and the only fix is a new zone.

In the Bunny dashboard:

1. Open **Storage → Add Storage Zone**.
2. Pick a name, for example `agiletracker-files` (lowercase letters, digits and dashes).
3. Enable **S3 Compatibility**.
4. Choose the **Standard** tier and a region that supports S3 (`DE`, `NY`, `SG`, `UK`, `SE`, `LA`, `JH`, `SYD`).
5. Create the zone, then open **Access → S3** and copy the regional endpoint and region.

The same can be done through the Bunny API, which is what this project does. `BUNNY_API_KEY` is the account API key from **Account settings → API**:

```bash
curl -X POST https://api.bunny.net/storagezone \
  -H "AccessKey: $BUNNY_API_KEY" \
  -H "content-type: application/json" \
  -d '{"Name":"agiletracker-files","Region":"DE","ZoneTier":0,"StorageZoneType":1}'
```

`StorageZoneType: 1` is the S3 flag and the response contains the Storage Zone password. Then create a Pull Zone so the files get public URLs:

```bash
curl -X POST https://api.bunny.net/pullzone \
  -H "AccessKey: $BUNNY_API_KEY" \
  -H "content-type: application/json" \
  -d '{"Name":"agiletracker-files","StorageZoneId":<storage-zone-id>,"Type":0}'
```

The Pull Zone's system hostname (`https://agiletracker-files.b-cdn.net`) is the public base URL.

Bunny maps its Storage Zone details to S3 credentials as follows:

| Laravel setting | Bunny value |
| --- | --- |
| Access key ID | Storage Zone name |
| Secret access key | Storage Zone password |
| Bucket | Storage Zone name |
| Region | Region shown under **Access → S3** (`de`, `ny`, …) |
| Endpoint | Regional S3 endpoint shown under **Access → S3** |

Do not use `BUNNY_API_KEY` as the secret access key. It is an account-wide key for the provisioning API and the application never reads it at runtime.

## 3. Configure AgileTracker

Fill the Bunny block in `.env`:

```dotenv
ATTACHMENTS_DISK=bunny

BUNNY_API_KEY=your-account-api-key          # provisioning only
BUNNY_STORAGE_ZONE=agiletracker-files
BUNNY_STORAGE_PASSWORD=your-storage-zone-password
BUNNY_STORAGE_REGION=de
BUNNY_STORAGE_ENDPOINT=https://de-s3.storage.bunnycdn.com
BUNNY_CDN_URL=https://agiletracker-files.b-cdn.net
```

`BUNNY_STORAGE_ENDPOINT` and `BUNNY_CDN_URL` are required, not optional: the endpoint replaces the AWS host, and the CDN URL is what turns a stored path into a link the editors can render.

Keep `.env` out of version control and never commit the Storage Zone password.

Reload the configuration after any change:

```bash
php artisan config:clear
```

To keep local storage as the default and use Bunny only for selected files, leave `ATTACHMENTS_DISK=public` and select the `bunny` disk in code with `Storage::disk('bunny')`.

## 4. Where files are used

Both uploaders read `config/attachments.php`, so `ATTACHMENTS_DISK` switches them together:

| Uploader | Location on the disk | Manager |
| --- | --- | --- |
| Item description files | `attachments/items/` | `ItemResource::descriptionEditor()` |
| Comment attachments | `comments/attachments/{comment_id}/` | `relaticle/comments` |

`ATTACHMENTS_MAX_SIZE` (kilobytes, default `10240`) and the accepted MIME types in `config/attachments.php` apply to both. The comment composer keeps its own copy in `config/comments.php` because the package reads that config directly.

Both uploaders need a disk that serves public URLs — the markdown editor renders static content and the comments package builds attachment links with `Storage::disk()->url()`. Use the `public` disk locally and the `bunny` disk in production. Attachments are public-by-URL once uploaded; private delivery is a separate design.

## 5. First example

This writes a file to Bunny, reads it back, and deletes it:

```php
use Illuminate\Support\Facades\Storage;

$path = 'examples/hello.txt';

Storage::disk('bunny')->put($path, 'Hello from AgileTracker');

$contents = Storage::disk('bunny')->get($path); // Hello from AgileTracker

Storage::disk('bunny')->delete($path);
```

For an uploaded file, pass the disk name as the second argument to `store()` and save the returned path in your database:

```php
$path = $request->file('attachment')->store('attachments', config('attachments.disk'));
```

Validate uploads in a Form Request before storing them.

## 6. Check the live connection

Run this one-off check after configuring real Bunny credentials. It creates and then removes `examples/bunny-s3-check.txt`:

```bash
php artisan tinker --execute '$s = Illuminate\Support\Facades\Storage::disk(config("attachments.disk")); $s->put("examples/bunny-s3-check.txt", "Bunny S3 works"); echo $s->get("examples/bunny-s3-check.txt"); $s->delete("examples/bunny-s3-check.txt");'
```

The command should print `Bunny S3 works`. Then confirm delivery through the Pull Zone:

```bash
php artisan tinker --execute 'Illuminate\Support\Facades\Storage::disk("bunny")->put("examples/cdn-check.txt", "cdn-ok");'
curl https://agiletracker-files.b-cdn.net/examples/cdn-check.txt
```

The `curl` returns `cdn-ok` once the object exists (the first request after a cold cache can take a second).

## 7. Check the uploaders

- `/i/{id}/edit` — the description toolbar has an attach button; upload an image, save, and the image renders on `/i/{id}`.
- `/i/{id}` — the comment composer has an attach button; upload two files and submit, and both appear under the comment with their sizes.
- Set `ATTACHMENTS_DISK=public` and `php artisan config:clear` to confirm everything works without Bunny.

## Troubleshooting

- **`S3 API is not enabled for this storage zone`:** the zone was created without S3 compatibility. It cannot be converted — create a new zone with `StorageZoneType: 1` (or the dashboard's **S3 Compatibility** switch) and move the credentials over.
- **`SignatureDoesNotMatch` or `AccessDenied`:** the access key ID must be the Storage Zone name and the secret must be the Storage Zone password, not `BUNNY_API_KEY`.
- **`Storage Zone is null or bucket not found`:** keep `use_path_style_endpoint` on (the `bunny` disk always sets it) and confirm that the bucket and access key ID both equal the Storage Zone name.
- **A file uploads but its URL is wrong or empty:** set `BUNNY_CDN_URL` from the Pull Zone hostname; without it the disk can store objects but cannot produce links.
- **Old configuration is still used:** run `php artisan config:clear` after editing `.env`.
- **Tests try to reach Bunny:** the suite pins `ATTACHMENTS_DISK=public` in `phpunit.xml`; do not run upload tests against real credentials.

## References

- [Laravel 13 filesystem documentation](https://laravel.com/docs/13.x/filesystem)
- [Bunny S3 API](https://docs.bunny.net/storage/s3)
- [Bunny Add Storage Zone API](https://docs.bunny.net/api-reference/core/storage-zone/add-storage-zone)
- [RFC: Bunny Storage for Item and Comment Files](../rfc/2026-11-15-bunny-file-storage.md)

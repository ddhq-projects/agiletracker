# Bunny.net S3 Storage

Use Bunny Storage through Laravel's standard S3 filesystem disk. AgileTracker already includes the required disk configuration in `config/filesystems.php`; you only need the S3 adapter and environment values.

## 1. Install the S3 adapter

```bash
composer require league/flysystem-aws-s3-v3 "^3.0" --with-all-dependencies
```

## 2. Create an S3-compatible Storage Zone

1. In the Bunny dashboard, open **Delivery → Storage** and create a Storage Zone with **S3 compatibility** enabled. If this option is unavailable on your account, contact Bunny support about access.
2. Open the Storage Zone and go to **Access → S3**.
3. Copy the regional endpoint and region shown there. An endpoint looks like `https://de-s3.storage.bunnycdn.com`; use your own region instead of copying `de`.

Bunny maps its Storage Zone details to S3 credentials as follows:

| Laravel setting | Bunny value |
| --- | --- |
| Access key ID | Storage Zone name |
| Secret access key | Storage Zone password |
| Bucket | Storage Zone name |
| Region | Region shown under **Access → S3** |
| Endpoint | Regional S3 endpoint shown under **Access → S3** |

Do not use your Bunny account API key as the secret access key.

## 3. Configure AgileTracker

Replace the example values in `.env` with the values from your Bunny dashboard:

```dotenv
FILESYSTEM_DISK=s3

AWS_ACCESS_KEY_ID=agiletracker-files
AWS_SECRET_ACCESS_KEY=your-storage-zone-password
AWS_DEFAULT_REGION=de
AWS_BUCKET=agiletracker-files
AWS_ENDPOINT=https://de-s3.storage.bunnycdn.com
AWS_USE_PATH_STYLE_ENDPOINT=true
```

Keep `.env` out of version control and never commit the Storage Zone password.

After changing environment values, reload Laravel's configuration:

```bash
php artisan config:clear
```

To keep local storage as the default and use Bunny only for selected files, leave `FILESYSTEM_DISK=local` and explicitly select the `s3` disk in code.

## 4. First example

This writes a file to Bunny, reads it back, and deletes it:

```php
use Illuminate\Support\Facades\Storage;

$path = 'examples/hello.txt';

Storage::disk('s3')->put($path, 'Hello from AgileTracker');

$contents = Storage::disk('s3')->get($path); // Hello from AgileTracker

Storage::disk('s3')->delete($path);
```

For an uploaded file, pass `s3` as the second argument to `store()` and save the returned path in your database:

```php
$path = $request->file('attachment')->store('attachments', 's3');
```

Validate uploads in a Form Request before storing them.

## 5. Check the live connection

Run this one-off check after configuring real Bunny credentials. It creates and then removes `examples/bunny-s3-check.txt`:

```bash
php artisan tinker --execute '$path = "examples/bunny-s3-check.txt"; Illuminate\Support\Facades\Storage::disk("s3")->put($path, "Bunny S3 works"); dump(Illuminate\Support\Facades\Storage::disk("s3")->get($path)); Illuminate\Support\Facades\Storage::disk("s3")->delete($path);'
```

The command should print `Bunny S3 works`.

## Optional: serve public files through a Bunny Pull Zone

If the Storage Zone is connected to a Pull Zone, set `AWS_URL` to its public hostname:

```dotenv
AWS_URL=https://your-pull-zone.b-cdn.net
```

Then generate a public URL with:

```php
$url = Storage::disk('s3')->url($path);
```

Only use this for files intended to be public. Keep private attachments on a private delivery path instead of exposing them through a public Pull Zone.

## Troubleshooting

- **Credentials or region mismatch:** use the exact region and S3 endpoint from **Access → S3**, not the standard Storage API hostname.
- **Storage Zone is null or bucket not found:** set `AWS_USE_PATH_STYLE_ENDPOINT=true` and confirm that the bucket and access key ID both equal the Storage Zone name.
- **Old configuration is still used:** run `php artisan config:clear` after editing `.env`.
- **Class or adapter not found:** install `league/flysystem-aws-s3-v3` as shown above.

## References

- [Laravel 13 filesystem documentation](https://laravel.com/docs/13.x/filesystem)
- [Bunny developer documentation](https://docs.bunny.net/)

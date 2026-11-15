<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Attachment Disk
    |--------------------------------------------------------------------------
    |
    | User-uploaded files (item descriptions and comment attachments) go to
    | this disk. Both uploaders need the disk to produce public URLs, so it
    | must be the `public` disk locally or the `bunny` disk in production.
    | Point ATTACHMENTS_DISK at `bunny` to keep files out of the repository.
    |
    */

    'disk' => env('ATTACHMENTS_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Attachment Directory
    |--------------------------------------------------------------------------
    |
    | The prefix used inside the disk. Item editor uploads live under
    | `<directory>/items`, comment uploads under `comments/attachments/...`.
    |
    */

    'directory' => env('ATTACHMENTS_DIRECTORY', 'attachments'),

    /*
    |--------------------------------------------------------------------------
    | Upload Limits
    |--------------------------------------------------------------------------
    |
    | `max_size` is in kilobytes, matching Laravel's `max` validation rule.
    | Both lists mirror what the comment composer and the item editor accept.
    |
    */

    'max_size' => (int) env('ATTACHMENTS_MAX_SIZE', 10240),

    'allowed_types' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ],

];

<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\ActivityRedactor;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Reads the activity columns as a collection and masks secrets on the way
 * out, so neither the item timeline nor the administrative journal can leak
 * a value that was written before the redaction existed.
 *
 * @implements CastsAttributes<Collection<int|string, mixed>|null, mixed>
 */
final class RedactedActivityData implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Collection
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        if (! is_array($decoded)) {
            return null;
        }

        $redacted = ActivityRedactor::redactChanges($decoded);

        return $redacted instanceof Collection ? $redacted : collect($redacted);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $data = $value instanceof Collection ? $value->all() : $value;

        return json_encode($data);
    }
}

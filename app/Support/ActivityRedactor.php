<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Masks the values that must never be readable in the audit log, even when a
 * row was written by an older version or by a third-party source.
 */
final class ActivityRedactor
{
    public const string MASK = '[redacted]';

    /**
     * Keys whose value is replaced, and suffixes that make a key sensitive.
     *
     * @var list<string>
     */
    private const array SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'remember_token',
        'api_token',
        'access_token',
        'refresh_token',
        'token',
        'secret',
        'authorization',
        'attachment_content',
        'attachment_contents',
    ];

    /**
     * @var list<string>
     */
    private const array SENSITIVE_SUFFIXES = ['_password', '_token', '_secret', '_key'];

    /**
     * Redacts an old/new change set, keeping its shape.
     *
     * @param  Collection<int|string, mixed>|array<int|string, mixed>|null  $changes
     * @return Collection<int|string, mixed>|null
     */
    public static function redactChanges(Collection|array|null $changes): Collection|array|null
    {
        if ($changes === null) {
            return null;
        }

        $isCollection = $changes instanceof Collection;
        $data = $isCollection ? $changes->all() : $changes;

        foreach (['attributes', 'old'] as $section) {
            if (! isset($data[$section]) || ! is_array($data[$section])) {
                continue;
            }

            $data[$section] = self::redactArray($data[$section]);
        }

        // A flat map without the attributes/old wrapper is masked as a whole.
        if (! array_key_exists('attributes', $data) && ! array_key_exists('old', $data)) {
            $data = self::redactArray($data);
        }

        return $isCollection ? collect($data) : $data;
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, mixed>
     */
    public static function redactArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $data[$key] = self::MASK;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::redactArray($value);
            }
        }

        return $data;
    }

    public static function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        if (in_array($key, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_SUFFIXES as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }
}

@php
    /** @var \App\Models\Activity $activity */
    $changes = $activity->attribute_changes;
    $attributes = $changes?->get('attributes') ?? [];
    $old = $changes?->get('old') ?? [];
@endphp

@if (empty($attributes) && empty($old))
    <p class="text-sm text-gray-500 dark:text-gray-400">
        No field changes were recorded for this event.
    </p>
@else
    <div class="overflow-hidden rounded-lg ring-1 ring-gray-200 dark:ring-white/10">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Field</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Old</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">New</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($attributes as $field => $newValue)
                    <tr>
                        <td class="px-3 py-2 font-mono text-xs text-gray-700 dark:text-gray-200">{{ $field }}</td>
                        <td class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Str::limit((string) json_encode($old[$field] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200) }}</td>
                        <td class="px-3 py-2 text-xs text-gray-900 dark:text-gray-100">{{ \Illuminate\Support\Str::limit((string) json_encode($newValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200) }}</td>
                    </tr>
                @endforeach

                @foreach (array_diff_key($old, $attributes) as $field => $oldValue)
                    <tr>
                        <td class="px-3 py-2 font-mono text-xs text-gray-700 dark:text-gray-200">{{ $field }}</td>
                        <td class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Str::limit((string) json_encode($oldValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200) }}</td>
                        <td class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">—</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

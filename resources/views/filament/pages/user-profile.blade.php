<x-filament-panels::page>
    <x-filament::section heading="Profile">
        <dl class="grid gap-6 sm:grid-cols-2">
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Name</dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $profile->name }}</dd>
            </div>

            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Email</dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $profile->email }}</dd>
            </div>

            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Member since</dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                    {{ $profile->created_at?->toFormattedDateString() ?? '—' }}
                </dd>
            </div>
        </dl>
    </x-filament::section>
</x-filament-panels::page>

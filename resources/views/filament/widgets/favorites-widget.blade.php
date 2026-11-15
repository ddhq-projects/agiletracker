<x-filament-widgets::widget>
    <div class="flex flex-col gap-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                Favorites
            </h2>

            <x-filament::link :href="$favoritesUrl" tag="a" size="sm">
                View all
            </x-filament::link>
        </div>

        @if ($favorites->isEmpty())
            <div class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-gray-300 px-4 py-8 text-center dark:border-white/10">
                <x-filament::icon
                    icon="heroicon-o-star"
                    class="h-8 w-8 text-gray-400 dark:text-gray-500"
                />

                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No favorites yet. Add tasks, users or links for one-click access.
                </p>

                <x-filament::link :href="$favoritesUrl" tag="a" size="sm">
                    Go to Favorites
                </x-filament::link>
            </div>
        @else
            <div
                x-sortable
                x-on:end.stop="$wire.reorderFavorites($event.target.sortable.toArray())"
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
            >
                @foreach ($favorites as $row)
                    @php
                        $favorite = $row['favorite'];
                        $target = $row['target'];
                        $type = $favorite->url !== null && $favorite->favoritable_type === null
                            ? 'Link'
                            : class_basename($favorite->favoritable_type ?? 'Unknown');
                        $caption = $favorite->favoritable_type === null
                            ? (parse_url((string) $favorite->url, PHP_URL_HOST) ?? $favorite->url)
                            : (string) ($favorite->favoritable?->title ?? $favorite->favoritable?->name ?? '—');
                    @endphp

                    <div
                        x-sortable-item="{{ $favorite->id }}"
                        wire:key="favorite-card-{{ $favorite->id }}"
                        class="group flex flex-col justify-between gap-3 rounded-xl border border-gray-200 bg-white p-3 shadow-sm transition hover:border-primary-300 dark:border-white/10 dark:bg-gray-900 dark:hover:border-primary-500/50"
                    >
                        <div class="flex items-start gap-2">
                            <button
                                type="button"
                                x-sortable-handle
                                aria-label="Reorder {{ $favorite->name }}"
                                class="mt-0.5 cursor-grab rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:text-gray-500 dark:hover:bg-white/5 dark:hover:text-gray-300"
                            >
                                <x-filament::icon icon="heroicon-m-bars-3" class="h-4 w-4" />
                            </button>

                            <a
                                href="{{ $target->url }}"
                                @if ($target->isExternal) target="_blank" rel="noopener noreferrer" @endif
                                class="line-clamp-2 text-sm font-medium text-gray-950 hover:text-primary-600 hover:underline dark:text-white dark:hover:text-primary-400"
                            >
                                {{ $favorite->name }}
                            </a>
                        </div>

                        <div class="flex items-end justify-between gap-2">
                            <div class="min-w-0">
                                <span class="inline-flex items-center rounded-md bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                    {{ $type }}
                                </span>

                                <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400" title="{{ $caption }}">
                                    {{ $caption }}
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-1">
                                <button
                                    type="button"
                                    wire:click="moveFavorite({{ $favorite->id }}, 'up')"
                                    aria-label="Move {{ $favorite->name }} earlier"
                                    class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-white/5 dark:hover:text-gray-300"
                                >
                                    <x-filament::icon icon="heroicon-m-chevron-up" class="h-4 w-4" />
                                </button>

                                <button
                                    type="button"
                                    wire:click="moveFavorite({{ $favorite->id }}, 'down')"
                                    aria-label="Move {{ $favorite->name }} later"
                                    class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-white/5 dark:hover:text-gray-300"
                                >
                                    <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($favorites->count() === \App\Filament\Widgets\FavoritesWidget::CARD_LIMIT)
                <div class="flex justify-end">
                    <x-filament::link :href="$favoritesUrl" tag="a" size="sm">
                        Show all favorites
                    </x-filament::link>
                </div>
            @endif
        @endif
    </div>
</x-filament-widgets::widget>

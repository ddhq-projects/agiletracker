@props([
    'labels',
])

@php
    use Filament\Support\Colors\Color;

    /**
     * Labels store the colour the picker returned, usually a hex value. Filament
     * resolves colour names from a fixed palette, so a stored hex has to become
     * a palette of its own to keep the label's identity on screen.
     */
    $palette = fn (?string $color): array|string => blank($color)
        ? 'gray'
        : (str_starts_with($color, '#') ? Color::generatePalette($color) : $color);
@endphp

<span class="inline-flex flex-wrap items-center gap-1">
    @foreach ($labels as $label)
        <x-filament::badge :color="$palette($label->color)" size="xs">
            {{ $label->name }}
        </x-filament::badge>
    @endforeach
</span>

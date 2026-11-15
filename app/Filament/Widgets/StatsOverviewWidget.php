<?php

namespace App\Filament\Widgets;

use App\Models\Item;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $user = auth()->user();

        $visible = fn () => Item::query()->visibleTo($user);

        return [
            Stat::make('Total Items', $visible()->count())
                ->color('primary')
                ->icon('heroicon-o-clipboard-document-list'),

            Stat::make('Assigned to me', $visible()->where('assignee_id', $user?->getKey())->count())
                ->color('warning')
                ->icon('heroicon-o-arrow-path'),

            Stat::make('Updated Today', $visible()->whereDate('updated_at', today())->count())
                ->color('success')
                ->icon('heroicon-o-check-circle'),

            Stat::make('Overdue', $visible()->whereDate('due_date', '<', today())->count())
                ->color('danger')
                ->icon('heroicon-o-exclamation-triangle'),
        ];
    }
}

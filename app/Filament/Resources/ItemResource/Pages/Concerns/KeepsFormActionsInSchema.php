<?php

declare(strict_types=1);

namespace App\Filament\Resources\ItemResource\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;

/**
 * The item form places its actions in the layout's right rail
 * (`ItemResource::form()`), so the form wrapper renders no footer. The page
 * still owns which actions exist and what they do.
 */
trait KeepsFormActionsInSchema
{
    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler($this->getSubmitFormLivewireMethodName());
    }

    /**
     * The page's own form actions, for the layout to render where it wants.
     *
     * @return array<Action>
     */
    public function getRailFormActions(): array
    {
        return $this->getFormActions();
    }
}

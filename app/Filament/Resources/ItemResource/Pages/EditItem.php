<?php

namespace App\Filament\Resources\ItemResource\Pages;

use App\Access\ItemAccess;
use App\Actions\SyncItemAccessGroups;
use App\Filament\Resources\ItemResource;
use App\Filament\Resources\ItemResource\Pages\Concerns\KeepsFormActionsInSchema;
use App\Models\Item;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Gate;

class EditItem extends EditRecord
{
    use KeepsFormActionsInSchema;

    protected static string $resource = ItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @return array<int, Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('saveAndView')
                ->label('Save and view')
                ->color('success')
                ->action('saveAndView'),
            ...parent::getFormActions(),
        ];
    }

    public function saveAndView(): void
    {
        $this->save();

        $this->redirect($this->getResource()::getUrl('view', ['record' => $this->getRecord()]));
    }

    public function saveAndDelete(): void
    {
        $this->save();

        $record = $this->getRecord();

        $record->delete();

        $this->redirect($this->getResource()::getUrl('index'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['access_group_ids'] = $this->getRecord()->accessGroups()->pluck('groups.id')->all();

        return $data;
    }

    /**
     * The form is only a window: a request that carries fields the user may
     * not write is narrowed here before anything is saved.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Item $item */
        $item = $this->getRecord();

        if (! Gate::allows('manageAccess', $item)) {
            unset($data['project_group_id'], $data['visibility']);
        }

        if (($data['parent_id'] ?? null) !== null && ItemResource::parentLabel($data['parent_id']) === null) {
            abort(422);
        }

        $user = auth()->user();
        $allowed = $user === null ? [] : app(ItemAccess::class)->updatableAttributes($user, $item);

        if (is_array($allowed)) {
            $data = array_intersect_key($data, array_flip($allowed));
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $item = $this->getRecord();

        if (! Gate::allows('manageAccess', $item)) {
            return;
        }

        app(SyncItemAccessGroups::class)(
            $item,
            (array) ($this->data['access_group_ids'] ?? []),
            auth()->user(),
        );
    }
}

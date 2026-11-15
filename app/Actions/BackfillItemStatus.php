<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ItemStatus;
use Illuminate\Support\Facades\DB;

/**
 * Copies the workflow labels written by the old Kanban handler onto the new
 * native `items.status` column, then detaches only those labels.
 */
final class BackfillItemStatus
{
    /**
     * Label names recognised as workflow state, lowest precedence first.
     *
     * @var array<string, string>
     */
    private const LABEL_TO_STATUS = [
        'todo' => ItemStatus::Todo->value,
        'to do' => ItemStatus::Todo->value,
        'in_progress' => ItemStatus::InProgress->value,
        'in progress' => ItemStatus::InProgress->value,
        'review' => ItemStatus::Review->value,
        'done' => ItemStatus::Done->value,
    ];

    /**
     * Precedence when an item carries several workflow labels.
     *
     * @var list<string>
     */
    private const PRECEDENCE = [
        ItemStatus::Done->value,
        ItemStatus::Review->value,
        ItemStatus::InProgress->value,
        ItemStatus::Todo->value,
    ];

    public function __invoke(): void
    {
        $rows = DB::table('item_label')
            ->join('labels', 'labels.id', '=', 'item_label.label_id')
            ->select(['item_label.item_id', 'labels.name'])
            ->get();

        /** @var array<int, array<int, string>> $statuses */
        $statuses = [];

        foreach ($rows as $row) {
            $status = self::LABEL_TO_STATUS[strtolower(trim((string) $row->name))] ?? null;

            if ($status === null) {
                continue;
            }

            $statuses[(int) $row->item_id][] = $status;
        }

        $workflowLabelIds = DB::table('labels')
            ->pluck('name', 'id')
            ->filter(fn (string $name): bool => isset(self::LABEL_TO_STATUS[strtolower(trim($name))]))
            ->keys()
            ->all();

        DB::transaction(function () use ($statuses, $workflowLabelIds): void {
            foreach ($statuses as $itemId => $found) {
                $status = collect(self::PRECEDENCE)->first(
                    fn (string $candidate): bool => in_array($candidate, $found, true)
                ) ?? ItemStatus::Todo->value;

                DB::table('items')->where('id', $itemId)->update(['status' => $status]);
            }

            if ($workflowLabelIds !== []) {
                DB::table('item_label')->whereIn('label_id', $workflowLabelIds)->delete();
            }
        });
    }
}

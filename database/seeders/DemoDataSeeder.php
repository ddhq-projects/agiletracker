<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\GroupMemberRole;
use App\Enums\GroupType;
use App\Enums\ItemStatus;
use App\Models\Favorite;
use App\Models\Group;
use App\Models\Item;
use App\Models\Label;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // ─── Labels ──────────────────────────────────────────────────────
        // Workflow state lives in `items.status`, so the seed set keeps only
        // descriptive tags.
        $labels = [
            ['name' => 'Task', 'color' => '#3b82f6'],
            ['name' => 'Bug', 'color' => '#ef4444'],
            ['name' => 'Feature', 'color' => '#8b5cf6'],
            ['name' => 'Epic', 'color' => '#f59e0b'],
            ['name' => 'Low', 'color' => '#6b7280'],
            ['name' => 'Medium', 'color' => '#3b82f6'],
            ['name' => 'High', 'color' => '#f59e0b'],
            ['name' => 'Critical', 'color' => '#ef4444'],
        ];
        foreach ($labels as $data) {
            Label::firstOrCreate(['name' => $data['name']], $data);
        }

        $labelTask = Label::where('name', 'Task')->first();
        $labelEpic = Label::where('name', 'Epic')->first();
        $labelHigh = Label::where('name', 'High')->first();
        $labelMedium = Label::where('name', 'Medium')->first();

        $admin = User::where('email', 'admin@example.com')->first();
        $alice = User::where('email', 'alice@example.com')->first();
        $bob = User::where('email', 'bob@example.com')->first();
        $carol = User::where('email', 'carol@example.com')->first();
        $otherUsers = [$alice, $bob, $carol];

        // ─── Groups ──────────────────────────────────────────────────────
        // One of each type shows the naming the access model expects: a
        // project group names the project of an item, while teams and
        // departments are organisational units.
        $engineering = Group::firstOrCreate(
            ['name' => 'Engineering'],
            ['type' => GroupType::Department, 'description' => 'Everyone who ships code.'],
        );

        $platform = Group::firstOrCreate(
            ['name' => 'Platform team'],
            ['type' => GroupType::Team, 'parent_id' => $engineering->getKey()],
        );

        $coreProject = Group::firstOrCreate(
            ['name' => 'AgileTracker Core'],
            ['type' => GroupType::Project],
        );

        $mobileProject = Group::firstOrCreate(
            ['name' => 'Mobile App'],
            ['type' => GroupType::Project],
        );

        $engineering->users()->syncWithoutDetaching([
            $admin->getKey() => ['role' => GroupMemberRole::Manager->value],
        ]);
        $platform->users()->syncWithoutDetaching([
            $alice->getKey() => ['role' => GroupMemberRole::Manager->value],
            $bob->getKey() => ['role' => GroupMemberRole::Member->value],
            $carol->getKey() => ['role' => GroupMemberRole::Member->value],
        ]);
        $coreProject->users()->syncWithoutDetaching([
            $alice->getKey() => ['role' => GroupMemberRole::Manager->value],
            $bob->getKey() => ['role' => GroupMemberRole::Member->value],
        ]);
        $mobileProject->users()->syncWithoutDetaching([
            $carol->getKey() => ['role' => GroupMemberRole::Member->value],
        ]);

        // ─── Projects ────────────────────────────────────────────────────
        $project1 = Item::factory()->create([
            'title' => 'AgileTracker Core',
            'description' => 'Core functionality for the AgileTracker project management tool.',
            'status' => ItemStatus::InProgress,
            'project_group_id' => $coreProject->getKey(),
        ]);
        $project1->labels()->sync([$labelEpic->id, $labelHigh->id]);

        $project2 = Item::factory()->create([
            'title' => 'Mobile App',
            'description' => 'React Native mobile application for on-the-go task management.',
            'status' => ItemStatus::Todo,
            'project_group_id' => $mobileProject->getKey(),
        ]);
        $project2->labels()->sync([$labelEpic->id, $labelMedium->id]);

        $project3 = Item::factory()->create([
            'title' => 'API v2',
            'description' => 'Public REST API for third-party integrations.',
            'status' => ItemStatus::Done,
        ]);
        $project3->labels()->sync([$labelEpic->id, $labelHigh->id]);

        $projects = [$project1->id, $project2->id, $project3->id];

        // ── Inbox: 3 entries for admin@example.com ───────────────────────
        foreach ([1, 2, 3] as $i) {
            $task = Item::factory()->create([
                'title' => "Inbox Task $i",
                'parent_id' => $projects[array_rand($projects)],
                'assignee_id' => $admin->id,
                'reporter_id' => $otherUsers[array_rand($otherUsers)]->id,
                'status' => ItemStatus::Todo,
            ]);
            $task->labels()->sync([$labelTask->id]);
            $task->to()->sync([$admin->id]);
        }

        // ── Sent: 3 entries for admin@example.com ────────────────────────
        foreach ([1, 2, 3] as $i) {
            $task = Item::factory()->create([
                'title' => "Sent Task $i",
                'parent_id' => $projects[array_rand($projects)],
                'assignee_id' => $otherUsers[array_rand($otherUsers)]->id,
                'reporter_id' => $admin->id,
                'status' => ItemStatus::InProgress,
            ]);
            $task->labels()->sync([$labelTask->id]);
        }

        // ── Favorites: 3 entries for admin@example.com ───────────────────
        $allItems = Item::whereNotNull('parent_id')->get();
        foreach ($allItems->random(min(3, $allItems->count())) as $item) {
            Favorite::firstOrCreate([
                'user_id' => $admin->id,
                'favoritable_type' => Item::class,
                'favoritable_id' => $item->id,
            ], [
                'name' => $item->title,
            ]);
        }
    }
}

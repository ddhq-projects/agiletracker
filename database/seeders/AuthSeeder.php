<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AuthSeeder extends Seeder
{
    public function run(): void
    {
        // ─── Users ────────────────────────────────────────────────────────
        $admin = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Admin', 'password' => Hash::make('password')]
        );
        $admin->syncRoles([RoleName::Admin->value]);

        $alice = User::updateOrCreate(
            ['email' => 'alice@example.com'],
            ['name' => 'Alice Johnson', 'password' => Hash::make('password')]
        );
        $alice->syncRoles([RoleName::Manager->value]);

        $bob = User::updateOrCreate(
            ['email' => 'bob@example.com'],
            ['name' => 'Bob Williams', 'password' => Hash::make('password')]
        );
        $bob->syncRoles([RoleName::User->value]);

        $carol = User::updateOrCreate(
            ['email' => 'carol@example.com'],
            ['name' => 'Carol Davis', 'password' => Hash::make('password')]
        );
        $carol->syncRoles([RoleName::User->value]);
    }
}

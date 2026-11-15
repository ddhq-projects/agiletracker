<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('favorites', function (Blueprint $table): void {
            $table->unsignedInteger('position')->default(0);
        });

        $positions = [];

        $favorites = DB::table('favorites')
            ->orderBy('user_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'user_id']);

        foreach ($favorites as $favorite) {
            $positions[$favorite->user_id] = ($positions[$favorite->user_id] ?? 0) + 1;

            DB::table('favorites')
                ->where('id', $favorite->id)
                ->update(['position' => $positions[$favorite->user_id]]);
        }

        Schema::table('favorites', function (Blueprint $table): void {
            $table->index(['user_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('favorites', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'position']);
            $table->dropColumn('position');
        });
    }
};

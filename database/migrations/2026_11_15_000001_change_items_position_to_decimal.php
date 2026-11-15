<?php

declare(strict_types=1);

use App\Actions\RegenerateItemPositions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->decimal('position', 20, 10)->nullable()->change();
        });

        (new RegenerateItemPositions)();

        Schema::table('items', function (Blueprint $table) {
            $table->unique(['status', 'position'], 'items_status_position_unique');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropUnique('items_status_position_unique');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->integer('position')->nullable()->default(0)->change();
        });

        DB::table('items')->whereNull('position')->update(['position' => 0]);
    }
};

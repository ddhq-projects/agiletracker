<?php

declare(strict_types=1);

use App\Actions\BackfillItemStatus;
use App\Enums\ItemStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('status')->default(ItemStatus::Todo->value)->after('title');
        });

        (new BackfillItemStatus)();
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};

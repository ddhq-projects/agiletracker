<?php

use App\Enums\ItemVisibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historical items keep the column default and stay visible to everyone;
     * only items created after the release start life as restricted.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('visibility')->default(ItemVisibility::Public->value)->index();
            $table->foreignId('project_group_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('groups')
                ->restrictOnDelete();
        });

        Schema::create('item_group', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['item_id', 'group_id']);
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_group');

        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_group_id');
            $table->dropColumn('visibility');
        });
    }
};

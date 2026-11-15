<?php

declare(strict_types=1);

use App\Actions\BackfillDefaultBoard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boards', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        Schema::create('board_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 20)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('wip_limit')->nullable();
            $table->timestamps();

            $table->unique(['board_id', 'name']);
        });

        Schema::create('board_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_column_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('position', 20, 10)->nullable();
            $table->timestamps();

            $table->unique(['board_id', 'item_id']);
            $table->unique(['board_column_id', 'position']);
        });

        (new BackfillDefaultBoard)();
    }

    public function down(): void
    {
        Schema::dropIfExists('board_items');
        Schema::dropIfExists('board_columns');
        Schema::dropIfExists('boards');
    }
};

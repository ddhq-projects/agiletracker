<?php

declare(strict_types=1);

use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adapts the application's original `comments` table (item_id / user_id, Markdown
 * bodies) to the polymorphic schema `relaticle/comments` expects, in place and
 * without losing a row: the package's own create-table migration must never run
 * against an existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->nullableMorphs('commentable');
            $table->nullableMorphs('commenter');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('pinned_at')->nullable();
        });

        $sanitizer = app('comments.html_sanitizer');

        DB::table('comments')->orderBy('id')->chunkById(100, function ($comments) use ($sanitizer): void {
            foreach ($comments as $comment) {
                DB::table('comments')->where('id', $comment->id)->update([
                    'commentable_type' => (new Item)->getMorphClass(),
                    'commentable_id' => $comment->item_id,
                    'commenter_type' => (new User)->getMorphClass(),
                    'commenter_id' => $comment->user_id,
                    'body' => $sanitizer->sanitize(Str::markdown((string) $comment->body)),
                ]);
            }
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->string('commentable_type')->nullable(false)->change();
            $table->unsignedBigInteger('commentable_id')->nullable(false)->change();
            $table->string('commenter_type')->nullable(false)->change();
            $table->unsignedBigInteger('commenter_id')->nullable(false)->change();
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->index(['commentable_type', 'commentable_id', 'parent_id']);
            $table->foreign('parent_id')->references('id')->on('comments')->cascadeOnDelete();
        });

        // SQLite refuses to drop a column named in a foreign key, so the legacy
        // constraints go first and the columns follow.
        Schema::table('comments', function (Blueprint $table) {
            $table->dropForeign(['item_id']);
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn(['item_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->foreignId('item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
        });

        DB::table('comments')->orderBy('id')->chunkById(100, function ($comments): void {
            foreach ($comments as $comment) {
                DB::table('comments')->where('id', $comment->id)->update([
                    'item_id' => $comment->commentable_type === (new Item)->getMorphClass() ? $comment->commentable_id : null,
                    'user_id' => $comment->commenter_type === (new User)->getMorphClass() ? $comment->commenter_id : null,
                ]);
            }
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex(['commentable_type', 'commentable_id', 'parent_id']);
            $table->dropIndex(['commentable_type', 'commentable_id']);
            $table->dropIndex(['commenter_type', 'commenter_id']);
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn(['commentable_type', 'commentable_id', 'commenter_type', 'commenter_id', 'parent_id', 'edited_at', 'pinned_at']);
        });
    }
};

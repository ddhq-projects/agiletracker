<?php

declare(strict_types=1);

use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuilds the pre-package comments table on a throwaway connection and replays
 * the application migration that adapts it, so the in-place upgrade is checked
 * against data written by the old schema.
 */
function runCommentAdaptationOnLegacyTable(array $rows): void
{
    config(['database.connections.comments_legacy' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);

    DB::purge('comments_legacy');

    Schema::connection('comments_legacy')->create('users', function (Blueprint $table): void {
        $table->id();
    });

    Schema::connection('comments_legacy')->create('items', function (Blueprint $table): void {
        $table->id();
    });

    Schema::connection('comments_legacy')->create('comments', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('item_id')->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->text('body');
        $table->timestamps();
        $table->softDeletes();
    });

    foreach (collect($rows)->pluck('item_id')->unique() as $itemId) {
        DB::connection('comments_legacy')->table('items')->insert(['id' => $itemId]);
    }

    foreach (collect($rows)->pluck('user_id')->unique() as $userId) {
        DB::connection('comments_legacy')->table('users')->insert(['id' => $userId]);
    }

    DB::connection('comments_legacy')->table('comments')->insert($rows);

    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('comments_legacy');

    try {
        $migration = require database_path('migrations/2026_11_15_000002_adapt_comments_table_for_polymorphic_relations.php');
        $migration->up();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

test('the migration preserves every legacy comment while moving it to the polymorphic columns', function () {
    $created = now()->subDay()->startOfSecond();

    runCommentAdaptationOnLegacyTable([
        [
            'id' => 7,
            'item_id' => 3,
            'user_id' => 5,
            'body' => "**Bold** scope\n\n- first point",
            'created_at' => $created,
            'updated_at' => $created,
            'deleted_at' => null,
        ],
        [
            'id' => 8,
            'item_id' => 4,
            'user_id' => 6,
            'body' => 'Removed comment',
            'created_at' => $created,
            'updated_at' => $created,
            'deleted_at' => $created,
        ],
    ]);

    $first = DB::connection('comments_legacy')->table('comments')->where('id', 7)->first();
    $second = DB::connection('comments_legacy')->table('comments')->where('id', 8)->first();

    expect($first->commentable_type)->toBe(Item::class)
        ->and((int) $first->commentable_id)->toBe(3)
        ->and($first->commenter_type)->toBe(User::class)
        ->and((int) $first->commenter_id)->toBe(5)
        ->and($first->created_at)->toBe($created->toDateTimeString())
        ->and($first->deleted_at)->toBeNull()
        ->and($first->body)->toContain('<strong>Bold</strong>')
        ->and($first->body)->toContain('<li>first point</li>')
        ->and($second->deleted_at)->not->toBeNull();

    expect(Schema::connection('comments_legacy')->hasColumn('comments', 'item_id'))->toBeFalse()
        ->and(Schema::connection('comments_legacy')->hasColumn('comments', 'user_id'))->toBeFalse();
});

test('the migration sanitizes legacy HTML instead of keeping it', function () {
    runCommentAdaptationOnLegacyTable([[
        'id' => 9,
        'item_id' => 1,
        'user_id' => 1,
        'body' => 'Hello <script>alert(1)</script> <b>there</b>',
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => null,
    ]]);

    $comment = DB::connection('comments_legacy')->table('comments')->where('id', 9)->first();

    expect($comment->body)->not->toContain('<script>')
        ->and($comment->body)->toContain('there');
});

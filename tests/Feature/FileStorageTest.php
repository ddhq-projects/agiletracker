<?php

declare(strict_types=1);

use App\Filament\Resources\ItemResource;
use App\Models\Comment;
use App\Models\Item;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Relaticle\Comments\Livewire\Comments;

test('uploads use the configured attachments disk', function () {
    expect(config('attachments.disk'))->toBe('public')
        ->and(config('comments.attachments.enabled'))->toBeTrue()
        ->and(config('comments.attachments.disk'))->toBe(config('attachments.disk'));
});

test('the item description editor uploads to the attachments disk', function () {
    $editor = ItemResource::descriptionEditor();

    expect($editor->getFileAttachmentsDiskName())->toBe(config('attachments.disk'))
        ->and($editor->getFileAttachmentsDirectory())->toBe(config('attachments.directory').'/items')
        ->and($editor->getFileAttachmentsMaxSize())->toBe(config('attachments.max_size'))
        ->and($editor->getFileAttachmentsAcceptedFileTypes())->toBe(config('attachments.allowed_types'))
        ->and($editor->hasToolbarButton('attachFiles'))->toBeTrue();
});

test('the item editor follows the disk from configuration', function () {
    config(['attachments.disk' => 'bunny']);

    expect(ItemResource::descriptionEditor()->getFileAttachmentsDiskName())->toBe('bunny');
});

test('a comment attachment is stored on the attachments disk', function () {
    $disk = config('attachments.disk');
    Storage::fake($disk);

    actingAsUser();
    $item = Item::factory()->create();

    Livewire::test(Comments::class, ['model' => $item])
        ->set('commentData.body', '<p>With a screenshot</p>')
        ->set('attachments', [UploadedFile::fake()->image('screenshot.png')])
        ->call('addComment');

    $comment = Comment::with('attachments')->firstOrFail();
    $attachment = $comment->attachments->first();

    expect($comment->attachments)->toHaveCount(1)
        ->and($attachment->disk)->toBe($disk)
        ->and($attachment->original_name)->toBe('screenshot.png')
        ->and($attachment->mime_type)->toStartWith('image/');

    Storage::disk($disk)->assertExists($attachment->file_path);
});

test('a comment attachment of a disallowed type is rejected', function () {
    Storage::fake(config('attachments.disk'));

    actingAsUser();
    $item = Item::factory()->create();

    Livewire::test(Comments::class, ['model' => $item])
        ->set('commentData.body', '<p>Executable</p>')
        ->set('attachments', [UploadedFile::fake()->create('payload.exe', 8)])
        ->call('addComment')
        ->assertHasErrors(['attachments.0']);

    expect(Comment::count())->toBe(0);
});

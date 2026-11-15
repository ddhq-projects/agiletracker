<?php

declare(strict_types=1);

namespace App\Rest\Resources;

use App\Models\Comment;
use App\Models\Item;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Lomkit\Rest\Fields\DateTime;
use Lomkit\Rest\Fields\ID;
use Lomkit\Rest\Fields\Integer;
use Lomkit\Rest\Fields\MutationOperation;
use Lomkit\Rest\Fields\Text;
use Lomkit\Rest\Http\Routing\Endpoint;
use Lomkit\Rest\Mutations\MutationNode;
use Lomkit\Rest\Relations\BelongsTo;

/**
 * The public contract stays `item_id` / `user_id` even though the package
 * stores the rows through polymorphic columns.
 */
class CommentResource extends Resource
{
    public static string $model = Comment::class;

    /**
     * @return list<string>
     */
    public function abilities(Endpoint $endpoint): array
    {
        return match ($endpoint) {
            Endpoint::Details, Endpoint::Search => ['viewAny', 'comments:read'],
            Endpoint::Mutate, Endpoint::Destroy => ['viewAny', 'comments:write'],
            default => parent::abilities($endpoint),
        };
    }

    public function fields(): array
    {
        return [
            ID::make('id'),
            Text::make('body')
                ->label('Sanitized HTML comment body')
                ->searchable()
                ->creationRules('required')
                ->examples('<p>Confirm the scope of the first release</p>'),
            Integer::make('item_id')
                ->virtual()
                ->updatable(false)
                ->requires(['commentable_type', 'commentable_id'])
                ->resolveUsing(fn (Comment $comment): ?int => $comment->item_id)
                ->fillUsing(function (Comment $comment, mixed $value): void {
                    $comment->item_id = (int) $value;
                })
                ->filterable(['=', '!='])
                ->filterUsing(fn (Builder $query, string $operator, mixed $value): Builder => $query
                    ->where('commentable_type', (new Item)->getMorphClass())
                    ->where('commentable_id', $operator, $value))
                ->rules(Rule::exists('items', 'id')->whereNull('deleted_at'))
                ->creationRules('required'),
            Integer::make('user_id')
                ->virtual()
                ->nullable()
                ->readonly()
                ->requires(['commenter_type', 'commenter_id'])
                ->resolveUsing(fn (Comment $comment): ?int => $comment->user_id)
                ->filterable(['=', '!='])
                ->filterUsing(fn (Builder $query, string $operator, mixed $value): Builder => $query
                    ->where('commenter_type', (new User)->getMorphClass())
                    ->where('commenter_id', $operator, $value)),
            DateTime::make('created_at')->readonly(),
            DateTime::make('updated_at')->readonly(),
        ];
    }

    public function relations(): array
    {
        return [
            BelongsTo::make('item', ItemResource::class)->onlyOperations(),
            BelongsTo::make('author', UserResource::class)->onlyOperations(),
        ];
    }

    /**
     * Comments of a soft-deleted item stay in the database but leave the API,
     * and a comment is only reachable while its item is.
     */
    public function perimeterQuery(Builder $query): Builder
    {
        $user = $this->request()->user();

        return $query
            ->where('commentable_type', (new Item)->getMorphClass())
            ->whereHas('item', function (Builder $query) use ($user): void {
                $query->visibleTo($user);
            });
    }

    public function mutating(MutationNode $entry, Model $model): void
    {
        if ($entry->operation === MutationOperation::Create) {
            $model->user_id = $this->request()->user()?->getKey();

            $itemId = $model->commentable_id ?? $entry->attributes['item_id'] ?? null;
            $item = $itemId === null ? null : Item::query()->find($itemId);

            if ($item === null || ! Gate::allows('create', Comment::class) || ! Gate::allows('view', $item)) {
                throw new HttpResponseException(response()->json([
                    'message' => __('You may not comment on this item.'),
                ], 403));
            }
        }
    }
}

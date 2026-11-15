---
title: "lomkit/laravel-rest-api: dependency and usage examples"
status: done
completed: 2026-11-15
rfc: ../rfc/archive/2026-11-11-rest-api-search.md
---

# Spec: `lomkit/laravel-rest-api`

Reference for the package that backs API v1. The feature itself is described in
[rest-api.md](rest-api.md); this page records the version pin and the patterns the project uses, with
copy-ready examples.

## Dependency

```json
"lomkit/laravel-rest-api": "^3.0"
```

- Installed version: `v3.4.0` (locked in `composer.lock`).
- Package requirements: PHP 8.3+, Laravel 13.30+. The project runs PHP 8.4/8.5 on Laravel 13.
- `composer.json` also pins `dedoc/scramble:^0.13.48`, because the package conflicts with Scramble
  `<0.13.48` and `>=0.14`.
- The service provider and the `Rest` facade are auto-discovered (`extra.laravel`), no manual
  registration is needed.
- `config/rest.php` is published with `php artisan vendor:publish --tag="rest-config"`.

## What the package gives us

| Thing | In this project |
|---|---|
| Declarative resource | `App\Rest\Resources\{Item,User,Label,Comment}Resource` |
| Thin controller binding | `App\Rest\Controllers\*Controller` (`public static string $resource`) |
| Routes | `routes/api.php` via `Rest::resource()` |
| Endpoints | `details`, `search`, `mutate`, `destroy`, `operate` (actions) |
| Authorization | Laravel policies + Sanctum token abilities |
| Documentation | Scramble, generated from the same resources |

The package does not generate migrations, models or policies — the Eloquent model stays the source of
truth and the resource only declares what is exposed.

## End-to-end skeleton

Three files are enough to expose a model.

```php
// app/Rest/Controllers/LabelsController.php
declare(strict_types=1);

namespace App\Rest\Controllers;

use App\Rest\Resources\LabelResource;

class LabelsController extends Controller
{
    public static string $resource = LabelResource::class;
}
```

```php
// routes/api.php
Route::prefix('v1')
    ->middleware(['auth:sanctum', 'throttle:api'])
    ->group(function (): void {
        Rest::resource('labels', LabelsController::class)
            ->only('details', 'search', 'mutate', 'destroy');
    });
```

Resulting routes (`php artisan route:list --path=api/v1`):

```
GET    api/v1/labels              # details: fields and limits (+ any requested `with` section)
POST   api/v1/labels/search       # filters, sorts, includes, aggregates, text
POST   api/v1/labels/mutate       # batch create/update + relation operations
DELETE api/v1/labels              # destroy by explicit ids
```

`->only(...)` is the allow-list: `restore`, `force` and `metric` are not registered in API v1.

## Resource declaration

A complete example from the codebase (`UserResource`, abridged comments):

```php
<?php

declare(strict_types=1);

namespace App\Rest\Resources;

use App\Models\User;
use Illuminate\Validation\Rule;
use Lomkit\Rest\Attributes\WithoutPerimeter;
use Lomkit\Rest\Fields\DateTime;
use Lomkit\Rest\Fields\ID;
use Lomkit\Rest\Fields\Text;
use Lomkit\Rest\Http\Routing\Endpoint;
use Lomkit\Rest\Mutations\Contextual;
use Lomkit\Rest\Mutations\MutationContext;
use Lomkit\Rest\Relations\HasMany;

#[WithoutPerimeter]
class UserResource extends Resource
{
    public static string $model = User::class;

    public function abilities(Endpoint $endpoint): array
    {
        return match ($endpoint) {
            Endpoint::Details, Endpoint::Search => ['viewAny', 'users:read'],
            Endpoint::Mutate => ['viewAny', 'users:write'],
            default => parent::abilities($endpoint),
        };
    }

    public function fields(): array
    {
        return [
            ID::make('id'),
            Text::make('name')
                ->searchable()
                ->rules('max:255')
                ->creationRules('required')
                ->examples('Jane Doe'),
            Text::make('email')
                ->searchable()
                ->rules('email', 'max:255')
                ->creationRules('required', Rule::unique('users', 'email'))
                ->updateRules(
                    'filled',
                    Contextual::rule(
                        fn (MutationContext $mutation) => Rule::unique('users', 'email')->ignore($mutation->key)
                    ),
                )
                ->examples('jane@example.com'),
            Text::make('password')
                ->writeOnly()
                ->rules('min:12')
                ->creationRules('required'),
            DateTime::make('created_at')->readonly(),
            DateTime::make('updated_at')->readonly(),
        ];
    }

    public function relations(): array
    {
        return [
            HasMany::make('assignedItems', ItemResource::class)->onlyOperations(),
            HasMany::make('reportedItems', ItemResource::class)->onlyOperations(),
            HasMany::make('comments', CommentResource::class)->onlyOperations(),
        ];
    }
}
```

Rules of thumb used here:

- `#[WithoutPerimeter]` when the policy alone scopes the rows; otherwise override
  `perimeterQuery(Builder $query)` (see `CommentResource`, which hides comments of deleted items).
- `onlyOperations()` on a relation makes it an include that can never be written; pass mutation
  operations explicitly for writable relations (`ItemResource::labels` allows
  `Attach`/`Detach`/`Sync`).
- `readonly()` — refused on create and update. `writeOnly()` — validated and written, never returned,
  filtered or sorted.
- `Contextual::rule()` receives `MutationContext` (`key`, `operation`, …), so an update can ignore
  the current record in a unique rule.
- `abilities(Endpoint)` is the per-endpoint token ability list; the policy is checked separately by
  the package.

### Virtual fields

`ItemResource::type` is stored in the `meta` JSON column, so it is declared virtual with all three
callbacks:

```php
Text::make('type')
    ->virtual()
    ->nullable()
    ->requires(['meta'])
    ->resolveUsing(fn (Item $item): ?string => $item->meta['type'] ?? null)
    ->fillUsing(function (Item $item, mixed $value): void {
        $item->meta = [...($item->meta ?? []), 'type' => (string) $value];
    })
    ->filterable(['=', '!='])
    ->filterUsing(fn (Builder $query, string $operator, mixed $value): Builder
        => $query->where('meta->type', $operator, $value))
    ->rules('in:task,bug,feature')
    ->creationRules('required')
    ->updateRules('filled')
    ->examples('task');
```

`requires()` lists the columns the callback reads; they are loaded only when the field is returned.
`fillUsing()` keeps any other `meta` keys intact.

### Lifecycle hooks

```php
// set the reporter on create, validate parent_id on update
public function mutating(MutationNode $entry, Model $model): void
{
    if ($entry->operation === MutationOperation::Create) {
        $model->reporter_id = $this->request()->user()?->getKey();
    }
}

// 409 instead of a destructive delete
public function destroying(Model $model): void
{
    if ($model->children()->exists()) {
        throw new HttpResponseException(response()->json([
            'message' => __('Items with active children cannot be deleted.'),
        ], 409));
    }
}
```

### Custom action

```php
use Lomkit\Rest\Actions\Action;
use Lomkit\Rest\Actions\ActionFields;
use Lomkit\Rest\Actions\ActionTarget;
use Lomkit\Rest\Actions\ActionTransaction;
use Lomkit\Rest\Fields\Integer;

/** @extends Action<Item> */
class AssignItems extends Action
{
    public ?string $name = 'Assign items';

    public ?string $uriKey = 'assign';

    public ActionTarget $target = ActionTarget::Resources;

    public ?int $maxResources = 100;

    public ActionTransaction $transaction = ActionTransaction::Whole;

    public function abilities(): array
    {
        return ['viewAny'];
    }

    public function authorizeEach(): ?string
    {
        return 'update';
    }

    public function fields(): array
    {
        return [
            Integer::make('assignee_id')
                ->nullable()
                ->rules('exists:users,id')
                ->examples(3),
        ];
    }

    /** @param Collection<int, Item> $models */
    public function handle(ActionFields $fields, Collection $models): void
    {
        foreach ($models as $item) {
            $item->assignee_id = $fields->assignee_id;
            $item->save();
        }
    }
}
```

It is returned from `ItemResource::actions()`. `ActionTransaction::Whole` rolls back every row when
one fails, `maxResources` caps the batch, `authorizeEach()` runs the model policy ability per row.

## Client payloads

All examples are authenticated with `Authorization: Bearer <token>`.

### Details

```http
GET /api/v1/items?with=relations.operations,actions,authorizations
```

`fields`, `limits` and `meta` are always returned; `relations`, `actions`, `authorizations` and the
other sections only when listed in `with` (an unknown section is a `422`).

```json
{
  "data": {
    "fields": [
      { "name": "id", "label": "Id", "type": "id", "nullable": false },
      { "name": "title", "label": "Title", "type": "text", "nullable": false }
    ],
    "limits": [15, 25, 50, 100],
    "meta": {},
    "relations": [
      {
        "name": "labels",
        "relation": "labels",
        "resource": "labels",
        "operations": ["attach", "detach", "sync"]
      }
    ],
    "actions": [
      { "name": "Assign items", "uriKey": "assign", "target": "resources", "destructive": false, "confirm": false, "confirmation": false }
    ],
    "authorizations": {
      "viewAny": { "allowed": true, "message": null },
      "create": { "allowed": true, "message": null }
    }
  }
}
```

Add `fields.capabilities` and `fields.rules` to `with` to learn what a field accepts:

```http
GET /api/v1/items?with=fields.capabilities,fields.rules
```

```json
{
  "name": "title",
  "label": "Title",
  "type": "text",
  "nullable": false,
  "select": true,
  "sort": true,
  "create": true,
  "update": true,
  "filter": { "operators": ["=", "!=", "like", "not like", "ilike", "not ilike", "in", "not in"] },
  "rules": {
    "all": ["string", "max:255"],
    "create": ["required"],
    "update": []
  }
}
```

### Search

```http
POST /api/v1/items/search
Content-Type: application/json
```

```json
{
  "search": {
    "text": { "value": "billing", "mode": "words" },
    "filters": [
      { "field": "is_epic", "operator": "=", "value": true },
      { "field": "status", "operator": "=", "value": "in_progress" },
      { "field": "labels.name", "operator": "=", "value": "billing" }
    ],
    "sorts": [{ "field": "due_date", "direction": "asc" }],
    "includes": [
      { "relation": "labels", "selects": [{ "field": "name" }] },
      { "relation": "comments", "selects": [{ "field": "body" }] }
    ],
    "aggregates": [{ "relation": "children", "type": "count" }],
    "limit": 25,
    "page": 2
  }
}
```

Response shape:

```json
{
  "data": [
    {
      "id": 12,
      "title": "Billing revamp",
      "status": "in_progress",
      "type": "task",
      "is_epic": true,
      "labels": [{ "name": "billing" }],
      "comments": [{ "body": "<p>Confirm the scope</p>" }],
      "children_count": 2
    }
  ],
  "current_page": 2,
  "from": 26,
  "last_page": 2,
  "per_page": 25,
  "to": 41,
  "total": 41,
  "meta": {}
}
```

Notes:

- Text modes: `words` (every word), `phrase`; the search uses `Contains` (`LIKE`).
- Undeclared field, relation or operator → `422` on that path (`search.filters.0.field`,
  `search.includes.0.relation`, `search.filters.0.operator`).
- `search.trashed` is rejected with `422` by `Resource::searching()` — trashed rows are not part of
  API v1. Restore/force delete are not registered either.
- The default page is 15 rows ordered by `id DESC` (`defaultOrderBy()`).

### Mutate

One request, many operations, one transaction:

```json
{
  "mutate": [
    {
      "operation": "create",
      "attributes": {
        "title": "Billing revamp",
        "description": "Project to revamp the billing system",
        "type": "task",
        "is_epic": true,
        "due_date": "2026-11-20"
      },
      "relations": {
        "labels": [
          { "operation": "sync", "key": [1, 2] }
        ]
      }
    },
    {
      "operation": "update",
      "key": 12,
      "attributes": { "status": "done" }
    }
  ],
  "response": {
    "selects": [
      { "field": "id" },
      { "field": "title" },
      { "field": "type" },
      { "field": "is_epic" }
    ]
  }
}
```

```json
{
  "operations": [
    { "operation": "create", "key": 42 },
    { "operation": "update", "key": 12 }
  ],
  "records": [
    { "id": 42, "title": "Billing revamp", "type": "task", "is_epic": true },
    { "id": 12, "title": "Billing revamp", "type": "task", "is_epic": false }
  ]
}
```

Relation operations the project uses: `sync`, `attach`, `detach` on `items.labels`. `parent` and
`children` are include-only, so the tree changes only through `parent_id`, and a cycle is a `422`
raised in `ItemResource::mutating()`. An `operations` entry echoes the operation and the key of its
row (plus `type` and nested relation entries when the request sent them).

### Destroy

```http
DELETE /api/v1/items
```

```json
{ "resources": [42, 43] }
```

The response returns the deleted rows (`{"data": [{"id": 42}, {"id": 43}]}`). Items with active
children answer `409` without changes; every other delete is soft. Trashed ids in the payload are
refused with `422` on `resources.N`.

### Action

```http
POST /api/v1/items/actions/assign
```

```json
{
  "resources": [42, 43, 44],
  "fields": [{ "name": "assignee_id", "value": 7 }]
}
```

```json
{ "data": { "impacted": 3, "result": null } }
```

The action accepts an explicit `resources` list, a `search` block, or neither (whole perimeter); this
one declares `ActionTarget::Resources`, so a `search` payload is a `422`. Sending `assignee_id: null`
clears the assignee.

## Project conventions and gotchas

- **`includeLimit` must be part of `limits()`.** The package throws `InvalidResourceException` when
  the default `includeLimit` (10) is not in the list; the shared base uses 15, matching
  `defaultLimit`.
- **One source of the contract.** No API Resources, Form Requests or Scribe for these models. Adding
  a `Route::apiResource()` next to a Lomkit resource would create a second, divergent authorization
  model.
- **Two authorization layers.** The token ability (`abilities(Endpoint)` + a Gate in
  `AppServiceProvider`) runs first, then the model policy (row-level). Both are required.
- **`password` is write-only.** It never appears in a row, a filter or a sort; the OpenAPI export
  documents it as `Write-only.` because Scramble 0.13 does not support `writeOnly`.
- **The API contract survives internal refactors.** `CommentResource` keeps `item_id` / `user_id` as
  virtual fields over `commentable_*` / `commenter_*`, and `ItemResource::type` stays virtual over
  `meta.type`, so the database layout can change without a client-visible break.
- **The OpenAPI snapshot is a test.** `tests/api.json` is exported by Scramble and compared in
  `ApiDocumentationTest`; regenerate and commit it when the declarations change.

## Useful commands

```bash
php artisan rest:resource ItemResource --model=Item          # scaffold a resource
php artisan rest:resource ItemResource --model=Item --generate # one field per column
php artisan rest:controller ItemsController --resource=ItemResource
php artisan rest:action AssignItems
php artisan rest:quick-start                                 # model picker for a fresh API
php artisan rest:check                                       # routes, policies and action checks
php artisan vendor:publish --tag="rest-config"               # publish config/rest.php
```

`php artisan rest:check` is the project's contract check: 17 authenticated routes, 4 policies, and
the action checks every row. It runs in the same `php artisan test` pipeline as the snapshot test.

## Tests

| File | Covers |
|---|---|
| `tests/Feature/Api/ApiFoundationTest.php` | routes, auth, abilities, limits, pagination, `422` shapes |
| `tests/Feature/Api/ItemApiTest.php` | search, includes, aggregates, mutate, 409 destroy, assign action |
| `tests/Feature/Api/ItemStatusApiTest.php` | native `status` field and workflow validation |
| `tests/Feature/Api/UserApiTest.php` | write-only password, `admin`, unique rule on update |
| `tests/Feature/Api/LabelApiTest.php` | label CRUD and item membership |
| `tests/Feature/Api/CommentApiTest.php` | author policy, perimeter over a live item, polymorphic mapping |
| `tests/Feature/Api/ApiDocumentationTest.php` | OpenAPI snapshot |

## References

- Package documentation: <https://laravel-rest-api.lomkit.com>
- Repository: <https://github.com/Lomkit/laravel-rest-api>
- Vendor skill shipped with the package:
  `vendor/lomkit/laravel-rest-api/resources/boost/skills/laravel-rest-api/SKILL.md`
- Project feature spec: [rest-api.md](rest-api.md)
- RFC: [../rfc/archive/2026-11-11-rest-api-search.md](../rfc/archive/2026-11-11-rest-api-search.md)

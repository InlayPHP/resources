# Inlay Resources

[![Packagist](https://img.shields.io/packagist/v/inlayphp/resources?style=flat-square&label=packagist)](https://packagist.org/packages/inlayphp/resources)
[![PHP](https://img.shields.io/packagist/dependency-v/inlayphp/resources/php?style=flat-square)](https://packagist.org/packages/inlayphp/resources)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../LICENSE)

**Fluent Laravel resource orchestration for Inlay forms, tables, infolists, and panels**

Inlay Resources is the PHP-first CRUD layer for Laravel and Inertia. One resource owns its Eloquent query, table, form, validation class, authorization, pages, persistence hooks, and navigation metadata; React or Vue only renders the serialized form/table contracts.

## Install

```bash
composer require inlayphp/resources
```

Resources depends on the PHP form, table, validation, authorization, and panel contracts. Install the matching React or Vue renderers for the schemas used by your pages. For a complete administration foundation, `composer require inlayphp/inlay` installs the clean panel package set; permission management, media, Spatie adapters, and imports remain opt-in.

Install the relation-manager renderer used by your Inertia frontend:

```bash
pnpm add @inlayphp/resources @inlayphp/resources-react
# or
pnpm add @inlayphp/resources @inlayphp/resources-vue
```

## Generate a resource

```bash
php artisan make:inlay-resource User
```

This creates `UserResource`, list/create/edit page classes, and `UserRules`. Existing files are never replaced unless `--force` is supplied.

Options decide the resource's shape:

```bash
php artisan make:inlay-resource User --view           # adds a read-only page and its infolist
php artisan make:inlay-resource User --soft-deletes   # enables the trashed query, filter, and action presets
php artisan make:inlay-resource User --simple         # one list page, records managed in modals
```

`--simple` and `--view` are refused together: a modal-only resource has no page to view a record on.

`--generate` reads the model's table and writes the form, table, and validation rules from it:

```bash
php artisan make:inlay-resource User --generate
```

Booleans become Toggles, date columns become `DatePicker`s, and datetime columns become
`DateTimePicker`s with the right value shape. Numeric columns become numeric TextInputs,
and long text becomes a Textarea that is left out of the table. The first string column is
made searchable, nullability decides
`required()` and the generated rule, and framework-owned columns — keys, timestamps,
`deleted_at`, `remember_token` — are skipped. The Validation class is derived from the same
reading. It is a starting point: the output is ordinary PHP to edit, read once at
generation time rather than re-derived at runtime.

Generate an owner-scoped relationship manager separately:

```bash
php artisan make:inlay-relation-manager UserResource posts title
```

This creates `PostsRelationManager` and a centralized `UserPostRules` validation class. The generated authorization stub is intentionally fail-closed.

## Define the resource

```php
use App\Models\User;
use App\Validation\UserRules;
use Illuminate\Database\Eloquent\Model;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;
use Inlay\Resources\Resource;
use Inlay\Resources\ResourceOperation;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Table;

final class UserResource extends Resource
{
    protected static string $model = User::class;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required(),
            TextInput::make('email')->email()->required(),
        ]);
    }

    public static function validation(): string
    {
        return UserRules::class;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    protected static function canAccess(ResourceOperation $operation, ?Model $record, mixed $user): bool
    {
        return $user?->can($operation->policyAbility(), $record ?? User::class) ?? false;
    }
}
```

Page classes choose the Inertia component while the resource stays frontend-neutral:

```php
final class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;
    protected static string $component = 'users/index';
}
```

### Navigation groups and badges

Resources can describe their own place in a panel's navigation. The panel
registrar uses this metadata when it registers the resource, so a team can add
or reorder a module without maintaining a second navigation list:

```php
final class OrderResource extends Resource
{
    protected static ?string $navigationIcon = 'shopping-bag';
    protected static ?string $navigationGroup = 'Shop';
    protected static int $navigationSort = 30;
    protected static string|int|null $navigationBadge = 12;
}
```

Declare the matching `NavigationGroup` labels and order once in the panel
provider. The browser receives the group, item order, icon, and badge as part
of the normal `inlay.panels.v1` contract; it does not infer or duplicate
resource navigation.

## Global search

Declare what a resource contributes to search:

```php
final class UserResource extends Resource
{
    public static function globallySearchableAttributes(): array
    {
        return ['name', 'email'];
    }
}

GlobalSearch::across([UserResource::class, OrderResource::class])
    ->search($request->string('q'), $request->user(), prefix: 'admin');
```

Each resource is searched through its own scoped query and its own authorization, so a
match can never surface a record the visitor could not open directly, and a tenant-scoped
resource stays inside its tenant without extra configuration. A resource that declares no
attributes stays out of search entirely. Terms shorter than two characters are refused
rather than scanning the table, and every result links to the page the visitor would have
opened anyway. Results are named by the resource's record title, below.

## Record titles and breadcrumbs

Name a record once, and every place that mentions it agrees:

```php
final class UserResource extends Resource
{
    public static function recordTitleAttribute(): ?string
    {
        return 'name';
    }
}
```

Without a declared attribute, `recordTitle()` tries `name`, `title`, then `label`, and
falls back to the resource label with the key (`User #17`) so a record is never named
blank. `globalSearchTitle()` reads the same title, and `recordTitle()` may be overridden
outright when a title is composed from several columns.

Every resource page publishes a `breadcrumbs` prop — the resource's list page, the record,
then the current page — which both renderers draw:

```tsx
import { ResourceBreadcrumbs } from '@inlayphp/resources-react'

<ResourceBreadcrumbs breadcrumbs={breadcrumbs} />
```

```vue
<script setup lang="ts">
import { ResourceBreadcrumbs } from '@inlayphp/resources-vue'
</script>

<template>
  <ResourceBreadcrumbs :breadcrumbs="breadcrumbs" />
</template>
```

For a complete custom page shell, both official renderers also expose
`ResourcePage`. It composes the heading, breadcrumbs, record sub-navigation, and
named-view tabs around host content while keeping forms, tables, infolists, and
relation managers as ordinary child components. This is the recommended escape
hatch for pages that need custom layout without losing the resource contract.

A step links only where a page exists to open: the list step is dropped from the trail's
links when the resource has no index page, the record step links only when there is an
edit or view page, and the current page is always plain text carrying `aria-current="page"`.
Nested resources keep their parent segment, so `/admin/users/1/notes` is a URL that exists.
Override `breadcrumbLabel()` on a page to rename its own step.

## Record sub-navigation

Name the pages one record moves between:

```php
final class UserResource extends Resource
{
    public static function recordSubNavigation(): array
    {
        return ['view', 'edit', 'notes'];
    }
}

final class ViewUser extends ViewRecord
{
    public static function subNavigationLabel(): string
    {
        return 'Overview';
    }
}
```

Names are keys of `getPages()`, and every one of them must accept a record — an unknown
name, a duplicate, or the list page is refused when the page renders rather than drawn
wrong. Each entry is checked against the resource's own authorization for that page's
operation, so a visitor is only offered pages they could open; the check runs through
`authorize()`, so navigation and enforcement cannot drift apart. A page may override
`subNavigation()` to narrow the list, or return `[]` to hide it on itself.

Pages publish the result as a `subNavigation` prop, which both renderers draw:

```tsx
import { ResourceSubNavigation } from '@inlayphp/resources-react'

<ResourceSubNavigation items={subNavigation} />
```

```vue
<script setup lang="ts">
import { ResourceSubNavigation } from '@inlayphp/resources-vue'
</script>

<template>
  <ResourceSubNavigation :items="subNavigation" />
</template>
```

The active page carries `aria-current="page"` and is not a link back to itself, and the
component renders nothing when PHP offered no pages.

`Resource::allows($operation, $record, $user)` is available on its own for the same
question elsewhere — it reports what `authorize()` would decide without throwing.

## List page tabs

Declare named views of a list page's records:

```php
final class ListOrders extends ListRecords
{
    protected function tabs(): array
    {
        return [
            PageTab::make('all')->label('All orders')->default(),
            PageTab::make('unpaid')
                ->badge(fn (): int => Order::query()->where('status', 'unpaid')->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'unpaid')),
        ];
    }
}
```

The page narrows its own query before the table sees it, so the browser only sends a tab
name and the server decides what that name means. A requested tab the page never declared
falls back to the default rather than running an unknown query. The props carry
`tabs.active` and `tabs.items`; `@inlayphp/resources-react` and `@inlayphp/resources-vue`
export a `ResourceTabs` control with a proper tablist and arrow-key navigation. A page
without tabs publishes nothing extra.

## A page per relation

A record with many relations does not have to show them all at once:

```php
final class ManageOrderItems extends ManageRelatedRecords
{
    protected static string $resource = OrderResource::class;

    protected static string $component = 'orders/items';

    protected static string $relationManager = ItemsRelationManager::class;
}

// In the resource:
'items' => ManageOrderItems::route('/{record}/items'),
```

The page renders the same relation manager the record page would have, with the same
authorization; it only narrows which managers are built. A page naming a relation the
resource does not declare is refused rather than rendered.

## Page header actions

```php
final class ListOrders extends ListRecords
{
    protected function headerActions(): array
    {
        return [
            Action::make('export')
                ->authorizeUsing(fn (Request $request): bool => $request->user() !== null)
                ->action(fn () => ExportOrders::dispatch()),
            Action::make('docs')->url('https://example.com/docs'),
        ];
    }
}
```

An action without its own URL is pointed at the resource's action endpoint under a `page`
scope, resolved from the page rather than the table since it belongs to neither a row nor a
selection. It still runs through the resource's authorized query, so a record page's action
cannot reach a record the visitor could not open. An action that declares a URL is left
exactly as written, duplicate names are refused, and a page without header actions
publishes nothing extra. Lifecycle actions are fail-closed: add `authorizeUsing()` (or a
policy-backed callback) before they can execute. The `_inlay_page` query marker used by
the generated URL is internal transport metadata and is removed before action data is
validated.

## Page widgets

Declare widgets a resource shows above every page, and add page-specific ones:

```php
final class OrderResource extends Resource
{
    public static function widgets(): array
    {
        return [RevenueOverview::class];
    }
}

final class ListOrders extends ListRecords
{
    protected function footerWidgets(): array
    {
        return [OrdersPerDayChart::make('orders-per-day')];
    }
}
```

Widgets resolve through the same `WidgetResolver` a dashboard uses, so instances, provider
objects, and provider class names all work, and a page widget behaves exactly like a
dashboard widget. Pages publish them as `headerWidgets` and `footerWidgets`, which the
existing React and Vue widget renderers display. A resource with no widgets publishes
neither prop.

## Register all routes

```php
use App\Inlay\Resources\UserResource;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Inlay\Resources\Facades\InlayResources;

InlayResources::routes([UserResource::class], [
    'middleware' => ['auth'],
    'mutationMiddleware' => [HandlePrecognitiveRequests::class],
    // 'prefix' => 'admin',
    // 'name' => 'admin.inlay.',
]);
```

For a `users` resource this registers named GET index/create/edit pages plus POST `/users`, PATCH `/users/{record}`, and DELETE `/users/{record}`. Page-specific middleware declared on `PageRoute` is also applied.

Inside an Inlay panel, prefer registering the same resource classes through `Panel::resources()`. The panel supplies its prefix, route-name namespace, middleware, navigation, authorization ability registry, and Inertia panel defaults automatically:

```php
public function panel(Panel $panel): Panel
{
    return $panel
        ->path('/admin')
        ->middleware(['web'])
        ->authMiddleware(['auth'])
        ->resources([UserResource::class]);
}
```

## One validation class everywhere

```php
final class UserRules extends Validation
{
    public function rules(ValidationContext $context): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                Rule::unique('users')->ignore($context->record()),
            ],
        ];
    }
}
```

The same validation class can be consumed by Resources, Forms, Form Requests, Imports, APIs, and Actions. `ValidationContext` supplies the operation (`create` or `edit`), source, current record, authenticated user, prepared input, and options. The mutation controller authorizes before validation, and only validated keys reach persistence.

## Remote Select options

Remote searchable Select fields work unchanged in resource create and edit forms:

```php
Select::make('author_id')
    ->getSearchResultsUsing(fn (string $search): array => User::query()
        ->where('name', 'like', "%{$search}%")
        ->limit(50)
        ->pluck('name', 'id')
        ->all())
    ->getOptionLabelUsing(
        fn (int|string $value): ?string => User::find($value)?->name,
    )
    ->preload()
    ->searchDebounce(500);
```

The resource page answers option searches on its existing authorized GET route. On create and update, the same configured Form automatically verifies submitted values with the selected-label resolver before persistence. This prevents a client from submitting a value that the field provider does not recognize. Multiple selects use `getOptionLabelsUsing()`.

Resources also bind their Eloquent model automatically, so a BelongsTo field only needs its relationship name and label column:

```php
Select::make('author_id')
    ->relationship('author', 'name')
    ->searchable()
    ->preload();
```

Use the optional `modifyQueryUsing` callback to apply tenant, visibility, or soft-delete constraints. The same scoped query is used for search, initial labels, and server-side valid-option checks.

`BelongsToMany` is also automatic:

```php
Select::make('roles')
    ->relationship(
        'roles',
        'name',
        fn ($query) => $query->where('assignable', true),
    )
    ->searchable()
    ->pivotData(fn (int|string $roleId, User $user): array => [
        'assigned_by' => auth()->id(),
    ]);
```

Inlay makes this a multiple Select, hydrates current related keys on edit, validates every submitted key against the scoped relationship query, excludes `roles` from `User::fill()`, and calls `sync()` after the owner exists. The owner write and relationship sync share the Resource transaction. Omitting `roles` preserves existing assignments on a partial update; sending `roles: []` detaches visible assignments. Existing relationships excluded by `modifyQueryUsing` are neither exposed nor detached. Static arrays passed to `pivotData()` apply to every pivot row, while callbacks may calculate data for each related key.

`createOptionForm()` and `editOptionForm()` use the same resource routes and authorization boundary. Their callbacks receive only independently validated option-form data; they do not submit or persist the parent Resource. See the Forms README for the complete fluent API.

## Relation managers

Relation managers place a related Table and Form on edit/view resource pages without writing React or Vue business logic:

```php
use App\Validation\UserPostRules;
use Illuminate\Database\Eloquent\Model;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;
use Inlay\Resources\RelationManager;
use Inlay\Resources\RelationOperation;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Table;

final class PostsRelationManager extends RelationManager
{
    protected static string $relationship = 'posts';

    protected static ?string $recordTitleAttribute = 'title';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->sortable(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')->required(),
        ]);
    }

    public function validation(): string
    {
        return UserPostRules::class;
    }

    protected function canAccess(
        RelationOperation $operation,
        ?Model $record,
        mixed $user,
    ): bool {
        return $user?->can(
            match ($operation) {
                RelationOperation::ViewAny => 'viewAny',
                RelationOperation::Create => 'create',
                RelationOperation::Attach => 'attach',
                RelationOperation::Associate => 'associate',
                RelationOperation::Edit => 'update',
                RelationOperation::Delete => 'delete',
                RelationOperation::DeleteAny => 'deleteAny',
                RelationOperation::Restore => 'restore',
                RelationOperation::RestoreAny => 'restoreAny',
                RelationOperation::ForceDelete => 'forceDelete',
                RelationOperation::ForceDeleteAny => 'forceDeleteAny',
                RelationOperation::Detach => 'detach',
                RelationOperation::Dissociate => 'dissociate',
            },
            $record ?? Post::class,
        ) ?? false;
    }
}
```

Register it on the owner Resource:

```php
public static function getRelations(): array
{
    return [PostsRelationManager::class];
}
```

Group related managers into accessible tabs with the same PHP-only registration
style:

```php
use Inlay\Resources\RelationGroup;

public static function getRelations(): array
{
    return [
        RelationGroup::make('Customer activity', [
            OrdersRelationManager::class,
            NotesRelationManager::class,
            TicketsRelationManager::class,
        ])
            ->description('Orders, internal notes, and support history.')
            ->icon('heroicon-o-link')
            ->defaultRelation(OrdersRelationManager::class),

        AddressesRelationManager::class,
    ];
}
```

`RelationGroup` accepts an explicit `id()`, optional description and icon,
`contained(false)`, and either a manager class or relationship name for
`defaultRelation()`. Manager names and group IDs must be unique. The Resource
keeps one flat, owner-scoped route registry internally, so grouping never
changes endpoint URLs, authorization, validation, or query isolation.

React and Vue detect the repeated group metadata in the normal `relations`
prop. They render semantic `tablist`, `tab`, and `tabpanel` roles, support
Arrow Left/Right and Home/End navigation, move focus with the selected tab,
and render only the active manager. No frontend grouping configuration is
required.

## Nested resources

When a child record only makes sense inside its parent, nest the whole Resource
instead of embedding a Relation Manager. Declare the parent registration and
Inlay serves every page and mutation under `/parent-slug/{parent}/child-slug`:

```php
use Inlay\Resources\ParentResourceRegistration;

final class UserNoteResource extends Resource
{
    protected static string $model = UserNote::class;

    protected static ?string $slug = 'notes';

    public static function getParentResourceRegistration(): ParentResourceRegistration
    {
        return UserResource::asParent()
            ->relationship('notes')
            ->inverseRelationship('user');
    }
}
```

`relationship()` names the HasOne, HasMany, MorphOne, MorphMany, BelongsToMany,
or MorphToMany relationship on the parent model, and defaults to the plural
camel-cased child model name. `inverseRelationship()` names the child's
BelongsTo or MorphTo relationship. `parameter()` renames the `{parent}` route
parameter. Registration is validated once, before any route exists: the parent
must be a Resource, cannot be the child itself, cannot already be nested
(nesting is one level deep, matching the documented contract), and its relationship must
return the child's model.

The registration drives everything else:

- Routes become `/parent-slug/{parent}/child-slug/...`, named
  `inlay.<parent-slug>.<child-slug>.<page>`.
- List queries, record lookups, table actions, and editable columns run through
  the parent relationship, so another parent's records are never reachable.
- The parent record is resolved through the parent Resource's scoped query and
  authorized with its `view` operation on every request.
- Creation writes the foreign key (or pivot row) through the relationship, so
  the owner never depends on mass assignment or form input. Override
  `handleRecordCreationForParent()` to customize it.
- Form actions, redirects, and relation endpoints keep the parent segment.
- Pages receive a `parentRecord` prop, and `resource.parent` carries the
  registration metadata.
- Nested resources are excluded from panel navigation; link to them from the
  parent's pages.

Generate URLs with the parent record:

```php
UserNoteResource::url('index', parent: $user);          // /users/1/notes
UserNoteResource::url('edit', $note, $user);            // /users/1/notes/7/edit
UserNoteResource::baseUrl('admin', $user);              // /admin/users/1/notes
```

Edit and view page props include `relations`, each using the stable `inlay.resources.relation-manager.v1` contract. A relation contains the same Table and Form resources used elsewhere. View pages make managers read-only automatically.

Render every registered manager in React:

```tsx
import { router } from '@inertiajs/react'
import { RelationManagers } from '@inlayphp/resources-react'
import type { RelationManagerResource } from '@inlayphp/resources-react'

export default function EditUser({
  relations,
}: {
  relations: RelationManagerResource[]
}) {
  return <RelationManagers
    resources={relations}
    onChanged={() => router.reload({ only: ['relations'] })}
  />
}
```

Or in Vue:

```vue
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { RelationManagers } from '@inlayphp/resources-vue'
import type { RelationManagerResource } from '@inlayphp/resources-vue'

defineProps<{ relations: RelationManagerResource[] }>()
</script>

<template>
  <RelationManagers
    :resources="relations"
    @changed="router.reload({ only: ['relations'] })"
  />
</template>
```

Both renderers compose the standard Inlay Table and Form components, add accessible create/edit dialogs, map Laravel validation errors back to fields, resolve route templates safely, and emit a `changed` event after successful mutations. Pass `onQueryChange`/`@query-change` when the parent page should reload server-side search, filters, sorting, or pagination.

Relation managers inherit the Panel's semantic theme contract: dialog scrims,
surface rings, breadcrumbs, forms, tables, and action buttons all consume the
same `--inlay-*` variables. If a relation manager is mounted standalone, set
the same variables on its host (or pass a local theme to its Form/Table) rather
than adding palette-specific Tailwind classes.

Every related record is resolved again through the owner's Eloquent relationship. Create, edit, and delete support `HasOne`, `HasMany`, `MorphOne`, `MorphMany`, `BelongsToMany`, and `MorphToMany` where Eloquent permits the operation. Attach/detach endpoints are limited to `BelongsToMany` and `MorphToMany`. Mutation forms require a centralized validation class and writes run in transactions. Parent Resource authorization and relation-specific authorization both run before mutation.

Relation managers support the same soft-delete preset as top-level Resources.
Use Laravel's `SoftDeletes` trait on the related model and enable it on the
manager:

```php
final class Post extends Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
}

final class PostsRelationManager extends RelationManager
{
    protected static string $relationship = 'posts';

    protected static bool $softDeletes = true;
}
```

This adds the `TrashedFilter`, conditional Delete/Restore/Force delete row
actions, query-wide bulk equivalents, hosted Action endpoints, and
`beforeRestore()`, `afterRestore()`, `beforeForceDelete()`, and
`afterForceDelete()` hooks. Trashed records remain hidden by default but can be
resolved for authorized lifecycle actions through the owner's relationship;
soft deletion never weakens owner or tenant scoping. Read-only managers retain
the filter but omit mutation actions. Custom definitions using the preset names
win.

For many-to-many managers, the React and Vue renderers automatically add an
Attach button, a remote searchable Select form, and a confirmed Detach row
action. The chooser excludes records already connected to the owner. Scope the
candidate query for tenancy, visibility, guard, or archival rules:

```php
use Illuminate\Database\Eloquent\Builder;

protected function modifyAttachQuery(Builder $query): Builder
{
    return $query
        ->where('team_id', auth()->user()->team_id)
        ->where('archived', false);
}
```

That scope is enforced both while searching and while resolving a submitted
identifier, so a forged out-of-scope identifier cannot be attached.
`canAccess(RelationOperation::Attach, null, $user)` controls whether the
chooser is exposed; authorization runs again with the resolved candidate
before `syncWithoutDetaching()`. Detach always resolves through the owner's
existing relationship.

Attach forms may collect pivot attributes using the same Form components and
centralized validation used elsewhere:

```php
use App\Validation\RoleAssignmentRules;
use Inlay\Forms\Fields\TextInput;

public function attachForm(Form $form): Form
{
    return $form->schema([
        $this->getAttachRecordSelect(),
        TextInput::make('assignment_note')
            ->required()
            ->maxLength(255),
    ]);
}

public function attachValidation(): string
{
    return RoleAssignmentRules::class;
}
```

The relationship must explicitly expose writable columns:

```php
return $this->belongsToMany(Role::class)
    ->withPivot('assignment_note');
```

`RoleAssignmentRules` is application code and can return rules for `record`
and `assignment_note` when the operation is `relation.attach`. Inlay merges
field rules with the centralized rules, validates the remote record option
against `modifyAttachQuery()`, removes undeclared input, and passes only
`withPivot()` columns to Eloquent. A field not declared by `withPivot()` causes
configuration to fail instead of silently writing arbitrary pivot state.

Use the normal relation-manager `form()` to edit related-model and pivot
attributes together, matching the documented contract:

```php
public function form(Form $form): Form
{
    return $form->schema([
        TextInput::make('name')->required(), // related model
        TextInput::make('assignment_note')->required(), // pivot
    ]);
}

public function validation(): string
{
    return RoleAssignmentRules::class;
}
```

On create, Inlay inserts the related record and attaches it with the validated
pivot state. On edit, it updates related attributes and calls
`updateExistingPivot()` for declared pivot attributes in the same transaction.
The edit dialog is available independently of create authorization, so
pivot-only managers can allow `RelationOperation::Edit` while denying
`RelationOperation::Create`. Pivot columns appear as root Table row attributes
(`assignment_note`, not `pivot.assignment_note`) because that is how Eloquent
relationship queries expose them.

For `HasMany` and `MorphMany` managers, Inlay exposes the equivalent
Associate button, remote searchable Select, and confirmed Dissociate row
action. Association may claim an unowned record or move a record from another
owner, matching Eloquent and the documented contract semantics. Constrain that behavior
explicitly:

```php
/**
 * @param  Builder<Post>  $query
 * @return Builder<Post>
 */
protected function modifyAssociateQuery(Builder $query): Builder
{
    return $query
        ->where('team_id', auth()->user()->team_id)
        ->whereIn('status', ['draft', 'published']);
}
```

The same scope is applied to chooser searches and submitted identifiers.
`canAccess(RelationOperation::Associate, $candidate, $user)` runs before
Eloquent reassigns the foreign key. Dissociation resolves only through the
owner's current relationship, authorizes `Dissociate`, and transactionally
nulls the foreign key (and morph type for `MorphMany`). The relationship
columns must therefore be nullable when dissociation is enabled.

## Customize persistence safely

Default create/update uses Eloquent `fill()` and `save()` inside a database transaction. Relationship-managed field state is separated before `fill()` and saved after the owner model. Override narrow hooks instead of replacing controllers:

```php
protected static function mutateDataBeforeCreate(array $data): array
{
    return [...$data, 'password' => Str::random(32)];
}

protected static function beforeUpdate(Model $record, array $data): void {}
protected static function afterUpdate(Model $record, array $data): void {}

public static function successMessage(ResourceOperation $operation, Model $record): string
{
    return match ($operation) {
        ResourceOperation::Create => 'User invited.',
        default => parent::successMessage($operation, $record),
    };
}
```

Advanced resources may override `handleRecordCreation()` or `handleRecordUpdate()` for services or aggregate writes. Returned records must remain instances of the declared model; exceptions roll the transaction back.

## Soft deletes

Use Laravel's `SoftDeletes` trait on the model and enable the resource preset:

```php
use Illuminate\Database\Eloquent\SoftDeletes;

final class User extends Model
{
    use SoftDeletes;
}

final class UserResource extends Resource
{
    protected static string $model = User::class;

    protected static bool $softDeletes = true;
}
```

That single flag adds:

- the `TrashedFilter` with without/with/only-trashed choices;
- conditional Delete, Restore, and Force delete row actions;
- query-wide capable Delete, Restore, and Force delete bulk actions;
- resolution of trashed route/action records without exposing them in the
  default list;
- `deleteAny`, `restore`, `restoreAny`, `forceDelete`, and `forceDeleteAny`
  policy and permission abilities;
- transactional `beforeRestore()`, `afterRestore()`, `beforeForceDelete()`,
  and `afterForceDelete()` hooks.

When Laravel policies are enabled, define the matching methods:

```php
public function restore(User $user, Post $post): bool {}
public function restoreAny(User $user): bool {}
public function forceDelete(User $user, Post $post): bool {}
public function forceDeleteAny(User $user): bool {}
public function deleteAny(User $user): bool {}
```

Custom actions or a custom filter with the same preset name win; Inlay only
adds missing `trashed`, `delete`, `restore`, and `force-delete` definitions.

## Tenancy

A resource can belong to a tenant. Declare the relationship on the model that owns it:

```php
final class ProjectResource extends Resource
{
    protected static string $model = Project::class;

    protected static ?string $tenantRelationship = 'team';
}
```

Set the current tenant once per request, usually from middleware or a panel:

```php
Tenancy::resolve()->set($request->user()->currentTeam);
```

Every query the resource runs — list, action, and record resolution — is constrained to
that tenant, so another tenant's record cannot be listed, opened, or acted on by key. A
created record joins the tenant by having the key written server-side, so a forged
`team_id` in the payload is overwritten rather than honoured. `BelongsTo` and
`BelongsToMany` ownership are both supported.

A tenant-scoped resource with no current tenant refuses to read at all rather than
returning every tenant's records.

Register tenant-aware routes to resolve the tenant from the URL instead:

```php
InlayResources::routes([ProjectResource::class], [
    'prefix' => 'admin',
    'tenant' => ['model' => Team::class, 'parameter' => 'team', 'routeKey' => 'slug'],
]);
```

Every URL gains the tenant segment — `/{team}/admin/projects` — and every route, mutation
routes included, resolves the tenant before the controller runs. Membership is decided by
the model, not the URL: implement `TenantAccess` on the tenant to refuse a visitor who is
not a member.

```php
final class Team extends Model implements TenantAccess
{
    public function canAccessTenant(mixed $user): bool
    {
        return $user !== null && $this->members()->whereKey($user->getKey())->exists();
    }
}
```

An unknown tenant is a 404, and the resolved tenant is forgotten when the request ends.
Relation managers need no extra configuration: they hang off a record the resource
resolved, so another tenant's owner is already unreachable.

## Security guarantees

- Authorization is fail-closed until `canAccess()` is implemented.
- Owner lookup always uses the resource’s scoped `getEloquentQuery()`; relation lookup always uses the owner's relationship query.
- Authorization runs before mutation validation and persistence.
- Persistence receives validated input only and uses Eloquent fillable/guarded rules.
- All writes and lifecycle hooks run in a transaction.
- Page paths reject traversal, malformed encoding, and record-placeholder mismatches.
- Form action URLs use Inlay’s centralized safe-URL policy.
- Builders must be fresh; cached/replacement builders are rejected to prevent request-state leakage.

Parent-scoped nested resource URLs, fluent Relation Groups, and
accessible relation tabs are available.
Resource and Relation Manager soft-delete query, filter, row/bulk action,
lifecycle-hook, React, Vue, testing, and playground support is available. The core Relation Manager contract, scoped CRUD,
validated pivot-aware create/attach/edit forms, secure remote attach/detach and
associate/dissociate UI, generation, automatic React/Vue composition, and
read-only view behavior are available now.

## Testing Resources

The package autoloads a global `inlay()` test helper with familiar fluent APIs:

```php
it('searches and filters users', function () {
    $users = User::factory()->count(5)->create();

    inlay(UserResource::class, user: $this->admin)
        ->assertRelationGroupExists('customer-activity')
        ->assertRelationManagerExists('orders', 'customer-activity')
        ->assertTableColumnExists('email')
        ->assertTableFilterExists('status')
        ->assertCanSeeTableRecords($users)
        ->searchTable($users->first()->email)
        ->assertCanSeeTableRecords($users->take(1))
        ->assertCanNotSeeTableRecords($users->skip(1))
        ->resetTable()
        ->filterTable('status', 'active')
        ->sortTable('name', 'desc');
});
```

Tenant-scoped resources are driven the same way:

```php
inlay(ProjectResource::class, user: $this->member)
    ->assertTenantScoped()
    ->forTenant($acme)
    ->assertCanSeeTableRecords($acmeProjects)
    ->assertCanNotSeeTableRecords($globexProjects)
    // Another tenant's record is unreachable, not merely hidden.
    ->assertRecordOutsideTenant($globexProject)
    ->forTenant($globex)
    ->assertCanSeeTableRecords($globexProjects);
```

`forTenant()` applies for everything that follows, `withoutTenant()` clears it, and
`tenant()` returns the current one. The DSL runs the same scoped query a request does, so
parent-resource and tenant scoping are both exercised.

Forms run through the Resource's real centralized validation and persistence lifecycle:

```php
inlay(UserResource::class, user: $this->admin)
    ->assertFormFieldExists('email')
    ->fillForm([
        'name' => 'Ada Lovelace',
        'email' => null,
    ])
    ->call('create')
    ->assertHasFormErrors([
        'email' => 'required',
    ]);

$test = inlay(UserResource::class, user: $this->admin)
    ->fillForm([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
    ])
    ->call('create')
    ->assertHasNoFormErrors();

expect($test->record())->toBeInstanceOf(User::class);
```

Edit forms and editable table columns are equally direct:

```php
inlay(UserResource::class, user: $this->admin)
    ->forEdit($user)
    ->fillForm(['name' => 'Updated name'])
    ->call('save')
    ->assertHasNoFormErrors()
    ->editTableColumn($user, 'active', false)
    ->assertTableColumnStateSet('active', false, $user);
```

Row, header, and bulk actions use the same `ActionRunner` as production routes. Authorization, scoped record lookup, mounted form defaults, validation, hooks, transactions, and lifecycle results are exercised rather than mocked:

```php
inlay(UserResource::class, user: $this->admin)
    ->assertTableActionExists('activate')
    ->mountTableAction('activate', $user)
    ->fillTableActionForm(['reason' => 'Identity reviewed'])
    ->callMountedTableAction()
    ->assertHasNoTableActionErrors()
    ->assertTableActionSucceeded();

inlay(UserResource::class, user: $this->admin)
    ->assertTableHeaderActionExists('export')
    ->callTableHeaderAction('export')
    ->assertTableActionSucceeded(
        fn (mixed $result): bool => $result['queued'] === true,
    );

inlay(UserResource::class, user: $this->admin)
    ->assertTableBulkActionExists('suspend')
    ->selectTableRecords([$firstUser, $secondUser])
    ->callTableBulkAction('suspend', ['reason' => 'Review'])
    ->assertHasNoTableActionErrors()
    ->assertTableActionSucceeded();
```

Use `selectAllTableRecords()` for the table's authorized query-wide mode and pass excluded records when required. Invalid forms and selection counts are available through `assertHasTableActionErrors()`. Interrupted lifecycles use `assertTableActionHalted()` and `assertTableActionCancelled()`. Unexpected exceptions and authorization failures are deliberately not swallowed.

Every table interaction rebuilds the configured Table through `getEloquentQuery()`. Every mutation runs Resource authorization, centralized Laravel validation, transactions, relationship persistence, and lifecycle hooks. The helper therefore does not bypass the boundaries that production requests rely on.

The root Pest suite contains contract and lifecycle coverage; the Laravel playground verifies the complete panel route stack. HTTP feature tests remain important for middleware, routing, redirects, Precognition, uploads, and Inertia delivery.

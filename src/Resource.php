<?php

declare(strict_types=1);

namespace Inlay\Resources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inlay\Actions\Action;
use Inlay\Actions\BulkAction;
use Inlay\Authorization\AbilityDefinition;
use Inlay\Authorization\AuthorizationManager;
use Inlay\Forms\Form;
use Inlay\Infolists\Infolist;
use Inlay\NavigationItem;
use Inlay\Resources\Exceptions\ResourceAccessDenied;
use Inlay\Resources\Routing\PageRoute;
use Inlay\Resources\Routing\RouteKey;
use Inlay\Support\Condition;
use Inlay\Support\SafeUrl;
use Inlay\Tables\Table;
use Inlay\Tables\Filters\TrashedFilter;
use Inlay\Validation\Validation;
use Inlay\Validation\ValidationRunner;
use InvalidArgumentException;

abstract class Resource
{
    /** @var class-string<Model> */
    protected static string $model;

    protected static ?string $slug = null;

    protected static ?string $label = null;

    protected static ?string $pluralLabel = null;

    protected static ?string $navigationIcon = null;

    protected static bool $usesLaravelPolicy = false;

    /**
     * Opt into soft-delete resource behavior. The model must use Laravel's
     * SoftDeletes trait.
     */
    protected static bool $softDeletes = false;

    /**
     * Relationship on the model that owns its tenant. When set, every query is
     * scoped to the current tenant and every created record joins it.
     */
    protected static ?string $tenantRelationship = null;

    /** @return class-string<Model> */
    final public static function model(): string
    {
        $model = static::resolveModelClass();
        if ($model === null || ! is_subclass_of($model, Model::class)) {
            throw new \LogicException('Resources must declare a valid Eloquent model in static $model.');
        }

        return $model;
    }

    /** @return class-string<Model>|null */
    protected static function resolveModelClass(): ?string
    {
        return static::$model ?? null;
    }

    public static function slug(): string
    {
        $slug = static::$slug ?? Str::kebab(Str::pluralStudly(class_basename(static::model())));
        $slug = trim($slug, '/');

        if ($slug === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            throw new \LogicException("Invalid resource slug [{$slug}].");
        }

        return $slug;
    }

    public static function label(): string
    {
        return static::$label ?? Str::headline(class_basename(static::model()));
    }

    public static function pluralLabel(): string
    {
        return static::$pluralLabel ?? Str::plural(static::label());
    }

    /**
     * Nest this resource beneath a parent resource. Return
     * `ParentResource::asParent()->relationship('children')` to serve every page
     * under `/parent-slug/{parent}/child-slug`.
     */
    public static function getParentResourceRegistration(): ?ParentResourceRegistration
    {
        return null;
    }

    /** Start a parent registration for a nested resource. */
    final public static function asParent(): ParentResourceRegistration
    {
        return new ParentResourceRegistration(static::class);
    }

    /** The validated parent registration, or null for a root resource. */
    final public static function parentResource(): ?ParentResourceRegistration
    {
        $registration = static::getParentResourceRegistration();

        return $registration === null ? null : (clone $registration)->bind(static::class);
    }

    /** Registry and route name key, nested as `parent-slug.child-slug`. */
    final public static function routeKey(): string
    {
        $registration = static::parentResource();

        return $registration === null
            ? static::slug()
            : $registration->resource()::slug().'.'.static::slug();
    }

    /** Route URI relative to the registration prefix, with the parent placeholder. */
    final public static function routeUri(): string
    {
        $registration = static::parentResource();

        return $registration === null
            ? static::slug()
            : $registration->resource()::slug().'/{'.$registration->parameterName().'}/'.static::slug();
    }

    /** Absolute base URL for this resource, resolving the nested parent segment. */
    final public static function baseUrl(string $prefix = '', Model|string|int|null $parent = null): string
    {
        $registration = static::requireParentMatch($parent);
        $segments = trim($prefix, '/');

        if ($registration !== null) {
            $segments = trim(
                $segments.'/'.$registration->resource()::slug().'/'.RouteKey::encode($parent, 'Parent record'),
                '/',
            );
        }

        return '/'.trim($segments.'/'.static::slug(), '/');
    }

    public static function table(Table $table): Table
    {
        return $table;
    }

    /**
     * Build the complete resource table, including resource lifecycle presets.
     *
     * Applications continue to define columns and custom actions in table().
     * Inlay adds the standard soft-delete filter and actions when enabled.
     */
    final public static function configuredTable(): Table
    {
        $table = Table::make(static::slug());
        if (static::table($table) !== $table) {
            throw new \LogicException('Resource table configuration must return the supplied fresh table instance.');
        }

        if (! static::usesSoftDeletes()) {
            return $table;
        }

        if ($table->getFilter('trashed') === null) {
            $table->filters([...$table->getFilters(), TrashedFilter::make()]);
        }

        $actions = $table->getActions();
        if ($table->getAction('delete') === null) {
            $actions[] = static::deleteTableAction();
        }
        if ($table->getAction('restore') === null) {
            $actions[] = static::restoreTableAction();
        }
        if ($table->getAction('force-delete') === null) {
            $actions[] = static::forceDeleteTableAction();
        }
        $table->actions($actions);

        $bulk = $table->getBulkActionDefinitions();
        foreach ([
            'delete' => static::deleteBulkTableAction(),
            'restore' => static::restoreBulkTableAction(),
            'force-delete' => static::forceDeleteBulkTableAction(),
        ] as $name => $action) {
            if ($table->getBulkAction($name) === null) {
                $bulk[] = $action;
            }
        }
        $table->bulkActions($bulk);

        return $table;
    }

    public static function form(Form $form): Form
    {
        return $form;
    }

    /** @return array<string, mixed> */
    public static function formData(?Model $record): array
    {
        return $record?->toArray() ?? [];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist;
    }

    /** @return list<class-string<RelationManager>|RelationGroup> */
    public static function getRelations(): array
    {
        return [];
    }

    /** @return array<string, class-string<RelationManager>> */
    final public static function relations(): array
    {
        $relations = [];
        foreach (static::relationLayout() as $entry) {
            $managers = $entry instanceof RelationGroup ? $entry->relations() : [$entry];
            foreach ($managers as $manager) {
                $name = $manager::name();
                if (isset($relations[$name])) {
                    throw new InvalidArgumentException("Resource relation manager names must be unique; [{$name}] is duplicated.");
                }
                $relations[$name] = $manager;
            }
        }

        return $relations;
    }

    /** @return list<class-string<RelationManager>|RelationGroup> */
    final public static function relationLayout(): array
    {
        $layout = [];
        $groupIds = [];
        foreach (static::getRelations() as $entry) {
            if ($entry instanceof RelationGroup) {
                $metadata = $entry->jsonSerialize();
                if (isset($groupIds[$metadata['id']])) {
                    throw new InvalidArgumentException("Resource relation group IDs must be unique; [{$metadata['id']}] is duplicated.");
                }
                $groupIds[$metadata['id']] = true;
                $layout[] = $entry;

                continue;
            }
            if (! is_string($entry) || ! is_subclass_of($entry, RelationManager::class)) {
                throw new InvalidArgumentException(
                    'Resource relations must extend '.RelationManager::class.' or be a '.RelationGroup::class.'.',
                );
            }
            $layout[] = $entry;
        }

        return $layout;
    }

    final public static function relationGroup(string $manager): ?RelationGroup
    {
        foreach (static::relationLayout() as $entry) {
            if ($entry instanceof RelationGroup && $entry->contains($manager)) {
                return $entry;
            }
        }

        return null;
    }

    final public static function relation(string $name, Model $owner, mixed $user = null, bool $readOnly = false): RelationManager
    {
        $manager = static::relations()[$name]
            ?? throw new InvalidArgumentException("Unknown resource relation manager [{$name}].");

        return $manager::make($owner, $user, $readOnly);
    }

    /** @return array<string, PageRoute> */
    abstract public static function getPages(): array;

    /** @return array<string, PageRoute> */
    final public static function pages(): array
    {
        $pages = [];
        $paths = [];

        foreach (static::getPages() as $name => $route) {
            if (! is_string($name) || ! $route instanceof PageRoute) {
                throw new InvalidArgumentException('Resource pages must be a named array of PageRoute instances.');
            }

            $bound = clone $route;
            $bound->bind(static::class, $name);

            if (isset($paths[$bound->path()])) {
                throw new InvalidArgumentException("Resource page paths must be unique; [{$bound->path()}] is duplicated.");
            }

            $paths[$bound->path()] = true;
            $pages[$name] = $bound;
        }

        if ($pages === []) {
            throw new \LogicException('A resource must declare at least one page.');
        }

        return $pages;
    }

    final public static function page(string $name): PageRoute
    {
        return static::pages()[$name] ?? throw new InvalidArgumentException("Unknown resource page [{$name}].");
    }

    final public static function url(string $page, Model|string|int|null $record = null, Model|string|int|null $parent = null): string
    {
        return static::page($page)->url($record, $parent);
    }

    final public static function metadata(string $prefix = '', Model|string|int|null $parent = null): ResourceMetadata
    {
        $registration = static::parentResource();
        $pages = [];
        foreach (static::pages() as $name => $route) {
            $route->prefix($prefix)->parent($parent);
            $pages[$name] = $route->jsonSerialize();
        }

        return new ResourceMetadata(
            static::class,
            static::model(),
            static::slug(),
            static::label(),
            static::pluralLabel(),
            static::$navigationIcon,
            $pages,
            $registration?->jsonSerialize(),
        );
    }

    final public static function navigationItem(): NavigationItem
    {
        if (static::parentResource() !== null) {
            throw new \LogicException('Nested resource ['.static::class.'] has no standalone navigation item.');
        }

        $pages = static::pages();
        $route = $pages['index'] ?? throw new \LogicException('Resource navigation requires an [index] page.');

        if ($route->pageClass()::operation() !== ResourceOperation::ListRecords) {
            throw new \LogicException('A resource [index] page must use the list operation.');
        }

        $item = NavigationItem::make(static::slug())->label(static::pluralLabel())->url($route->url());

        if (static::$navigationIcon !== null) {
            $item->icon(static::$navigationIcon);
        }

        return $item;
    }

    public static function getEloquentQuery(): Builder
    {
        $model = static::model();
        $query = static::modifyEloquentQuery((new $model)->newQuery());

        if ($query->getModel()::class !== $model) {
            throw new \LogicException('A resource query must retain the declared resource model.');
        }

        return $query;
    }

    /**
     * The display query, scoped through the parent relationship when the
     * resource is nested.
     */
    final public static function scopedEloquentQuery(?Model $parent = null): Builder
    {
        return static::applyTenantScope(static::applyParentScope(static::getEloquentQuery(), $parent, false));
    }

    /**
     * Query used to resolve lifecycle-action records. Unlike the list query,
     * it can resolve soft-deleted records without showing them by default.
     */
    final public static function getActionEloquentQuery(?Model $parent = null): Builder
    {
        $query = static::applyTenantScope(static::applyParentScope(static::getEloquentQuery(), $parent, true));

        return static::usesSoftDeletes() ? $query->withTrashed() : $query;
    }

    /**
     * Constrain a resource query to the records reachable through the parent
     * relationship, so a nested URL can never expose another parent's records.
     */
    private static function applyParentScope(Builder $query, ?Model $parent, bool $withTrashed): Builder
    {
        $registration = static::requireParentMatch($parent);
        if ($registration === null) {
            return $query;
        }

        $relation = $registration->relationshipFor($parent);
        $related = $relation->getRelated();
        $relationQuery = $relation->getQuery();
        if ($withTrashed && static::usesSoftDeletes()) {
            $relationQuery->withTrashed();
        }

        return $query->whereIn(
            $related->getQualifiedKeyName(),
            $relationQuery->select($related->getQualifiedKeyName()),
        );
    }

    /**
     * Constrain a resource query to the current tenant.
     *
     * A resource that declares a tenant relationship is never readable without
     * a tenant: the alternative is leaking every tenant's records the moment a
     * request forgets to resolve one.
     */
    private static function applyTenantScope(Builder $query): Builder
    {
        $relationship = static::tenantRelationship();
        if ($relationship === null) {
            return $query;
        }

        $tenant = Tenancy::resolve()->current();
        if ($tenant === null) {
            throw new \LogicException('Resource ['.static::class.'] is tenant-scoped, but no tenant is current for this request.');
        }

        $relation = static::tenantRelation();

        if ($relation instanceof BelongsTo) {
            return $query->where($relation->getQualifiedForeignKeyName(), $tenant->getKey());
        }

        return $query->whereHas(
            $relationship,
            static fn (Builder $related): Builder => $related->whereKey($tenant->getKey()),
        );
    }

    /** @return Relation<Model, Model, mixed> */
    private static function tenantRelation(): Relation
    {
        $relationship = static::tenantRelationship();
        $model = static::model();
        $instance = new $model;

        if ($relationship === null || ! method_exists($instance, $relationship)) {
            throw new \LogicException("Tenant relationship [{$relationship}] does not exist on [{$model}].");
        }

        $relation = $instance->{$relationship}();
        if (! $relation instanceof BelongsTo && ! $relation instanceof BelongsToMany) {
            throw new \LogicException('A tenant relationship must be an Eloquent BelongsTo or BelongsToMany relationship.');
        }

        return $relation;
    }

    final public static function tenantRelationship(): ?string
    {
        return static::$tenantRelationship;
    }

    /** Make a new record belong to the current tenant before it is stored. */
    private static function associateTenant(Model $record): Model
    {
        $relationship = static::tenantRelationship();
        if ($relationship === null) {
            return $record;
        }

        $tenant = Tenancy::resolve()->requireCurrent();
        $relation = static::tenantRelation();

        if ($relation instanceof BelongsTo) {
            $record->setAttribute($relation->getForeignKeyName(), $tenant->getKey());
            $record->save();

            return $record;
        }

        $record->save();
        $record->{$relationship}()->attach($tenant->getKey());

        return $record;
    }

    /** Assert that the supplied parent record matches this resource's nesting. */
    private static function requireParentMatch(Model|string|int|null $parent): ?ParentResourceRegistration
    {
        $registration = static::parentResource();

        if ($registration === null && $parent !== null) {
            throw new InvalidArgumentException('Resource ['.static::class.'] is not nested beneath a parent resource.');
        }

        if ($registration !== null && $parent === null) {
            throw new InvalidArgumentException('Resource ['.static::class.'] requires a parent record.');
        }

        return $registration;
    }

    /**
     * Widgets every page of this resource shows above its content.
     *
     * @return list<mixed>
     */
    /**
     * Columns global search looks in. An empty list keeps the resource out of
     * global search entirely.
     *
     * @return list<string>
     */
    public static function globallySearchableAttributes(): array
    {
        return [];
    }

    /** The title a global search result shows. */
    /**
     * The attribute that names a record, used by titles and breadcrumbs.
     */
    public static function recordTitleAttribute(): ?string
    {
        return null;
    }

    /**
     * How a single record is named wherever one is shown.
     *
     * Global search, breadcrumbs, and page titles all read the same title, so
     * a record cannot be called one thing in one place and another elsewhere.
     */
    public static function recordTitle(Model $record): string
    {
        $declared = static::recordTitleAttribute();
        foreach ($declared === null ? ['name', 'title', 'label'] : [$declared] as $attribute) {
            $value = $record->getAttribute($attribute);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return static::label().' #'.$record->getKey();
    }

    public static function globalSearchTitle(Model $record): string
    {
        return static::recordTitle($record);
    }

    /**
     * Whether an operation is permitted, asked as a question rather than a rule.
     *
     * `authorize()` stays the enforcement point: this only reports what it would
     * decide, so navigation can leave out a link the visitor could not follow
     * without ever becoming a second, divergent set of rules.
     */
    final public static function allows(ResourceOperation $operation, ?Model $record = null, mixed $user = null): bool
    {
        try {
            static::authorize($operation, $record, $user);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Global search reuses the resource's own view authorization, so a result
     * can never appear for a record the visitor could not open.
     */
    public static function canAccessGlobalSearch(mixed $user): bool
    {
        return static::allows(ResourceOperation::ListRecords, null, $user);
    }

    /**
     * The pages a single record moves between, named as in `pages()`.
     *
     * An empty list means a record's pages are reached only through the table,
     * which is the default. Every named page must accept a record.
     *
     * @return list<string>
     */
    public static function recordSubNavigation(): array
    {
        return [];
    }

    /**
     * The URL that opens one record, or null when nothing can open it.
     *
     * A nested resource has no address without its parent, so asking for one
     * without a parent record yields null rather than an exception: callers
     * such as global search and breadcrumbs then show plain text instead.
     *
     * @internal
     */
    final public static function globalSearchUrl(Model $record, string $prefix = '', Model|string|int|null $parent = null): ?string
    {
        if (static::parentResource() !== null && $parent === null) {
            return null;
        }

        foreach (['edit', 'view'] as $page) {
            if (array_key_exists($page, static::pages())) {
                return static::baseUrl($prefix, $parent).'/'.rawurlencode((string) $record->getRouteKey())
                    .($page === 'edit' ? '/edit' : '');
            }
        }

        return null;
    }

    public static function widgets(): array
    {
        return [];
    }

    final public static function usesSoftDeletes(): bool
    {
        if (! static::$softDeletes) {
            return false;
        }

        $model = static::model();
        if (! in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            throw new \LogicException(
                'Resource ['.static::class.'] enables soft deletes, but model ['.$model.'] does not use '.SoftDeletes::class.'.',
            );
        }

        return true;
    }

    protected static function modifyEloquentQuery(Builder $query): Builder
    {
        return $query;
    }

    final public static function resolveRecord(string|int $key, ?Model $parent = null): Model
    {
        $query = static::getActionEloquentQuery($parent);
        $routeKey = $query->getModel()->getRouteKeyName();

        return $query->where($routeKey, $key)->firstOrFail();
    }

    final public static function authorize(ResourceOperation $operation, ?Model $record = null, mixed $user = null): void
    {
        if ($operation->requiresRecord() !== ($record !== null)) {
            throw new InvalidArgumentException('Authorization record does not match the resource operation.');
        }

        if ($record !== null) {
            $model = static::model();
            if (! $record instanceof $model) {
                throw new InvalidArgumentException('Authorization record does not belong to this resource.');
            }
        }

        if (static::$usesLaravelPolicy) {
            app(AuthorizationManager::class)->authorize(
                $user,
                $operation->policyAbility(),
                $record ?? static::model(),
            );

            return;
        }

        if (! static::canAccess($operation, $record, $user)) {
            throw new ResourceAccessDenied("Access denied for [{$operation->value}] on resource [".static::class.'].');
        }
    }

    final public static function usesLaravelPolicy(): bool
    {
        return static::$usesLaravelPolicy;
    }

    public static function permissionPrefix(): string
    {
        return static::slug();
    }

    /** @return list<AbilityDefinition> */
    final public static function abilityDefinitions(): array
    {
        $prefix = static::permissionPrefix();
        $group = static::pluralLabel();

        $abilities = [
            AbilityDefinition::make($prefix.'.viewAny')->label('View any')->group($group),
            AbilityDefinition::make($prefix.'.view')->label('View')->group($group),
            AbilityDefinition::make($prefix.'.create')->label('Create')->group($group),
            AbilityDefinition::make($prefix.'.update')->label('Update')->group($group),
            AbilityDefinition::make($prefix.'.delete')->label('Delete')->group($group)->dangerous(),
        ];

        if (static::usesSoftDeletes()) {
            array_push(
                $abilities,
                AbilityDefinition::make($prefix.'.deleteAny')->label('Delete any')->group($group)->dangerous(),
                AbilityDefinition::make($prefix.'.restore')->label('Restore')->group($group),
                AbilityDefinition::make($prefix.'.restoreAny')->label('Restore any')->group($group),
                AbilityDefinition::make($prefix.'.forceDelete')->label('Force delete')->group($group)->dangerous(),
                AbilityDefinition::make($prefix.'.forceDeleteAny')->label('Force delete any')->group($group)->dangerous(),
            );
        }

        return $abilities;
    }

    protected static function canAccess(ResourceOperation $operation, ?Model $record, mixed $user): bool
    {
        return false;
    }

    public static function createActionUrl(): ?string
    {
        return static::parentResource() === null ? '/'.static::slug() : null;
    }

    public static function updateActionUrl(Model $record): ?string
    {
        return static::parentResource() === null
            ? '/'.static::slug().'/'.RouteKey::encode($record, 'Record')
            : null;
    }

    final public static function configuredForm(ResourceOperation $operation, ?Model $record = null): Form
    {
        if (! in_array($operation, [ResourceOperation::Create, ResourceOperation::Edit], true)) {
            throw new InvalidArgumentException('Only create and edit operations have resource forms.');
        }
        if ($operation->requiresRecord() !== ($record !== null)) {
            throw new InvalidArgumentException('The resource form record does not match its operation.');
        }
        if ($record !== null) {
            $model = static::model();
            if (! $record instanceof $model) {
                throw new InvalidArgumentException('The resource form record does not belong to this resource.');
            }
        }

        $form = Form::make(static::slug().'.'.$operation->value);
        $form->model($record ?? static::model());
        if (static::form($form) !== $form) {
            throw new \LogicException('Resource form configuration must return the supplied fresh form instance.');
        }
        $validation = static::validation();
        if ($validation !== null) {
            $form->validation($validation, static::validationOperation($operation));
        }
        if ($record !== null) {
            $form->data(static::formData($record));
        }
        $action = $record === null ? static::createActionUrl() : static::updateActionUrl($record);
        if ($action !== null) {
            $form->action($action)->method($record === null ? 'post' : 'patch');
        }

        return $form;
    }

    /** @return class-string<Validation>|null */
    public static function validation(): ?string
    {
        return null;
    }

    public static function validationOperation(ResourceOperation $operation): string
    {
        return $operation->value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    final public static function validateMutation(
        ValidationRunner $validator,
        array $data,
        ResourceOperation $operation,
        ?Model $record = null,
        mixed $user = null,
        ?Request $request = null,
    ): array {
        if (! in_array($operation, [ResourceOperation::Create, ResourceOperation::Edit], true)) {
            throw new InvalidArgumentException('Only create and edit operations accept mutation validation.');
        }

        $validation = static::validation();
        if ($validation === null) {
            throw new \LogicException('Resource mutations require a centralized validation class.');
        }

        return static::configuredForm($operation, $record)->validate(
            $validator,
            $data,
            $record,
            $user,
            options: ['resource' => static::class, 'request' => $request],
        );
    }

    /** @param array<string, mixed> $data */
    final public static function createRecord(array $data, ?Model $parent = null): Model
    {
        $modelClass = static::model();
        $registration = static::requireParentMatch($parent);
        $connection = (new $modelClass)->getConnection();

        return $connection->transaction(function () use ($data, $modelClass, $registration, $parent): Model {
            static::beforeCreate($data);
            $data = static::mutateDataBeforeCreate($data);
            $form = static::configuredForm(ResourceOperation::Create);
            $persistence = $form->splitRelationshipData($data);
            $relation = $registration?->relationshipFor($parent);
            $record = $relation === null
                ? static::handleRecordCreation($persistence['attributes'])
                : static::handleRecordCreationForParent($relation, $persistence['attributes']);

            if (! $record instanceof $modelClass) {
                throw new \LogicException('Resource creation must return its declared model.');
            }

            $form->saveRelationships($record, $persistence['relationships']);

            static::afterCreate($record, $data);

            return $record;
        });
    }


    /** @param array<string, mixed> $data */
    final public static function updateRecord(Model $record, array $data): Model
    {
        $modelClass = static::model();
        if (! $record instanceof $modelClass) {
            throw new InvalidArgumentException('Update record does not belong to this resource.');
        }

        return $record->getConnection()->transaction(function () use ($record, $data, $modelClass): Model {
            static::beforeUpdate($record, $data);
            $data = static::mutateDataBeforeUpdate($data, $record);
            $form = static::configuredForm(ResourceOperation::Edit, $record);
            $persistence = $form->splitRelationshipData($data);
            $updated = static::handleRecordUpdate($record, $persistence['attributes']);

            if (! $updated instanceof $modelClass) {
                throw new \LogicException('Resource update must return its declared model.');
            }

            $form->saveRelationships($updated, $persistence['relationships']);

            static::afterUpdate($updated, $data);

            return $updated;
        });
    }

    final public static function deleteRecord(Model $record): void
    {
        $modelClass = static::model();
        if (! $record instanceof $modelClass) {
            throw new InvalidArgumentException('Delete record does not belong to this resource.');
        }

        $record->getConnection()->transaction(function () use ($record): void {
            static::beforeDelete($record);
            $record->delete();
            static::afterDelete($record);
        });
    }

    final public static function restoreRecord(Model $record): void
    {
        static::assertSoftDeletedRecord($record, 'restore');

        $record->getConnection()->transaction(function () use ($record): void {
            static::beforeRestore($record);
            $record->restore();
            static::afterRestore($record);
        });
    }

    final public static function forceDeleteRecord(Model $record): void
    {
        static::assertSoftDeletedRecord($record, 'force delete');

        $record->getConnection()->transaction(function () use ($record): void {
            static::beforeForceDelete($record);
            $record->forceDelete();
            static::afterForceDelete($record);
        });
    }

    /** @param array<string, mixed> $data */
    protected static function mutateDataBeforeCreate(array $data): array
    {
        return $data;
    }

    /** @param array<string, mixed> $data */
    protected static function mutateDataBeforeUpdate(array $data, Model $record): array
    {
        return $data;
    }

    /** @param array<string, mixed> $data */
    protected static function handleRecordCreation(array $data): Model
    {
        $modelClass = static::model();
        $record = new $modelClass;
        $record->fill($data);

        if (static::tenantRelationship() !== null) {
            return static::associateTenant($record);
        }

        $record->save();

        return $record;
    }

    /**
     * Create a nested record through the parent relationship, so the foreign
     * key or pivot row is written without relying on mass assignment.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function handleRecordCreationForParent(Relation $relation, array $data): Model
    {
        $modelClass = static::model();
        $record = new $modelClass;
        $record->fill($data);

        if ($relation instanceof HasOneOrMany) {
            $relation->save($record);

            return $record;
        }

        if (! $relation instanceof BelongsToMany) {
            throw new \LogicException('Nested resource creation requires a HasOne, HasMany, MorphOne, MorphMany, BelongsToMany, or MorphToMany parent relationship.');
        }

        $record->save();
        $relation->attach($record->getKey());

        return $record;
    }

    /** @param array<string, mixed> $data */
    protected static function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->fill($data);
        $record->save();

        return $record;
    }

    /** @param array<string, mixed> $data */
    protected static function beforeCreate(array $data): void {}

    /** @param array<string, mixed> $data */
    protected static function afterCreate(Model $record, array $data): void {}

    /** @param array<string, mixed> $data */
    protected static function beforeUpdate(Model $record, array $data): void {}

    /** @param array<string, mixed> $data */
    protected static function afterUpdate(Model $record, array $data): void {}

    protected static function beforeDelete(Model $record): void {}

    protected static function afterDelete(Model $record): void {}

    protected static function beforeRestore(Model $record): void {}

    protected static function afterRestore(Model $record): void {}

    protected static function beforeForceDelete(Model $record): void {}

    protected static function afterForceDelete(Model $record): void {}

    /**
     * Where a save lands, or null to return to the list page.
     *
     * A create commonly wants the record it just made rather than the list it
     * came from. The URL is validated as safe, because this is the one place a
     * resource decides where a visitor's browser goes next.
     */
    public static function redirectUrlAfter(ResourceOperation $operation, Model $record, string $prefix = '', ?Model $parent = null): ?string
    {
        return null;
    }

    /** @internal Resolve and validate the declared redirect. */
    final public static function resolvedRedirectUrlAfter(ResourceOperation $operation, Model $record, string $prefix = '', ?Model $parent = null): ?string
    {
        $url = static::redirectUrlAfter($operation, $record, $prefix, $parent);

        return $url === null ? null : SafeUrl::from($url)->value();
    }

    public static function successMessage(ResourceOperation $operation, Model $record): string
    {
        return match ($operation) {
            ResourceOperation::Create => static::label().' created.',
            ResourceOperation::Edit => static::label().' updated.',
            ResourceOperation::Delete => static::label().' deleted.',
            ResourceOperation::Restore => static::label().' restored.',
            ResourceOperation::ForceDelete => static::label().' permanently deleted.',
            default => static::label().' saved.',
        };
    }

    private static function assertSoftDeletedRecord(Model $record, string $operation): void
    {
        $modelClass = static::model();
        if (! $record instanceof $modelClass) {
            throw new InvalidArgumentException(ucfirst($operation).' record does not belong to this resource.');
        }
        if (! static::usesSoftDeletes()) {
            throw new \LogicException('Resource ['.static::class."] must enable soft deletes before it can {$operation} records.");
        }
        if (! method_exists($record, 'trashed') || ! $record->trashed()) {
            throw new \LogicException('Only soft-deleted records can be '.$operation.'d.');
        }
    }

    private static function deleteTableAction(): Action
    {
        return Action::make('delete')
            ->label('Delete')
            ->color('danger')
            ->requiresConfirmation()
            ->visibleWhen(Condition::blank('deleted_at'))
            ->authorizeUsing(static function (?Model $record, mixed $user): bool {
                if ($record === null) {
                    return false;
                }
                static::authorize(ResourceOperation::Delete, $record, $user);

                return true;
            })
            ->action(static fn (Model $record) => static::deleteRecord($record))
            ->successNotificationTitle(static::label().' deleted.');
    }

    private static function restoreTableAction(): Action
    {
        return Action::make('restore')
            ->label('Restore')
            ->color('success')
            ->requiresConfirmation()
            ->visibleWhen(Condition::filled('deleted_at'))
            ->authorizeUsing(static function (?Model $record, mixed $user): bool {
                if ($record === null) {
                    return false;
                }
                static::authorize(ResourceOperation::Restore, $record, $user);

                return true;
            })
            ->action(static fn (Model $record) => static::restoreRecord($record))
            ->successNotificationTitle(static::label().' restored.');
    }

    private static function forceDeleteTableAction(): Action
    {
        return Action::make('force-delete')
            ->label('Force delete')
            ->color('danger')
            ->requiresConfirmation()
            ->visibleWhen(Condition::filled('deleted_at'))
            ->authorizeUsing(static function (?Model $record, mixed $user): bool {
                if ($record === null) {
                    return false;
                }
                static::authorize(ResourceOperation::ForceDelete, $record, $user);

                return true;
            })
            ->action(static fn (Model $record) => static::forceDeleteRecord($record))
            ->successNotificationTitle(static::label().' permanently deleted.');
    }

    private static function deleteBulkTableAction(): BulkAction
    {
        return BulkAction::make('delete')
            ->label('Delete selected')
            ->color('danger')
            ->requiresConfirmation()
            ->deselectRecordsAfterCompletion()
            ->authorizeUsing(static function (mixed $user): bool {
                static::authorize(ResourceOperation::DeleteAny, user: $user);

                return true;
            })
            ->action(static function (Collection $records, mixed $user): int {
                $records->each(static function (Model $record) use ($user): void {
                    static::authorize(ResourceOperation::Delete, $record, $user);
                    static::deleteRecord($record);
                });

                return $records->count();
            })
            ->successNotificationTitle('Selected '.static::pluralLabel().' deleted.')
            ->databaseTransaction();
    }

    private static function restoreBulkTableAction(): BulkAction
    {
        return BulkAction::make('restore')
            ->label('Restore selected')
            ->color('success')
            ->requiresConfirmation()
            ->deselectRecordsAfterCompletion()
            ->authorizeUsing(static function (mixed $user): bool {
                static::authorize(ResourceOperation::RestoreAny, user: $user);

                return true;
            })
            ->action(static function (Collection $records, mixed $user): int {
                $records->each(static function (Model $record) use ($user): void {
                    static::authorize(ResourceOperation::Restore, $record, $user);
                    static::restoreRecord($record);
                });

                return $records->count();
            })
            ->successNotificationTitle('Selected '.static::pluralLabel().' restored.')
            ->databaseTransaction();
    }

    private static function forceDeleteBulkTableAction(): BulkAction
    {
        return BulkAction::make('force-delete')
            ->label('Force delete selected')
            ->color('danger')
            ->requiresConfirmation()
            ->deselectRecordsAfterCompletion()
            ->authorizeUsing(static function (mixed $user): bool {
                static::authorize(ResourceOperation::ForceDeleteAny, user: $user);

                return true;
            })
            ->action(static function (Collection $records, mixed $user): int {
                $records->each(static function (Model $record) use ($user): void {
                    static::authorize(ResourceOperation::ForceDelete, $record, $user);
                    static::forceDeleteRecord($record);
                });

                return $records->count();
            })
            ->successNotificationTitle('Selected '.static::pluralLabel().' permanently deleted.')
            ->databaseTransaction();
    }
}

<?php

declare(strict_types=1);

namespace Inlay\Resources;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inlay\Actions\Action;
use Inlay\Actions\BulkAction;
use Inlay\Forms\Fields\Select;
use Inlay\Forms\Form;
use Inlay\Resources\Exceptions\ResourceAccessDenied;
use Inlay\Support\Condition;
use Inlay\Tables\Filters\TrashedFilter;
use Inlay\Tables\Table;
use Inlay\Validation\Validation;
use Inlay\Validation\ValidationRunner;
use JsonSerializable;

abstract class RelationManager implements JsonSerializable
{
    protected static string $relationship;

    protected static ?string $title = null;

    protected static ?string $recordTitleAttribute = null;

    protected static bool $softDeletes = false;

    private ?Table $resolvedTable = null;

    private ?string $baseUrl = null;

    private ?RelationGroup $relationGroup = null;

    final public function __construct(
        private readonly Model $ownerRecord,
        private readonly mixed $user = null,
        private readonly bool $readOnly = false,
    ) {}

    public static function make(Model $ownerRecord, mixed $user = null, bool $readOnly = false): static
    {
        return new static($ownerRecord, $user, $readOnly);
    }

    final public static function name(): string
    {
        if (! isset(static::$relationship) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', static::$relationship) !== 1) {
            throw new \LogicException('Relation managers must declare a valid static $relationship name.');
        }

        return static::$relationship;
    }

    final public static function title(): string
    {
        return static::$title ?? Str::headline(static::name());
    }

    final public function ownerRecord(): Model
    {
        return $this->ownerRecord;
    }

    final public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    final public function relationship(): Relation
    {
        $name = static::name();
        if (! method_exists($this->ownerRecord, $name)) {
            throw new \LogicException("Relationship [{$name}] does not exist on [".get_class($this->ownerRecord).'].');
        }
        $relation = $this->ownerRecord->{$name}();
        if (! $relation instanceof Relation) {
            throw new \LogicException("Relationship method [{$name}] must return an Eloquent relation.");
        }

        return $relation;
    }

    public function table(Table $table): Table
    {
        return $table;
    }

    public function form(Form $form): Form
    {
        return $form;
    }

    /**
     * Configure the many-to-many Attach form. Include
     * $this->getAttachRecordSelect() alongside any pivot fields.
     */
    public function attachForm(Form $form): Form
    {
        return $form->schema([
            $this->getAttachRecordSelect(),
        ]);
    }

    /** @return class-string<Validation>|null */
    public function attachValidation(): ?string
    {
        return null;
    }

    /**
     * Customize the records available to the attach chooser.
     *
     * Tenant, ownership, archival, or other application constraints belong here.
     */
    protected function modifyAttachQuery(Builder $query): Builder
    {
        return $query;
    }

    /**
     * Customize the records available to the associate chooser.
     *
     * The query may include unowned records or records currently owned by
     * another parent. Tenant and ownership constraints belong here.
     */
    protected function modifyAssociateQuery(Builder $query): Builder
    {
        return $query;
    }

    /** @return class-string<Validation>|null */
    public function validation(): ?string
    {
        return null;
    }

    public function validationOperation(RelationOperation $operation): string
    {
        return 'relation.'.$operation->value;
    }

    final public function tableQuery(): Builder
    {
        return $this->relationship()->getQuery();
    }

    final public function actionTableQuery(): Builder
    {
        $query = $this->tableQuery();

        return $this->usesSoftDeletes() ? $query->withTrashed() : $query;
    }

    final public function usesSoftDeletes(): bool
    {
        if (! static::$softDeletes) {
            return false;
        }

        $related = $this->relationship()->getRelated()::class;
        if (! in_array(SoftDeletes::class, class_uses_recursive($related), true)) {
            throw new \LogicException(
                'Relation manager ['.static::class.'] enables soft deletes, but related model ['.$related.'] does not use '.SoftDeletes::class.'.',
            );
        }

        return true;
    }

    final public function configuredTable(): Table
    {
        $table = Table::make(static::name());
        if ($this->table($table) !== $table) {
            throw new \LogicException('Relation manager table configuration must return the supplied fresh table instance.');
        }

        if (! $this->usesSoftDeletes()) {
            return $table;
        }

        if ($table->getFilter('trashed') === null) {
            $table->filters([...$table->getFilters(), TrashedFilter::make()]);
        }

        if (! $this->readOnly) {
            $actions = $table->getActions();
            if ($table->getAction('delete') === null) {
                $actions[] = $this->deleteTableAction();
            }
            if ($table->getAction('restore') === null) {
                $actions[] = $this->restoreTableAction();
            }
            if ($table->getAction('force-delete') === null) {
                $actions[] = $this->forceDeleteTableAction();
            }
            $table->actions($actions);

            $bulk = $table->getBulkActionDefinitions();
            foreach ([
                'delete' => $this->deleteBulkTableAction(),
                'restore' => $this->restoreBulkTableAction(),
                'force-delete' => $this->forceDeleteBulkTableAction(),
            ] as $name => $action) {
                if ($table->getBulkAction($name) === null) {
                    $bulk[] = $action;
                }
            }
            $table->bulkActions($bulk);
        }

        if ($this->baseUrl !== null) {
            $table->defaultLifecycleActionUrls($this->baseUrl);
        }

        return $table;
    }

    /** @param array<string, mixed> $input */
    final public function resolveTable(array $input = [], int $perPage = 15): Table
    {
        $this->authorize(RelationOperation::ViewAny);
        $table = $this->configuredTable();

        return $this->resolvedTable = $table->query($this->tableQuery(), $input, $perPage);
    }

    final public function configuredForm(RelationOperation $operation, ?Model $record = null): Form
    {
        if (! in_array($operation, [RelationOperation::Create, RelationOperation::Edit], true)) {
            throw new \InvalidArgumentException('Only create and edit relation operations have forms.');
        }
        if (($operation === RelationOperation::Edit) !== ($record !== null)) {
            throw new \InvalidArgumentException('The relation form record does not match its operation.');
        }
        if ($record !== null) {
            $this->assertRelatedRecord($record);
        }

        return $this->buildConfiguredForm($operation, $record);
    }

    final public function configuredFormTemplate(RelationOperation $operation): Form
    {
        if (! in_array($operation, [RelationOperation::Create, RelationOperation::Edit], true)) {
            throw new \InvalidArgumentException('Only create and edit relation operations have form templates.');
        }

        return $this->buildConfiguredForm($operation);
    }

    private function buildConfiguredForm(RelationOperation $operation, ?Model $record = null): Form
    {
        $model = $this->relationship()->getRelated();
        $form = Form::make(static::name().'.'.$operation->value)
            ->model($record ?? $model::class);
        if ($this->form($form) !== $form) {
            throw new \LogicException('Relation manager form configuration must return the supplied fresh form instance.');
        }
        if (($validation = $this->validation()) !== null) {
            $form->validation($validation, $this->validationOperation($operation));
        }
        if ($record !== null) {
            $form->data($record->toArray());
        }
        if ($this->baseUrl !== null) {
            $url = $record === null
                ? $this->baseUrl
                : $this->baseUrl.'/'.rawurlencode((string) $record->getRouteKey());
            $form->action($url)->method($record === null ? 'post' : 'patch');
        }

        return $form;
    }

    final public function configuredAttachForm(): Form
    {
        $this->authorize(RelationOperation::Attach);
        $form = Form::make(static::name().'.attach')
            ->submitLabel('Attach');
        if ($this->attachForm($form) !== $form) {
            throw new \LogicException('Relation manager attach form configuration must return the supplied fresh form instance.');
        }
        if (! $form->getField('record') instanceof Select) {
            throw new \LogicException('Relation manager attach forms must include $this->getAttachRecordSelect().');
        }
        $this->assertAttachPivotFields($form);
        if (($validation = $this->attachValidation()) !== null) {
            $form->validation($validation, $this->validationOperation(RelationOperation::Attach))
                ->mergeFieldRules();
        }
        if ($this->baseUrl !== null) {
            // The Form uses this read endpoint for its remote Select. Submission is
            // handled by the record-specific attach endpoint in the renderer.
            $form->action($this->baseUrl.'/attach-options')->method('post');
        }

        return $form;
    }

    final protected function getAttachRecordSelect(): Select
    {
        $titleAttribute = $this->attachTitleAttribute();

        return Select::make('record')
            ->label('Record')
            ->required()
            ->searchable()
            ->preload()
            ->getSearchResultsUsing(
                fn (string $search): array => $this->searchAttachOptions($search),
            )
            ->getOptionLabelUsing(
                fn (string|int $value): ?string => $this->attachCandidateQuery()
                    ->whereKey($value)
                    ->value($titleAttribute),
            );
    }

    final public function configuredAssociateForm(): Form
    {
        $this->authorize(RelationOperation::Associate);
        $titleAttribute = $this->relationTitleAttribute('associate');
        $form = Form::make(static::name().'.associate')
            ->submitLabel('Associate')
            ->schema([
                Select::make('record')
                    ->label('Record')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->getSearchResultsUsing(
                        fn (string $search): array => $this->searchAssociateOptions($search),
                    )
                    ->getOptionLabelUsing(
                        fn (string|int $value): ?string => $this->associateCandidateQuery()
                            ->whereKey($value)
                            ->value($titleAttribute),
                    ),
            ]);
        if ($this->baseUrl !== null) {
            $form->action($this->baseUrl.'/associate-options')->method('post');
        }

        return $form;
    }

    /** @return array<string|int, string> */
    final public function searchAttachOptions(string $search = ''): array
    {
        $this->authorize(RelationOperation::Attach);
        if (mb_strlen($search) > 200) {
            throw new \InvalidArgumentException('Relation attach search must be at most 200 characters.');
        }

        $titleAttribute = $this->attachTitleAttribute();

        return $this->attachCandidateQuery()
            ->when(
                $search !== '',
                fn (Builder $query): Builder => $query->where($titleAttribute, 'like', '%'.$search.'%'),
            )
            ->orderBy($titleAttribute)
            ->limit(50)
            ->pluck($titleAttribute, $this->relationship()->getRelated()->getKeyName())
            ->all();
    }

    /** @return array<string|int, string> */
    final public function searchAssociateOptions(string $search = ''): array
    {
        $this->authorize(RelationOperation::Associate);
        if (mb_strlen($search) > 200) {
            throw new \InvalidArgumentException('Relation associate search must be at most 200 characters.');
        }

        $relation = $this->associableRelationship();
        $titleAttribute = $this->relationTitleAttribute('associate');

        return $this->associateCandidateQuery()
            ->when(
                $search !== '',
                fn (Builder $query): Builder => $query->where($titleAttribute, 'like', '%'.$search.'%'),
            )
            ->orderBy($titleAttribute)
            ->limit(50)
            ->pluck($titleAttribute, $relation->getRelated()->getKeyName())
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{record: string|int, pivot: array<string, mixed>}
     */
    final public function validateAttachMutation(
        ValidationRunner $runner,
        ValidationFactory $factory,
        array $data,
        ?Request $request = null,
    ): array {
        $form = $this->configuredAttachForm();
        $validated = $form->hasValidation()
            ? $form->validate(
                $runner,
                $data,
                user: $this->user,
                options: [
                    'relationManager' => static::class,
                    'ownerRecord' => $this->ownerRecord,
                    'request' => $request,
                ],
            )
            : $form->validateWithFactory($factory, $data, $request);
        $record = $validated['record'] ?? null;
        if (! is_string($record) && ! is_int($record)) {
            throw new \LogicException('The validated Attach form did not contain a record identifier.');
        }
        unset($validated['record']);

        return ['record' => $record, 'pivot' => $this->normalizePivotData($validated)];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    final public function validateMutation(
        ValidationRunner $runner,
        array $data,
        RelationOperation $operation,
        ?Model $record = null,
        ?Request $request = null,
    ): array {
        if ($this->validation() === null) {
            throw new \LogicException('Relation manager mutations require a centralized validation class.');
        }

        return $this->configuredForm($operation, $record)->validate(
            $runner,
            $data,
            $record,
            $this->user,
            options: [
                'relationManager' => static::class,
                'ownerRecord' => $this->ownerRecord,
                'request' => $request,
            ],
        );
    }

    /** @param array<string, mixed> $data */
    final public function createRecord(array $data): Model
    {
        $this->authorize(RelationOperation::Create);
        $relation = $this->relationship();
        if (! $this->isCreationRelation($relation)) {
            throw new \LogicException('Creating related records requires HasOne, HasMany, MorphOne, MorphMany, BelongsToMany, or MorphToMany.');
        }

        return $this->ownerRecord->getConnection()->transaction(function () use ($relation, $data): Model {
            $form = $this->configuredForm(RelationOperation::Create);
            $persistence = $form->splitRelationshipData($data);
            if ($relation instanceof BelongsToMany || $relation instanceof MorphToMany) {
                $attributes = $this->splitPivotAttributes($persistence['attributes']);
                $record = $relation->getRelated()->newInstance($attributes['related']);
                $record->save();
                $relation->attach($record, $attributes['pivot']);
                foreach ($attributes['pivot'] as $name => $value) {
                    $record->setAttribute($name, $value);
                    $record->syncOriginalAttribute($name);
                }
            } else {
                $record = $relation->create($persistence['attributes']);
            }
            $form->saveRelationships($record, $persistence['relationships']);

            return $record;
        });
    }

    /** @param array<string, mixed> $data */
    final public function updateRecord(Model $record, array $data): Model
    {
        $this->assertRelatedRecord($record);
        $this->authorize(RelationOperation::Edit, $record);
        $relation = $this->relationship();

        return $record->getConnection()->transaction(function () use ($record, $data, $relation): Model {
            $form = $this->configuredForm(RelationOperation::Edit, $record);
            $persistence = $form->splitRelationshipData($data);
            if ($relation instanceof BelongsToMany || $relation instanceof MorphToMany) {
                $attributes = $this->splitPivotAttributes($persistence['attributes']);
                if ($attributes['related'] !== []) {
                    $record->fill($attributes['related'])->save();
                }
                if ($attributes['pivot'] !== []) {
                    $relation->updateExistingPivot($record->getKey(), $attributes['pivot']);
                    foreach ($attributes['pivot'] as $name => $value) {
                        $record->setAttribute($name, $value);
                        $record->syncOriginalAttribute($name);
                    }
                }
            } else {
                $record->fill($persistence['attributes'])->save();
            }
            $form->saveRelationships($record, $persistence['relationships']);

            return $record;
        });
    }

    final public function deleteRecord(Model $record): void
    {
        $this->assertRelatedRecord($record);
        $this->authorize(RelationOperation::Delete, $record);
        if ($this->usesSoftDeletes() && $record->trashed()) {
            throw new \LogicException('A trashed related record cannot be deleted again.');
        }
        $record->getConnection()->transaction(function () use ($record): void {
            $this->beforeDelete($record);
            $record->delete();
            $this->afterDelete($record);
        });
    }

    final public function restoreRecord(Model $record): void
    {
        $this->assertTrashedRelatedRecord($record, RelationOperation::Restore);
        $record->getConnection()->transaction(function () use ($record): void {
            $this->beforeRestore($record);
            $record->restore();
            $this->afterRestore($record);
        });
    }

    final public function forceDeleteRecord(Model $record): void
    {
        $this->assertTrashedRelatedRecord($record, RelationOperation::ForceDelete);
        $record->getConnection()->transaction(function () use ($record): void {
            $this->beforeForceDelete($record);
            $record->forceDelete();
            $this->afterForceDelete($record);
        });
    }

    /** @param array<string, mixed> $pivotData */
    final public function attachRecord(Model|string|int $record, array $pivotData = []): Model
    {
        $relation = $this->manyToManyRelationship();
        $pivotData = $this->normalizePivotData($pivotData);
        $candidate = $this->attachCandidateQuery()
            ->where(
                $relation->getRelated()->qualifyColumn($relation->getRelated()->getRouteKeyName()),
                $record instanceof Model ? $record->getRouteKey() : $record,
            )
            ->firstOrFail();
        $relatedClass = $relation->getRelated()::class;
        if (! $candidate instanceof $relatedClass) {
            throw new \InvalidArgumentException('The attach record does not match the related model.');
        }
        $this->authorize(RelationOperation::Attach, $candidate);
        $relation->syncWithoutDetaching([
            $candidate->getKey() => $pivotData,
        ]);

        return $candidate;
    }

    final public function detachRecord(Model|string|int $record): void
    {
        $relation = $this->manyToManyRelationship();
        $related = $record instanceof Model ? $record : $this->resolveRecord($record);
        $this->authorize(RelationOperation::Detach, $related);
        $relation->detach($related->getKey());
    }

    final public function associateRecord(Model|string|int $record): Model
    {
        $relation = $this->associableRelationship();
        $candidate = $this->associateCandidateQuery()
            ->where(
                $relation->getRelated()->qualifyColumn($relation->getRelated()->getRouteKeyName()),
                $record instanceof Model ? $record->getRouteKey() : $record,
            )
            ->firstOrFail();
        $relatedClass = $relation->getRelated()::class;
        if (! $candidate instanceof $relatedClass) {
            throw new \InvalidArgumentException('The associate record does not match the related model.');
        }
        $this->authorize(RelationOperation::Associate, $candidate);

        return $candidate->getConnection()->transaction(function () use ($relation, $candidate): Model {
            $saved = $relation->save($candidate);
            if (! $saved instanceof Model) {
                throw new \RuntimeException('The related record could not be associated.');
            }

            return $saved;
        });
    }

    final public function dissociateRecord(Model|string|int $record): void
    {
        $relation = $this->associableRelationship();
        $related = $record instanceof Model ? $record : $this->resolveRecord($record);
        $this->assertRelatedRecord($related);
        $this->authorize(RelationOperation::Dissociate, $related);

        $related->getConnection()->transaction(function () use ($relation, $related): void {
            $related->setAttribute($relation->getForeignKeyName(), null);
            if ($relation instanceof MorphOneOrMany) {
                $related->setAttribute($relation->getMorphType(), null);
            }
            $related->save();
        });
    }

    final public function resolveRecord(string|int $key): Model
    {
        $model = $this->actionTableQuery()->where(
            $this->relationship()->getRelated()->qualifyColumn(
                $this->relationship()->getRelated()->getRouteKeyName(),
            ),
            $key,
        )->firstOrFail();
        $this->assertRelatedRecord($model);

        return $model;
    }

    final public function authorize(RelationOperation $operation, ?Model $record = null): void
    {
        if (! $this->can($operation, $record)) {
            throw new ResourceAccessDenied("Access denied for relation operation [{$operation->value}] on [".static::class.'].');
        }
    }

    final public function can(RelationOperation $operation, ?Model $record = null): bool
    {
        return ! ($this->readOnly && $operation !== RelationOperation::ViewAny)
            && $this->canAccess($operation, $record, $this->user);
    }

    protected function canAccess(RelationOperation $operation, ?Model $record, mixed $user): bool
    {
        return false;
    }

    final public function baseUrl(string $url): static
    {
        $this->baseUrl = rtrim($url, '/');
        $this->resolvedTable?->defaultLifecycleActionUrls($this->baseUrl);

        return $this;
    }

    final public function relationGroup(?RelationGroup $group): static
    {
        if ($group !== null && ! $group->contains(static::class)) {
            throw new \InvalidArgumentException(
                'The relation group does not contain manager ['.static::class.'].',
            );
        }
        $this->relationGroup = $group;

        return $this;
    }

    /** @return array<string, mixed> */
    final public function jsonSerialize(): array
    {
        $table = $this->resolvedTable ?? $this->resolveTable();
        $manyToMany = $this->relationship() instanceof BelongsToMany
            || $this->relationship() instanceof MorphToMany;
        $associable = $this->relationship() instanceof HasMany
            || $this->relationship() instanceof MorphMany;

        return [
            'contract' => 'inlay.resources.relation-manager.v1',
            'name' => static::name(),
            'title' => static::title(),
            'recordTitleAttribute' => static::$recordTitleAttribute,
            'readOnly' => $this->readOnly,
            'group' => $this->relationGroup,
            'table' => $table,
            'createForm' => $this->can(RelationOperation::Create)
                ? $this->configuredFormTemplate(RelationOperation::Create)
                : null,
            'editForm' => $this->can(RelationOperation::Edit)
                ? $this->configuredFormTemplate(RelationOperation::Edit)
                : null,
            'attachForm' => $manyToMany && $this->can(RelationOperation::Attach)
                ? $this->configuredAttachForm()
                : null,
            'associateForm' => $associable && $this->can(RelationOperation::Associate)
                ? $this->configuredAssociateForm()
                : null,
            'capabilities' => [
                'softDeletes' => $this->usesSoftDeletes(),
                'create' => $this->can(RelationOperation::Create),
                'edit' => $this->can(RelationOperation::Edit),
                'delete' => $this->can(RelationOperation::Delete),
                'attach' => $manyToMany && $this->can(RelationOperation::Attach),
                'detach' => $manyToMany && $this->can(RelationOperation::Detach),
                'associate' => $associable && $this->can(RelationOperation::Associate),
                'dissociate' => $associable && $this->can(RelationOperation::Dissociate),
            ],
            'endpoints' => $this->baseUrl === null ? null : [
                'create' => $this->baseUrl,
                'update' => $this->baseUrl.'/{record}',
                'delete' => $this->baseUrl.'/{record}',
                'attach' => $this->baseUrl.'/{record}/attach',
                'detach' => $this->baseUrl.'/{record}/detach',
                'attachOptions' => $this->baseUrl.'/attach-options',
                'associate' => $this->baseUrl.'/{record}/associate',
                'dissociate' => $this->baseUrl.'/{record}/dissociate',
                'associateOptions' => $this->baseUrl.'/associate-options',
            ],
        ];
    }

    private function attachCandidateQuery(): Builder
    {
        $relation = $this->manyToManyRelationship();
        $related = $relation->getRelated();
        $query = $related->newQuery();
        $modified = $this->modifyAttachQuery($query);
        if ($modified->getModel()::class !== $related::class) {
            throw new \LogicException('Relation attach queries must target the related model.');
        }
        $attached = $relation->allRelatedIds()->values()->all();
        if ($attached !== []) {
            $modified->whereNotIn($related->getQualifiedKeyName(), $attached);
        }

        return $modified;
    }

    private function assertAttachPivotFields(Form $form): void
    {
        $allowed = $this->attachPivotColumns();
        foreach ($form->getFields() as $name => $field) {
            if ($field instanceof Select && $field->name() === 'record') {
                continue;
            }
            if (! in_array($field->name(), $allowed, true)) {
                throw new \LogicException("Attach form field [{$name}] must be declared by withPivot() on the relationship.");
            }
        }
    }

    /** @return list<string> */
    private function attachPivotColumns(): array
    {
        $columns = $this->manyToManyRelationship()->getPivotColumns();
        foreach ($columns as $column) {
            if (! is_string($column) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
                throw new \LogicException('Editable pivot columns must be plain column names.');
            }
        }

        return array_values(array_unique($columns));
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalizePivotData(array $data): array
    {
        $allowed = $this->attachPivotColumns();
        $unexpected = array_diff(array_keys($data), $allowed);
        if ($unexpected !== []) {
            throw new \InvalidArgumentException('Unexpected pivot attributes: '.implode(', ', $unexpected).'.');
        }

        return array_intersect_key($data, array_flip($allowed));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{related: array<string, mixed>, pivot: array<string, mixed>}
     */
    private function splitPivotAttributes(array $attributes): array
    {
        $pivot = array_intersect_key($attributes, array_flip($this->attachPivotColumns()));

        return [
            'related' => array_diff_key($attributes, $pivot),
            'pivot' => $pivot,
        ];
    }

    private function attachTitleAttribute(): string
    {
        return $this->relationTitleAttribute('attach');
    }

    private function relationTitleAttribute(string $operation): string
    {
        $attribute = static::$recordTitleAttribute ?? $this->relationship()->getRelated()->getRouteKeyName();
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $attribute) !== 1) {
            throw new \LogicException("Relation {$operation} record title attributes must be plain column names.");
        }

        return $attribute;
    }

    private function associateCandidateQuery(): Builder
    {
        $relation = $this->associableRelationship();
        $related = $relation->getRelated();
        $query = $related->newQuery();
        $modified = $this->modifyAssociateQuery($query);
        if ($modified->getModel()::class !== $related::class) {
            throw new \LogicException('Relation associate queries must target the related model.');
        }
        $associated = $relation->getQuery()->pluck($related->getQualifiedKeyName())->all();
        if ($associated !== []) {
            $modified->whereNotIn($related->getQualifiedKeyName(), $associated);
        }

        return $modified;
    }

    private function assertRelatedRecord(Model $record): void
    {
        $relatedClass = $this->relationship()->getRelated()::class;
        if (! $record instanceof $relatedClass) {
            throw new \InvalidArgumentException('Record does not match the relation manager model.');
        }
        if (! $this->actionTableQuery()->where(
            $this->relationship()->getRelated()->getQualifiedKeyName(),
            $record->getKey(),
        )->exists()) {
            throw new ResourceAccessDenied('The record is not available through the owner relationship.');
        }
    }

    private function manyToManyRelationship(): BelongsToMany|MorphToMany
    {
        $relation = $this->relationship();
        if (! $relation instanceof BelongsToMany && ! $relation instanceof MorphToMany) {
            throw new \LogicException('Attach and detach require a BelongsToMany or MorphToMany relationship.');
        }

        return $relation;
    }

    private function associableRelationship(): HasOneOrMany
    {
        $relation = $this->relationship();
        if (! $relation instanceof HasMany && ! $relation instanceof MorphMany) {
            throw new \LogicException('Associate and dissociate require a HasMany or MorphMany relationship.');
        }

        return $relation;
    }

    private function isCreationRelation(Relation $relation): bool
    {
        return $relation instanceof HasOne
            || $relation instanceof HasMany
            || $relation instanceof MorphOne
            || $relation instanceof MorphMany
            || $relation instanceof BelongsToMany
            || $relation instanceof MorphToMany;
    }

    protected function beforeDelete(Model $record): void {}

    protected function afterDelete(Model $record): void {}

    protected function beforeRestore(Model $record): void {}

    protected function afterRestore(Model $record): void {}

    protected function beforeForceDelete(Model $record): void {}

    protected function afterForceDelete(Model $record): void {}

    private function assertTrashedRelatedRecord(Model $record, RelationOperation $operation): void
    {
        if (! $this->usesSoftDeletes()) {
            throw new \LogicException('Relation manager ['.static::class.'] must enable soft deletes first.');
        }
        $this->assertRelatedRecord($record);
        $this->authorize($operation, $record);
        if (! method_exists($record, 'trashed') || ! $record->trashed()) {
            throw new \LogicException('Only trashed related records may use the ['.$operation->value.'] operation.');
        }
    }

    private function deleteTableAction(): Action
    {
        return Action::make('delete')
            ->label('Delete')
            ->color('danger')
            ->requiresConfirmation()
            ->visibleWhen(Condition::blank('deleted_at'))
            ->authorizeUsing(fn (?Model $record): bool => $record !== null && $this->can(RelationOperation::Delete, $record))
            ->action(fn (Model $record) => $this->deleteRecord($record))
            ->successNotificationTitle('Related record deleted.');
    }

    private function restoreTableAction(): Action
    {
        return Action::make('restore')
            ->label('Restore')
            ->color('success')
            ->requiresConfirmation()
            ->visibleWhen(Condition::filled('deleted_at'))
            ->authorizeUsing(fn (?Model $record): bool => $record !== null && $this->can(RelationOperation::Restore, $record))
            ->action(fn (Model $record) => $this->restoreRecord($record))
            ->successNotificationTitle('Related record restored.');
    }

    private function forceDeleteTableAction(): Action
    {
        return Action::make('force-delete')
            ->label('Force delete')
            ->color('danger')
            ->requiresConfirmation()
            ->visibleWhen(Condition::filled('deleted_at'))
            ->authorizeUsing(fn (?Model $record): bool => $record !== null && $this->can(RelationOperation::ForceDelete, $record))
            ->action(fn (Model $record) => $this->forceDeleteRecord($record))
            ->successNotificationTitle('Related record permanently deleted.');
    }

    private function deleteBulkTableAction(): BulkAction
    {
        return $this->bulkSoftDeleteAction(
            'delete',
            'Delete selected',
            RelationOperation::DeleteAny,
            RelationOperation::Delete,
            fn (Model $record) => $this->deleteRecord($record),
            'Selected related records deleted.',
        );
    }

    private function restoreBulkTableAction(): BulkAction
    {
        return $this->bulkSoftDeleteAction(
            'restore',
            'Restore selected',
            RelationOperation::RestoreAny,
            RelationOperation::Restore,
            fn (Model $record) => $this->restoreRecord($record),
            'Selected related records restored.',
            'success',
        );
    }

    private function forceDeleteBulkTableAction(): BulkAction
    {
        return $this->bulkSoftDeleteAction(
            'force-delete',
            'Force delete selected',
            RelationOperation::ForceDeleteAny,
            RelationOperation::ForceDelete,
            fn (Model $record) => $this->forceDeleteRecord($record),
            'Selected related records permanently deleted.',
        );
    }

    private function bulkSoftDeleteAction(
        string $name,
        string $label,
        RelationOperation $anyOperation,
        RelationOperation $recordOperation,
        \Closure $callback,
        string $message,
        string $color = 'danger',
    ): BulkAction {
        return BulkAction::make($name)
            ->label($label)
            ->color($color)
            ->requiresConfirmation()
            ->deselectRecordsAfterCompletion()
            ->authorizeUsing(fn (): bool => $this->can($anyOperation))
            ->action(function (Collection $records) use ($recordOperation, $callback): int {
                $records->each(function (Model $record) use ($recordOperation, $callback): void {
                    $this->authorize($recordOperation, $record);
                    $callback($record);
                });

                return $records->count();
            })
            ->successNotificationTitle($message)
            ->databaseTransaction();
    }
}

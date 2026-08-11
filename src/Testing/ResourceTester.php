<?php

declare(strict_types=1);

namespace Inlay\Resources\Testing;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inlay\Actions\Action;
use Inlay\Actions\ActionResult;
use Inlay\Actions\ActionRunner;
use Inlay\Forms\Testing\FormTester;
use Inlay\Resources\Resource;
use Inlay\Resources\RelationGroup;
use Inlay\Resources\ResourceOperation;
use Inlay\Resources\Tenancy;
use Inlay\Tables\Table;
use Inlay\Tables\Testing\TableTester;
use Inlay\Support\Testing\Assertions;
use Inlay\Validation\ValidationRunner;

final class ResourceTester
{
    /** @var class-string<Resource> */
    private string $resource;

    private ?Model $record = null;

    private ResourceOperation $formOperation = ResourceOperation::Create;

    private ?FormTester $formTester = null;

    private ?TableTester $tableTester = null;

    /** @var array<string, mixed> */
    private array $tableInput = [];

    private Request $request;

    private ValidationFactory $validationFactory;

    private ValidationRunner $validationRunner;

    private ?ActionRunner $actionRunner;

    /** @var array<string, mixed>|null */
    private ?array $tableSelection = null;

    /** @var array{scope: string, name: string, records: list<string|int>, data: array<string, mixed>}|null */
    private ?array $mountedTableAction = null;

    private ?ActionResult $lastTableActionResult = null;

    /** @var array<string, list<string>> */
    private array $lastTableActionErrors = [];

    /**
     * @param class-string<Resource> $resource
     */
    private function __construct(string $resource, private mixed $user = null, ?ValidationFactory $validationFactory = null, ?ValidationRunner $validationRunner = null, ?ActionRunner $actionRunner = null)
    {
        if (! is_subclass_of($resource, Resource::class)) {
            throw new \InvalidArgumentException("Resource tester [{$resource}] must extend ".Resource::class.'.');
        }
        $this->resource = $resource;
        $container = Container::getInstance();
        $this->validationFactory = $validationFactory ?? $container->make(ValidationFactory::class);
        $this->validationRunner = $validationRunner ?? $container->make(ValidationRunner::class);
        $this->actionRunner = $actionRunner;
        $this->request = Request::create('/', 'GET');
        $this->request->setUserResolver(fn (): mixed => $this->user);
    }

    /**
     * @param class-string<Resource> $resource
     */
    public static function make(string $resource, mixed $user = null, ?ValidationFactory $validationFactory = null, ?ValidationRunner $validationRunner = null, ?ActionRunner $actionRunner = null): self
    {
        return new self($resource, $user, $validationFactory, $validationRunner, $actionRunner);
    }

    /**
     * Run everything that follows inside a tenant.
     *
     * The tester drives the same scoping a request does, so a test can prove
     * that another tenant's records are unreachable rather than assuming it.
     */
    public function forTenant(?Model $tenant): self
    {
        Tenancy::resolve()->set($tenant);
        $this->formTester = null;
        $this->tableTester = null;

        return $this;
    }

    public function withoutTenant(): self
    {
        return $this->forTenant(null);
    }

    public function tenant(): ?Model
    {
        return Tenancy::resolve()->current();
    }

    public function assertTenantScoped(): self
    {
        Assertions::true(
            $this->resource::tenantRelationship() !== null,
            "Expected resource [{$this->resource}] to be tenant-scoped.",
        );

        return $this;
    }

    public function assertNotTenantScoped(): self
    {
        Assertions::true(
            $this->resource::tenantRelationship() === null,
            "Expected resource [{$this->resource}] not to be tenant-scoped.",
        );

        return $this;
    }

    /** Assert that a record belongs to a tenant other than the current one. */
    public function assertRecordOutsideTenant(Model|string|int $record): self
    {
        $key = $record instanceof Model ? $record->getKey() : $record;

        try {
            $this->resource::resolveRecord($key);
        } catch (ModelNotFoundException) {
            return $this;
        }

        Assertions::fail("Record [{$key}] is reachable inside the current tenant.");
    }

    public function forCreate(): self
    {
        $this->record = null;
        $this->formOperation = ResourceOperation::Create;
        $this->formTester = null;

        return $this;
    }

    public function forEdit(Model|string|int $record): self
    {
        $this->record = $record instanceof Model ? $record : $this->resource::resolveRecord($record);
        $this->formOperation = ResourceOperation::Edit;
        $this->formTester = null;

        return $this;
    }

    public function record(): ?Model
    {
        return $this->record;
    }

    public function relation(string $name, Model|string|int $owner): RelationManagerTester
    {
        $owner = $owner instanceof Model ? $owner : $this->resource::resolveRecord($owner);

        return new RelationManagerTester(
            $this->resource::relation($name, $owner, $this->user),
            $this->validationFactory,
            $this->validationRunner,
            $this->user,
        );
    }

    public function assertRelationGroupExists(string $id, ?Closure $check = null): self
    {
        $group = collect($this->resource::relationLayout())->first(
            fn (mixed $entry): bool => $entry instanceof RelationGroup
                && $entry->jsonSerialize()['id'] === $id,
        );
        Assertions::true($group instanceof RelationGroup, "Expected relation group [{$id}] to exist.");
        if ($check !== null) {
            Assertions::true(
                $check($group) === true,
                "Relation group [{$id}] exists, but its configuration assertion failed.",
            );
        }

        return $this;
    }

    public function assertRelationManagerExists(string $name, ?string $group = null): self
    {
        $manager = $this->resource::relations()[$name] ?? null;
        Assertions::true(is_string($manager), "Expected relation manager [{$name}] to exist.");
        if ($group !== null) {
            $actual = $this->resource::relationGroup($manager)?->jsonSerialize()['id'] ?? null;
            Assertions::same($group, $actual, "Relation manager [{$name}] does not belong to group [{$group}].");
        }

        return $this;
    }

    /** @param array<string, mixed> $state */
    public function fillForm(array $state): self
    {
        $this->form()->fillForm($state);

        return $this;
    }

    public function assertFormFieldExists(string $name, ?Closure $check = null): self
    {
        $this->form()->assertFormFieldExists($name, $check);

        return $this;
    }

    public function assertFormFieldDoesNotExist(string $name): self
    {
        $this->form()->assertFormFieldDoesNotExist($name);

        return $this;
    }

    /** @param array<string, mixed>|Closure(array<string, mixed>): array<string, mixed> $expected */
    public function assertSchemaStateSet(array|Closure $expected): self
    {
        $this->form()->assertSchemaStateSet($expected);

        return $this;
    }

    /** @param list<string>|array<string, string> $expected */
    public function assertHasFormErrors(array $expected): self
    {
        $this->form()->assertHasFormErrors($expected);

        return $this;
    }

    public function assertHasNoFormErrors(): self
    {
        $this->form()->assertHasNoFormErrors();

        return $this;
    }

    public function call(string $method): self
    {
        $method = strtolower(trim($method));
        if ($method === 'create' && $this->record !== null) {
            throw new \LogicException('Create tests cannot use an edit record.');
        }
        if (in_array($method, ['save', 'update'], true) && $this->record === null) {
            throw new \LogicException('Save and update tests require forEdit().');
        }
        if (! in_array($method, ['create', 'save', 'update'], true)) {
            throw new \InvalidArgumentException("Unsupported resource test method [{$method}].");
        }

        $operation = $method === 'create' ? ResourceOperation::Create : ResourceOperation::Edit;
        $this->resource::authorize($operation, $this->record, $this->user);
        $form = $this->form();
        $form->validate($this->validationFactory, $this->validationRunner, $this->record, $this->user, $this->request);
        if ($form->hasErrors()) {
            return $this;
        }

        $this->record = $operation === ResourceOperation::Create
            ? $this->resource::createRecord($form->validated())
            : $this->resource::updateRecord($this->record ?? throw new \LogicException('Missing edit record.'), $form->validated());
        $this->formOperation = ResourceOperation::Edit;

        return $this;
    }

    public function searchTable(string $search): self
    {
        $this->tableInput[$this->resource::slug().'_search'] = $search;
        $this->refreshTable();

        return $this;
    }

    /** @param 'asc'|'desc' $direction */
    public function sortTable(string $column, string $direction = 'asc'): self
    {
        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Table sort direction must be asc or desc.');
        }
        $this->tableInput[$this->resource::slug().'_sort'] = $column;
        $this->tableInput[$this->resource::slug().'_direction'] = $direction;
        $this->refreshTable();

        return $this;
    }

    public function filterTable(string $filter, mixed $value): self
    {
        $key = $this->resource::slug().'_filters';
        $filters = $this->tableInput[$key] ?? [];
        if (! is_array($filters)) {
            $filters = [];
        }
        $this->tableInput[$key] = [...$filters, $filter => $value];
        $this->refreshTable();

        return $this;
    }

    public function resetTable(): self
    {
        $this->tableInput = [];
        $this->refreshTable();

        return $this;
    }

    public function assertTableColumnExists(string $name, ?Closure $check = null): self
    {
        $this->table()->assertTableColumnExists($name, $check);

        return $this;
    }

    public function assertTableColumnDoesNotExist(string $name): self
    {
        $this->table()->assertTableColumnDoesNotExist($name);

        return $this;
    }

    public function assertTableFilterExists(string $name, ?Closure $check = null): self
    {
        $this->table()->assertTableFilterExists($name, $check);

        return $this;
    }

    public function assertTableActionExists(string $name, ?Closure $check = null): self
    {
        $this->table()->assertTableActionExists($name, $check);

        return $this;
    }

    public function assertTableHeaderActionExists(string $name, ?Closure $check = null): self
    {
        $this->table()->assertTableHeaderActionExists($name, $check);

        return $this;
    }

    public function assertTableBulkActionExists(string $name, ?Closure $check = null): self
    {
        $this->table()->assertTableBulkActionExists($name, $check);

        return $this;
    }

    /** @param iterable<Model|string|int> $records */
    public function selectTableRecords(iterable $records): self
    {
        $keys = [];
        foreach ($records as $record) {
            $keys[] = $record instanceof Model ? $record->getKey() : $record;
        }
        $this->tableSelection = ['mode' => 'page', 'records' => $keys];

        return $this;
    }

    /** @param iterable<Model|string|int> $excluded */
    public function selectAllTableRecords(iterable $excluded = []): self
    {
        $keys = [];
        foreach ($excluded as $record) {
            $keys[] = $record instanceof Model ? $record->getKey() : $record;
        }
        $this->tableSelection = ['mode' => 'query', 'excluded' => $keys];

        return $this;
    }

    /** @param array<string, mixed> $data */
    public function mountTableAction(string $name, Model|string|int $record, array $data = []): self
    {
        return $this->mountLifecycleTableAction('row', $name, [$this->recordKey($record)], $data);
    }

    /** @param array<string, mixed> $data */
    public function mountTableHeaderAction(string $name, array $data = []): self
    {
        return $this->mountLifecycleTableAction('header', $name, [], $data);
    }

    /** @param array<string, mixed> $data */
    public function mountTableBulkAction(string $name, array $data = []): self
    {
        return $this->mountLifecycleTableAction('bulk', $name, [], $data);
    }

    /** @param array<string, mixed> $data */
    public function fillTableActionForm(array $data): self
    {
        if ($this->mountedTableAction === null) {
            throw new \LogicException('Mount a table action before filling its form.');
        }
        $this->mountedTableAction['data'] = [...$this->mountedTableAction['data'], ...$data];

        return $this;
    }

    public function callMountedTableAction(): self
    {
        $mounted = $this->mountedTableAction
            ?? throw new \LogicException('Mount a table action before calling it.');

        return $this->runLifecycleTableAction(
            $mounted['scope'],
            $mounted['name'],
            $mounted['records'],
            $mounted['data'],
        );
    }

    /** @param array<string, mixed> $data */
    public function callTableAction(string $name, Model|string|int $record, array $data = []): self
    {
        return $this->runLifecycleTableAction('row', $name, [$this->recordKey($record)], $data);
    }

    /** @param array<string, mixed> $data */
    public function callTableHeaderAction(string $name, array $data = []): self
    {
        return $this->runLifecycleTableAction('header', $name, [], $data);
    }

    /** @param array<string, mixed> $data */
    public function callTableBulkAction(string $name, array $data = []): self
    {
        return $this->runLifecycleTableAction('bulk', $name, [], $data);
    }

    public function assertTableActionSucceeded(?Closure $check = null): self
    {
        Assertions::true(
            $this->lastTableActionResult?->status === 'succeeded',
            'Expected the last table action to succeed.',
        );
        if ($check !== null) {
            Assertions::true(
                $check($this->lastTableActionResult?->result) === true,
                'The table action succeeded, but its result assertion failed.',
            );
        }

        return $this;
    }

    public function assertTableActionHalted(?string $message = null): self
    {
        Assertions::true($this->lastTableActionResult?->status === 'halted', 'Expected the last table action to halt.');
        if ($message !== null) {
            Assertions::same($message, $this->lastTableActionResult?->message, 'The halted action message does not match.');
        }

        return $this;
    }

    public function assertTableActionCancelled(?string $message = null): self
    {
        Assertions::true($this->lastTableActionResult?->status === 'cancelled', 'Expected the last table action to be cancelled.');
        if ($message !== null) {
            Assertions::same($message, $this->lastTableActionResult?->message, 'The cancelled action message does not match.');
        }

        return $this;
    }

    /** @param list<string>|array<string, string> $expected */
    public function assertHasTableActionErrors(array $expected): self
    {
        foreach ($expected as $field => $rule) {
            $name = is_int($field) ? $rule : $field;
            Assertions::true(
                isset($this->lastTableActionErrors[$name]),
                "Expected table action validation errors for [{$name}].",
            );
            if (! is_int($field)) {
                Assertions::true(
                    collect($this->lastTableActionErrors[$name])->contains(
                        fn (string $message): bool => str_contains(strtolower($message), strtolower($rule)),
                    ),
                    "Expected the [{$name}] table action error to contain [{$rule}].",
                );
            }
        }

        return $this;
    }

    public function assertHasNoTableActionErrors(): self
    {
        Assertions::same([], $this->lastTableActionErrors, 'Expected the last table action to have no validation errors.');

        return $this;
    }

    public function assertCountTableRecords(int $count): self
    {
        $this->table()->assertCountTableRecords($count);

        return $this;
    }

    /** @param iterable<mixed> $records */
    public function assertCanSeeTableRecords(iterable $records, bool $inOrder = false): self
    {
        $this->table()->assertCanSeeTableRecords($records, $inOrder);

        return $this;
    }

    /** @param iterable<mixed> $records */
    public function assertCanNotSeeTableRecords(iterable $records): self
    {
        $this->table()->assertCanNotSeeTableRecords($records);

        return $this;
    }

    public function assertTableColumnStateSet(string $column, mixed $expected, Model|string|int $record): self
    {
        $this->table()->assertTableColumnStateSet($column, $expected, $record);

        return $this;
    }

    public function editTableColumn(Model|string|int $record, string $column, mixed $state): self
    {
        $model = $record instanceof Model ? $record : $this->resource::resolveRecord($record);
        $this->resource::authorize(ResourceOperation::Edit, $model, $this->user);
        $table = $this->freshConfiguredTable();
        $table->updateEditableColumn(
            $this->resource::getEloquentQuery(),
            $model->getKey(),
            $column,
            $state,
            $this->request,
            $this->validationFactory,
            true,
        );
        $this->refreshTable();

        return $this;
    }

    private function form(): FormTester
    {
        return $this->formTester ??= FormTester::make(
            $this->resource::configuredForm($this->formOperation, $this->record),
        );
    }

    private function table(): TableTester
    {
        if ($this->tableTester === null) {
            $this->resource::authorize(ResourceOperation::ListRecords, user: $this->user);
            $this->refreshTable();
        }

        return $this->tableTester ?? throw new \LogicException('Unable to resolve resource table.');
    }

    private function refreshTable(): void
    {
        $this->resource::authorize(ResourceOperation::ListRecords, user: $this->user);
        $table = $this->freshConfiguredTable()
            // The DSL must see what a request sees: the scoped query, not the
            // raw one, or parent and tenant scoping go untested.
            ->query($this->resource::scopedEloquentQuery(), $this->tableInput, 100);
        $this->tableTester = $this->tableTester === null
            ? TableTester::make($table)
            : $this->tableTester->replace($table);
    }

    private function freshConfiguredTable(): Table
    {
        return $this->resource::configuredTable();
    }

    /** @param list<string|int> $recordKeys @param array<string, mixed> $data */
    private function mountLifecycleTableAction(string $scope, string $name, array $recordKeys, array $data): self
    {
        [$table, $action, $records, $request] = $this->prepareLifecycleTableAction($scope, $name, $recordKeys, $data);
        $table->validateLifecycleActionRecords($action, $scope, $records, $this->validationFactory);
        $payload = $this->actionRunner()->mountForm($action, $request, $data, $records);
        $mountedData = $payload['form']['data'] ?? $data;
        $this->mountedTableAction = [
            'scope' => $scope,
            'name' => $name,
            'records' => $recordKeys,
            'data' => is_array($mountedData) ? $mountedData : $data,
        ];

        return $this;
    }

    /** @param list<string|int> $recordKeys @param array<string, mixed> $data */
    private function runLifecycleTableAction(string $scope, string $name, array $recordKeys, array $data): self
    {
        $this->lastTableActionResult = null;
        $this->lastTableActionErrors = [];
        try {
            [$table, $action, $records, $request] = $this->prepareLifecycleTableAction($scope, $name, $recordKeys, $data);
            $table->validateLifecycleActionRecords($action, $scope, $records, $this->validationFactory);
            $this->lastTableActionResult = $this->actionRunner()->run($action, $request, $data, $records);
        } catch (ValidationException $exception) {
            $this->lastTableActionErrors = $exception->errors();
        }
        $this->mountedTableAction = null;
        $this->refreshTable();

        return $this;
    }

    /**
     * @param list<string|int> $recordKeys
     * @param array<string, mixed> $data
     * @return array{Table, Action, Collection<int, Model>, Request}
     */
    private function prepareLifecycleTableAction(string $scope, string $name, array $recordKeys, array $data): array
    {
        $this->resource::authorize(ResourceOperation::ListRecords, user: $this->user);
        $table = $this->freshConfiguredTable();
        $action = $table->lifecycleAction($name, $scope);
        $query = $this->resource::getActionEloquentQuery();
        $records = match ($scope) {
            'header' => collect(),
            'row' => $query->whereKey($recordKeys)->get(),
            'bulk' => $table->resolveSelectedRecords(
                $query,
                $this->tableSelection ?? throw new \LogicException('Select table records before calling a bulk action.'),
                $this->tableInput,
            ),
            default => throw new \InvalidArgumentException("Unknown table action scope [{$scope}]."),
        };
        if ($scope === 'row' && ($records->count() !== 1 || count($recordKeys) !== 1)) {
            throw ValidationException::withMessages(['record' => 'The selected record is unavailable in the authorized table query.']);
        }
        $request = Request::create('/', strtoupper($action->methodValue()), $data);
        $request->query->add($this->tableInput);
        $request->setUserResolver(fn (): mixed => $this->user);

        return [$table, $action, $records->values(), $request];
    }

    private function actionRunner(): ActionRunner
    {
        if ($this->actionRunner instanceof ActionRunner) {
            return $this->actionRunner;
        }
        $container = Container::getInstance();
        if ($container->bound(ActionRunner::class)) {
            return $this->actionRunner = $container->make(ActionRunner::class);
        }

        throw new \LogicException('Action testing requires ActionRunner to be registered in Laravel or supplied to inlay().');
    }

    private function recordKey(Model|string|int $record): string|int
    {
        return $record instanceof Model ? $record->getKey() : $record;
    }
}

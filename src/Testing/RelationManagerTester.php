<?php

declare(strict_types=1);

namespace Inlay\Resources\Testing;

use Closure;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inlay\Forms\Testing\FormTester;
use Inlay\Resources\RelationManager;
use Inlay\Resources\RelationOperation;
use Inlay\Tables\Testing\TableTester;
use Inlay\Validation\ValidationRunner;

final class RelationManagerTester
{
    private RelationOperation $formOperation = RelationOperation::Create;

    private ?Model $record = null;

    private ?FormTester $formTester = null;

    private ?TableTester $tableTester = null;

    private Request $request;

    public function __construct(
        private readonly RelationManager $manager,
        private readonly ValidationFactory $validationFactory,
        private readonly ValidationRunner $validationRunner,
        private readonly mixed $user = null,
    ) {
        $this->request = Request::create('/', 'GET');
        $this->request->setUserResolver(fn (): mixed => $user);
    }

    public function forCreate(): self
    {
        $this->formOperation = RelationOperation::Create;
        $this->record = null;
        $this->formTester = null;

        return $this;
    }

    public function forEdit(Model|string|int $record): self
    {
        $this->record = $record instanceof Model ? $record : $this->manager->resolveRecord($record);
        $this->formOperation = RelationOperation::Edit;
        $this->formTester = null;

        return $this;
    }

    public function record(): ?Model
    {
        return $this->record;
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
        if (! in_array($method, ['create', 'save', 'update'], true)) {
            throw new \InvalidArgumentException("Unsupported relation test method [{$method}].");
        }
        $operation = $method === 'create' ? RelationOperation::Create : RelationOperation::Edit;
        if (($operation === RelationOperation::Edit) !== ($this->record !== null)) {
            throw new \LogicException('Relation create tests must not have a record; save/update tests require forEdit().');
        }

        $form = $this->form();
        $form->validate(
            $this->validationFactory,
            $this->validationRunner,
            $this->record,
            $this->user,
            request: $this->request,
        );
        if ($form->hasErrors()) {
            return $this;
        }
        $this->record = $operation === RelationOperation::Create
            ? $this->manager->createRecord($form->validated())
            : $this->manager->updateRecord(
                $this->record ?? throw new \LogicException('Missing related record.'),
                $form->validated(),
            );
        $this->formOperation = RelationOperation::Edit;
        $this->formTester = null;
        $this->refreshTable();

        return $this;
    }

    /** @param array<string, mixed> $pivotData */
    public function attach(Model|string|int $record, array $pivotData = []): self
    {
        $this->record = $this->manager->attachRecord($record, $pivotData);
        $this->refreshTable();

        return $this;
    }

    public function detach(Model|string|int $record): self
    {
        $this->manager->detachRecord($record);
        $this->refreshTable();

        return $this;
    }

    public function associate(Model|string|int $record): self
    {
        $this->record = $this->manager->associateRecord($record);
        $this->refreshTable();

        return $this;
    }

    public function dissociate(Model|string|int $record): self
    {
        $this->manager->dissociateRecord($record);
        $this->refreshTable();

        return $this;
    }

    public function delete(Model|string|int $record): self
    {
        $this->manager->deleteRecord($this->resolveRecord($record));
        $this->refreshTable();

        return $this;
    }

    public function restore(Model|string|int $record): self
    {
        $this->manager->restoreRecord($this->resolveRecord($record));
        $this->refreshTable();

        return $this;
    }

    public function forceDelete(Model|string|int $record): self
    {
        $this->manager->forceDeleteRecord($this->resolveRecord($record));
        $this->refreshTable();

        return $this;
    }

    public function assertTableColumnExists(string $name, ?Closure $check = null): self
    {
        $this->table()->assertTableColumnExists($name, $check);

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

    public function assertTableBulkActionExists(string $name, ?Closure $check = null): self
    {
        $this->table()->assertTableBulkActionExists($name, $check);

        return $this;
    }

    public function assertCountTableRecords(int $count): self
    {
        $this->table()->assertCountTableRecords($count);

        return $this;
    }

    /** @param iterable<mixed> $records */
    public function assertCanSeeTableRecords(iterable $records): self
    {
        $this->table()->assertCanSeeTableRecords($records);

        return $this;
    }

    private function form(): FormTester
    {
        return $this->formTester ??= FormTester::make(
            $this->manager->configuredForm($this->formOperation, $this->record),
        );
    }

    private function table(): TableTester
    {
        if ($this->tableTester === null) {
            $this->refreshTable();
        }

        return $this->tableTester ?? throw new \LogicException('Unable to resolve relation table.');
    }

    private function refreshTable(): void
    {
        $table = $this->manager->resolveTable(perPage: 100);
        $this->tableTester = $this->tableTester === null
            ? TableTester::make($table)
            : $this->tableTester->replace($table);
    }

    private function resolveRecord(Model|string|int $record): Model
    {
        return $record instanceof Model ? $record : $this->manager->resolveRecord($record);
    }
}

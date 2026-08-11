<?php

declare(strict_types=1);

namespace Inlay\Resources\Http\Controllers;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Inlay\Forms\Exceptions\UploadRejected;
use Inlay\Forms\Form;
use Inlay\Forms\Uploads\TemporaryUploadManager;
use Inlay\Actions\Action;
use Inlay\Actions\ActionRunner;
use Inlay\Resources\Resource;
use Inlay\Resources\ResourceOperation;
use Inlay\Resources\RelationManager;
use Inlay\Resources\RelationOperation;
use Inlay\Tables\Table;
use Inlay\Validation\ValidationRunner;

final class ResourceController
{
    public function page(Request $request, ValidationFactory $validationFactory): Response|JsonResponse
    {
        $resource = $this->resource((string) $request->route('inlayResource'));
        $prefix = (string) $request->route('inlayPrefix', '');
        $parent = $this->parentRecord($request, $resource);

        if ($request->boolean('_inlay_action_form') && $request->query('_inlay_action') !== null) {
            return $this->tableActionForm($request, $resource, app(ActionRunner::class), $parent);
        }

        $route = $resource::page((string) $request->route('inlayPage'))->prefix($prefix);

        $props = $route->props(
            input: $request->query(),
            record: $request->route('record'),
            user: $request->user(),
            parent: $parent,
        );
        if (($props['form'] ?? null) instanceof Form) {
            $base = $resource::baseUrl($prefix, $parent);
            $props['form']->deferredViewEndpoint($request->getPathInfo());
            $operation = $route->pageClass()::operation();
            if ($operation === ResourceOperation::Create) {
                $props['form']->action($base)->method('post');
            } elseif ($operation === ResourceOperation::Edit) {
                $props['form']->action($base.'/'.rawurlencode((string) $request->route('record')))->method('patch');
            } elseif ($operation === ResourceOperation::ListRecords) {
                // A list page may host a create form (the same pattern used by
                // the API's simple resources). Its endpoint is the resource
                // store route for whichever prefix registered this page; a
                // page class must not hard-code the panel it was first mounted
                // under when the same resource is rendered standalone or by a
                // second renderer.
                $props['form']->action($base)->method('post');
            }

            if ($request->query->has('_inlay_view')) {
                $view = $request->query('_inlay_view');
                if (! is_string($view) || preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/', $view) !== 1) {
                    throw new \InvalidArgumentException('The deferred schema view name is invalid.');
                }

                return new JsonResponse($props['form']->resolveDeferredView($view, $request));
            }

            if ($request->query->has('_inlay_select_action')) {
                [$field, $action, $value] = $this->optionActionInput($request);

                return new JsonResponse([
                    'contract' => 'inlay.forms.select-option-form.v1',
                    'form' => $props['form']->selectOptionActionForm($field, $action, $value, $request, $validationFactory),
                ]);
            }

            if ($request->query->has('_inlay_options')) {
                $field = $request->query('_inlay_options');
                $search = $request->query('search', '');
                if (! is_string($field) || preg_match('/^[A-Za-z0-9_.*-]+$/', $field) !== 1) {
                    throw new \InvalidArgumentException('The remote select field is invalid.');
                }
                if (! is_string($search) || mb_strlen($search) > 200) {
                    throw new \InvalidArgumentException('The remote select search must be a string of at most 200 characters.');
                }

                return new JsonResponse([
                    'options' => $props['form']->searchSelectOptions($field, $search, $request),
                ]);
            }
            if ($request->query->has('_inlay_morph_options')) {
                $field = $request->query('_inlay_morph_options');
                $type = $request->query('type');
                $search = $request->query('search', '');
                if (! is_string($field) || preg_match('/^[A-Za-z0-9_.*-]+$/', $field) !== 1 || ! is_string($type) || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $type) !== 1 || ! is_string($search) || mb_strlen($search) > 200) {
                    throw new \InvalidArgumentException('The remote MorphTo search is invalid.');
                }

                return new JsonResponse(['options' => $props['form']->searchMorphToOptions($field, $type, $search)]);
            }
        }
        if (($props['table'] ?? null) instanceof Table) {
            $base = $resource::baseUrl($prefix, $parent);
            $props['table']->editableColumnUrl($base.'/_inlay/table-column');
            $props['table']->defaultLifecycleActionUrls($base);
        }

        return Inertia::render($route->pageClass()::component(), $props);
    }

    public function updateTableColumn(Request $request, ValidationFactory $validationFactory): JsonResponse
    {
        $resource = $this->resource((string) $request->route('inlayResource'));
        $recordKey = $request->input('record');
        $columnName = $request->input('column');
        if (
            (! is_string($recordKey) && ! is_int($recordKey))
            || ! is_string($columnName)
            || preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $columnName) !== 1
        ) {
            throw ValidationException::withMessages([
                'column' => 'A valid record and editable column are required.',
            ]);
        }

        $parent = $this->parentRecord($request, $resource);
        $record = $resource::resolveRecord((string) $recordKey, $parent);
        $resource::authorize(ResourceOperation::Edit, $record, $request->user());
        $table = $resource::configuredTable();

        return new JsonResponse($table->updateEditableColumn(
            $resource::scopedEloquentQuery($parent),
            $record->getKey(),
            $columnName,
            $request->input('state'),
            $request,
            $validationFactory,
            true,
        ));
    }

    public function store(
        Request $request,
        ValidationRunner $validator,
        ValidationFactory $validationFactory,
        ?TemporaryUploadManager $temporaryUploads = null,
        ?ActionRunner $actionRunner = null,
    ): RedirectResponse|JsonResponse
    {
        $resource = $this->resource((string) $request->route('inlayResource'));
        $parent = $this->parentRecord($request, $resource);
        if ($request->query('_inlay_action') !== null) {
            $runner = $actionRunner ?? app(ActionRunner::class);

            return $request->boolean('_inlay_action_form')
                ? $this->tableActionForm($request, $resource, $runner, $parent)
                : $this->runTableAction($request, $resource, $runner, $parent);
        }
        $resource::authorize(ResourceOperation::Create, user: $request->user());
        $form = $resource::configuredForm(ResourceOperation::Create);
        if ($temporaryUploads === null && $form->hasTemporaryUploads()) {
            $temporaryUploads = app(TemporaryUploadManager::class);
        }
        if ($request->query->has('_inlay_state_update')) {
            return $this->stateUpdateResponse($request, $form);
        }
        if ($request->query->has('_inlay_wizard')) {
            return $this->validateWizardStep($request, $form, $validator, $validationFactory);
        }
        if ($request->query->has('_inlay_rich_mention')) {
            return $this->mentionResponse($request, $form);
        }
        if ($request->query->has('_inlay_rich_block')) {
            return $this->validateRichEditorBlock($request, $form, $validationFactory);
        }
        if ($request->query->has('_inlay_rich_attachment')) {
            return $this->receiveRichEditorAttachment($request, $form);
        }
        if ($request->query->has('_inlay_upload')) {
            return $this->receiveTemporaryUpload($request, $form, $temporaryUploads ?? throw new \LogicException('Temporary uploads require '.TemporaryUploadManager::class.'.'));
        }
        if ($request->query->has('_inlay_select_action')) {
            [$field, $action, $value] = $this->optionActionInput($request);

            return new JsonResponse([
                'contract' => 'inlay.forms.select-option-result.v1',
                'option' => $resource::configuredForm(ResourceOperation::Create)
                    ->processSelectOptionAction($field, $action, $request->all(), $value, $request, $validationFactory),
            ]);
        }
        $input = $temporaryUploads === null ? $request->all() : $this->resolveTemporaryUploads($request, $form, $temporaryUploads, $validationFactory);
        $data = $resource::validateMutation($validator, $input, ResourceOperation::Create, user: $request->user(), request: $request);
        $data = $form->storeUploadedFiles($data, $request);
        $record = $resource::createRecord($data, $parent);
        $temporaryUploads?->consumeResolved($request);

        return $this->redirect($resource, ResourceOperation::Create, $record, $parent);
    }

    public function update(Request $request, ValidationRunner $validator, ValidationFactory $validationFactory, ?TemporaryUploadManager $temporaryUploads = null): RedirectResponse|JsonResponse
    {
        $resource = $this->resource((string) $request->route('inlayResource'));
        $parent = $this->parentRecord($request, $resource);
        $model = $resource::resolveRecord((string) $request->route('record'), $parent);
        $resource::authorize(ResourceOperation::Edit, $model, $request->user());
        $form = $resource::configuredForm(ResourceOperation::Edit, $model);
        if ($temporaryUploads === null && $form->hasTemporaryUploads()) {
            $temporaryUploads = app(TemporaryUploadManager::class);
        }
        if ($request->query->has('_inlay_state_update')) {
            return $this->stateUpdateResponse($request, $form);
        }
        if ($request->query->has('_inlay_wizard')) {
            return $this->validateWizardStep($request, $form, $validator, $validationFactory, $model);
        }
        if ($request->query->has('_inlay_rich_mention')) {
            return $this->mentionResponse($request, $form);
        }
        if ($request->query->has('_inlay_rich_block')) {
            return $this->validateRichEditorBlock($request, $form, $validationFactory);
        }
        if ($request->query->has('_inlay_rich_attachment')) {
            return $this->receiveRichEditorAttachment($request, $form);
        }
        if ($request->query->has('_inlay_upload')) {
            return $this->receiveTemporaryUpload($request, $form, $temporaryUploads ?? throw new \LogicException('Temporary uploads require '.TemporaryUploadManager::class.'.'));
        }
        if ($request->query->has('_inlay_select_action')) {
            [$field, $action, $value] = $this->optionActionInput($request);

            return new JsonResponse([
                'contract' => 'inlay.forms.select-option-result.v1',
                'option' => $resource::configuredForm(ResourceOperation::Edit, $model)
                    ->processSelectOptionAction($field, $action, $request->all(), $value, $request, $validationFactory),
            ]);
        }
        $input = $temporaryUploads === null ? $request->all() : $this->resolveTemporaryUploads($request, $form, $temporaryUploads, $validationFactory);
        $data = $resource::validateMutation($validator, $input, ResourceOperation::Edit, $model, $request->user(), $request);
        $data = $form->storeUploadedFiles($data, $request);
        $model = $resource::updateRecord($model, $data);
        $temporaryUploads?->consumeResolved($request);

        return $this->redirect($resource, ResourceOperation::Edit, $model, $parent);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $resource = $this->resource((string) $request->route('inlayResource'));
        $parent = $this->parentRecord($request, $resource);
        $model = $resource::resolveRecord((string) $request->route('record'), $parent);
        $resource::authorize(ResourceOperation::Delete, $model, $request->user());
        $resource::deleteRecord($model);

        return $this->redirect($resource, ResourceOperation::Delete, $model, $parent);
    }

    /**
     * @param class-string<Resource> $resource
     */
    private function runTableAction(Request $request, string $resource, ActionRunner $runner, ?Model $parent = null): JsonResponse
    {
        [$action, $resolved] = $this->resolveTableAction($request, $resource, $parent);

        return new JsonResponse($runner->run($action, $request, $this->actionData($request), $resolved));
    }

    /**
     * Mount a table-hosted action form, or serve one of its sub-transports:
     * live state updates, uploads, option actions, and deferred views.
     *
     * @param  class-string<resource>  $resource
     */
    private function tableActionForm(Request $request, string $resource, ActionRunner $runner, ?Model $parent = null): JsonResponse
    {
        [$action, $resolved] = $this->resolveTableAction($request, $resource, $parent);
        $data = $this->actionData($request);

        if ($runner->handlesFormSubRequest($request)) {
            $subRequest = $runner->formSubRequest($action, $request, $data, $resolved);

            return new JsonResponse($subRequest['payload'], $subRequest['status']);
        }

        return new JsonResponse($runner->mountForm($action, $request, $data, $resolved));
    }

    /**
     * @param  class-string<resource>  $resource
     * @return array{Action, \Illuminate\Support\Collection<int, Model>}
     */
    private function resolveTableAction(Request $request, string $resource, ?Model $parent): array
    {
        $actionName = $request->query('_inlay_action');
        $scope = $request->query('_inlay_action_scope');
        $record = $request->query('record');
        $records = $request->input('records', []);
        $selection = $request->input('selection');
        if (
            ! is_string($actionName)
            || ! is_string($scope)
            || ! in_array($scope, ['page', 'row', 'bulk'], true)
            || ! is_array($records)
            || ($selection !== null && ! is_array($selection))
        ) {
            throw ValidationException::withMessages([
                'action' => 'Valid resource table action metadata is required.',
            ]);
        }

        if ($scope === 'page') {
            return $this->resolvePageAction($request, $resource, $parent, $actionName);
        }

        $resource::authorize(ResourceOperation::ListRecords, user: $request->user());
        $table = $resource::configuredTable();
        $table->defaultLifecycleActionUrls($resource::baseUrl((string) $request->route('inlayPrefix', ''), $parent));
        $action = $table->lifecycleAction($actionName, $scope);
        $query = $resource::getActionEloquentQuery($parent);
        $resolved = match ($scope) {
            'row' => (is_string($record) || is_int($record))
                ? $query->whereKey($record)->get()
                : collect(),
            'bulk' => $table->resolveSelectionForAction(
                $action,
                $query,
                $selection ?? ['mode' => 'page', 'records' => array_values($records)],
                is_array($selection) && isset($selection['query']) && is_array($selection['query'])
                    ? $selection['query']
                    : $request->query(),
            ),
        };
        if ($scope === 'row' && $resolved->count() !== 1) {
            throw ValidationException::withMessages([
                'record' => 'The selected record is unavailable in the authorized resource query.',
            ]);
        }
        $table->validateLifecycleActionRecords($action, $scope, $resolved);

        return [$action, $resolved];
    }

    /**
     * Resolve a page header action without routing page-specific actions
     * through the table's row/bulk selection rules.
     *
     * @param  class-string<resource>  $resource
     * @return array{Action, \Illuminate\Support\Collection<int, Model>}
     */
    private function resolvePageAction(Request $request, string $resource, ?Model $parent, string $actionName): array
    {
        $pageName = $request->query('_inlay_page');
        if (! is_string($pageName) || preg_match('/^[a-z][a-z0-9_-]*$/', $pageName) !== 1) {
            throw ValidationException::withMessages([
                'action' => 'A valid resource page action name is required.',
            ]);
        }

        $page = $resource::pages()[$pageName] ?? throw ValidationException::withMessages([
            'action' => 'The requested resource page action is unavailable.',
        ]);
        $operation = $page->operation();
        $records = collect();

        if ($operation->requiresRecord()) {
            $recordKey = $request->query('record');
            if (! is_string($recordKey) && ! is_int($recordKey)) {
                throw ValidationException::withMessages([
                    'record' => 'A record page action requires a record key.',
                ]);
            }

            $record = $resource::resolveRecord($recordKey, $parent);
            $resource::authorize($operation, $record, $request->user());
            $records = collect([$record]);
        } else {
            $resource::authorize($operation, user: $request->user());
        }

        return [$page->pageInstance()->headerAction($actionName), $records];
    }

    /** @return array<string, mixed> */
    private function actionData(Request $request): array
    {
        return $request->except([
            '_inlay_action',
            '_inlay_action_form',
            '_inlay_action_scope',
            '_inlay_page',
            'record',
            'records',
            'selection',
            'table',
        ]);
    }

    public function storeRelation(Request $request, ValidationRunner $validator, ?ActionRunner $actionRunner = null): JsonResponse
    {
        $manager = $this->relationManager($request, ResourceOperation::Edit);
        if ($request->query('_inlay_action') !== null) {
            $runner = $actionRunner ?? app(ActionRunner::class);

            return $request->boolean('_inlay_action_form')
                ? $this->relationTableActionForm($request, $manager, $runner)
                : $this->runRelationTableAction($request, $manager, $runner);
        }
        $data = $manager->validateMutation(
            $validator,
            $request->all(),
            RelationOperation::Create,
            request: $request,
        );
        $record = $manager->createRecord($data);

        return new JsonResponse([
            'contract' => 'inlay.resources.relation-mutation.v1',
            'operation' => 'create',
            'record' => $record->toArray(),
        ], 201);
    }

    private function runRelationTableAction(Request $request, RelationManager $manager, ActionRunner $runner): JsonResponse
    {
        [$action, $resolved] = $this->resolveRelationTableAction($request, $manager);

        return new JsonResponse($runner->run($action, $request, $this->actionData($request), $resolved));
    }

    /**
     * Serve relation-hosted action forms and their sub-transports. Only
     * action-form traffic reaches this GET endpoint.
     */
    public function relationActionForm(Request $request, ?ActionRunner $actionRunner = null): JsonResponse
    {
        if (! $request->boolean('_inlay_action_form') || $request->query('_inlay_action') === null) {
            throw ValidationException::withMessages([
                'action' => 'Valid relation action form metadata is required.',
            ]);
        }

        return $this->relationTableActionForm(
            $request,
            $this->relationManager($request, ResourceOperation::Edit),
            $actionRunner ?? app(ActionRunner::class),
        );
    }

    private function relationTableActionForm(Request $request, RelationManager $manager, ActionRunner $runner): JsonResponse
    {
        [$action, $resolved] = $this->resolveRelationTableAction($request, $manager);
        $data = $this->actionData($request);

        if ($runner->handlesFormSubRequest($request)) {
            $subRequest = $runner->formSubRequest($action, $request, $data, $resolved);

            return new JsonResponse($subRequest['payload'], $subRequest['status']);
        }

        return new JsonResponse($runner->mountForm($action, $request, $data, $resolved));
    }

    /** @return array{Action, \Illuminate\Support\Collection<int, Model>} */
    private function resolveRelationTableAction(Request $request, RelationManager $manager): array
    {
        $actionName = $request->query('_inlay_action');
        $scope = $request->query('_inlay_action_scope');
        $record = $request->query('record');
        $records = $request->input('records', []);
        $selection = $request->input('selection');
        if (
            ! is_string($actionName)
            || ! is_string($scope)
            || ! in_array($scope, ['row', 'bulk'], true)
            || ! is_array($records)
            || ($selection !== null && ! is_array($selection))
        ) {
            throw ValidationException::withMessages([
                'action' => 'Valid relation table action metadata is required.',
            ]);
        }

        $manager->authorize(RelationOperation::ViewAny);
        $table = $manager->baseUrl($request->getPathInfo())->configuredTable();
        $action = $table->lifecycleAction($actionName, $scope);
        $query = $manager->actionTableQuery();
        $resolved = match ($scope) {
            'row' => (is_string($record) || is_int($record))
                ? $query->whereKey($record)->get()
                : collect(),
            'bulk' => $table->resolveSelectionForAction(
                $action,
                $query,
                $selection ?? ['mode' => 'page', 'records' => array_values($records)],
                is_array($selection) && isset($selection['query']) && is_array($selection['query'])
                    ? $selection['query']
                    : $request->query(),
            ),
        };
        if ($scope === 'row' && $resolved->count() !== 1) {
            throw ValidationException::withMessages([
                'record' => 'The selected record is unavailable through the authorized relationship.',
            ]);
        }
        $table->validateLifecycleActionRecords($action, $scope, $resolved);

        return [$action, $resolved];
    }

    public function updateRelation(Request $request, ValidationRunner $validator): JsonResponse
    {
        $manager = $this->relationManager($request, ResourceOperation::Edit);
        $record = $manager->resolveRecord((string) $request->route('related'));
        $data = $manager->validateMutation(
            $validator,
            $request->all(),
            RelationOperation::Edit,
            $record,
            $request,
        );
        $record = $manager->updateRecord($record, $data);

        return new JsonResponse([
            'contract' => 'inlay.resources.relation-mutation.v1',
            'operation' => 'edit',
            'record' => $record->toArray(),
        ]);
    }

    public function destroyRelation(Request $request): JsonResponse
    {
        $manager = $this->relationManager($request, ResourceOperation::Edit);
        $record = $manager->resolveRecord((string) $request->route('related'));
        $manager->deleteRecord($record);

        return new JsonResponse(null, 204);
    }

    public function attachRelation(
        Request $request,
        ValidationRunner $validator,
        ValidationFactory $validationFactory,
    ): JsonResponse
    {
        $manager = $this->relationManager($request, ResourceOperation::Edit);
        $data = $manager->validateAttachMutation(
            $validator,
            $validationFactory,
            [
                ...$request->all(),
                'record' => (string) $request->route('related'),
            ],
            $request,
        );
        $record = $manager->attachRecord($data['record'], $data['pivot']);

        return new JsonResponse([
            'contract' => 'inlay.resources.relation-mutation.v1',
            'operation' => 'attach',
            'record' => $record->toArray(),
        ]);
    }

    public function relationAttachOptions(Request $request): JsonResponse
    {
        $field = $request->query('_inlay_options');
        $search = $request->query('search', '');
        if ($field !== 'record' || ! is_string($search) || mb_strlen($search) > 200) {
            throw new \InvalidArgumentException('The relation attach option search is invalid.');
        }
        $manager = $this->relationManager($request, ResourceOperation::Edit);

        return new JsonResponse([
            'options' => $manager->configuredAttachForm()
                ->searchSelectOptions('record', $search, $request),
        ]);
    }

    public function detachRelation(Request $request): JsonResponse
    {
        $this->relationManager($request, ResourceOperation::Edit)
            ->detachRecord((string) $request->route('related'));

        return new JsonResponse(null, 204);
    }

    public function associateRelation(Request $request): JsonResponse
    {
        $record = $this->relationManager($request, ResourceOperation::Edit)
            ->associateRecord((string) $request->route('related'));

        return new JsonResponse([
            'contract' => 'inlay.resources.relation-mutation.v1',
            'operation' => 'associate',
            'record' => $record->toArray(),
        ]);
    }

    public function relationAssociateOptions(Request $request): JsonResponse
    {
        $field = $request->query('_inlay_options');
        $search = $request->query('search', '');
        if ($field !== 'record' || ! is_string($search) || mb_strlen($search) > 200) {
            throw new \InvalidArgumentException('The relation associate option search is invalid.');
        }
        $manager = $this->relationManager($request, ResourceOperation::Edit);

        return new JsonResponse([
            'options' => $manager->configuredAssociateForm()
                ->searchSelectOptions('record', $search, $request),
        ]);
    }

    public function dissociateRelation(Request $request): JsonResponse
    {
        $this->relationManager($request, ResourceOperation::Edit)
            ->dissociateRecord((string) $request->route('related'));

        return new JsonResponse(null, 204);
    }

    /** @param class-string<resource> $resource */
    private function redirect(string $resource, ResourceOperation $operation, Model $record, ?Model $parent = null): RedirectResponse
    {
        $prefix = (string) request()->route('inlayPrefix', '');
        $declared = $resource::resolvedRedirectUrlAfter($operation, $record, $prefix, $parent);

        return redirect($declared ?? $resource::baseUrl($prefix, $parent))
            ->with('success', $resource::successMessage($operation, $record));
    }

    private function stateUpdateResponse(Request $request, Form $form): JsonResponse
    {
        $path = $request->input('path');
        $data = $request->input('data');
        $revision = $request->input('revision');
        if (! is_string($path) || ! is_array($data) || ! is_int($revision)) {
            throw new \InvalidArgumentException('The state update request is invalid.');
        }

        return new JsonResponse($form->processStateUpdate(
            $path,
            $request->input('value'),
            $request->input('old'),
            $data,
            $revision,
            $request,
        ));
    }

    /** @return class-string<resource> */
    private function resource(string $resource): string
    {
        if (! is_subclass_of($resource, Resource::class)) {
            throw new \InvalidArgumentException("Invalid Inlay resource [{$resource}].");
        }

        return $resource;
    }

    /**
     * Resolve and authorize the parent record of a nested resource, so nested
     * URLs can only reach records the visitor may view through that parent.
     *
     * @param  class-string<resource>  $resource
     */
    private function parentRecord(Request $request, string $resource): ?Model
    {
        $registration = $resource::parentResource();
        if ($registration === null) {
            return null;
        }

        $key = $request->route($registration->parameterName());
        if (! is_string($key) && ! is_int($key)) {
            throw new \InvalidArgumentException('The nested resource parent record is missing.');
        }

        $parentResource = $registration->resource();
        $parent = $parentResource::resolveRecord($key);
        $parentResource::authorize(ResourceOperation::View, $parent, $request->user());

        return $parent;
    }

    private function relationManager(Request $request, ResourceOperation $ownerOperation): RelationManager
    {
        $resource = $this->resource((string) $request->route('inlayResource'));
        $owner = $resource::resolveRecord((string) $request->route('record'), $this->parentRecord($request, $resource));
        $resource::authorize($ownerOperation, $owner, $request->user());

        return $resource::relation(
            (string) $request->route('relation'),
            $owner,
            $request->user(),
        );
    }

    /** @return array{string, 'create'|'edit', string|int|null} */
    private function optionActionInput(Request $request): array
    {
        $field = $request->query('_inlay_field');
        $action = $request->query('_inlay_select_action');
        $value = $request->query('value');
        if (! is_string($field) || preg_match('/^[A-Za-z0-9_.*-]+$/', $field) !== 1) {
            throw new \InvalidArgumentException('The select option action field is invalid.');
        }
        if (! is_string($action) || ! in_array($action, ['create', 'edit'], true)) {
            throw new \InvalidArgumentException('The select option action is invalid.');
        }
        if ($action === 'edit' && (! is_string($value) || $value === '')) {
            throw new \InvalidArgumentException('Edit option actions require a selected value.');
        }

        return [$field, $action, $value];
    }

    private function receiveTemporaryUpload(Request $request, Form $form, TemporaryUploadManager $manager): JsonResponse
    {
        $field = $request->query('_inlay_upload');
        $file = $request->file('file');
        if (! is_string($field) || preg_match('/^[A-Za-z0-9_.*-]+$/', $field) !== 1 || ! $file instanceof UploadedFile) {
            throw new \InvalidArgumentException('The temporary resource upload request is invalid.');
        }
        try {
            $upload = $manager->receive($form->temporaryUploadField($field), $field, $file, $request);
        } catch (UploadRejected $exception) {
            return new JsonResponse(['message' => $exception->validationMessage, 'errors' => [$exception->field => [$exception->validationMessage]]], 422);
        }

        return new JsonResponse(['contract' => 'inlay.forms.temporary-upload.v1', 'upload' => $upload], 201);
    }

    private function receiveRichEditorAttachment(Request $request, Form $form): JsonResponse
    {
        $field = $request->query('_inlay_rich_attachment');
        $file = $request->file('file');
        if (! is_string($field) || preg_match('/^[A-Za-z0-9_.*-]+$/', $field) !== 1 || ! $file instanceof UploadedFile) {
            throw new \InvalidArgumentException('The rich editor resource attachment request is invalid.');
        }
        try {
            $attachment = $form->richEditorAttachmentField($field)->storeFileAttachment($file, $request);
        } catch (UploadRejected $exception) {
            return new JsonResponse(['message' => $exception->validationMessage, 'errors' => [$exception->field => [$exception->validationMessage]]], 422);
        }

        return new JsonResponse(['contract' => 'inlay.forms.rich-editor-attachment.v1', 'attachment' => $attachment], 201);
    }

    private function validateRichEditorBlock(Request $request, Form $form, ValidationFactory $validationFactory): JsonResponse
    {
        $field = $request->query('_inlay_rich_block');
        $block = $request->query('block');
        if (! is_string($field) || preg_match('/^[A-Za-z0-9_.*-]+$/', $field) !== 1 || ! is_string($block) || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $block) !== 1) {
            throw new \InvalidArgumentException('The rich editor resource custom block request is invalid.');
        }
        $blockForm = $form->richEditorField($field)->customBlockForm($block);

        return new JsonResponse([
            'contract' => 'inlay.forms.rich-editor-block.v1',
            'config' => $blockForm->validateWithFactory($validationFactory, $request->all(), $request),
        ]);
    }

    private function validateWizardStep(
        Request $request,
        Form $form,
        ValidationRunner $validationRunner,
        ValidationFactory $validationFactory,
        ?Model $record = null,
    ): JsonResponse {
        $wizard = $request->query('_inlay_wizard');
        $step = $request->query('step');
        if (! is_string($wizard) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $wizard) !== 1 || ! is_string($step) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $step) !== 1) {
            throw new \InvalidArgumentException('The wizard step validation request is invalid.');
        }

        $haltMessage = $form->validateWizardStep(
            $validationRunner,
            $validationFactory,
            $wizard,
            $step,
            $request->all(),
            $record,
            $request->user(),
            ['request' => $request, 'resource' => $request->route('inlayResource')],
        );
        if ($haltMessage !== null) {
            return new JsonResponse([
                'contract' => 'inlay.forms.wizard-step-validation.v1',
                'valid' => false,
                'halted' => true,
                'message' => $haltMessage,
            ], 409);
        }

        return new JsonResponse(['contract' => 'inlay.forms.wizard-step-validation.v1', 'valid' => true]);
    }

    private function mentionResponse(Request $request, Form $form): JsonResponse
    {
        $field = $request->query('_inlay_rich_mention');
        $trigger = $request->query('trigger');
        if (! is_string($field) || preg_match('/^[A-Za-z0-9_.*-]+$/', $field) !== 1 || ! is_string($trigger) || mb_strlen($trigger) !== 1) {
            throw new \InvalidArgumentException('The rich editor mention request is invalid.');
        }
        $provider = $form->richEditorField($field)->mentionProvider($trigger);
        $ids = $request->input('ids');
        if ($ids !== null) {
            if (! is_array($ids) || array_filter($ids, static fn (mixed $id): bool => ! is_string($id) && ! is_int($id)) !== []) {
                throw new \InvalidArgumentException('Mention label IDs must be an array of strings or integers.');
            }

            return new JsonResponse(['contract' => 'inlay.forms.rich-editor-mentions.v1', 'labels' => $provider->labels($ids, $request)]);
        }
        $search = $request->input('search', '');
        if (! is_string($search)) {
            throw new \InvalidArgumentException('Mention search must be a string.');
        }

        return new JsonResponse(['contract' => 'inlay.forms.rich-editor-mentions.v1', 'options' => $provider->search($search, $request)]);
    }

    /** @return array<string, mixed> */
    private function resolveTemporaryUploads(Request $request, Form $form, TemporaryUploadManager $manager, ValidationFactory $validationFactory): array
    {
        try {
            return $form->resolveTemporaryUploads($request->all(), $request, $manager);
        } catch (UploadRejected $exception) {
            $validator = $validationFactory->make([], []);
            $validator->errors()->add($exception->field, $exception->validationMessage);

            throw new ValidationException($validator);
        }
    }
}

<?php

declare(strict_types=1);

namespace Inlay\Resources\Routing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;
use Inlay\Resources\Http\Controllers\ResourceController;
use Inlay\Resources\Http\Middleware\ResolveTenant;
use Inlay\Resources\Resource;
use InvalidArgumentException;

final class ResourceRegistrar
{
    public function __construct(private readonly Router $router) {}

    /**
     * @param  list<class-string<resource>>  $resources
     * @param  array{defaults?: array<string, string>, middleware?: list<string>, mutationMiddleware?: list<string>, name?: string, prefix?: string, tenant?: array{model: class-string<Model>, parameter?: string, routeKey?: string}}  $options
     */
    public function routes(array $resources, array $options = []): self
    {
        $middleware = $options['middleware'] ?? [];
        $namePrefix = trim($options['name'] ?? 'inlay.', '.').'.';
        $uriPrefix = trim($options['prefix'] ?? '', '/');
        $defaults = $options['defaults'] ?? [];

        // A tenant segment leads every URL and every route resolves it before
        // the controller runs, so no resource query can precede the tenant.
        $tenant = $options['tenant'] ?? null;
        if ($tenant !== null) {
            if (! is_array($tenant) || ! isset($tenant['model']) || ! is_subclass_of($tenant['model'], Model::class)) {
                throw new InvalidArgumentException('Tenant routing requires a tenant model.');
            }

            $parameter = trim((string) ($tenant['parameter'] ?? 'tenant'), '{}');
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $parameter) !== 1) {
                throw new InvalidArgumentException('A tenant route parameter must be a valid identifier.');
            }

            $uriPrefix = trim('{'.$parameter.'}/'.$uriPrefix, '/');
            $middleware = [ResolveTenant::class, ...$middleware];
            $defaults = [
                ...$defaults,
                'inlayTenantModel' => $tenant['model'],
                'inlayTenantParameter' => $parameter,
                'inlayTenantRouteKey' => $tenant['routeKey'] ?? (new $tenant['model'])->getRouteKeyName(),
            ];
        }

        $mutationMiddleware = [...$middleware, ...($options['mutationMiddleware'] ?? [])];

        foreach ($resources as $resource) {
            if (! is_string($resource) || ! is_subclass_of($resource, Resource::class)) {
                throw new InvalidArgumentException('Registered resources must extend '.Resource::class.'.');
            }

            $base = trim($uriPrefix.'/'.$resource::routeUri(), '/');
            $routeKey = $resource::routeKey();
            foreach ($resource::pages() as $name => $page) {
                $uri = $base.($page->path() === '/' ? '' : $page->path());
                $route = $this->router->get($uri, [ResourceController::class, 'page'])
                    ->defaults('inlayResource', $resource)
                    ->defaults('inlayPage', $name)
                    ->defaults('inlayPrefix', $uriPrefix)
                    ->name($namePrefix.$routeKey.'.'.$name);
                foreach ($defaults as $key => $value) {
                    $route->defaults($key, $value);
                }
                $route->middleware([...$middleware, ...$page->middlewareList()]);
            }

            $store = $this->router->post($base, [ResourceController::class, 'store'])
                ->defaults('inlayResource', $resource)
                ->defaults('inlayPrefix', $uriPrefix)
                ->middleware($mutationMiddleware)
                ->name($namePrefix.$routeKey.'.store');
            $columnUpdate = $this->router->patch($base.'/_inlay/table-column', [ResourceController::class, 'updateTableColumn'])
                ->defaults('inlayResource', $resource)
                ->defaults('inlayPrefix', $uriPrefix)
                ->middleware($mutationMiddleware)
                ->name($namePrefix.$routeKey.'.table-column.update');
            $relationStore = $this->router->post($base.'/{record}/_inlay/relations/{relation}', [ResourceController::class, 'storeRelation'])
                ->defaults('inlayResource', $resource)
                ->defaults('inlayPrefix', $uriPrefix)
                ->middleware($mutationMiddleware)
                ->name($namePrefix.$routeKey.'.relations.store');
            $relationActionForm = $this->router->get($base.'/{record}/_inlay/relations/{relation}', [ResourceController::class, 'relationActionForm'])
                ->defaults('inlayResource', $resource)
                ->defaults('inlayPrefix', $uriPrefix)
                ->middleware($middleware)
                ->name($namePrefix.$routeKey.'.relations.action-form');
            $relationAttachOptions = $this->router->get($base.'/{record}/_inlay/relations/{relation}/attach-options', [ResourceController::class, 'relationAttachOptions'])
                ->defaults('inlayResource', $resource)
                ->defaults('inlayPrefix', $uriPrefix)
                ->middleware($middleware)
                ->name($namePrefix.$routeKey.'.relations.attach-options');
            $relationAssociateOptions = $this->router->get($base.'/{record}/_inlay/relations/{relation}/associate-options', [ResourceController::class, 'relationAssociateOptions'])
                ->defaults('inlayResource', $resource)
                ->defaults('inlayPrefix', $uriPrefix)
                ->middleware($middleware)
                ->name($namePrefix.$routeKey.'.relations.associate-options');
            $relationUpdate = $this->router->patch($base.'/{record}/_inlay/relations/{relation}/{related}', [ResourceController::class, 'updateRelation'])
                ->defaults('inlayResource', $resource)
                ->defaults('inlayPrefix', $uriPrefix)
                ->middleware($mutationMiddleware)
                ->name($namePrefix.$routeKey.'.relations.update');
            $relationDestroy = $this->router->delete($base.'/{record}/_inlay/relations/{relation}/{related}', [ResourceController::class, 'destroyRelation'])
                ->defaults('inlayResource', $resource)
                ->defaults('inlayPrefix', $uriPrefix)
                ->middleware($mutationMiddleware)
                ->name($namePrefix.$routeKey.'.relations.destroy');
            $relationAttach = $this->router->post($base.'/{record}/_inlay/relations/{relation}/{related}/attach', [ResourceController::class, 'attachRelation'])
                ->defaults('inlayResource', $resource)
                ->defaults('inlayPrefix', $uriPrefix)
                ->middleware($mutationMiddleware)
                ->name($namePrefix.$routeKey.'.relations.attach');
            $relationDetach = $this->router->delete($base.'/{record}/_inlay/relations/{relation}/{related}/detach', [ResourceController::class, 'detachRelation'])
                ->defaults('inlayResource', $resource)
                ->defaults('inlayPrefix', $uriPrefix)
                ->middleware($mutationMiddleware)
                ->name($namePrefix.$routeKey.'.relations.detach');
            $relationAssociate = $this->router->post($base.'/{record}/_inlay/relations/{relation}/{related}/associate', [ResourceController::class, 'associateRelation'])
                ->defaults('inlayResource', $resource)
                ->defaults('inlayPrefix', $uriPrefix)
                ->middleware($mutationMiddleware)
                ->name($namePrefix.$routeKey.'.relations.associate');
            $relationDissociate = $this->router->delete($base.'/{record}/_inlay/relations/{relation}/{related}/dissociate', [ResourceController::class, 'dissociateRelation'])
                ->defaults('inlayResource', $resource)
                ->defaults('inlayPrefix', $uriPrefix)
                ->middleware($mutationMiddleware)
                ->name($namePrefix.$routeKey.'.relations.dissociate');
            $update = $this->router->patch($base.'/{record}', [ResourceController::class, 'update'])
                ->defaults('inlayResource', $resource)
                ->defaults('inlayPrefix', $uriPrefix)
                ->middleware($mutationMiddleware)
                ->name($namePrefix.$routeKey.'.update');
            $destroy = $this->router->delete($base.'/{record}', [ResourceController::class, 'destroy'])
                ->defaults('inlayResource', $resource)
                ->defaults('inlayPrefix', $uriPrefix)
                ->middleware($mutationMiddleware)
                ->name($namePrefix.$routeKey.'.destroy');
            foreach ([
                $store,
                $columnUpdate,
                $relationStore,
                $relationActionForm,
                $relationAttachOptions,
                $relationAssociateOptions,
                $relationUpdate,
                $relationDestroy,
                $relationAttach,
                $relationDetach,
                $relationAssociate,
                $relationDissociate,
                $update,
                $destroy,
            ] as $route) {
                foreach ($defaults as $key => $value) {
                    $route->defaults($key, $value);
                }
            }
        }

        return $this;
    }
}

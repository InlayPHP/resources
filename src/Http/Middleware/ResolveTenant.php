<?php

declare(strict_types=1);

namespace Inlay\Resources\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inlay\Resources\Contracts\TenantAccess;
use Inlay\Resources\Tenancy;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolve the tenant a request addresses before any resource query runs.
 *
 * The tenant comes from the URL, but membership is decided by the tenant
 * model, so a visitor cannot reach another tenant by editing the address bar.
 */
final class ResolveTenant
{
    public function handle(Request $request, Closure $next): mixed
    {
        $route = $request->route();
        $model = $route?->defaults['inlayTenantModel'] ?? null;
        $parameter = $route?->defaults['inlayTenantParameter'] ?? 'tenant';

        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            throw new \LogicException('Tenant routes require a tenant model.');
        }

        $key = $request->route($parameter);
        if ($key instanceof Model) {
            $tenant = $key;
        } else {
            $instance = new $model;
            $routeKey = $route?->defaults['inlayTenantRouteKey'] ?? $instance->getRouteKeyName();
            $tenant = $instance->newQuery()->where($routeKey, $key)->first();
        }

        if (! $tenant instanceof Model) {
            throw new NotFoundHttpException('Tenant not found.');
        }

        if ($tenant instanceof TenantAccess && ! $tenant->canAccessTenant($request->user())) {
            throw new AccessDeniedHttpException('This tenant is not available to you.');
        }

        Tenancy::resolve()->set($tenant);

        try {
            return $next($request);
        } finally {
            Tenancy::resolve()->forget();
        }
    }
}

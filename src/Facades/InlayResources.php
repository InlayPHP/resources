<?php

declare(strict_types=1);

namespace Inlay\Resources\Facades;

use Illuminate\Support\Facades\Facade;
use Inlay\Resources\Routing\ResourceRegistrar;

/** @method static ResourceRegistrar routes(array $resources, array $options = []) */
final class InlayResources extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ResourceRegistrar::class;
    }
}

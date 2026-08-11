<?php

declare(strict_types=1);

namespace Inlay\Resources;

use Illuminate\Support\ServiceProvider;
use Inlay\Resources\Console\MakeResourceCommand;
use Inlay\Resources\Console\MakeRelationManagerCommand;
use Inlay\Resources\Routing\ResourceRegistrar;

final class ResourcesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ResourceRegistrar::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([MakeResourceCommand::class, MakeRelationManagerCommand::class]);
        }
    }
}

<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Inlay\Resources\Resource;
use Inlay\Resources\Testing\ResourceTester;
use Inlay\Actions\ActionRunner;
use Inlay\Validation\ValidationRunner;

if (! function_exists('inlay')) {
    /**
     * @param class-string<Resource> $resource
     */
    function inlay(
        string $resource,
        mixed $user = null,
        ?ValidationFactory $validationFactory = null,
        ?ValidationRunner $validationRunner = null,
        ?ActionRunner $actionRunner = null,
    ): ResourceTester {
        return ResourceTester::make($resource, $user, $validationFactory, $validationRunner, $actionRunner);
    }
}

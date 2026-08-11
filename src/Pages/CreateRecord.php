<?php

declare(strict_types=1);

namespace Inlay\Resources\Pages;

use Illuminate\Database\Eloquent\Model;
use Inlay\Resources\ResourceOperation;

abstract class CreateRecord extends ResourcePage
{
    public static function operation(): ResourceOperation
    {
        return ResourceOperation::Create;
    }

    protected function content(string $resource, array $input, ?Model $record): array
    {
        return ['form' => $resource::configuredForm(ResourceOperation::Create)];
    }
}

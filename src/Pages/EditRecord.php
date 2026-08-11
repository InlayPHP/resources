<?php

declare(strict_types=1);

namespace Inlay\Resources\Pages;

use Illuminate\Database\Eloquent\Model;
use Inlay\Resources\ResourceOperation;

abstract class EditRecord extends ResourcePage
{
    public static function operation(): ResourceOperation
    {
        return ResourceOperation::Edit;
    }

    protected function content(string $resource, array $input, ?Model $record): array
    {
        if ($record === null) {
            throw new \LogicException('Edit pages require a resolved record.');
        }

        return [
            'record' => $record->toArray(),
            'form' => $resource::configuredForm(ResourceOperation::Edit, $record),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Inlay\Resources\Pages;

use Illuminate\Database\Eloquent\Model;
use Inlay\Infolists\Infolist;
use Inlay\Resources\ResourceOperation;

abstract class ViewRecord extends ResourcePage
{
    public static function operation(): ResourceOperation
    {
        return ResourceOperation::View;
    }

    protected function content(string $resource, array $input, ?Model $record): array
    {
        if ($record === null) {
            throw new \LogicException('View pages require a resolved record.');
        }

        $infolist = Infolist::make($resource::slug().'.view');
        if ($resource::infolist($infolist) !== $infolist) {
            throw new \LogicException('Resource infolist configuration must return the supplied fresh infolist instance.');
        }

        return [
            'record' => $record->toArray(),
            'infolist' => $infolist->record($record)->data($record->toArray()),
        ];
    }
}

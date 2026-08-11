<?php

declare(strict_types=1);

namespace Inlay\Resources\Pages;

use Illuminate\Database\Eloquent\Model;
use Inlay\Resources\RelationManager;
use Inlay\Resources\ResourceOperation;

/**
 * A page dedicated to one of a record's relation managers.
 *
 * A record with many relations does not have to show them all on the edit
 * page. This gives one of them its own URL, using the same relation manager
 * the record page would have rendered, with the same authorization.
 */
abstract class ManageRelatedRecords extends ResourcePage
{
    /** @var class-string<RelationManager> */
    protected static string $relationManager;

    public static function operation(): ResourceOperation
    {
        return ResourceOperation::View;
    }

    /** @return class-string<RelationManager> */
    final public static function relationManager(): string
    {
        $manager = static::$relationManager ?? null;
        if (! is_string($manager) || ! is_subclass_of($manager, RelationManager::class)) {
            throw new \LogicException('A managed relation page must declare a valid relation manager in static $relationManager.');
        }

        return $manager;
    }

    protected function relationManagers(string $resource): array
    {
        return [static::relationManager()];
    }

    protected function content(string $resource, array $input, ?Model $record): array
    {
        if ($record === null) {
            throw new \LogicException('Managed relation pages require a resolved record.');
        }

        $manager = static::relationManager();
        if (! in_array($manager, $resource::relations(), true)) {
            throw new \LogicException("Relation manager [{$manager}] does not belong to resource [{$resource}].");
        }

        return ['record' => $record->toArray()];
    }
}

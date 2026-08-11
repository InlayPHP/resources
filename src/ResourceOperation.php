<?php

declare(strict_types=1);

namespace Inlay\Resources;

enum ResourceOperation: string
{
    case ListRecords = 'list';
    case Create = 'create';
    case View = 'view';
    case Edit = 'edit';
    case Delete = 'delete';
    case DeleteAny = 'deleteAny';
    case Restore = 'restore';
    case RestoreAny = 'restoreAny';
    case ForceDelete = 'forceDelete';
    case ForceDeleteAny = 'forceDeleteAny';

    public function policyAbility(): string
    {
        return match ($this) {
            self::ListRecords => 'viewAny',
            self::Create => 'create',
            self::View => 'view',
            self::Edit => 'update',
            self::Delete => 'delete',
            self::DeleteAny => 'deleteAny',
            self::Restore => 'restore',
            self::RestoreAny => 'restoreAny',
            self::ForceDelete => 'forceDelete',
            self::ForceDeleteAny => 'forceDeleteAny',
        };
    }

    public function requiresRecord(): bool
    {
        return in_array($this, [self::View, self::Edit, self::Delete, self::Restore, self::ForceDelete], true);
    }
}

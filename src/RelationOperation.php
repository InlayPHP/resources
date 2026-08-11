<?php

declare(strict_types=1);

namespace Inlay\Resources;

enum RelationOperation: string
{
    case ViewAny = 'viewAny';
    case Create = 'create';
    case Edit = 'edit';
    case Delete = 'delete';
    case DeleteAny = 'deleteAny';
    case Restore = 'restore';
    case RestoreAny = 'restoreAny';
    case ForceDelete = 'forceDelete';
    case ForceDeleteAny = 'forceDeleteAny';
    case Attach = 'attach';
    case Detach = 'detach';
    case Associate = 'associate';
    case Dissociate = 'dissociate';
}

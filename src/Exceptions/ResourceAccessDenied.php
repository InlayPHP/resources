<?php

declare(strict_types=1);

namespace Inlay\Resources\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

final class ResourceAccessDenied extends AuthorizationException {}

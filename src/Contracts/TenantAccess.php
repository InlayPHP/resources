<?php

declare(strict_types=1);

namespace Inlay\Resources\Contracts;

/**
 * A tenant that decides who may enter it.
 *
 * Implement this on the tenant model to have Inlay refuse a URL whose tenant
 * the authenticated user is not a member of.
 */
interface TenantAccess
{
    public function canAccessTenant(mixed $user): bool;
}

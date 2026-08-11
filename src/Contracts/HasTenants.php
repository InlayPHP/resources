<?php

declare(strict_types=1);

namespace Inlay\Resources\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A user who belongs to tenants.
 *
 * The panel asks the user which tenants to offer, so membership stays where
 * the application already models it.
 */
interface HasTenants
{
    /** @return iterable<Model> */
    public function inlayTenants(): iterable;
}

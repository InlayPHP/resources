<?php

declare(strict_types=1);

namespace Inlay\Resources\Routing;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Encodes record keys used inside resource and nested resource URLs. */
final class RouteKey
{
    public static function encode(Model|string|int $record, string $context): string
    {
        $key = $record instanceof Model ? $record->getRouteKey() : $record;

        if (! is_string($key) && ! is_int($key)) {
            throw new InvalidArgumentException("{$context} route key must be a string or integer.");
        }

        if (trim((string) $key) === '') {
            throw new InvalidArgumentException("{$context} route key cannot be empty.");
        }

        return rawurlencode((string) $key);
    }
}

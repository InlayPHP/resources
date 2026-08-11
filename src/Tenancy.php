<?php

declare(strict_types=1);

namespace Inlay\Resources;

use Illuminate\Container\Container as LaravelContainer;
use Illuminate\Database\Eloquent\Model;

/**
 * The tenant the current request belongs to.
 *
 * Panels and middleware set it once per request; Resources read it to scope
 * every query and to own every record they create. Nothing serializes it, so a
 * browser can never choose its own tenant.
 */
final class Tenancy
{
    private ?Model $tenant = null;

    public static function resolve(): self
    {
        $container = LaravelContainer::getInstance();

        if (! $container->bound(self::class)) {
            $container->instance(self::class, new self);
        }

        return $container->make(self::class);
    }

    public function set(?Model $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function current(): ?Model
    {
        return $this->tenant;
    }

    public function requireCurrent(): Model
    {
        if ($this->tenant === null) {
            throw new \LogicException('No tenant is current for this request.');
        }

        return $this->tenant;
    }

    public function forget(): self
    {
        $this->tenant = null;

        return $this;
    }
}

<?php

declare(strict_types=1);

namespace Inlay\Resources;

use InvalidArgumentException;

final class ResourceRegistry
{
    /** @var array<string, class-string<resource>> */
    private array $resources = [];

    /** @param class-string<resource> $resource */
    public function register(string $resource): self
    {
        if (! is_subclass_of($resource, Resource::class)) {
            throw new InvalidArgumentException("Resource [{$resource}] must extend ".Resource::class.'.');
        }

        $slug = $resource::routeKey();
        if (isset($this->resources[$slug]) && $this->resources[$slug] !== $resource) {
            throw new InvalidArgumentException("Resource slug [{$slug}] is already registered.");
        }

        $this->resources[$slug] = $resource;
        ksort($this->resources);

        return $this;
    }

    /** @param iterable<class-string<resource>> $resources */
    public function registerMany(iterable $resources): self
    {
        foreach ($resources as $resource) {
            $this->register($resource);
        }

        return $this;
    }

    public function has(string $slug): bool
    {
        return isset($this->resources[$slug]);
    }

    /** @return class-string<resource> */
    public function get(string $slug): string
    {
        return $this->resources[$slug] ?? throw new InvalidArgumentException("Resource [{$slug}] is not registered.");
    }

    /** @return array<string, class-string<resource>> */
    public function all(): array
    {
        return $this->resources;
    }

    /** @return array<string, ResourceMetadata> */
    public function metadata(): array
    {
        $metadata = [];
        foreach ($this->resources as $slug => $resource) {
            $metadata[$slug] = $resource::metadata();
        }

        return $metadata;
    }
}

<?php

declare(strict_types=1);

namespace Inlay\Resources\Routing;

use Illuminate\Database\Eloquent\Model;
use Inlay\Resources\Pages\ResourcePage;
use Inlay\Resources\Resource;
use Inlay\Resources\ResourceOperation;
use InvalidArgumentException;
use JsonSerializable;

final class PageRoute implements JsonSerializable
{
    /** @var class-string<resource>|null */
    private ?string $resource = null;

    private ?string $name = null;

    private string $prefix = '';

    private Model|string|int|null $parent = null;

    /** @var list<string> */
    private array $middleware = [];

    /** @param class-string<ResourcePage> $page */
    public function __construct(private readonly string $page, private readonly string $path)
    {
        if (! is_subclass_of($page, ResourcePage::class)) {
            throw new InvalidArgumentException("Resource page [{$page}] must extend ".ResourcePage::class.'.');
        }

        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
            throw new InvalidArgumentException('A resource page path must begin with one slash.');
        }

        $withoutRecord = str_replace('{record}', '', $path);
        if (preg_match('/[\x00-\x1F\x7F?#]/', $path) === 1 || str_contains($withoutRecord, '{') || str_contains($withoutRecord, '}')) {
            throw new InvalidArgumentException("Invalid resource page path [{$path}].");
        }

        if (substr_count($path, '{record}') > 1) {
            throw new InvalidArgumentException('A resource page path may contain one record placeholder.');
        }

        if ($path !== '/') {
            foreach (explode('/', substr($path, 1)) as $segment) {
                if ($segment === '' || preg_match('/%(?![0-9A-Fa-f]{2})/', $segment) === 1) {
                    throw new InvalidArgumentException("Invalid resource page path [{$path}].");
                }

                if ($segment === '{record}') {
                    continue;
                }

                $decoded = rawurldecode($segment);
                if ($decoded === '' || in_array($decoded, ['.', '..'], true) || preg_match('/[\x00-\x20\x7F%\\\\\/{}]/u', $decoded) === 1) {
                    throw new InvalidArgumentException("Invalid resource page path [{$path}].");
                }
            }
        }
    }

    /** @param class-string<resource> $resource */
    public function bind(string $resource, string $name): self
    {
        if (! is_subclass_of($resource, Resource::class)) {
            throw new InvalidArgumentException("Resource [{$resource}] must extend ".Resource::class.'.');
        }

        if (! preg_match('/^[a-z][a-z0-9_-]*$/', $name)) {
            throw new InvalidArgumentException("Invalid resource page name [{$name}].");
        }

        if ($this->page::resource() !== $resource) {
            throw new InvalidArgumentException("Page [{$this->page}] belongs to [{$this->page::resource()}], not [{$resource}].");
        }

        $requiresRecord = $this->page::operation()->requiresRecord();
        $hasRecord = str_contains($this->path, '{record}');

        if ($requiresRecord !== $hasRecord) {
            throw new InvalidArgumentException("Page [{$name}] record placeholder does not match its operation.");
        }

        $this->resource = $resource;
        $this->name = $name;

        return $this;
    }

    /** @param list<string> $middleware */
    public function middleware(array $middleware): self
    {
        foreach ($middleware as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                throw new InvalidArgumentException('Resource page middleware must be non-empty strings.');
            }
        }

        $this->middleware = array_values(array_unique($middleware));

        return $this;
    }

    /** @return class-string<ResourcePage> */
    public function pageClass(): string
    {
        return $this->page;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function name(): string
    {
        return $this->name ?? throw new \LogicException('Bind the page route to a resource before reading its name.');
    }

    /** @return list<string> */
    public function middlewareList(): array
    {
        return $this->middleware;
    }

    public function prefix(string $prefix): self
    {
        $this->prefix = trim($prefix, '/');

        return $this;
    }

    public function prefixValue(): string
    {
        return $this->prefix;
    }

    /** The parent record used to resolve nested resource URLs. */
    public function parent(Model|string|int|null $parent): self
    {
        $this->parent = $parent;

        return $this;
    }

    public function url(Model|string|int|null $record = null, Model|string|int|null $parent = null): string
    {
        $resource = $this->resource ?? throw new \LogicException('Bind the page route before generating its URL.');
        $base = $resource::baseUrl($this->prefix, $parent ?? $this->parent);
        $suffix = $this->path === '/' ? '' : $this->path;
        $url = $base.$suffix;

        if (str_contains($url, '{record}')) {
            if ($record === null) {
                throw new InvalidArgumentException("Page [{$this->name()}] requires a record.");
            }

            $url = str_replace('{record}', RouteKey::encode($record, "Page [{$this->name()}] record"), $url);
        } elseif ($record !== null) {
            throw new InvalidArgumentException("Page [{$this->name()}] does not accept a record.");
        }

        return $url;
    }

    /** @param array<string, mixed> $input */
    /** @internal */
    public function pageInstance(): ResourcePage
    {
        return new $this->page;
    }

    public function operation(): ResourceOperation
    {
        return $this->page::operation();
    }

    public function props(array $input = [], Model|string|int|null $record = null, mixed $user = null, ?Model $parent = null): array
    {
        $page = $this->pageInstance();

        return $page->props($input, $record, $user, $this, $parent);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $resource = $this->resource ?? throw new \LogicException('Bind the page route before serializing it.');
        $needsParent = $resource::parentResource() !== null && $this->parent === null;

        return [
            'name' => $this->name(),
            'path' => $this->path,
            'url' => str_contains($this->path, '{record}') || $needsParent ? null : $this->url(),
            'operation' => $this->page::operation()->value,
            'component' => $this->page::component(),
            'middleware' => $this->middleware,
        ];
    }
}

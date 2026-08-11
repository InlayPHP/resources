<?php

declare(strict_types=1);

namespace Inlay\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Declares that a resource is nested beneath a parent resource, so its pages
 * live at `/parent-slug/{parent}/child-slug` and every query, record lookup and
 * creation is scoped through the parent relationship.
 */
final class ParentResourceRegistration implements JsonSerializable
{
    /** @var list<string> */
    private const RESERVED_PARAMETERS = ['record', 'related', 'relation', 'inlayResource', 'inlayPage', 'inlayPrefix'];

    private ?string $relationship = null;

    private ?string $inverseRelationship = null;

    private string $parameter = 'parent';

    /** @var class-string<resource>|null */
    private ?string $child = null;

    /** @param class-string<resource> $resource */
    public function __construct(private readonly string $resource)
    {
        if (! is_subclass_of($resource, Resource::class)) {
            throw new InvalidArgumentException("Parent resource [{$resource}] must extend ".Resource::class.'.');
        }
    }

    /** The relationship on the parent model that owns the nested records. */
    public function relationship(string $relationship): self
    {
        $this->relationship = self::validIdentifier($relationship, 'parent relationship');

        return $this;
    }

    /** The BelongsTo or MorphTo relationship on the nested model. */
    public function inverseRelationship(string $inverseRelationship): self
    {
        $this->inverseRelationship = self::validIdentifier($inverseRelationship, 'inverse relationship');

        return $this;
    }

    /** The route parameter that carries the parent record key. */
    public function parameter(string $parameter): self
    {
        if (preg_match('/^[a-z][A-Za-z0-9_]*$/', $parameter) !== 1 || in_array($parameter, self::RESERVED_PARAMETERS, true)) {
            throw new InvalidArgumentException("Invalid nested resource parent parameter [{$parameter}].");
        }

        $this->parameter = $parameter;

        return $this;
    }

    /**
     * Bind the registration to the nested resource and validate the whole
     * nesting contract before any route, query or URL is derived from it.
     *
     * @param  class-string<resource>  $child
     */
    public function bind(string $child): self
    {
        if (! is_subclass_of($child, Resource::class)) {
            throw new InvalidArgumentException("Nested resource [{$child}] must extend ".Resource::class.'.');
        }

        if ($child === $this->resource) {
            throw new InvalidArgumentException("Resource [{$child}] cannot be nested beneath itself.");
        }

        if ($this->resource::getParentResourceRegistration() !== null) {
            throw new InvalidArgumentException(
                "Resource [{$child}] cannot be nested beneath [{$this->resource}], which is nested itself.",
            );
        }

        $this->child = $child;

        $relation = $this->relationOn(new ($this->resource::model()));
        $related = $relation->getRelated()::class;
        if ($related !== $child::model()) {
            throw new InvalidArgumentException(
                "Parent relationship [{$this->relationshipName()}] returns [{$related}], not [".$child::model().'].',
            );
        }

        $inverse = $this->inverseRelationship;
        if ($inverse !== null) {
            $model = new ($child::model());
            if (! method_exists($model, $inverse)) {
                throw new InvalidArgumentException("Inverse relationship [{$inverse}] does not exist on [".$child::model().'].');
            }
            $relation = $model->{$inverse}();
            if (! $relation instanceof BelongsTo && ! $relation instanceof MorphTo) {
                throw new InvalidArgumentException("Inverse relationship [{$inverse}] must return a BelongsTo or MorphTo relation.");
            }
        }

        return $this;
    }

    /** @return class-string<resource> */
    public function resource(): string
    {
        return $this->resource;
    }

    public function relationshipName(): string
    {
        return $this->relationship ?? Str::camel(Str::pluralStudly(class_basename($this->boundChild()::model())));
    }

    public function inverseRelationshipName(): ?string
    {
        return $this->inverseRelationship;
    }

    public function parameterName(): string
    {
        return $this->parameter;
    }

    /** Resolve the live parent relationship used to scope nested records. */
    public function relationshipFor(Model $parent): Relation
    {
        $model = $this->resource::model();
        if (! $parent instanceof $model) {
            throw new InvalidArgumentException('The parent record does not belong to ['.$this->resource.'].');
        }

        return $this->relationOn($parent);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'resource' => $this->resource,
            'slug' => $this->resource::slug(),
            'relationship' => $this->relationshipName(),
            'inverseRelationship' => $this->inverseRelationship,
            'parameter' => $this->parameter,
        ];
    }

    private function relationOn(Model $parent): Relation
    {
        $name = $this->relationshipName();
        if (! method_exists($parent, $name)) {
            throw new InvalidArgumentException("Parent relationship [{$name}] does not exist on [".$parent::class.'].');
        }

        $relation = $parent->{$name}();
        if (! $relation instanceof HasOneOrMany && ! $relation instanceof BelongsToMany) {
            throw new InvalidArgumentException(
                "Parent relationship [{$name}] must return a HasOne, HasMany, MorphOne, MorphMany, BelongsToMany, or MorphToMany relation.",
            );
        }

        return $relation;
    }

    /** @return class-string<resource> */
    private function boundChild(): string
    {
        return $this->child ?? throw new \LogicException('Bind the parent registration to a nested resource before reading it.');
    }

    private static function validIdentifier(string $value, string $context): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid {$context} name [{$value}].");
        }

        return $value;
    }
}

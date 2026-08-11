<?php

declare(strict_types=1);

namespace Inlay\Resources;

use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;

final class RelationGroup implements JsonSerializable
{
    private string $id;

    private ?string $description = null;

    private ?string $icon = null;

    private ?string $defaultRelation = null;

    private bool $contained = true;

    /**
     * @param  list<class-string<RelationManager>>  $relations
     */
    private function __construct(
        private readonly string $label,
        private readonly array $relations,
    ) {
        if (trim($label) === '' || mb_strlen($label) > 120) {
            throw new InvalidArgumentException('Relation group labels must contain between 1 and 120 characters.');
        }
        if ($relations === []) {
            throw new InvalidArgumentException('Relation groups must contain at least one relation manager.');
        }

        $seen = [];
        foreach ($relations as $relation) {
            if (! is_string($relation) || ! is_subclass_of($relation, RelationManager::class)) {
                throw new InvalidArgumentException('Relation groups may only contain '.RelationManager::class.' classes.');
            }
            $name = $relation::name();
            if (isset($seen[$name])) {
                throw new InvalidArgumentException("Relation manager [{$name}] is duplicated inside the group.");
            }
            $seen[$name] = true;
        }

        $this->id(Str::slug($label));
    }

    /** @param list<class-string<RelationManager>> $relations */
    public static function make(string $label, array $relations): self
    {
        return new self($label, $relations);
    }

    public function id(string $id): self
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,79}$/', $id) !== 1) {
            throw new InvalidArgumentException('Relation group IDs must be safe wire identifiers.');
        }
        $this->id = $id;

        return $this;
    }

    public function description(?string $description): self
    {
        if ($description !== null && (trim($description) === '' || mb_strlen($description) > 500)) {
            throw new InvalidArgumentException('Relation group descriptions must contain between 1 and 500 characters.');
        }
        $this->description = $description;

        return $this;
    }

    public function icon(?string $icon): self
    {
        if ($icon !== null && preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,119}$/', $icon) !== 1) {
            throw new InvalidArgumentException('Relation group icons must be safe icon identifiers.');
        }
        $this->icon = $icon;

        return $this;
    }

    public function defaultRelation(?string $relation): self
    {
        if ($relation !== null && is_subclass_of($relation, RelationManager::class)) {
            if (! $this->contains($relation)) {
                throw new InvalidArgumentException("Default relation manager [{$relation}] does not belong to this group.");
            }
            $relation = $relation::name();
        }
        if ($relation !== null && ! in_array($relation, $this->relationNames(), true)) {
            throw new InvalidArgumentException("Default relation [{$relation}] does not belong to this group.");
        }
        $this->defaultRelation = $relation;

        return $this;
    }

    public function contained(bool $contained = true): self
    {
        $this->contained = $contained;

        return $this;
    }

    /** @return list<class-string<RelationManager>> */
    public function relations(): array
    {
        return $this->relations;
    }

    /** @return list<string> */
    public function relationNames(): array
    {
        return array_map(
            static fn (string $relation): string => $relation::name(),
            $this->relations,
        );
    }

    public function contains(string $manager): bool
    {
        return in_array($manager, $this->relations, true);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'contract' => 'inlay.resources.relation-group.v1',
            'id' => $this->id,
            'label' => $this->label,
            'description' => $this->description,
            'icon' => $this->icon,
            'defaultRelation' => $this->defaultRelation ?? $this->relationNames()[0],
            'contained' => $this->contained,
        ];
    }
}

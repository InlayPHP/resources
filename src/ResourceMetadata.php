<?php

declare(strict_types=1);

namespace Inlay\Resources;

use Illuminate\Database\Eloquent\Model;
use JsonSerializable;

final readonly class ResourceMetadata implements JsonSerializable
{
    /**
     * @param  class-string<resource>  $resource
     * @param  class-string<Model>  $model
     * @param  array<string, array<string, mixed>>  $pages
     * @param  array<string, mixed>|null  $parent
     */
    public function __construct(
        public string $resource,
        public string $model,
        public string $slug,
        public string $label,
        public string $pluralLabel,
        public ?string $navigationIcon,
        public array $pages,
        public ?array $parent = null,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'contract' => 'inlay.resources.v1',
            'resource' => $this->resource,
            'model' => $this->model,
            'slug' => $this->slug,
            'label' => $this->label,
            'pluralLabel' => $this->pluralLabel,
            'navigationIcon' => $this->navigationIcon,
            'parent' => $this->parent,
            'pages' => $this->pages,
        ];
    }
}

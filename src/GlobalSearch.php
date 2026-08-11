<?php

declare(strict_types=1);

namespace Inlay\Resources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Search several resources at once, without leaving their boundaries.
 *
 * Every match is found through the resource's own scoped query and checked
 * against its own authorization, so global search can never surface a record
 * the visitor could not open directly.
 */
final class GlobalSearch
{
    /** @param list<class-string<Resource>> $resources */
    private function __construct(private readonly array $resources) {}

    /** @param list<class-string<Resource>> $resources */
    public static function across(array $resources): self
    {
        foreach ($resources as $resource) {
            if (! is_string($resource) || ! is_subclass_of($resource, Resource::class)) {
                throw new \InvalidArgumentException('Global search resources must extend '.Resource::class.'.');
            }
        }

        return new self(array_values($resources));
    }

    /**
     * @return list<array{resource: string, label: string, title: string, url: string|null}>
     */
    public function search(string $term, mixed $user = null, string $prefix = '', int $limit = 5): array
    {
        $term = trim($term);
        if ($term === '' || mb_strlen($term) < 2) {
            return [];
        }
        if ($limit < 1 || $limit > 50) {
            throw new \InvalidArgumentException('A global search limit must be between 1 and 50.');
        }

        $results = [];
        foreach ($this->resources as $resource) {
            $attributes = $resource::globallySearchableAttributes();
            if ($attributes === [] || ! $resource::canAccessGlobalSearch($user)) {
                continue;
            }

            $query = $resource::scopedEloquentQuery();
            $query->where(static function (Builder $query) use ($attributes, $term): void {
                foreach ($attributes as $attribute) {
                    $query->orWhere($attribute, 'like', '%'.$term.'%');
                }
            });

            foreach ($query->limit($limit)->get() as $record) {
                $results[] = [
                    'resource' => $resource,
                    'label' => $resource::label(),
                    'title' => $resource::globalSearchTitle($record),
                    'url' => $resource::globalSearchUrl($record, $prefix),
                ];
            }
        }

        return $results;
    }
}

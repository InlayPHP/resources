<?php

declare(strict_types=1);

namespace Inlay\Resources\Pages;

use Illuminate\Database\Eloquent\Model;
use Inlay\Resources\ResourceOperation;
use Inlay\Tables\Table;

abstract class ListRecords extends ResourcePage
{
    protected int $perPage = 15;

    public static function operation(): ResourceOperation
    {
        return ResourceOperation::ListRecords;
    }

    /**
     * Named views of this page's records.
     *
     * @return list<PageTab>
     */
    protected function tabs(): array
    {
        return [];
    }

    protected function content(string $resource, array $input, ?Model $record): array
    {
        $tabs = $this->resolvedTabs();
        $query = $resource::scopedEloquentQuery($this->parentRecord());

        $active = null;
        if ($tabs !== []) {
            $active = $this->activeTab($tabs, $input);
            $tabs[$active]->applyQuery($query);
        }

        $table = $resource::configuredTable();
        $table->query($query, $input, $this->perPage);

        return $tabs === []
            ? ['table' => $table]
            : ['table' => $table, 'tabs' => ['active' => $active, 'items' => array_values($tabs)]];
    }

    /** @return array<string, PageTab> */
    private function resolvedTabs(): array
    {
        $tabs = [];
        foreach ($this->tabs() as $tab) {
            if (! $tab instanceof PageTab) {
                throw new \InvalidArgumentException('List page tabs must be '.PageTab::class.' instances.');
            }
            if (array_key_exists($tab->name(), $tabs)) {
                throw new \InvalidArgumentException("Duplicate list page tab [{$tab->name()}].");
            }
            $tabs[$tab->name()] = $tab;
        }

        return $tabs;
    }

    /**
     * The tab this request asks for, or the declared default.
     *
     * A requested name is only honored when the page declares it, so a browser
     * cannot ask for a view that does not exist.
     *
     * @param  array<string, PageTab>  $tabs
     * @param  array<string, mixed>  $input
     */
    private function activeTab(array $tabs, array $input): string
    {
        $requested = $input['tab'] ?? null;
        if (is_string($requested) && array_key_exists($requested, $tabs)) {
            return $requested;
        }

        foreach ($tabs as $name => $tab) {
            if ($tab->isDefault()) {
                return $name;
            }
        }

        return array_key_first($tabs);
    }
}

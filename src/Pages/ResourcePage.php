<?php

declare(strict_types=1);

namespace Inlay\Resources\Pages;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inlay\Actions\Action;
use Inlay\Resources\RelationManager;
use Inlay\Resources\Resource;
use Inlay\Resources\ResourceOperation;
use Inlay\Resources\Routing\PageRoute;
use Inlay\Widgets\Contracts\ProvidesWidgets;
use Inlay\Widgets\Widget;
use Inlay\Widgets\WidgetDashboard;
use Inlay\Widgets\WidgetResolver;

abstract class ResourcePage
{
    /** @var class-string<resource> */
    protected static string $resource;

    protected static string $component;

    protected static ?string $title = null;

    private ?Model $parentRecord = null;

    public static function route(string $path): PageRoute
    {
        return new PageRoute(static::class, $path);
    }

    /** @return class-string<resource> */
    public static function resource(): string
    {
        if (! isset(static::$resource) || ! is_subclass_of(static::$resource, Resource::class)) {
            throw new \LogicException('Resource pages must declare a valid static $resource class.');
        }

        return static::$resource;
    }

    public static function component(): string
    {
        if (! isset(static::$component) || trim(static::$component) === '') {
            throw new \LogicException('Resource pages must declare a non-empty static $component.');
        }

        return static::$component;
    }

    /**
     * The page's own name, when it has one of its own.
     *
     * A CRUD page is named by what it does, so it needs none. A page that is
     * neither list nor create nor one record — a settings screen, a report —
     * would otherwise inherit the list page's heading and a breadcrumb reading
     * "List", which is how a custom page ends up indistinguishable from the
     * page it sits beside.
     */
    public static function title(): ?string
    {
        if (static::$title === null) {
            return null;
        }

        $title = trim(static::$title);

        return $title === '' ? throw new \LogicException('A resource page title cannot be empty.') : $title;
    }

    abstract public static function operation(): ResourceOperation;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    final public function props(array $input, Model|string|int|null $record, mixed $user, PageRoute $route, ?Model $parent = null): array
    {
        $resource = static::resource();
        $this->parentRecord = $parent;
        $model = $this->resolveRecord($resource, $record);
        $resource::authorize(static::operation(), $model, $user);

        $props = [
            'resource' => $resource::metadata($route->prefixValue(), $parent),
            'page' => $route->parent($parent),
            ...$this->content($resource, $input, $model),
        ];
        if ($parent !== null) {
            $props['parentRecord'] = $parent->toArray();
        }
        $props['heading'] = $this->heading($resource, $model);
        $subheading = $this->subheading($resource, $model);
        if ($subheading !== null) {
            $props['subheading'] = $subheading;
        }
        $props['breadcrumbs'] = $this->breadcrumbs($resource, $route, $model, $parent);
        $subNavigation = $this->resolvedSubNavigation($resource, $route, $model, $user, $parent);
        if ($subNavigation !== []) {
            $props['subNavigation'] = $subNavigation;
        }
        $headerActions = $this->resolvedHeaderActions($route, $model, $parent);
        if ($headerActions !== []) {
            $props['headerActions'] = $headerActions;
        }

        foreach (['headerWidgets' => $this->headerWidgets(), 'footerWidgets' => $this->footerWidgets()] as $slot => $widgets) {
            if ($widgets !== []) {
                $props[$slot] = $this->resolveWidgets($widgets);
            }
        }

        $relations = $model === null ? [] : $this->relationManagers($resource);
        if ($relations !== []) {
            $base = $resource::baseUrl($route->prefixValue(), $parent)
                .'/'.rawurlencode((string) $model->getRouteKey()).'/_inlay/relations';
            $props['relations'] = array_values(array_map(
                function (string $manager) use ($base, $input, $model, $resource, $user): RelationManager {
                    $instance = $manager::make(
                        $model,
                        $user,
                        static::operation() === ResourceOperation::View,
                    )
                        ->relationGroup($resource::relationGroup($manager))
                        ->baseUrl($base.'/'.$manager::name());
                    $instance->resolveTable($input);

                    return $instance;
                },
                $relations,
            ));
        }

        return $props;
    }

    /**
     * The trail from the resource's list page to this one.
     *
     * The record link points at the page a visitor could actually open, and is
     * left out when there is none, so a breadcrumb never leads nowhere.
     *
     * @param  class-string<resource>  $resource
     * @return list<array{label: string, url: string|null}>
     */
    private function breadcrumbs(string $resource, PageRoute $route, ?Model $record, ?Model $parent): array
    {
        $pages = $resource::pages();
        $trail = [[
            'label' => $resource::pluralLabel(),
            'url' => array_key_exists('index', $pages)
                ? $resource::baseUrl($route->prefixValue(), $parent)
                : null,
        ]];

        if ($record !== null) {
            $trail[] = [
                'label' => $resource::recordTitle($record),
                'url' => $resource::globalSearchUrl($record, $route->prefixValue(), $parent),
            ];
        }

        if ($route->name() !== 'index') {
            $trail[] = ['label' => static::breadcrumbLabel(), 'url' => null];
        }

        return $trail;
    }

    /**
     * The page's own heading.
     *
     * A list page is named for the collection, a create page for what it will
     * make, and a page about one record is named for that record — which is the
     * record's own title, so a heading and a breadcrumb cannot disagree.
     *
     * @param  class-string<resource>  $resource
     */
    protected function heading(string $resource, ?Model $record): string
    {
        $title = static::title();
        if ($title !== null) {
            return $title;
        }

        if ($record !== null) {
            return $resource::recordTitle($record);
        }

        return static::operation() === ResourceOperation::Create
            ? 'Create '.$resource::label()
            : $resource::pluralLabel();
    }

    /**
     * A line beneath the heading, or null when the page needs none.
     *
     * @param  class-string<resource>  $resource
     */
    protected function subheading(string $resource, ?Model $record): ?string
    {
        return null;
    }

    /** The name this page goes by in a breadcrumb trail. */
    public static function breadcrumbLabel(): string
    {
        return static::title() ?? ucfirst(static::operation()->value);
    }

    /** The name this page goes by in a record's sub-navigation. */
    public static function subNavigationLabel(): string
    {
        return static::breadcrumbLabel();
    }

    /**
     * The sibling pages this record moves between, defaulting to the resource's.
     *
     * A page may narrow the list, or return an empty one to hide the navigation
     * entirely on that page.
     *
     * @return list<string>
     */
    protected function subNavigation(): array
    {
        return static::resource()::recordSubNavigation();
    }

    /**
     * @param  class-string<resource>  $resource
     * @return list<array{name: string, label: string, url: string, active: bool}>
     */
    private function resolvedSubNavigation(string $resource, PageRoute $route, ?Model $record, mixed $user, ?Model $parent): array
    {
        $names = $this->subNavigation();
        if ($names === [] || $record === null) {
            return [];
        }

        $pages = $resource::pages();
        $items = [];
        $seen = [];

        foreach ($names as $name) {
            // Checked before authorization, so a duplicate is reported whether
            // or not this visitor would have been offered the page.
            if (isset($seen[$name])) {
                throw new \InvalidArgumentException("Duplicate sub-navigation page [{$name}].");
            }
            $seen[$name] = true;

            $page = $pages[$name] ?? throw new \InvalidArgumentException(
                "Unknown sub-navigation page [{$name}] on resource [{$resource}].",
            );

            if (! $page->operation()->requiresRecord()) {
                throw new \LogicException("Sub-navigation page [{$name}] does not accept a record.");
            }

            // The visitor is never offered a page they would be turned away from.
            if (! $resource::allows($page->operation(), $record, $user)) {
                continue;
            }

            $items[$name] = [
                'name' => $name,
                'label' => $page->pageClass()::subNavigationLabel(),
                // `pages()` hands back its own clones, so the prefix this
                // request was mounted under can be applied to each of them.
                'url' => $page->prefix($route->prefixValue())->url($record, $parent),
                'active' => $name === $route->name(),
            ];
        }

        return array_values($items);
    }

    /**
     * The relation managers this page renders.
     *
     * A page may narrow this to one relation, or to none at all.
     *
     * @param  class-string<resource>  $resource
     * @return list<class-string<RelationManager>>
     */
    protected function relationManagers(string $resource): array
    {
        return $resource::relations();
    }

    /**
     * Actions offered in this page's header.
     *
     * @return list<Action>
     */
    protected function headerActions(): array
    {
        return [];
    }

    /**
     * @return list<Action>
     */
    private function resolvedHeaderActions(PageRoute $route, ?Model $record, ?Model $parent): array
    {
        $actions = $this->headerActions();
        if ($actions === []) {
            return [];
        }

        $resource = static::resource();
        $base = $resource::baseUrl($route->prefixValue(), $parent);
        $names = [];

        $resolved = [];

        foreach ($actions as $action) {
            if (! $action instanceof Action) {
                throw new \InvalidArgumentException('Page header actions must extend '.Action::class.'.');
            }
            if (in_array($action->name(), $names, true)) {
                throw new \InvalidArgumentException("Duplicate page header action [{$action->name()}].");
            }
            $names[] = $action->name();

            // Page definitions may intentionally reuse a static Action object.
            // Never write the request-specific endpoint back onto that shared
            // definition: doing so leaks one record/parent URL into the next
            // request. The action runner receives the original definition by
            // name, while the browser receives this request's clone.
            $action = clone $action;

            if (! $action->hasUrl()) {
                $action->url($base.'?'.http_build_query(array_filter([
                    '_inlay_action' => $action->name(),
                    '_inlay_action_scope' => 'page',
                    '_inlay_page' => $route->name(),
                    'record' => $record?->getRouteKey(),
                ], static fn (mixed $value): bool => $value !== null)));
            }

            $resolved[] = $action;
        }

        return $resolved;
    }

    /** @internal Resolve a header action by name for the action endpoint. */
    final public function headerAction(string $name): Action
    {
        foreach ($this->headerActions() as $action) {
            if ($action instanceof Action && $action->name() === $name) {
                return $action;
            }
        }

        throw new \InvalidArgumentException("Unknown page header action [{$name}].");
    }

    /**
     * Widgets rendered above this page, defaulting to the resource's own.
     *
     * @return list<Widget|ProvidesWidgets|class-string<ProvidesWidgets>>
     */
    protected function headerWidgets(): array
    {
        return static::resource()::widgets();
    }

    /** @return list<Widget|ProvidesWidgets|class-string<ProvidesWidgets>> */
    protected function footerWidgets(): array
    {
        return [];
    }

    /**
     * Widgets resolve through the same resolver a dashboard uses, so a page
     * widget and a dashboard widget behave identically.
     *
     * @param  list<Widget|ProvidesWidgets|class-string<ProvidesWidgets>>  $widgets
     */
    private function resolveWidgets(array $widgets): WidgetDashboard
    {
        $container = Container::getInstance();

        return $container->make(WidgetResolver::class)->resolve(
            $widgets,
            $container->make(Request::class),
        );
    }

    /**
     * @param  class-string<resource>  $resource
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    abstract protected function content(string $resource, array $input, ?Model $record): array;

    /** The resolved parent record when the resource is nested. */
    final protected function parentRecord(): ?Model
    {
        return $this->parentRecord;
    }

    /** @param class-string<resource> $resource */
    private function resolveRecord(string $resource, Model|string|int|null $record): ?Model
    {
        if (! static::operation()->requiresRecord()) {
            if ($record !== null) {
                throw new \InvalidArgumentException('This resource page does not accept a record.');
            }

            return null;
        }

        if ($record instanceof Model) {
            $modelClass = $resource::model();

            if (! $record instanceof $modelClass) {
                throw new \InvalidArgumentException('The supplied record does not belong to this resource model.');
            }

            return $resource::resolveRecord($record->getRouteKey(), $this->parentRecord);
        }

        if ($record === null) {
            throw new \InvalidArgumentException('This resource page requires a record.');
        }

        return $resource::resolveRecord($record, $this->parentRecord);
    }
}

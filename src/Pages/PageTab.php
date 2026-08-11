<?php

declare(strict_types=1);

namespace Inlay\Resources\Pages;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Inlay\Support\ClosureEvaluator;
use JsonSerializable;

/**
 * A named view of a list page's records.
 *
 * The tab decides how the records are narrowed, so the browser only ever sends
 * a tab name and the server owns what that name means.
 */
final class PageTab implements JsonSerializable
{
    private ?string $label = null;

    private ?Closure $modifyQuery = null;

    private string|int|Closure|null $badge = null;

    private bool $default = false;

    private function __construct(private readonly string $name)
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException('A page tab name may only contain letters, numbers, underscores, and hyphens.');
        }
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(string $label): self
    {
        if (trim($label) === '') {
            throw new \InvalidArgumentException('A page tab label cannot be empty.');
        }

        $this->label = trim($label);

        return $this;
    }

    public function badge(string|int|Closure|null $badge): self
    {
        $this->badge = $badge;

        return $this;
    }

    /** Narrow the records this tab shows. */
    public function modifyQueryUsing(Closure $callback): self
    {
        $this->modifyQuery = $callback;

        return $this;
    }

    public function default(bool $default = true): self
    {
        $this->default = $default;

        return $this;
    }

    public function isDefault(): bool
    {
        return $this->default;
    }

    /** @internal */
    public function applyQuery(Builder $query): void
    {
        if ($this->modifyQuery === null) {
            return;
        }

        $result = ClosureEvaluator::evaluate(
            $this->modifyQuery,
            ['query' => $query, 'tab' => $this],
            [Builder::class => $query, self::class => $this],
            [$query, $this],
        );

        if ($result !== null && $result !== $query) {
            throw new \LogicException("Page tab [{$this->name}] query callbacks must return the supplied Builder or null.");
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label ?? ucwords(str_replace(['_', '-'], ' ', $this->name)),
            'badge' => $this->resolvedBadge(),
        ];
    }

    private function resolvedBadge(): string|int|null
    {
        $badge = $this->badge instanceof Closure
            ? ClosureEvaluator::evaluate($this->badge, ['tab' => $this], [self::class => $this], [$this])
            : $this->badge;

        if ($badge === null || is_int($badge) || is_string($badge)) {
            return $badge;
        }

        throw new \UnexpectedValueException("Page tab [{$this->name}] badges must resolve to a string, integer, or null.");
    }
}

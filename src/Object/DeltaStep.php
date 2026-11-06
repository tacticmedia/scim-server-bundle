<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Object;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Patch\PatchOperationType;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;

/**
 * One step of {@see CollectionDeltas} on the collections of one attribute. A route is an id as the step gives it, the
 * index of the collection of its type, and its spelling in the target of each collection that it can name.
 *
 * @phpstan-type Route array{string, int|null, array<int, string>}
 *
 * @internal
 */
final readonly class DeltaStep
{
    /**
     * @param non-empty-list<AttributeMapping> $collections
     * @param non-empty-list<ResourceMapping>  $targets     the target of each collection
     */
    public function __construct(
        private DeferredRemovals $elements,
        private array $collections,
        public array $targets,
        public PatchOperationType $type,
    ) {
    }

    public function typedIndex(?string $type): ?int
    {
        return null === $type ? null : array_find_key($this->collections, static fn (AttributeMapping $collection): bool => 0 === strcasecmp((string) $collection->type, $type));
    }

    /**
     * A Replace reads every element, the other steps only the ids of the routes.
     *
     * @param list<Route> $routes
     *
     * @return list<array<array-key, int>> the present ids of each collection, as keys
     */
    public function present(array $routes): array
    {
        return array_map(
            fn (AttributeMapping $collection, int $index): array => array_flip($this->elements->elementIds($collection, PatchOperationType::Replace === $this->type ? null : $this->wanted($routes, $index))),
            $this->collections,
            array_keys($this->collections),
        );
    }

    /**
     * @param list<array<array-key, int>> $present
     * @param array<int, string>          $canonicals
     */
    public function contains(array $present, array $canonicals): bool
    {
        return array_any($canonicals, static fn (string $canonical, int $index): bool => isset($present[$index][$canonical]));
    }

    /**
     * @param list<Route> $routes the routes of ids that no collection has
     *
     * @return list<list<string>> the added ids of each collection
     *
     * @throws ScimException 400 invalidValue for a route that no target store knows
     */
    public function add(array $routes): array
    {
        $added = [];

        foreach ($this->collections as $index => $collection) {
            $wanted = $this->wanted($routes, $index);
            $placed = array_values(array_diff($wanted, $this->elements->addElements($collection, $wanted)));
            $added[] = $placed;
            $placedIds = array_flip($placed);
            $routes = array_values(array_filter($routes, static fn (array $route): bool => !isset($route[2][$index], $placedIds[$route[2][$index]])));
        }

        return [] === $routes ? $added : throw $this->unknown($routes);
    }

    /**
     * @param list<array<array-key, int>> $present
     * @param list<Route>                 $routes
     *
     * @return list<list<string>> the removed ids of each collection
     */
    public function remove(array $present, array $routes): array
    {
        $removed = [];

        foreach ($this->collections as $index => $collection) {
            $ids = match ($this->type) {
                PatchOperationType::Add => [],
                PatchOperationType::Remove => array_map(strval(...), array_keys($present[$index])),
                PatchOperationType::Replace => array_values(array_diff(array_map(strval(...), array_keys($present[$index])), $this->wanted($routes, $index))),
            };

            $this->elements->removeElements($collection, $ids);

            $removed[] = $ids;
        }

        return $removed;
    }

    /**
     * @param list<list<string>> $ids the ids of each collection
     *
     * @return list<array<string, string>>
     */
    public function elements(array $ids): array
    {
        $elements = [];

        foreach ($this->collections as $index => $collection) {
            foreach ($ids[$index] ?? [] as $id) {
                $elements[] = ['value' => $id] + (null === $collection->type ? [] : ['type' => $collection->type]);
            }
        }

        return $elements;
    }

    /**
     * @param list<Route> $routes
     *
     * @return list<string> the distinct ids of the routes in the spelling of the target of collection $index
     */
    private function wanted(array $routes, int $index): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (array $route): ?string => $route[2][$index] ?? null, $routes), \is_string(...))));
    }

    /**
     * As {@see CollectionWriter}: the typed ids of the first collection that misses some, else every untyped id.
     *
     * @param non-empty-list<Route> $routes
     */
    private function unknown(array $routes): ScimException
    {
        $typed = array_filter(array_column($routes, 1), \is_int(...));

        if ([] === $typed) {
            return References::unknown($this->collections[0], $this->targets, array_column($routes, 0));
        }

        $index = min($typed);

        return References::unknown($this->collections[$index], [$this->targets[$index]], array_column(array_filter($routes, static fn (array $route): bool => $index === $route[1]), 0));
    }
}

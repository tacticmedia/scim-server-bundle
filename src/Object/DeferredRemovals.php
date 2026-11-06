<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Object;

use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;

/**
 * The elements of the collections of one object during the steps of a delta PATCH. Removals wait for flush(), so an
 * element that a later step adds back costs no write.
 *
 * @internal
 */
final class DeferredRemovals
{
    /** @var array<string, array{AttributeMapping, array<array-key, true>}> each collection and its pending ids, by member */
    private array $pending = [];

    public function __construct(
        private readonly ResourceMapping $mapping,
        private readonly CollectionDeltaStore $store,
        private readonly object $object,
    ) {
    }

    /**
     * @param list<string>|null $among element ids; null: every element
     *
     * @return list<string> as {@see CollectionDeltaStore::elementIds()}, without the pending ids
     */
    public function elementIds(AttributeMapping $collection, ?array $among): array
    {
        $pending = $this->pendingIds($collection);

        return array_values(array_filter($this->store->elementIds($this->mapping, $this->object, $collection, $among), static fn (string $id): bool => !isset($pending[$id])));
    }

    /**
     * @param list<string> $ids ids that are not elements
     *
     * @return list<string> the ids that name no target; a pending id is kept
     */
    public function addElements(AttributeMapping $collection, array $ids): array
    {
        $pending = $this->pendingIds($collection);
        $new = array_values(array_filter($ids, static fn (string $id): bool => !isset($pending[$id])));
        $this->pending[$collection->member] = [$collection, array_diff_key($pending, array_flip($ids))];

        return [] === $new ? [] : $this->store->addElements($this->mapping, $this->object, $collection, $new);
    }

    /**
     * @param list<string> $ids ids of elements
     */
    public function removeElements(AttributeMapping $collection, array $ids): void
    {
        $this->pending[$collection->member] = [$collection, $this->pendingIds($collection) + array_fill_keys($ids, true)];
    }

    public function flush(): void
    {
        foreach ($this->pending as [$collection, $ids]) {
            if ([] !== $ids) {
                $this->store->removeElements($this->mapping, $this->object, $collection, array_map(strval(...), array_keys($ids)));
            }
        }

        $this->pending = [];
    }

    /**
     * @return array<array-key, true>
     */
    private function pendingIds(AttributeMapping $collection): array
    {
        return $this->pending[$collection->member][1] ?? [];
    }
}

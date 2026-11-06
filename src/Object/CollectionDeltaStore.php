<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Object;

use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;

/**
 * An ObjectStore that changes the elements of a ReferenceCollection mapping without loading them, so that a PATCH of
 * Group "members" costs the same for any group size. Element ids are SCIM ids in the spelling of the id converter of
 * the target mapping. The repository calls lock(), elementIds(), addElements() and removeElements() inside
 * transactional(), and then update() for the version.
 */
interface CollectionDeltaStore extends ObjectStore
{
    /**
     * Every change of the elements through this store must change meta.version after update().
     */
    public function supportsDeltas(ResourceMapping $mapping, AttributeMapping $collection): bool;

    /**
     * Locks the stored object until the transaction ends, and returns it with its stored state.
     *
     * @param mixed $version the member value of meta.version that the stored object must have; null: no condition
     *
     * @return object|null null for an unknown or malformed id, or another version
     */
    public function lock(ResourceMapping $mapping, string $id, mixed $version = null): ?object;

    /**
     * @param list<string>|null $among element ids; null: every element
     *
     * @return list<string> the ids of $among, in that order, that are elements; with $among null, every element id
     */
    public function elementIds(ResourceMapping $mapping, object $object, AttributeMapping $collection, ?array $among = null): array;

    /**
     * @param list<string> $ids ids that are not elements
     *
     * @return list<string> the ids that name no target, in the order of $ids; the store adds the others
     */
    public function addElements(ResourceMapping $mapping, object $object, AttributeMapping $collection, array $ids): array;

    /**
     * @param list<string> $ids ids of elements
     */
    public function removeElements(ResourceMapping $mapping, object $object, AttributeMapping $collection, array $ids): void;
}

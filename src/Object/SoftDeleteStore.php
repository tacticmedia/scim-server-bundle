<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Object;

use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Query\Plan\Node;

/**
 * An ObjectStore that finds soft-deleted objects, so that a create can revive one. A revive calls transactional()
 * inside transactional(): when the inner $write returns, query() must see the objects that it updated.
 */
interface SoftDeleteStore extends ObjectStore
{
    /**
     * Called inside transactional().
     *
     * @return object|null a soft-deleted object that matches $filter; null when none does or the mapping has no soft delete
     */
    public function findDeleted(ResourceMapping $mapping, Node $filter): ?object;
}

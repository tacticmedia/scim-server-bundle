<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Query;

use TacticMedia\ScimServer\Query\SortOrder;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Query\Plan\Node;

/**
 * A storage-agnostic list query over the objects of one mapped class.
 */
final readonly class ObjectQuery
{
    /**
     * @param Node|null              $filter     null: every object
     * @param list<AttributeMapping> $sortBy     Fields, Slots or DQL expressions of the root class at one path, the primary group first: the sort key is the first present one; or an ElementCollection and one member of its element class: the sort key is that member of the primary element, else of the first element; []: by id
     * @param int<1, max>            $startIndex 1-based
     * @param int<0, max>            $count
     * @param list<AttributeMapping> $load       Reference, ReferenceCollection and ElementCollection mappings that the response renders; a store may preload them
     */
    public function __construct(
        public ?Node $filter,
        public array $sortBy,
        public SortOrder $sortOrder,
        public int $startIndex,
        public int $count,
        public array $load = [],
    ) {
    }
}

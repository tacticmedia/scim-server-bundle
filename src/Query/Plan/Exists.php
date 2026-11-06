<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Query\Plan;

use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;

/**
 * The related object (Reference) or one element of the collection (ReferenceCollection) exists and satisfies
 * $condition. The operands of $condition are mappings of the target class, in v1 only its id mapping.
 */
final readonly class Exists implements Node
{
    /**
     * @param AttributeMapping $relation  a Reference or a ReferenceCollection of the class one level up
     * @param Node|null        $condition null: any related object
     */
    public function __construct(
        public AttributeMapping $relation,
        public ?Node $condition = null,
    ) {
    }
}

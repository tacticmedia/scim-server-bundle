<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Query\Plan;

use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;

/** The member is not null and, for a string, not "". $operand is a Field or a Slot. */
final readonly class IsPresent implements Node
{
    public function __construct(
        public AttributeMapping $operand,
    ) {
    }
}

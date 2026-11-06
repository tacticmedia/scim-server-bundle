<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Query\Plan;

/** The node does not hold. Compare and IsPresent never hold for an absent value, so Negate holds for it. */
final readonly class Negate implements Node
{
    public function __construct(
        public Node $node,
    ) {
    }
}

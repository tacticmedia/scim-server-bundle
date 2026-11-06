<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Query\Plan;

/** At least one node holds. */
final readonly class AnyOf implements Node
{
    /**
     * @param list<Node> $nodes two or more
     */
    public function __construct(
        public array $nodes,
    ) {
    }
}

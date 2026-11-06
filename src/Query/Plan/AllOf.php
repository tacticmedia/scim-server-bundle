<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Query\Plan;

/** Every node holds. */
final readonly class AllOf implements Node
{
    /**
     * @param list<Node> $nodes two or more
     */
    public function __construct(
        public array $nodes,
    ) {
    }
}

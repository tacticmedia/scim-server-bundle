<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Object;

use TacticMedia\ScimServer\Filter\Comparison;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Filter\LogicalOr;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Schema\Uniqueness;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Query\Plan\Node;
use TacticMedia\ScimServerBundle\Query\QueryPlanner;

/**
 * Finds the soft-deleted object that a create revives.
 *
 * @internal
 */
final readonly class Revival
{
    public function __construct(
        private QueryPlanner $planner,
    ) {
    }

    /**
     * Matches the unique attributes of $document with eq, as the server checks them against the live objects.
     *
     * @param array<string, mixed> $document
     *
     * @return object|null null also when the mapping does not revive or the store is no {@see SoftDeleteStore}
     */
    public function find(ResourceMapping $mapping, ObjectStore $store, array $document): ?object
    {
        $filter = true === $mapping->softDelete?->revive && $store instanceof SoftDeleteStore ? $this->filter($mapping, $document) : null;
        $plan = $filter instanceof Filter ? $this->planner->plan(new ListQuery($filter, count: 1), $mapping) : false;

        return $store instanceof SoftDeleteStore && false !== $plan && $plan->filter instanceof Node ? $store->findDeleted($mapping, $plan->filter) : null;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function filter(ResourceMapping $mapping, array $document): ?Filter
    {
        $comparisons = array_filter(array_map(static fn (AttributeMapping $attribute): ?Comparison => self::comparison($mapping, $attribute, $document), $mapping->attributes));

        return array_reduce($comparisons, static fn (?Filter $filter, Comparison $comparison): Filter => $filter instanceof Filter ? new LogicalOr($filter, $comparison) : $comparison);
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function comparison(ResourceMapping $mapping, AttributeMapping $attribute, array $document): ?Comparison
    {
        $value = Uniqueness::None === $attribute->definition->uniqueness || $attribute === $mapping->id ? null : DocumentValues::scalar($mapping, $attribute, $document);

        return \is_scalar($value) ? new Comparison($attribute->path, ComparisonOperator::Equal, $value) : null;
    }
}

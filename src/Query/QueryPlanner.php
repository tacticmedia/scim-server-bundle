<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Query;

use Symfony\Component\DependencyInjection\ServiceLocator;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServerBundle\Converter\ValueConverter;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingKind;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Query\Planning\ElementConditions;
use TacticMedia\ScimServerBundle\Query\Planning\MemberConditions;
use TacticMedia\ScimServerBundle\Query\Planning\RootConditions;

/**
 * Turns a library ListQuery into a storage-agnostic ObjectQuery over the objects of one mapping.
 */
final readonly class QueryPlanner
{
    /**
     * @param ServiceLocator<ValueConverter> $converters keyed by converter class name
     */
    public function __construct(
        private MappingRegistry $mappings,
        private ServiceLocator $converters,
    ) {
    }

    /**
     * @return ObjectQuery|false false: no object matches; the caller returns ListResult([], 0) and runs no query
     *
     * @throws ScimException 400 invalidFilter, 400 invalidValue (sortBy)
     */
    public function plan(ListQuery $query, ResourceMapping $mapping): ObjectQuery|false
    {
        $sortBy = $this->sortBy($query->sortBy, $mapping);
        $filter = $query->filter instanceof Filter ? $this->conditions($mapping)->plan($query->filter) : true;

        return false === $filter ? false : new ObjectQuery(
            true === $filter ? null : $filter,
            $sortBy,
            $query->sortOrder,
            $query->startIndex,
            $query->count,
            $this->load($query, $mapping),
        );
    }

    private function conditions(ResourceMapping $mapping): RootConditions
    {
        $members = new MemberConditions($this->converters);

        return new RootConditions($mapping, $members, new ElementConditions($mapping, $this->mappings, $members));
    }

    /**
     * The Field, or the Slots of the groups in declaration order with the primary group first: RFC 7644 3.4.2.3 sorts
     * by the primary value, else by the first value. {@see MemberConditions::canSort()} selects them. Else an element
     * sub-attribute.
     *
     * @return list<AttributeMapping>
     */
    private function sortBy(?AttributePath $path, ResourceMapping $mapping): array
    {
        if (!$path instanceof AttributePath) {
            return [];
        }

        $sortable = array_values(array_filter(
            $mapping->attributesAt($path),
            static fn (AttributeMapping $member): bool => MemberConditions::canSort($mapping, $member),
        ));
        usort($sortable, static fn (AttributeMapping $left, AttributeMapping $right): int => $right->primary <=> $left->primary);

        return [] === $sortable ? self::elementSortBy($path, $mapping) : $sortable;
    }

    /**
     * The ElementCollection and its member at $path: the store sorts by the member of the primary element, else of
     * the first element.
     *
     * @return list<AttributeMapping>
     */
    private static function elementSortBy(AttributePath $path, ResourceMapping $mapping): array
    {
        $collection = array_find($mapping->attributesAt($path->parent()), static fn (AttributeMapping $member): bool => MappingKind::ElementCollection === $member->kind);
        $member = $collection?->element?->attributeAt($path);

        return null === $collection || null === $member || Mutability::WriteOnly === $member->definition->mutability
            ? throw ScimException::invalidValue(\sprintf("sortBy '%s' is not supported.", $path)) : [$collection, $member];
    }

    /**
     * The relations that the response renders: not excluded and, with "attributes", named by it or by one of its
     * sub-attributes.
     *
     * @return list<AttributeMapping>
     */
    private function load(ListQuery $query, ResourceMapping $mapping): array
    {
        $rendered = static fn (AttributeMapping $relation): bool => !array_any($query->excludedAttributes, $relation->path->equals(...))
            && ([] === $query->attributes || array_any($query->attributes, static fn (AttributePath $path): bool => $path->parent()->equals($relation->path)));

        return array_values(array_filter(
            $mapping->attributes,
            static fn (AttributeMapping $member): bool => \in_array($member->kind, [MappingKind::Reference, MappingKind::ReferenceCollection, MappingKind::ElementCollection], true) && $rendered($member),
        ));
    }
}

<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Object;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Comparison;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Filter\LogicalOr;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Query\SortOrder;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\Uniqueness;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingKind;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Query\ObjectQuery;
use TacticMedia\ScimServerBundle\Query\Plan\Node;
use TacticMedia\ScimServerBundle\Query\QueryPlanner;

/**
 * Finds the soft-deleted object that a create revives, and removes what the object keeps of its deleted state.
 *
 * @internal
 */
final readonly class Revival
{
    public function __construct(
        private QueryPlanner $planner,
        private MappingRegistry $mappings,
        private References $references,
        private MemberValues $values,
        private CollectionDeltas $deltas,
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
     * Removes the writable links of other objects to $object, empties its own writable links and resets its writable
     * writeOnly Fields and Slots to their default. Store queries must find $object restored.
     *
     * @return list<array{ResourceMapping, object}> the other objects that lost a link, with their mapping
     *
     * @throws ScimException 400 invalidValue when a converter fails
     */
    public function reset(ResourceMapping $mapping, object $object): array
    {
        $id = $this->references->idOf($mapping, $object);
        $changed = [];

        foreach ($this->mappings->all() as $owner) {
            foreach (array_filter($owner->attributes, static fn (AttributeMapping $attribute): bool => self::refersTo($attribute, $mapping)) as $link) {
                $changed = [...$changed, ...$this->detach($owner, $link, $object, $id)];
            }
        }

        foreach (array_filter($mapping->attributes, static fn (AttributeMapping $attribute): bool => $attribute->writable) as $attribute) {
            $this->clear($mapping, $attribute, $object);
        }

        return $changed;
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

    private static function refersTo(AttributeMapping $attribute, ResourceMapping $target): bool
    {
        return \in_array($attribute->kind, [MappingKind::Reference, MappingKind::ReferenceCollection], true)
            && 0 === strcasecmp((string) $attribute->targetType, $target->name())
            && $attribute->writable
            && (MappingKind::ReferenceCollection === $attribute->kind || $attribute->nullable);
    }

    /**
     * The delta store path when a member PATCH takes it, else the remover or the setter.
     *
     * @return list<array{ResourceMapping, object}>
     */
    private function detach(ResourceMapping $owner, AttributeMapping $link, object $object, string $id): array
    {
        $plan = $this->planner->plan(new ListQuery(new Comparison($link->path->withSubAttribute('value'), ComparisonOperator::Equal, $id)), $owner);
        $store = $this->references->store($owner);
        // shortcut: loads every object that refers to $object at once; page the query if that set can be large
        $others = false === $plan ? [] : $store->query($owner, new ObjectQuery($plan->filter, [], SortOrder::Ascending, 1, \PHP_INT_MAX))->objects;
        $delta = \in_array($link, $this->deltas->collections($owner)[strtolower((string) $link->path)] ?? [], true);

        foreach ($others as $other) {
            match (true) {
                MappingKind::Reference === $link->kind => $this->values->set($link, $other, null),
                $delta && $store instanceof CollectionDeltaStore => $store->removeElements($owner, $other, $link, [$id]),
                default => $other->{(string) $link->remover}($object),
            };
        }

        return array_map(static fn (object $other): array => [$owner, $other], $others);
    }

    private function clear(ResourceMapping $mapping, AttributeMapping $attribute, object $object): void
    {
        match (true) {
            MappingKind::ReferenceCollection === $attribute->kind => array_map(static fn (object $element): mixed => $object->{(string) $attribute->remover}($element), $this->elements($attribute, $object)),
            MappingKind::Reference === $attribute->kind && $attribute->nullable => $this->values->set($attribute, $object, null),
            Mutability::WriteOnly === $attribute->definition->mutability && \in_array($attribute->kind, [MappingKind::Field, MappingKind::Slot], true) => $this->values->set($attribute, $object, $this->values->fromScim($mapping, $attribute, $attribute->default, $object)),
            default => null,
        };
    }

    /**
     * @return list<object>
     */
    private function elements(AttributeMapping $collection, object $owner): array
    {
        $value = $this->values->get($collection, $owner);

        return array_values(array_filter(is_iterable($value) ? iterator_to_array($value, false) : [], \is_object(...)));
    }
}

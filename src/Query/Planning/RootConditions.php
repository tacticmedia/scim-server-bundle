<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Query\Planning;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Comparison;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Filter\Present;
use TacticMedia\ScimServer\Filter\ValuePathFilter;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingKind;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Query\Plan\Node;

/**
 * Plans a resolved filter on the objects of one mapping. A condition on a sub-attribute of a multi-valued or
 * related attribute holds when one element satisfies it.
 *
 * @internal
 */
final readonly class RootConditions
{
    public function __construct(
        private ResourceMapping $mapping,
        private MemberConditions $members,
        private ElementConditions $elements,
    ) {
    }

    /**
     * @throws ScimException invalidFilter
     */
    public function plan(Filter $filter): Node|bool
    {
        return FilterWalker::plan($filter, $this->leaf(...), $this->valuePath(...), $this->notEqual(...));
    }

    /**
     * ne of a multi-valued attribute: no element has a value, or one element has a value that is not equal.
     */
    private function notEqual(Comparison $equal): Node|bool
    {
        if (true !== $this->mapping->resourceType->resolve($equal->path)?->attribute->multiValued) {
            return Fold::not($this->leaf($equal));
        }

        $present = new Present($equal->path);

        return Fold::anyOf([
            Fold::not($this->leaf($present)),
            $this->inElements($equal->path, static fn (\Closure $inElement): Node|bool => Fold::allOf([$inElement($present), Fold::not($inElement($equal))])),
        ]);
    }

    private function leaf(Comparison|Present $leaf): Node|bool
    {
        $mappings = $this->mapping->attributesAt($leaf->path);

        return 1 === \count($mappings) && MemberConditions::isValue($mappings[0])
            ? $this->members->of($this->mapping, $mappings[0], $leaf)
            : $this->inElements($leaf->path, static fn (\Closure $inElement): Node|bool => $inElement($leaf));
    }

    private function valuePath(ValuePathFilter $filter): Node|bool
    {
        $nested = static fn (ValuePathFilter $inner): never => throw ScimException::invalidFilter(\sprintf("Value paths do not nest: '%s[...]'.", $inner->path));

        return $this->inElements($filter->path, static fn (\Closure $inElement): Node|bool => FilterWalker::plan($filter->filter, $inElement, $nested));
    }

    /**
     * One related object, or one element of an ElementCollection that the read renders, satisfies $condition.
     *
     * @param \Closure(\Closure(Comparison|Present): (Node|bool)): (Node|bool) $condition
     */
    private function inRelation(AttributeMapping $relation, \Closure $condition): Node|bool
    {
        if (MappingKind::ElementCollection !== $relation->kind) {
            return Fold::exists($relation, $condition(fn (Comparison|Present $leaf): Node|bool => $this->elements->inRelation($relation, $leaf)));
        }

        return Fold::exists($relation, Fold::allOf([ElementConditions::rendered($relation), $condition(fn (Comparison|Present $leaf): Node|bool => $this->elements->inElement($relation, $leaf))]));
    }

    /**
     * @param \Closure(\Closure(Comparison|Present): (Node|bool)): (Node|bool) $condition gets the leaf planner of one element
     */
    private function inElements(AttributePath $path, \Closure $condition): Node|bool
    {
        $relations = array_values(array_filter(
            $this->mapping->attributesAt($path->parent()),
            static fn (AttributeMapping $mapping): bool => \in_array($mapping->kind, [MappingKind::Reference, MappingKind::ReferenceCollection, MappingKind::ElementCollection], true),
        ));
        $groups = ElementGroup::of($this->mapping, $path);

        return match (true) {
            [] !== $relations => Fold::anyOf(array_map(fn (AttributeMapping $relation): Node|bool => $this->inRelation($relation, $condition), $relations)),
            [] !== $groups => Fold::anyOf(array_map(
                fn (ElementGroup $group): Node|bool => Fold::allOf([$group->exists(), $condition(fn (Comparison|Present $leaf): Node|bool => $this->elements->inGroup($group, $leaf))]),
                $groups,
            )),
            default => throw FilterRejection::of($this->mapping, $path),
        };
    }
}

<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Query\Planning;

use TacticMedia\ScimServer\Filter\Comparison;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Filter\FilterEvaluator;
use TacticMedia\ScimServer\Filter\Present;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingKind;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Query\Plan\IsPresent;
use TacticMedia\ScimServerBundle\Query\Plan\Node;

/**
 * Conditions on one sub-attribute of one element: of an element group, or of the related object of a Reference or
 * ReferenceCollection. "type" and "primary" fold to the constant that the rendered element gives.
 *
 * @internal
 */
final readonly class ElementConditions
{
    public function __construct(
        private ResourceMapping $mapping,
        private MappingRegistry $mappings,
        private MemberConditions $members,
        private FilterEvaluator $evaluator = new FilterEvaluator(),
    ) {
    }

    public function inGroup(ElementGroup $group, Comparison|Present $leaf): Node|bool
    {
        $path = $leaf->path;
        $member = $group->member($path);

        return match (true) {
            $member instanceof AttributeMapping => $this->members->of($this->mapping, $member, $leaf),
            null === $path->subAttribute => $leaf instanceof Present,
            \in_array(strtolower($path->subAttribute), ['type', 'primary'], true) => $this->constant($leaf, $group->element()),
            array_any($this->mapping->attributesAt($path), static fn (AttributeMapping $other): bool => MappingKind::Slot === $other->kind) => false,
            default => throw FilterRejection::of($this->mapping, $path),
        };
    }

    public function inRelation(AttributeMapping $relation, Comparison|Present $leaf): Node|bool
    {
        return match (strtolower((string) $leaf->path->subAttribute)) {
            'value' => $this->relatedId($relation, $leaf),
            'type' => $this->constant($leaf, ['type' => $relation->type]),
            default => throw FilterRejection::notFilterable($leaf->path),
        };
    }

    /**
     * A leaf on a sub-attribute of one element of an ElementCollection. A leaf on the attribute itself is a comparison
     * on a complex value, which the library never holds, or pr, which {@see rendered()} decides.
     */
    public function inElement(AttributeMapping $collection, Comparison|Present $leaf): Node|bool
    {
        $member = $collection->element?->attributeAt($leaf->path);

        return match (true) {
            $member instanceof AttributeMapping => $this->members->of($this->mapping, $member, $leaf),
            null === $leaf->path->subAttribute => $leaf instanceof Present,
            default => throw FilterRejection::notSupported($leaf->path),
        };
    }

    /**
     * The read renders the element: a member that is not writeOnly renders a value. A member that is not nullable,
     * has no converter and is not a string always renders one.
     */
    public static function rendered(AttributeMapping $collection): Node|bool
    {
        $readable = array_filter($collection->element->attributes ?? [], static fn (AttributeMapping $member): bool => Mutability::WriteOnly !== $member->definition->mutability);

        return Fold::anyOf(array_values(array_map(
            static fn (AttributeMapping $member): Node|bool => $member->nullable || null !== $member->converter || MemberConditions::holdsString($member) ? new IsPresent($member) : true,
            $readable,
        )));
    }

    private function relatedId(AttributeMapping $relation, Comparison|Present $leaf): Node|bool
    {
        if ($leaf instanceof Present) {
            return true;
        }

        if (ComparisonOperator::Equal !== $leaf->operator) {
            throw FilterRejection::operator($leaf->operator, $leaf->path);
        }

        $target = $this->mappings->get((string) $relation->targetType);

        return $this->members->compare($target, $target->id, ComparisonOperator::Equal, $leaf->value);
    }

    /**
     * @param array<string, mixed> $element
     */
    private function constant(Comparison|Present $leaf, array $element): bool
    {
        $attribute = $this->mapping->resourceType->resolve($leaf->path->parent()) ?? throw FilterRejection::notSupported($leaf->path);

        return $this->evaluator->matchesElement($leaf, $attribute, $element);
    }
}

<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;

/**
 * Infers the mapping kind from the member, the path and the schema.
 *
 * @internal
 */
final class KindResolver
{
    public const string NOT_ELEMENT_ATTRIBUTE = 'has elementClass:, which needs a whole multi-valued complex attribute that does not refer to resources, for example "emails". Map such an attribute, or remove elementClass:.';
    private const array NOT_RESOURCE_TYPES = ['external', 'uri'];

    /**
     * @return array{MappingKind, string|null} the kind and the target resource type of a reference
     */
    public static function resolve(Member $member, ResolvedPath $path): array
    {
        $attribute = $path->attribute->attribute;

        return match (true) {
            null !== $member->attribute->elementClass => [self::elementCollection($member, $path), null],
            $member->isMethod() => null === $path->type ? [MappingKind::Computed, null] : throw $member->fail('has a value filter. A method maps a path without [...].'),
            !$path->attribute->subAttribute instanceof AttributeDefinition && [] !== self::referenceTypes($attribute) => self::reference($member, $attribute, $path->type),
            null !== $path->type => [self::slot($member, $path), null],
            default => [self::field($member, $attribute, $path), null],
        };
    }

    /**
     * Resource types that the $ref sub-attribute names; empty when $attribute is not a reference to resources.
     *
     * @return list<string>
     */
    private static function referenceTypes(AttributeDefinition $attribute): array
    {
        $types = $attribute->subAttribute('$ref')->referenceTypes ?? [];

        return array_values(array_diff($types, self::NOT_RESOURCE_TYPES)) === $types ? $types : [];
    }

    /**
     * @return array{MappingKind, string}
     */
    private static function reference(Member $member, AttributeDefinition $attribute, ?string $type): array
    {
        $types = self::referenceTypes($attribute);

        if (!$attribute->multiValued) {
            return null === $type ? [MappingKind::Reference, $types[0]] : throw $member->fail('has a value filter, but only a multi-valued attribute takes one.');
        }

        $named = array_find($types, static fn (string $candidate): bool => 0 === strcasecmp($candidate, (string) $type));

        return [MappingKind::ReferenceCollection, $named ?? (1 === \count($types) ? $types[0] : throw $member->fail(\sprintf('refers to %s. Name one: %s[type eq "%s"].', implode(' and ', $types), $attribute->name, $types[0])))];
    }

    private static function elementCollection(Member $member, ResolvedPath $path): MappingKind
    {
        $attribute = $path->attribute->attribute;
        $whole = null === $path->type && !$path->attribute->subAttribute instanceof AttributeDefinition && $attribute->multiValued && AttributeType::Complex === $attribute->type;

        return match (true) {
            $member->isMethod() => throw $member->fail('has elementClass:, which needs a property. Move elementClass: to a collection property with an adder and a remover.'),
            !$whole || [] !== self::referenceTypes($attribute) => throw $member->fail(self::NOT_ELEMENT_ATTRIBUTE),
            null !== $member->attribute->converter => throw $member->fail('has converter: and elementClass:. Set converter: on the properties of the element class.'),
            default => MappingKind::ElementCollection,
        };
    }

    private static function slot(Member $member, ResolvedPath $path): MappingKind
    {
        $attribute = $path->attribute->attribute;
        $subAttribute = $path->attribute->subAttribute;

        if (!$attribute->multiValued || !$attribute->subAttribute('type') instanceof AttributeDefinition) {
            throw $member->fail(\sprintf('has a value filter, but "%s" is not a multi-valued attribute with a type sub-attribute.', $attribute->name));
        }

        return $subAttribute instanceof AttributeDefinition && !\in_array($subAttribute->name, ['type', 'primary'], true)
            ? MappingKind::Slot
            : throw $member->fail(\sprintf('maps no element value. Name one sub-attribute other than type and primary, for example %s[type eq "%s"].value; set primary: for primary.', $attribute->name, $path->type));
    }

    private static function field(Member $member, AttributeDefinition $attribute, ResolvedPath $path): MappingKind
    {
        if ($attribute->multiValued) {
            throw $member->fail(\sprintf('is multi-valued. Map one element: %s[type eq "<type>"].<sub-attribute>.', $attribute->name));
        }

        return AttributeType::Complex === $attribute->type && !$path->attribute->subAttribute instanceof AttributeDefinition
            ? throw $member->fail(\sprintf('is complex. Map one sub-attribute, for example %s.%s.', $attribute->name, $attribute->subAttributes[0]->name ?? 'value')) : MappingKind::Field;
    }
}

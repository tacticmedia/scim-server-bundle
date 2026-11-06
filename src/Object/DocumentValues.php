<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Object;

use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingKind;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;

/**
 * Reads the values of mappings from a document that the library normalized: schema spelling, no null values.
 *
 * @internal
 */
final class DocumentValues
{
    /**
     * The value of the top-level attribute of $attribute.
     *
     * @param array<string, mixed> $document
     */
    public static function attribute(ResourceMapping $mapping, AttributeMapping $attribute, array $document): mixed
    {
        $path = $attribute->path;
        $container = $path->schemaUrn === $mapping->resourceType->schema->id ? $document : $document[(string) $path->schemaUrn] ?? null;

        return \is_array($container) ? $container[$path->attribute] ?? null : null;
    }

    /**
     * The value of a Field, or of a Slot in the element of its group.
     *
     * @param array<string, mixed> $document
     */
    public static function scalar(ResourceMapping $mapping, AttributeMapping $attribute, array $document): mixed
    {
        $value = self::attribute($mapping, $attribute, $document);
        $parent = MappingKind::Slot === $attribute->kind ? self::element($value, $attribute) : $value;
        $subAttribute = $attribute->path->subAttribute;

        return null === $subAttribute ? $parent : (\is_array($parent) ? $parent[$subAttribute] ?? null : null);
    }

    /**
     * The id of a single reference value.
     */
    public static function referenceId(mixed $value): ?string
    {
        return self::stringAt($value, 'value');
    }

    /**
     * @return array<string, string|null> the type of each referenced id, in document order; the first element of an id wins
     */
    public static function referenceIds(mixed $elements): array
    {
        $types = [];

        foreach (\is_array($elements) ? $elements : [] as $element) {
            $id = self::stringAt($element, 'value');

            if (null !== $id) {
                $types += [$id => self::stringAt($element, 'type')];
            }
        }

        return $types;
    }

    /**
     * An element goes to the group of its type, an element without type to the primary group. Of two elements, the
     * primary one wins, else the first.
     *
     * @return array<mixed>|null
     */
    private static function element(mixed $elements, AttributeMapping $slot): ?array
    {
        $candidates = array_values(array_filter(\is_array($elements) ? $elements : [], static fn (mixed $element): bool => \is_array($element) && self::inGroup($element, $slot)));

        return array_find($candidates, static fn (array $element): bool => true === ($element['primary'] ?? null)) ?? $candidates[0] ?? null;
    }

    /**
     * @param array<mixed> $element
     */
    private static function inGroup(array $element, AttributeMapping $slot): bool
    {
        $type = self::stringAt($element, 'type');

        return null === $type ? $slot->primary : 0 === strcasecmp($type, (string) $slot->type);
    }

    private static function stringAt(mixed $value, string $key): ?string
    {
        return \is_array($value) && \is_string($value[$key] ?? null) ? $value[$key] : null;
    }
}

<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Schema;

use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServer\Schema\SchemaExtension;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingKind;

/**
 * Builds the published resource type: only mapped attributes and only extensions with a mapped attribute. The
 * common attributes (id, externalId, meta) are in no schema; the library resolves them itself.
 */
final readonly class SchemaBuilder
{
    /**
     * @param ResourceType           $available  every schema that the class may map
     * @param list<AttributeMapping> $attributes
     */
    public function publish(ResourceType $available, array $attributes): ResourceType
    {
        $extensions = array_filter(array_map(
            fn (SchemaExtension $extension): SchemaExtension => new SchemaExtension($this->schema($extension->schema, $attributes), $extension->required),
            $available->schemaExtensions,
        ), static fn (SchemaExtension $extension): bool => [] !== $extension->schema->attributes);

        return new ResourceType($available->name, $available->endpoint, $this->schema($available->schema, $attributes), array_values($extensions), $available->description);
    }

    /**
     * @param list<AttributeMapping> $attributes
     */
    private function schema(Schema $schema, array $attributes): Schema
    {
        $published = [];

        foreach ($schema->attributes as $definition) {
            $mapped = array_values(array_filter($attributes, static fn (AttributeMapping $mapping): bool => 0 === strcasecmp((string) $mapping->path->schemaUrn, $schema->id) && 0 === strcasecmp($mapping->path->attribute, $definition->name)));

            if ([] !== $mapped) {
                $published[] = $this->attribute($definition, $mapped);
            }
        }

        return new Schema($schema->id, $schema->name, $schema->description, $published);
    }

    /**
     * @param non-empty-list<AttributeMapping> $mapped
     */
    private function attribute(AttributeDefinition $definition, array $mapped): AttributeDefinition
    {
        $whole = array_find($mapped, static fn (AttributeMapping $mapping): bool => null === $mapping->path->subAttribute);

        return match (true) {
            MappingKind::ReferenceCollection === $whole?->kind => AttributeDefinitions::with($definition, subAttributes: $this->subAttributes($definition, $mapped, true)),
            $whole instanceof AttributeMapping => $whole->definition,
            default => AttributeDefinitions::with($definition, subAttributes: $this->subAttributes($definition, $mapped, false)),
        };
    }

    /**
     * Schema order. $all keeps every sub-attribute (reference collections), else only the mapped ones. With a value
     * filter on a mapping, "type" gets the filter literals as canonicalValues, and "primary" is kept.
     *
     * @param non-empty-list<AttributeMapping> $mapped
     *
     * @return list<AttributeDefinition>
     */
    private function subAttributes(AttributeDefinition $parent, array $mapped, bool $all): array
    {
        $types = array_values(array_unique(array_filter(array_map(static fn (AttributeMapping $mapping): ?string => $mapping->type, $mapped), static fn (?string $type): bool => null !== $type)));
        $subAttributes = [];

        foreach ($parent->subAttributes as $subAttribute) {
            $subAttributes[] = array_find($mapped, static fn (AttributeMapping $mapping): bool => $mapping->path->subAttribute === $subAttribute->name)->definition
                ?? $this->unmapped($subAttribute, $types, $all);
        }

        return array_values(array_filter($subAttributes));
    }

    /**
     * @param list<string> $types
     */
    private function unmapped(AttributeDefinition $subAttribute, array $types, bool $all): ?AttributeDefinition
    {
        return match (true) {
            'type' === $subAttribute->name => [] === $types ? null : AttributeDefinitions::with($subAttribute, canonicalValues: $types),
            $all || ([] !== $types && 'primary' === $subAttribute->name) => $subAttribute,
            default => null,
        };
    }
}

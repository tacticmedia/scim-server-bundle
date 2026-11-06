<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Object;

use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\SchemaExtension;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingKind;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;

/**
 * Maps objects of #[ScimResource] classes to SCIM documents and documents onto objects.
 */
final readonly class DocumentMapper
{
    private MemberValues $values;

    private References $references;

    private DocumentReader $reader;

    private CollectionWriter $collections;

    private ElementWriter $elements;

    /**
     * @param ServiceLocator<mixed> $converters ValueConverter services by class name
     * @param ServiceLocator<mixed> $stores     ObjectStore services by class name
     */
    public function __construct(
        MappingRegistry $mappings,
        ServiceLocator $converters,
        ServiceLocator $stores,
        PropertyAccessorInterface $accessor = new PropertyAccessor(),
    ) {
        $this->values = new MemberValues($converters, $accessor);
        $this->references = new References($mappings, $stores, $this->values);
        $this->reader = new DocumentReader($this->values, $this->references);
        $this->collections = new CollectionWriter($this->references);
        $this->elements = new ElementWriter($this->values);
    }

    /**
     * @return array<string, mixed> canonical document, without meta.resourceType and meta.location
     */
    public function toDocument(ResourceMapping $mapping, object $object): array
    {
        $type = $mapping->resourceType;
        $extensions = array_map(static fn (SchemaExtension $extension): string => $extension->schema->id, $type->schemaExtensions);
        $document = array_fill_keys(['schemas', 'id', 'externalId', 'meta', ...array_map(static fn (AttributeDefinition $attribute): string => $attribute->name, $type->schema->attributes), ...$extensions], null);
        $readable = array_filter($mapping->attributes, static fn (AttributeMapping $attribute): bool => Mutability::WriteOnly !== $attribute->definition->mutability);

        foreach ($this->byAttribute($readable) as $attributes) {
            $path = $attributes[0]->path;
            $value = $this->reader->value($mapping, $attributes, $object);

            if ($path->schemaUrn === $type->schema->id) {
                $document[$path->attribute] = $value;
            } elseif (null !== $value) {
                $document[(string) $path->schemaUrn][$path->attribute] = $value;
            }
        }

        $listed = array_filter($type->schemaExtensions, static fn (SchemaExtension $extension): bool => $extension->required || null !== $document[$extension->schema->id]);
        $document['schemas'] = [$type->schema->id, ...array_values(array_map(static fn (SchemaExtension $extension): string => $extension->schema->id, $listed))];

        return array_filter($document, static fn (mixed $value): bool => null !== $value);
    }

    /**
     * Writes every writable mapping from $document onto $object: readOnly never, immutable on create or while it has
     * no value. An absent attribute writes its default, else null, else an empty collection; an absent writeOnly
     * attribute keeps the stored value.
     *
     * @param array<string, mixed> $document normalized by the library
     *
     * @throws ScimException 400 invalidValue
     */
    public function write(ResourceMapping $mapping, array $document, object $object, bool $create): void
    {
        $writable = array_filter($mapping->attributes, fn (AttributeMapping $attribute): bool => $this->writes($mapping, $attribute, $object, $create));

        foreach ($this->byAttribute($writable) as $attributes) {
            $this->writeAttribute($mapping, $attributes, $document, $object);
        }
    }

    /**
     * The value that write() gives the member of a Field, Slot or Reference mapping, without an owner object, for
     * a constructor argument. Null for other kinds.
     *
     * @param array<string, mixed> $document normalized by the library
     *
     * @throws ScimException 400 invalidValue
     */
    public function valueOf(ResourceMapping $mapping, AttributeMapping $attribute, array $document): mixed
    {
        return match ($attribute->kind) {
            MappingKind::Field, MappingKind::Slot => $this->values->fromScim($mapping, $attribute, DocumentValues::scalar($mapping, $attribute, $document) ?? $attribute->default, null),
            MappingKind::Reference => $this->resolve($attribute, $this->referenceId($mapping, $attribute, $document)),
            default => null,
        };
    }

    /**
     * @param non-empty-list<AttributeMapping> $attributes
     * @param array<string, mixed>             $document
     */
    private function writeAttribute(ResourceMapping $mapping, array $attributes, array $document, object $object): void
    {
        if (MappingKind::ReferenceCollection === $attributes[0]->kind) {
            $this->collections->write($attributes, DocumentValues::attribute($mapping, $attributes[0], $document), $object);

            return;
        }

        if (MappingKind::ElementCollection === $attributes[0]->kind) {
            $this->elements->write($mapping, $attributes[0], DocumentValues::attribute($mapping, $attributes[0], $document), $object);

            return;
        }

        foreach ($attributes as $attribute) {
            if (MappingKind::Reference === $attribute->kind) {
                $this->writeReference($mapping, $attribute, $document, $object);
            } else {
                $this->writeScalar($mapping, $attribute, $document, $object);
            }
        }
    }

    /**
     * @param array<string, mixed> $document
     */
    private function writeScalar(ResourceMapping $mapping, AttributeMapping $attribute, array $document, object $object): void
    {
        $value = DocumentValues::scalar($mapping, $attribute, $document);

        if (null !== $value || Mutability::WriteOnly !== $attribute->definition->mutability) {
            $this->values->set($attribute, $object, $this->values->fromScim($mapping, $attribute, $value ?? $attribute->default, $object));
        }
    }

    /**
     * Unchanged ids need no store lookup.
     *
     * @param array<string, mixed> $document
     */
    private function writeReference(ResourceMapping $mapping, AttributeMapping $reference, array $document, object $object): void
    {
        $id = $this->referenceId($mapping, $reference, $document);

        if (!$this->keeps($reference, $this->values->get($reference, $object), $id)) {
            $this->values->set($reference, $object, $this->resolve($reference, $id));
        }
    }

    /**
     * The current target stays when it has the id $id, or when it is soft-deleted and $id is null.
     */
    private function keeps(AttributeMapping $reference, mixed $current, ?string $id): bool
    {
        $target = $this->references->target($reference);

        return match (true) {
            !\is_object($current) => false,
            $this->references->isDeleted($target, $current) => null === $id,
            default => $this->references->idOf($target, $current) === $id,
        };
    }

    private function resolve(AttributeMapping $reference, ?string $id): ?object
    {
        return null === $id ? null : $this->references->resolve($reference, $this->references->target($reference), $id);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function referenceId(ResourceMapping $mapping, AttributeMapping $reference, array $document): ?string
    {
        return DocumentValues::referenceId(DocumentValues::attribute($mapping, $reference, $document));
    }

    /**
     * On replace, an immutable Field or Slot is written only while it renders no value: a stored value written back
     * from its rendering can lose precision. The library rejects a change of an assigned value, and the reference
     * writers apply only differences.
     */
    private function writes(ResourceMapping $mapping, AttributeMapping $attribute, object $object, bool $create): bool
    {
        return $attribute->writable && match ($attribute->definition->mutability) {
            Mutability::ReadOnly => false,
            Mutability::Immutable => $create || \in_array($attribute->kind, [MappingKind::Reference, MappingKind::ReferenceCollection], true) || null === $this->reader->value($mapping, [$attribute], $object),
            Mutability::ReadWrite, Mutability::WriteOnly => true,
        };
    }

    /**
     * @param array<AttributeMapping> $attributes
     *
     * @return list<non-empty-list<AttributeMapping>> the mappings of each top-level attribute, in declaration order
     */
    private function byAttribute(array $attributes): array
    {
        $groups = [];

        foreach ($attributes as $attribute) {
            $groups[strtolower((string) $attribute->path->parent())][] = $attribute;
        }

        return array_values($groups);
    }
}

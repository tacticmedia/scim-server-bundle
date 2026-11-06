<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServerBundle\Object\ObjectFactory;
use TacticMedia\ScimServerBundle\Object\ObjectStore;

/**
 * The compiled mapping of one #[ScimResource] class.
 */
final readonly class ResourceMapping
{
    /**
     * @param class-string                     $class
     * @param ResourceType                     $resourceType          published: only mapped attributes and extensions with mapped attributes
     * @param list<AttributeMapping>           $attributes            declaration order: properties, then methods, then an implicit id; contains $id
     * @param list<ConstructorParameter>       $constructorParameters empty when $factory is set or the constructor has no parameter
     * @param class-string<ObjectFactory>|null $factory
     * @param class-string<ObjectStore>|null   $store                 null: the Doctrine ORM store
     * @param list<string>|null                $validationGroups      null: no validation
     * @param SoftDelete|null                  $softDelete            null: DELETE removes the object
     */
    public function __construct(
        public string $class,
        public ResourceType $resourceType,
        public AttributeMapping $id,
        public array $attributes,
        public array $constructorParameters = [],
        public ?string $factory = null,
        public ?string $store = null,
        public ?string $idPrefix = null,
        public ?array $validationGroups = null,
        public bool $deltas = true,
        public ?SoftDelete $softDelete = null,
    ) {
    }

    /**
     * @param list<AttributeMapping> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        return clone ($this, ['attributes' => $attributes]);
    }

    public function name(): string
    {
        return $this->resourceType->name;
    }

    /**
     * The mappings of exactly $path (URN-qualified, any case). Several for Slot groups and ReferenceCollections.
     *
     * @return list<AttributeMapping>
     */
    public function attributesAt(AttributePath $path): array
    {
        return array_values(array_filter($this->attributes, static fn (AttributeMapping $mapping): bool => $mapping->path->equals($path)));
    }

    /**
     * The mappings of the top-level attribute of $path (URN-qualified, any case), its sub-attributes included.
     *
     * @return list<AttributeMapping>
     */
    public function attributesOf(AttributePath $path): array
    {
        return array_values(array_filter($this->attributes, static fn (AttributeMapping $mapping): bool => $mapping->path->parent()->equals($path->parent())));
    }
}

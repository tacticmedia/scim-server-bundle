<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServerBundle\Converter\ValueConverter;

/**
 * The compiled mapping of one #[ScimAttribute], or of the implicit id property.
 */
final readonly class AttributeMapping
{
    /**
     * @param AttributePath                     $path          URN-qualified, schema spelling, no value filter: "urn:...:User:emails.value", "urn:...:Group:members"
     * @param string                            $declaredPath  the argument of #[ScimAttribute]
     * @param AttributeDefinition               $definition    the published definition of the mapped attribute or sub-attribute
     * @param string                            $member        property or method name
     * @param string|null                       $phpType       native type name with "self" resolved ("string", "int", a class name); null when untyped or a union of several types
     * @param bool                              $writable      a setter, a writable public property, or for a ReferenceCollection an adder and a remover exist
     * @param bool                              $required      the library rejects a create or replace document without a value for $path
     * @param class-string<ValueConverter>|null $converter
     * @param string|null                       $type          the value filter literal: the "type" of a Slot or ReferenceCollection element
     * @param bool                              $primary       the Slot group whose element gets "primary": true
     * @param string|null                       $targetType    resource type name of a Reference or ReferenceCollection target
     * @param ElementMapping|null               $element       the element class of an ElementCollection
     * @param string|null                       $dqlExpression the #[ScimDqlExpression] of a Computed mapping, with {alias}
     */
    public function __construct(
        public AttributePath $path,
        public string $declaredPath,
        public AttributeDefinition $definition,
        public MappingKind $kind,
        public string $member,
        public bool $method,
        public ?string $phpType,
        public bool $nullable,
        public bool $writable,
        public bool $required,
        public ?string $adder = null,
        public ?string $remover = null,
        public ?string $converter = null,
        public ?string $type = null,
        public bool $primary = false,
        public ?string $targetType = null,
        public string|int|float|bool|null $default = null,
        public ?ElementMapping $element = null,
        public ?string $dqlExpression = null,
    ) {
    }

    /** "$email" for a property, "getFullName()" for a method. */
    public function label(): string
    {
        return $this->method ? $this->member.'()' : '$'.$this->member;
    }

    /** @internal */
    public function asPrimary(): self
    {
        return clone ($this, ['primary' => true]);
    }
}

<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

use TacticMedia\ScimServer\Path\AttributePath;

/**
 * The compiled element class of an ElementCollection. Its attributes are Field mappings of the sub-attributes, with
 * the path of the collection attribute: "urn:...:User:emails.value".
 */
final readonly class ElementMapping
{
    /**
     * @param class-string                     $class
     * @param non-empty-list<AttributeMapping> $attributes            schema order
     * @param list<ConstructorParameter>       $constructorParameters every constructor parameter but the owner
     * @param string|null                      $ownerParameter        the constructor parameter that gets the owner object
     */
    public function __construct(
        public string $class,
        public array $attributes,
        public array $constructorParameters,
        public ?string $ownerParameter,
    ) {
    }

    /**
     * The mapping of exactly $path (URN-qualified, any case).
     */
    public function attributeAt(AttributePath $path): ?AttributeMapping
    {
        return array_find($this->attributes, static fn (AttributeMapping $attribute): bool => $attribute->path->equals($path));
    }
}

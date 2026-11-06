<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Converter;

use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;

final readonly class ConversionContext
{
    /**
     * @param object|null $object the object that owns the member; null for a constructor argument and a filter literal
     */
    public function __construct(
        public ResourceMapping $resource,
        public AttributeMapping $attribute,
        public ?object $object = null,
    ) {
    }
}

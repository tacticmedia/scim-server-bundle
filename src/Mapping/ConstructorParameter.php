<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

/**
 * One constructor parameter of a mapped class, in declaration order. The value is the value that the document
 * mapper writes for $attribute, else null. A null value for an optional parameter means: omit the argument.
 */
final readonly class ConstructorParameter
{
    public function __construct(
        public string $name,
        public ?AttributeMapping $attribute,
        public bool $optional,
    ) {
    }
}

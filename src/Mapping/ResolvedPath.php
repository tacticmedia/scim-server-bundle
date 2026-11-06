<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

use TacticMedia\ScimServer\Schema\ResolvedAttribute;

/**
 * @internal
 */
final readonly class ResolvedPath
{
    /**
     * @param string|null $type       the literal of a [type eq "..."] value filter
     * @param bool        $appDefined the schema of the attribute is one that the application defines
     * @param bool        $common     id, externalId or meta (RFC 7643 section 3.1)
     */
    public function __construct(
        public ResolvedAttribute $attribute,
        public ?string $type,
        public bool $appDefined,
        public bool $common,
    ) {
    }

    public function isId(): bool
    {
        return $this->common && 'id' === $this->attribute->attribute->name;
    }

    /**
     * True when a create or replace document may omit the value.
     */
    public function isOptional(): bool
    {
        $required = $this->attribute->attribute->required && ($this->attribute->subAttribute->required ?? true);

        return null !== $this->type || !$required;
    }
}

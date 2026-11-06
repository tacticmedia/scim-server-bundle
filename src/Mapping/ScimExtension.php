<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

/**
 * A schema extension that the application defines. Its attributes come from the #[ScimAttribute] mappings with
 * paths qualified by $urn.
 */
final readonly class ScimExtension
{
    public function __construct(
        public string $urn,
        public string $name,
        public string $description = '',
        public bool $required = false,
    ) {
    }
}

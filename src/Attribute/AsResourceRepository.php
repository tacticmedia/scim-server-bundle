<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Attribute;

use TacticMedia\ScimServer\Schema\Schema;

/**
 * On a class that implements the library ResourceRepository: registers it as the repository of resource type $name.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsResourceRepository
{
    /**
     * @param list<string> $attributes supported paths in mapping syntax; /Schemas publishes only these
     * @param list<Schema> $schemas    schemas that RFC 7643 does not define (core schema or extensions)
     */
    public function __construct(
        public string $name,
        public array $attributes,
        public ?string $endpoint = null,
        public ?string $schema = null,
        public array $schemas = [],
        public string $description = '',
    ) {
    }
}

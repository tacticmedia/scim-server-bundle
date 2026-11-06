<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Schema;

use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;

/**
 * The published resource type of each name: from the mapping of a #[ScimResource] class, or from the
 * #[AsResourceRepository] of a raw repository.
 */
final class PublishedResourceTypes
{
    /** @var array<string, ResourceType> */
    private array $raw = [];

    /**
     * @param array<string, class-string> $repositoryClasses raw repository class by resource type name
     */
    public function __construct(
        private readonly MappingRegistry $mappings,
        private readonly array $repositoryClasses = [],
        private readonly RawResourceTypeLoader $loader = new RawResourceTypeLoader(),
    ) {
    }

    public function get(string $name): ResourceType
    {
        $class = $this->repositoryClasses[$name] ?? null;

        return null === $class ? $this->mappings->get($name)->resourceType : $this->raw[$name] ??= $this->loader->load($class);
    }
}

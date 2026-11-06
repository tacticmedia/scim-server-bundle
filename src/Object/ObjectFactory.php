<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Object;

/**
 * Creates the object of a POST, for a class with #[ScimResource(factory: ...)]. The document mapper then writes
 * every writable mapped attribute onto the object.
 */
interface ObjectFactory
{
    /**
     * @param array<string, mixed> $document canonical: schema spelling, extension attributes under their URN key
     */
    public function create(array $document): object;
}

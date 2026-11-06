<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Schema;

use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\Uniqueness;

/**
 * @internal
 */
final class AttributeDefinitions
{
    /**
     * A copy of $definition with the given characteristics replaced.
     *
     * @param list<string>|null              $canonicalValues
     * @param list<AttributeDefinition>|null $subAttributes
     */
    public static function with(AttributeDefinition $definition, ?Mutability $mutability = null, ?array $canonicalValues = null, ?array $subAttributes = null, ?Uniqueness $uniqueness = null, ?bool $required = null): AttributeDefinition
    {
        return new AttributeDefinition(
            $definition->name,
            $definition->type,
            $definition->multiValued,
            $definition->description,
            $required ?? $definition->required,
            $definition->caseExact,
            $mutability ?? $definition->mutability,
            $definition->returned,
            $uniqueness ?? $definition->uniqueness,
            $canonicalValues ?? $definition->canonicalValues,
            $definition->referenceTypes,
            $subAttributes ?? $definition->subAttributes,
        );
    }
}

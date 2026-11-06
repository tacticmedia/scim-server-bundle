<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Query\Planning;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;

/**
 * @internal
 */
final class FilterRejection
{
    /**
     * "Cannot be used" when a mapping exists at $path or at its whole attribute, else "not supported".
     */
    public static function of(ResourceMapping $mapping, AttributePath $path): ScimException
    {
        return [] === $mapping->attributesAt($path) && [] === $mapping->attributesAt($path->parent())
            ? self::notSupported($path)
            : self::notFilterable($path);
    }

    public static function notSupported(AttributePath $path): ScimException
    {
        return ScimException::invalidFilter(\sprintf("The attribute '%s' is not supported.", $path));
    }

    public static function notFilterable(AttributePath $path): ScimException
    {
        return ScimException::invalidFilter(\sprintf("The attribute '%s' cannot be used in a filter.", $path));
    }

    public static function operator(ComparisonOperator $operator, AttributePath $path): ScimException
    {
        return ScimException::invalidFilter(\sprintf("The operator '%s' is not supported for '%s'.", $operator->value, $path));
    }
}

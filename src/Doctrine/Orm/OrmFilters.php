<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Doctrine\Orm;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * The SQL filters of a manager across resetManager(), which builds the new manager from the configuration. A filter
 * that the application enabled at runtime, for example a tenant filter, would otherwise be lost, and the next
 * operation of a Bulk request would run unfiltered.
 *
 * @internal
 */
final class OrmFilters
{
    /**
     * @return array<string, mixed> the unserialized SQLFilter::__toString() by filter name
     */
    public static function enabled(EntityManagerInterface $manager): array
    {
        return array_map(static fn (SQLFilter $filter): mixed => unserialize((string) $filter), $manager->getFilters()->getEnabledFilters());
    }

    /**
     * @param array<string, mixed> $filters as enabled() returns them
     */
    public static function enable(EntityManagerInterface $manager, array $filters): void
    {
        foreach ($filters as $name => $parameters) {
            self::restore($manager->getFilters()->enable($name), \is_array($parameters) ? $parameters : []);
        }
    }

    /**
     * @param array<mixed> $parameters
     */
    private static function restore(SQLFilter $filter, array $parameters): void
    {
        foreach ($parameters as $name => ['value' => $value, 'type' => $type, 'is_list' => $isList]) {
            // An inferred type can be a ParameterType, which setParameter() infers again from the value.
            $isList ? $filter->setParameterList((string) $name, $value, $type) : $filter->setParameter((string) $name, $value, \is_string($type) ? $type : null);
        }
    }
}

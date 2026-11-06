<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

/**
 * Checks across all mapped classes: unique names and endpoints, existing reference targets, unambiguous integer ids
 * (no two int-id targets of one reference collection with the same idPrefix or none).
 *
 * @internal
 */
final class MappingSetChecks
{
    /**
     * @param list<ResourceMapping> $mappings
     *
     * @return list<string>
     */
    public static function errors(array $mappings): array
    {
        $byName = array_column(array_map(static fn (ResourceMapping $mapping): array => [strtolower($mapping->name()), $mapping], $mappings), 1, 0);

        return [
            ...self::duplicates($mappings, static fn (ResourceMapping $mapping): string => $mapping->name(), 'resource type name'),
            ...self::duplicates($mappings, static fn (ResourceMapping $mapping): string => $mapping->resourceType->endpoint, 'endpoint'),
            ...array_merge(...array_map(static fn (ResourceMapping $mapping): array => self::references($mapping, $byName), $mappings)),
        ];
    }

    /**
     * @param list<ResourceMapping>             $mappings
     * @param \Closure(ResourceMapping): string $key
     *
     * @return list<string>
     */
    private static function duplicates(array $mappings, \Closure $key, string $what): array
    {
        $groups = [];

        foreach ($mappings as $mapping) {
            $groups[strtolower($key($mapping))][] = $mapping->class;
        }

        $duplicates = array_filter($groups, static fn (array $classes): bool => \count($classes) > 1);

        return array_values(array_map(
            static fn (array $classes): string => \sprintf('%s use one %s. Give each class its own #[ScimResource] name and endpoint.', implode(' and ', $classes), $what),
            $duplicates,
        ));
    }

    /**
     * @param array<string, ResourceMapping> $byName keyed by lowercase name
     *
     * @return list<string>
     */
    private static function references(ResourceMapping $mapping, array $byName): array
    {
        $errors = [];
        $integerTargets = [];

        foreach (array_filter($mapping->attributes, static fn (AttributeMapping $attribute): bool => null !== $attribute->targetType) as $attribute) {
            $target = $byName[strtolower((string) $attribute->targetType)] ?? null;

            if (null === $target) {
                $errors[] = \sprintf('%s::%s: "%s" refers to %s, which no class maps. Map a class with #[ScimResource(\'%4$s\')].', $mapping->class, $attribute->label(), $attribute->declaredPath, $attribute->targetType);
            } elseif (MappingKind::ReferenceCollection === $attribute->kind && 'int' === $target->id->phpType) {
                $integerTargets[(string) $attribute->path][(string) $target->idPrefix][] = $target->name();
            }
        }

        return [...$errors, ...self::ambiguousIds($mapping, $integerTargets)];
    }

    /**
     * @param array<string, array<string, list<string>>> $integerTargets target names by reference collection path and idPrefix ("" for none)
     *
     * @return list<string>
     */
    private static function ambiguousIds(ResourceMapping $mapping, array $integerTargets): array
    {
        $errors = [];

        foreach ($integerTargets as $path => $byPrefix) {
            foreach (array_filter($byPrefix, static fn (array $targets): bool => \count(array_unique($targets)) > 1) as $prefix => $targets) {
                $errors[] = '' === (string) $prefix
                    ? \sprintf('%s: "%s" refers to %s, which have int ids, so a member id is ambiguous. Set idPrefix: on both, or use UUIDs.', $mapping->class, $path, implode(' and ', $targets))
                    : \sprintf('%s: "%s" refers to %s, which have int ids with the idPrefix "%s", so a member id is ambiguous. Give each its own idPrefix:, or use UUIDs.', $mapping->class, $path, implode(' and ', $targets), $prefix);
            }
        }

        return $errors;
    }
}

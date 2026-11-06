<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\ResourceType;

/**
 * Checks that need all mappings of one class: conflicts, required attributes, primary slot groups.
 *
 * @internal
 */
final class ClassChecks
{
    /**
     * @param \ReflectionClass<object> $class
     * @param list<AttributeMapping>   $attributes
     *
     * @return list<AttributeMapping> with primary set on every member of a primary slot group
     */
    public static function check(\ReflectionClass $class, ResourceType $available, array $attributes): array
    {
        $errors = [...self::conflicts($attributes), ...self::missingRequired($available, $attributes)];

        if ([] !== $errors) {
            throw new MappingException(array_map(static fn (string $error): string => $class->getName().': '.$error, $errors));
        }

        return self::primaries($class, $attributes);
    }

    /**
     * @param list<AttributeMapping> $attributes
     *
     * @return list<string>
     */
    private static function conflicts(array $attributes): array
    {
        $errors = [];

        foreach ($attributes as $index => $first) {
            $second = array_find(\array_slice($attributes, $index + 1), static fn (AttributeMapping $other): bool => self::overlap($first, $other));

            if (null !== $second) {
                $errors[] = \sprintf('%s ("%s") and %s ("%s") map the same value. Map it once.', $first->label(), $first->declaredPath, $second->label(), $second->declaredPath);
            }
        }

        return $errors;
    }

    private static function overlap(AttributeMapping $first, AttributeMapping $second): bool
    {
        $whole = static fn (AttributeMapping $mapping): bool => null === $mapping->path->subAttribute && MappingKind::ReferenceCollection !== $mapping->kind;

        return $first->path->parent()->equals($second->path->parent())
            && ($whole($first) || $whole($second) || ($first->path->equals($second->path) && 0 === strcasecmp((string) $first->type, (string) $second->type)));
    }

    /**
     * Required attributes of the core schema, and required sub-attributes of each complex attribute mapped through
     * its sub-attributes.
     *
     * @param list<AttributeMapping> $attributes
     *
     * @return list<string>
     */
    private static function missingRequired(ResourceType $available, array $attributes): array
    {
        $parents = array_map(static fn (AttributeMapping $mapping): AttributePath => $mapping->path->parent(), array_filter($attributes, static fn (AttributeMapping $mapping): bool => null !== $mapping->path->subAttribute));
        $wanted = array_map(static fn (AttributeDefinition $definition): AttributePath => new AttributePath($available->schema->id, $definition->name), $available->schema->attributes);

        foreach ($parents as $parent) {
            $subAttributes = $available->resolve($parent)?->attribute->subAttributes ?? [];
            $wanted = [...$wanted, ...array_map(static fn (AttributeDefinition $sub): AttributePath => $parent->withSubAttribute($sub->name), $subAttributes)];
        }

        $missing = array_filter($wanted, static fn (AttributePath $path): bool => self::isRequired($available, $path) && !array_any($attributes, static fn (AttributeMapping $mapping): bool => $mapping->path->equals($path) || $mapping->path->parent()->equals($path)));

        return array_values(array_unique(array_map(static fn (AttributePath $path): string => \sprintf('"%s" is required and not mapped. Add #[ScimAttribute(\'%1$s\')] to a member.', $path->attribute.(null === $path->subAttribute ? '' : '.'.$path->subAttribute)), $missing)));
    }

    private static function isRequired(ResourceType $available, AttributePath $path): bool
    {
        $definition = $available->resolve($path)?->definition();

        return true === $definition?->required && Mutability::ReadOnly !== $definition->mutability;
    }

    /**
     * @param \ReflectionClass<object> $class
     * @param list<AttributeMapping>   $attributes
     *
     * @return list<AttributeMapping>
     */
    private static function primaries(\ReflectionClass $class, array $attributes): array
    {
        $primary = [];

        foreach (array_filter($attributes, static fn (AttributeMapping $mapping): bool => $mapping->primary) as $mapping) {
            $attribute = strtolower((string) $mapping->path->parent());
            $type = strtolower((string) $mapping->type);

            if (($primary[$attribute] ?? $type) !== $type) {
                throw MappingException::inClass($class->getName(), \sprintf('"%s": only one slot group can be primary. Remove primary: from all types but one.', $mapping->path->attribute));
            }

            $primary[$attribute] = $type;
        }

        return array_map(
            static fn (AttributeMapping $mapping): AttributeMapping => ($primary[strtolower((string) $mapping->path->parent())] ?? null) === strtolower((string) $mapping->type) ? $mapping->asPrimary() : $mapping,
            $attributes,
        );
    }
}

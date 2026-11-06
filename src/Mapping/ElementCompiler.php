<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServerBundle\Schema\AttributeDefinitions;

/**
 * Compiles the element class of an ElementCollection: each mapped property is a Field of one sub-attribute.
 *
 * @internal
 */
final class ElementCompiler
{
    public const array WRITTEN = [Mutability::ReadWrite, Mutability::Immutable];

    /**
     * @param \ReflectionClass<object> $owner
     *
     * @return array{ElementMapping, AttributeDefinition} the element mapping and the definition of the collection attribute, both with the mapped sub-attributes in schema order
     */
    public static function compile(Member $collection, ResolvedPath $path, \ReflectionClass $owner): array
    {
        $class = self::elementClass($collection);
        $parent = $path->attribute->attribute;
        $order = array_flip(array_map(static fn (AttributeDefinition $sub): string => $sub->name, $parent->subAttributes));
        $attributes = self::attributes($class, $collection, $path);
        usort($attributes, static fn (AttributeMapping $left, AttributeMapping $right): int => $order[(string) $left->path->subAttribute] <=> $order[(string) $right->path->subAttribute]);
        [$ownerParameter, $parameters] = ElementConstructor::resolve($class, $owner->getName(), $attributes);
        $definition = AttributeDefinitions::with($parent, subAttributes: array_map(static fn (AttributeMapping $attribute): AttributeDefinition => $attribute->definition, $attributes));

        return [new ElementMapping($class->getName(), $attributes, $parameters, $ownerParameter), $definition];
    }

    /**
     * @return \ReflectionClass<object>
     */
    private static function elementClass(Member $collection): \ReflectionClass
    {
        $name = (string) $collection->attribute->elementClass;
        $class = class_exists($name) ? new \ReflectionClass($name) : throw $collection->fail(\sprintf('has elementClass: %s, which is not a class.', $name));

        $problem = match (true) {
            [] !== $class->getAttributes(ScimResource::class) => 'which has #[ScimResource]. An element class has no #[ScimResource].',
            !$class->isInstantiable() => 'which cannot be instantiated. Use a concrete class.',
            default => null,
        };

        return null === $problem ? $class : throw $collection->fail(\sprintf('has elementClass: %s, %s', $class->getName(), $problem));
    }

    /**
     * @param \ReflectionClass<object> $class
     *
     * @return non-empty-list<AttributeMapping>
     */
    private static function attributes(\ReflectionClass $class, Member $collection, ResolvedPath $path): array
    {
        $compiled = [];
        $errors = [];

        foreach (Member::all($class) as $member) {
            try {
                $compiled[] = [$member, self::attribute($member, $class, $path)];
            } catch (MappingException $mappingException) {
                $errors[] = $mappingException;
            }
        }

        $merged = MappingException::merge([...$errors, ...self::duplicates($class, $compiled)]);
        $attributes = array_column($compiled, 1);

        return match (true) {
            $merged instanceof MappingException => throw $merged,
            [] === $attributes => throw $collection->fail(\sprintf("has elementClass: %s, which maps no sub-attribute. Add #[ScimAttribute('<sub-attribute>')] to its properties.", $class->getName())),
            default => $attributes,
        };
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private static function attribute(Member $member, \ReflectionClass $class, ResolvedPath $path): AttributeMapping
    {
        $parent = $path->attribute->attribute;
        $sub = $member->isMethod() ? throw $member->fail('is mapped by a method. An element class maps properties.') : $parent->subAttribute($member->attribute->path);
        $sub ??= throw $member->fail(\sprintf('is not a sub-attribute of "%s". Use %s.', $parent->name, self::names($parent)));
        self::checkArguments($member);
        $subPath = new ResolvedPath(new ResolvedAttribute($path->attribute->schema, $parent, $sub), null, false, false);
        $definition = self::definition($member, $sub);
        TypeChecks::check($member, MappingKind::Field, $subPath, null, $class, $definition, $member->attribute->converter);
        [$writable] = new AccessChecks($class)->access($member, MappingKind::Field, $definition);
        self::checkDefault($member, $definition);

        return new AttributeMapping(
            path: $subPath->attribute->path(),
            declaredPath: $parent->name.'.'.$sub->name,
            definition: $definition,
            kind: MappingKind::Field,
            member: $member->name(),
            method: false,
            phpType: $member->phpType(),
            nullable: $member->allowsNull(),
            writable: $writable,
            required: $definition->required,
            converter: $member->attribute->converter,
            default: $member->attribute->default,
        );
    }

    /**
     * "value, display, type or primary".
     */
    private static function names(AttributeDefinition $parent): string
    {
        $names = array_map(static fn (AttributeDefinition $sub): string => $sub->name, $parent->subAttributes);
        $last = array_pop($names);

        return ([] === $names ? '' : implode(', ', $names).' or ').$last;
    }

    /**
     * Element collections exist only for RFC 7643 attributes, whose characteristics are fixed.
     */
    private static function checkArguments(Member $member): void
    {
        $attribute = $member->attribute;
        $set = array_keys(array_filter([
            'type' => $attribute->type,
            'required' => $attribute->required,
            'caseExact' => $attribute->caseExact,
            'mutability' => $attribute->mutability,
            'returned' => $attribute->returned,
            'uniqueness' => $attribute->uniqueness,
            'canonicalValues' => $attribute->canonicalValues,
            'description' => $attribute->description,
        ], static fn (mixed $value): bool => null !== $value));

        $problem = match (true) {
            [] !== $set => \sprintf('is defined by RFC 7643, so its characteristics are fixed. Remove %s:.', implode(':, ', $set)),
            $attribute->primary => 'has primary:, which applies only to a slot (attribute[type eq "<type>"].sub-attribute). Remove primary:.',
            null !== $attribute->elementClass => KindResolver::NOT_ELEMENT_ATTRIBUTE,
            default => null,
        };

        if (null !== $problem) {
            throw $member->fail($problem);
        }
    }

    /**
     * An element exists only as a whole object, so a written member that cannot be null and has no default makes its
     * sub-attribute required.
     */
    private static function definition(Member $member, AttributeDefinition $sub): AttributeDefinition
    {
        $enumValues = null === $member->attribute->converter && [] === $sub->canonicalValues ? PhpTypes::canonicalValues($member->phpType()) : [];
        $required = !$member->allowsNull() && null === $member->attribute->default && \in_array($sub->mutability, self::WRITTEN, true);

        return AttributeDefinitions::with($sub, canonicalValues: [] === $enumValues ? null : $enumValues, required: $sub->required || $required);
    }

    private static function checkDefault(Member $member, AttributeDefinition $definition): void
    {
        $default = $member->attribute->default;

        $problem = match (true) {
            null === $default => null,
            Mutability::ReadOnly === $definition->mutability => 'is readOnly, so default: has no effect. Remove default:.',
            !PhpTypes::isScimValue($definition->type, $default) => \sprintf('is %s, so default: must be a %1$s value.', $definition->type->value),
            default => null,
        };

        if (null !== $problem) {
            throw $member->fail($problem);
        }
    }

    /**
     * @param \ReflectionClass<object>              $class
     * @param list<array{Member, AttributeMapping}> $compiled
     *
     * @return list<MappingException>
     */
    private static function duplicates(\ReflectionClass $class, array $compiled): array
    {
        $errors = [];

        foreach ($compiled as $index => [$first, $attribute]) {
            $second = array_find(\array_slice($compiled, $index + 1), static fn (array $other): bool => $other[1]->path->equals($attribute->path))[0] ?? null;

            if ($second instanceof Member) {
                $errors[] = MappingException::inClass($class->getName(), \sprintf('$%s ("%s") and $%s ("%s") map the same value. Map it once.', $first->name(), $first->attribute->path, $second->name(), $second->attribute->path));
            }
        }

        return $errors;
    }
}

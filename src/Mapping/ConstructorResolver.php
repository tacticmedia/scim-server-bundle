<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

use TacticMedia\ScimServer\Schema\Mutability;

/**
 * Resolves each constructor parameter: a readWrite or immutable mapped property of the same name, else the default
 * value, else null. A writeOnly value is written after the constructor, because its converter may need the object.
 *
 * @internal
 */
final class ConstructorResolver
{
    /**
     * @param \ReflectionClass<object> $class
     * @param list<AttributeMapping>   $attributes
     *
     * @return list<ConstructorParameter>
     */
    public static function resolve(\ReflectionClass $class, ScimResource $resource, array $attributes): array
    {
        if (null !== $resource->factory) {
            return [];
        }

        if (!$class->isInstantiable()) {
            throw MappingException::inClass($class->getName(), 'the class cannot be instantiated. Set #[ScimResource(factory: ...)].');
        }

        return array_map(
            static fn (\ReflectionParameter $parameter): ConstructorParameter => self::parameter($class, $parameter, $attributes),
            $class->getConstructor()?->getParameters() ?? [],
        );
    }

    /**
     * @param \ReflectionClass<object> $class
     * @param list<AttributeMapping>   $attributes
     */
    private static function parameter(\ReflectionClass $class, \ReflectionParameter $parameter, array $attributes): ConstructorParameter
    {
        $name = $parameter->getName();
        $attribute = array_find($attributes, static fn (AttributeMapping $mapping): bool => !$mapping->method && $mapping->member === $name);
        $acceptsNull = $parameter->isOptional() || $parameter->allowsNull();

        if ($attribute instanceof AttributeMapping && \in_array($attribute->definition->mutability, [Mutability::ReadWrite, Mutability::Immutable], true)) {
            return self::mapped($class, $parameter, $attribute, $acceptsNull);
        }

        return match (true) {
            $acceptsNull => new ConstructorParameter($name, null, $parameter->isOptional()),
            Mutability::WriteOnly === $attribute?->definition->mutability => throw MappingException::inClass($class->getName(), \sprintf('the constructor parameter $%s cannot get the writeOnly "%s", which is written after the constructor. Make $%1$s nullable, give it a default value, or set #[ScimResource(factory: ...)].', $name, $attribute->declaredPath)),
            default => throw MappingException::inClass($class->getName(), \sprintf('cannot resolve the constructor parameter $%s. Map a writable attribute to a property named $%1$s, give the parameter a default value, or set #[ScimResource(factory: ...)].', $name)),
        };
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private static function mapped(\ReflectionClass $class, \ReflectionParameter $parameter, AttributeMapping $attribute, bool $acceptsNull): ConstructorParameter
    {
        if (!$acceptsNull && !$attribute->required && null === $attribute->default) {
            throw MappingException::inClass($class->getName(), \sprintf('the constructor parameter $%s gets null when "%s" is absent. Make $%1$s nullable, give it a default value, or set default: on "%2$s".', $parameter->getName(), $attribute->declaredPath));
        }

        return new ConstructorParameter($parameter->getName(), $attribute, $parameter->isOptional());
    }
}

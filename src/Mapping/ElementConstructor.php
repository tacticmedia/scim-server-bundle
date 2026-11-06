<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

/**
 * Resolves the constructor of an element class: the first parameter typed with the owner class gets the owner, a
 * parameter with the name of a written member gets its value, another parameter its default value or null.
 *
 * @internal
 */
final class ElementConstructor
{
    /**
     * @param \ReflectionClass<object> $class
     * @param list<AttributeMapping>   $attributes
     *
     * @return array{string|null, list<ConstructorParameter>} the owner parameter, and the other parameters
     */
    public static function resolve(\ReflectionClass $class, string $owner, array $attributes): array
    {
        $ownerParameter = null;
        $parameters = [];

        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            if (null === $ownerParameter && self::takesOwner($parameter, $owner)) {
                $ownerParameter = $parameter->getName();
            } else {
                $parameters[] = self::parameter($class, $parameter, $attributes, $owner);
            }
        }

        return [$ownerParameter, $parameters];
    }

    private static function takesOwner(\ReflectionParameter $parameter, string $owner): bool
    {
        $type = $parameter->getType();

        return $type instanceof \ReflectionNamedType && !$type->isBuiltin() && is_a($owner, $type->getName(), true);
    }

    /**
     * @param \ReflectionClass<object> $class
     * @param list<AttributeMapping>   $attributes
     */
    private static function parameter(\ReflectionClass $class, \ReflectionParameter $parameter, array $attributes, string $owner): ConstructorParameter
    {
        $name = $parameter->getName();
        $attribute = array_find($attributes, static fn (AttributeMapping $mapping): bool => $mapping->member === $name && \in_array($mapping->definition->mutability, ElementCompiler::WRITTEN, true));
        $acceptsNull = $parameter->isOptional() || $parameter->allowsNull();

        return match (true) {
            $attribute instanceof AttributeMapping && !$acceptsNull && !$attribute->required && null === $attribute->default => throw MappingException::inClass($class->getName(), \sprintf('the constructor parameter $%s gets null when "%s" is absent. Make $%1$s nullable, give it a default value, or set default: on "%2$s".', $name, $attribute->path->subAttribute)),
            $attribute instanceof AttributeMapping => new ConstructorParameter($name, $attribute, $parameter->isOptional()),
            $acceptsNull => new ConstructorParameter($name, null, $parameter->isOptional()),
            default => throw MappingException::inClass($class->getName(), \sprintf('cannot resolve the constructor parameter $%s. Map a writable sub-attribute to a property named $%1$s, type it with %s, or give it a default value.', $name, $owner)),
        };
    }
}

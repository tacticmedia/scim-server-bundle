<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

/**
 * A property or method with its #[ScimAttribute].
 *
 * @internal
 */
final readonly class Member
{
    public function __construct(
        public \ReflectionProperty|\ReflectionMethod $reflection,
        public ScimAttribute $attribute,
    ) {
    }

    /**
     * @param \ReflectionClass<object> $class
     *
     * @return list<self> properties of the class and its parents, then public methods, each with #[ScimAttribute]
     */
    public static function all(\ReflectionClass $class): array
    {
        $properties = [];

        for ($current = $class; $current instanceof \ReflectionClass; $current = $current->getParentClass() ?: null) {
            $properties += array_column(array_map(static fn (\ReflectionProperty $property): array => [$property->getName(), $property], $current->getProperties()), 1, 0);
        }

        return [...self::withAttribute(array_values($properties)), ...self::withAttribute($class->getMethods())];
    }

    /**
     * @param list<\ReflectionProperty|\ReflectionMethod> $reflections
     *
     * @return list<self>
     */
    private static function withAttribute(array $reflections): array
    {
        $members = [];

        foreach ($reflections as $reflection) {
            foreach ($reflection->getAttributes(ScimAttribute::class) as $attribute) {
                $members[] = new self($reflection, $attribute->newInstance());
            }
        }

        return $members;
    }

    public function name(): string
    {
        return $this->reflection->getName();
    }

    public function isMethod(): bool
    {
        return $this->reflection instanceof \ReflectionMethod;
    }

    public function label(): string
    {
        return $this->reflection->getDeclaringClass()->getName().'::'.($this->isMethod() ? $this->name().'()' : '$'.$this->name());
    }

    public function fail(string $problem): MappingException
    {
        return new MappingException([\sprintf('%s: "%s" %s', $this->label(), $this->attribute->path, $problem)]);
    }

    /**
     * The single named type with "self" and "static" resolved, else null.
     */
    public function phpType(): ?string
    {
        $type = $this->type();

        if (!$type instanceof \ReflectionNamedType) {
            return null;
        }

        return \in_array($type->getName(), ['self', 'static'], true) ? $this->reflection->getDeclaringClass()->getName() : $type->getName();
    }

    public function typeName(): string
    {
        return (string) ($this->type() ?? 'mixed');
    }

    public function allowsNull(): bool
    {
        return $this->type()?->allowsNull() ?? true;
    }

    private function type(): ?\ReflectionType
    {
        return $this->reflection instanceof \ReflectionMethod ? $this->reflection->getReturnType() : $this->reflection->getType();
    }
}

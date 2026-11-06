<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

/**
 * The compiled #[ScimSoftDelete] of a mapped class. Reads and writes the property by reflection, so it needs no
 * accessor.
 */
final readonly class SoftDelete
{
    private const string TYPE = '#[ScimSoftDelete] needs bool or a nullable DateTimeInterface, the member is %s. Type $%s as bool or ?\DateTimeImmutable.';

    /**
     * @param class-string                                    $class     the class that declares $property
     * @param class-string<\DateTime|\DateTimeImmutable>|null $dateClass null: a bool flag
     */
    public function __construct(
        public string $class,
        public string $property,
        public ?string $dateClass,
        public bool $revive,
    ) {
    }

    /**
     * @param \ReflectionClass<object> $class
     *
     * @throws MappingException
     */
    public static function compile(\ReflectionClass $class): ?self
    {
        $marked = self::marked($class);

        if (\count($marked) > 1) {
            throw MappingException::inClass($class->getName(), \sprintf('#[ScimSoftDelete] is on $%s. Keep it on one property.', implode(' and $', array_map(static fn (\ReflectionProperty $property): string => $property->getName(), $marked))));
        }

        return [] === $marked ? null : self::of($marked[0]);
    }

    public function isDeleted(object $object): bool
    {
        $value = new \ReflectionProperty($this->class, $this->property)->getValue($object);

        return null === $this->dateClass ? true === $value : null !== $value;
    }

    public function delete(object $object, \DateTimeImmutable $now): void
    {
        $this->write($object, null === $this->dateClass ? true : $this->dateClass::createFromInterface($now));
    }

    public function restore(object $object): void
    {
        $this->write($object, null === $this->dateClass ? false : null);
    }

    private function write(object $object, mixed $value): void
    {
        new \ReflectionProperty($this->class, $this->property)->setValue($object, $value);
    }

    /**
     * @param \ReflectionClass<object> $class
     *
     * @return list<\ReflectionProperty> the properties of the class and its parents with #[ScimSoftDelete]
     */
    private static function marked(\ReflectionClass $class): array
    {
        $marked = [];

        for ($current = $class; $current instanceof \ReflectionClass; $current = $current->getParentClass() ?: null) {
            foreach (array_filter($current->getProperties(), static fn (\ReflectionProperty $property): bool => [] !== $property->getAttributes(ScimSoftDelete::class)) as $property) {
                $marked[$property->getName()] ??= $property;
            }
        }

        return array_values($marked);
    }

    private static function of(\ReflectionProperty $property): self
    {
        $type = $property->getType();
        $name = $type instanceof \ReflectionNamedType ? $type->getName() : null;
        $nullable = $type?->allowsNull() ?? true;
        $label = $property->getDeclaringClass()->getName().'::$'.$property->getName();
        $problem = match (true) {
            [] !== $property->getAttributes(ScimAttribute::class) => '#[ScimSoftDelete] is on a property that #[ScimAttribute] maps, so a write could delete or restore the object. Use a property without #[ScimAttribute].',
            $property->isReadOnly() => '#[ScimSoftDelete] is on a readonly property, so DELETE cannot set it. Remove readonly.',
            'bool' === $name && !$nullable, null !== $name && $nullable && is_a($name, \DateTimeInterface::class, true) => null,
            default => \sprintf(self::TYPE, (string) ($type ?? 'mixed'), $property->getName()),
        };

        if (null !== $problem) {
            throw new MappingException([$label.': '.$problem]);
        }

        return new self($property->getDeclaringClass()->getName(), $property->getName(), self::dateClass($name), $property->getAttributes(ScimSoftDelete::class)[0]->newInstance()->revive);
    }

    /**
     * @return class-string<\DateTime|\DateTimeImmutable>|null
     */
    private static function dateClass(?string $type): ?string
    {
        return match (true) {
            null === $type, 'bool' === $type => null,
            is_a($type, \DateTime::class, true), is_a($type, \DateTimeImmutable::class, true) => $type,
            default => \DateTimeImmutable::class,
        };
    }
}

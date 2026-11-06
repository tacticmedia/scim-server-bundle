<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Object;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\ElementMapping;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;

/**
 * Writes the elements of an ElementCollection. An element of the document updates the first current element with
 * equal "value" and "type", case-insensitively. The others are created with the constructor and added with the
 * adder; the current elements without a match go through the remover.
 *
 * @internal
 */
final readonly class ElementWriter
{
    private const array KEY = ['value', 'type'];

    public function __construct(
        private MemberValues $values,
    ) {
    }

    /**
     * @param mixed $elements the attribute value of the normalized document
     *
     * @throws ScimException 400 invalidValue when a converter fails
     */
    public function write(ResourceMapping $mapping, AttributeMapping $collection, mixed $elements, object $owner): void
    {
        $element = $collection->element ?? throw new \LogicException(\sprintf('"%s" is not an element collection.', $collection->declaredPath));
        $unmatched = $this->current($collection, $owner);
        $added = [];

        foreach ($this->documents($elements) as $document) {
            $index = array_find_key($unmatched, fn (object $object): bool => $this->key($mapping, $element, $object) === $this->documentKey($document));

            if (null === $index) {
                $added[] = $document;
            } else {
                $this->update($mapping, $element, $unmatched[$index], $document);
                unset($unmatched[$index]);
            }
        }

        foreach ($unmatched as $object) {
            $owner->{(string) $collection->remover}($object);
        }

        foreach ($added as $document) {
            $owner->{(string) $collection->adder}($this->create($mapping, $element, $document, $owner));
        }
    }

    /**
     * @return array<int, object>
     */
    private function current(AttributeMapping $collection, object $owner): array
    {
        $value = $this->values->get($collection, $owner);

        return array_filter(\is_array($value) ? array_values($value) : ($value instanceof \Traversable ? iterator_to_array($value, false) : []), \is_object(...));
    }

    /**
     * @return list<array<mixed>>
     */
    private function documents(mixed $elements): array
    {
        return array_values(array_filter(\is_array($elements) ? $elements : [], \is_array(...)));
    }

    /**
     * @return list<mixed>
     */
    private function key(ResourceMapping $mapping, ElementMapping $element, object $object): array
    {
        return array_map(function (string $sub) use ($mapping, $element, $object): mixed {
            $attribute = $this->at($element, $sub);

            return self::fold($attribute instanceof AttributeMapping ? $this->values->toScim($mapping, $attribute, $object) : null);
        }, self::KEY);
    }

    /**
     * @param array<mixed> $document
     *
     * @return list<mixed>
     */
    private function documentKey(array $document): array
    {
        return array_map(static fn (string $sub): mixed => self::fold($document[$sub] ?? null), self::KEY);
    }

    private static function fold(mixed $value): mixed
    {
        return match (true) {
            '' === $value => null,
            \is_string($value) => strtolower($value),
            default => $value,
        };
    }

    private function at(ElementMapping $element, string $sub): ?AttributeMapping
    {
        return array_find($element->attributes, static fn (AttributeMapping $attribute): bool => $attribute->path->subAttribute === $sub);
    }

    /**
     * @param array<mixed> $document
     */
    private function create(ResourceMapping $mapping, ElementMapping $element, array $document, object $owner): object
    {
        $object = new ($element->class)(...[...$this->arguments($mapping, $element, $document), ...(null === $element->ownerParameter ? [] : [$element->ownerParameter => $owner])]);
        $this->update($mapping, $element, $object, $document);

        return $object;
    }

    /**
     * A null value of an optional parameter is omitted, so PHP uses the parameter default.
     *
     * @param array<mixed> $document
     *
     * @return array<string, mixed>
     */
    private function arguments(ResourceMapping $mapping, ElementMapping $element, array $document): array
    {
        $arguments = [];

        foreach ($element->constructorParameters as $parameter) {
            $value = null === $parameter->attribute ? null : $this->values->fromScim($mapping, $parameter->attribute, $this->value($parameter->attribute, $document), null);

            if (null !== $value || !$parameter->optional) {
                $arguments[$parameter->name] = $value;
            }
        }

        return $arguments;
    }

    /**
     * Every writable member: the value of the document, else the default, else null.
     *
     * @param array<mixed> $document
     */
    private function update(ResourceMapping $mapping, ElementMapping $element, object $object, array $document): void
    {
        foreach (array_filter($element->attributes, static fn (AttributeMapping $attribute): bool => $attribute->writable) as $attribute) {
            $this->values->set($attribute, $object, $this->values->fromScim($mapping, $attribute, $this->value($attribute, $document), $object));
        }
    }

    /**
     * @param array<mixed> $document
     */
    private function value(AttributeMapping $attribute, array $document): mixed
    {
        return $document[(string) $attribute->path->subAttribute] ?? $attribute->default;
    }
}

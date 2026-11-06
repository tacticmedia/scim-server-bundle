<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Object;

use Doctrine\ORM\EntityNotFoundException;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingKind;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;

/**
 * The value of one attribute of an object, from the mappings of that attribute.
 *
 * @internal
 */
final readonly class DocumentReader
{
    public function __construct(
        private MemberValues $values,
        private References $references,
    ) {
    }

    /**
     * @param non-empty-list<AttributeMapping> $attributes the mappings of one top-level attribute
     *
     * @return mixed null when the attribute has no value
     */
    public function value(ResourceMapping $mapping, array $attributes, object $object): mixed
    {
        $first = $attributes[0];

        return match (true) {
            MappingKind::Slot === $first->kind => $this->slots($mapping, $attributes, $object),
            MappingKind::ReferenceCollection === $first->kind => $this->nonEmpty(array_merge(...array_map(fn (AttributeMapping $collection): array => $this->collection($collection, $object), $attributes))),
            MappingKind::Reference === $first->kind => $this->reference($first, $object),
            MappingKind::ElementCollection === $first->kind => $this->elements($mapping, $first, $object),
            null === $first->path->subAttribute => $this->present($this->values->toScim($mapping, $first, $object)),
            default => $this->complex($mapping, $attributes, $object),
        };
    }

    /**
     * @param non-empty-list<AttributeMapping> $attributes
     *
     * @return array<string, mixed>|null
     */
    private function complex(ResourceMapping $mapping, array $attributes, object $object): ?array
    {
        $value = [];

        foreach ($attributes as $attribute) {
            $value[(string) $attribute->path->subAttribute] = $this->present($this->values->toScim($mapping, $attribute, $object));
        }

        return $this->nonEmpty(array_filter($value, static fn (mixed $subValue): bool => null !== $subValue));
    }

    /**
     * One element per slot group with a present member.
     *
     * @param non-empty-list<AttributeMapping> $slots
     *
     * @return list<array<string, mixed>>|null
     */
    private function slots(ResourceMapping $mapping, array $slots, object $object): ?array
    {
        $elements = [];
        $groups = [];

        foreach ($slots as $slot) {
            $value = $this->present($this->values->toScim($mapping, $slot, $object));

            if (null !== $value) {
                $elements[strtolower((string) $slot->type)][(string) $slot->path->subAttribute] = $value;
                $groups[strtolower((string) $slot->type)] = $slot;
            }
        }

        return $this->nonEmpty(array_map(static fn (array $element, AttributeMapping $slot): array => $element + ['type' => $slot->type] + ($slot->primary ? ['primary' => true] : []), $elements, $groups));
    }

    /**
     * One element per object with a present sub-attribute.
     *
     * @return list<array<string, mixed>>|null
     */
    private function elements(ResourceMapping $mapping, AttributeMapping $collection, object $owner): ?array
    {
        $readable = array_values(array_filter($collection->element->attributes ?? [], static fn (AttributeMapping $attribute): bool => Mutability::WriteOnly !== $attribute->definition->mutability));
        $value = $this->values->get($collection, $owner);
        $elements = [];

        if ([] === $readable || !\is_iterable($value)) {
            return null;
        }

        foreach ($value as $element) {
            $elements[] = \is_object($element) ? $this->complex($mapping, $readable, $element) : null;
        }

        return $this->nonEmpty(array_values(array_filter($elements)));
    }

    /**
     * A soft-deleted target, and a target that Doctrine cannot load, for example a row that an SQL filter hides, is
     * absent.
     *
     * @return array<string, mixed>|null
     */
    private function reference(AttributeMapping $reference, object $object): ?array
    {
        $target = $this->values->get($reference, $object);
        $mapping = $this->references->target($reference);

        try {
            return \is_object($target) && !$this->references->isDeleted($mapping, $target) ? $this->references->element($reference, $mapping, $target) : null;
        } catch (EntityNotFoundException) {
            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collection(AttributeMapping $collection, object $object): array
    {
        $target = $this->references->target($collection);
        $type = null === $collection->type ? [] : ['type' => $collection->type];

        return array_values(array_map(fn (object $element): array => $this->references->element($collection, $target, $element) + $type, $this->references->current($collection, $target, $object)));
    }

    private function present(mixed $value): mixed
    {
        return null === $value || '' === $value || [] === $value ? null : $value;
    }

    /**
     * @template T of array
     *
     * @param T $value
     *
     * @return T|null
     */
    private function nonEmpty(array $value): ?array
    {
        return [] === $value ? null : $value;
    }
}

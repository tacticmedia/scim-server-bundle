<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Object;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;

/**
 * Writes the elements of one multi-valued reference attribute (Group "members") onto its ReferenceCollection
 * mappings: a diff by SCIM id with hash maps, store lookups only for added ids, then the remover and the adder.
 */
final readonly class CollectionWriter
{
    public function __construct(
        private References $references,
    ) {
    }

    /**
     * An element with a type goes to the collection of that type. An element without a known type stays in the
     * collection that holds it; a new one goes to the first collection, in declaration order, whose store knows it.
     *
     * @param non-empty-list<AttributeMapping> $collections the writable ReferenceCollection mappings of one attribute
     * @param mixed                            $elements    the attribute value of the normalized document
     *
     * @throws ScimException 400 invalidValue for an id that no target store knows
     */
    public function write(array $collections, mixed $elements, object $owner): void
    {
        $targets = array_map($this->references->target(...), $collections);
        $current = array_map(fn (AttributeMapping $collection, ResourceMapping $target): array => $this->references->current($collection, $target, $owner), $collections, $targets);
        [$wanted, $typed, $untyped] = $this->sort($collections, $current, DocumentValues::referenceIds($elements));

        foreach ($collections as $index => $collection) {
            $wanted[$index] += $this->findAll($collection, $targets[$index], $typed[$index]);
        }

        foreach ($collections as $index => $collection) {
            $found = $this->references->find($targets[$index], $untyped);
            $wanted[$index] += $found;
            $untyped = array_values(array_diff($untyped, array_map(strval(...), array_keys($found))));
        }

        if ([] !== $untyped) {
            throw References::unknown($collections[0], $targets, $untyped);
        }

        foreach ($collections as $index => $collection) {
            $this->apply($owner, $collection, $current[$index], $wanted[$index]);
        }
    }

    /**
     * @param non-empty-list<AttributeMapping> $collections
     * @param list<array<string, object>>      $current
     * @param array<string, string|null>       $types       the type of each id of the document
     *
     * @return array{list<array<string, object>>, list<list<string>>, list<string>} the kept elements and the new typed ids of each collection, and the new untyped ids
     */
    private function sort(array $collections, array $current, array $types): array
    {
        $kept = array_fill(0, \count($collections), []);
        $typed = array_fill(0, \count($collections), []);
        $untyped = [];

        foreach ($types as $id => $type) {
            $id = (string) $id;
            $typedIndex = $this->typedIndex($collections, $type);
            $index = $typedIndex ?? array_find_key($current, static fn (array $elements): bool => isset($elements[$id]));

            if (null !== $index && isset($current[$index][$id])) {
                $kept[$index][$id] = $current[$index][$id];
            } elseif (null !== $typedIndex) {
                $typed[$typedIndex][] = $id;
            } else {
                $untyped[] = $id;
            }
        }

        return [$kept, $typed, $untyped];
    }

    /**
     * @param non-empty-list<AttributeMapping> $collections
     */
    private function typedIndex(array $collections, ?string $type): ?int
    {
        return null === $type ? null : array_find_key($collections, static fn (AttributeMapping $collection): bool => 0 === strcasecmp((string) $collection->type, $type));
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, object>
     */
    private function findAll(AttributeMapping $collection, ResourceMapping $target, array $ids): array
    {
        $found = $this->references->find($target, $ids);
        $missing = array_values(array_diff($ids, array_map(strval(...), array_keys($found))));

        return [] === $missing ? $found : throw References::unknown($collection, [$target], $missing);
    }

    /**
     * @param array<string, object> $current
     * @param array<string, object> $wanted
     */
    private function apply(object $owner, AttributeMapping $collection, array $current, array $wanted): void
    {
        foreach (array_diff_key($current, $wanted) as $element) {
            $owner->{(string) $collection->remover}($element);
        }

        foreach (array_diff_key($wanted, $current) as $element) {
            $owner->{(string) $collection->adder}($element);
        }
    }
}

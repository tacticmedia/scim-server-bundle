<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Object;

use Symfony\Component\DependencyInjection\ServiceLocator;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Patch\PatchOperationType;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Repository\ValuesChange;
use TacticMedia\ScimServer\Repository\ValuesStep;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServerBundle\Converter\ConversionContext;
use TacticMedia\ScimServerBundle\Converter\ConversionFailed;
use TacticMedia\ScimServerBundle\Converter\IdConverter;
use TacticMedia\ScimServerBundle\Converter\ValueConverter;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingKind;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;

/**
 * The steps of a delta PATCH on the ReferenceCollection mappings of an attribute, through a CollectionDeltaStore. An
 * id goes to the collection of its type; an id without a known type stays in the collection that has it, and a new
 * one goes to the first collection, in declaration order, whose store knows it, as in {@see CollectionWriter}. Ids
 * compare in the spelling of the id converter of each target, so an upper-case UUID names the member.
 *
 * @internal
 */
final readonly class CollectionDeltas
{
    /**
     * @param ServiceLocator<mixed> $converters ValueConverter services by class name
     */
    public function __construct(
        private References $references,
        private ServiceLocator $converters,
    ) {
    }

    /**
     * The server reads the resource without these attributes, so they need a mapping of meta.version. A mapping with
     * deltas: false has none.
     *
     * @return array<string, non-empty-list<AttributeMapping>> the writable ReferenceCollection mappings of each delta
     *                                                         attribute, by lower-case path
     */
    public function collections(ResourceMapping $mapping): array
    {
        $store = $mapping->deltas ? $this->references->store($mapping) : null;

        if (!$store instanceof CollectionDeltaStore || [] === $mapping->attributesAt(new AttributePath($mapping->resourceType->schema->id, 'meta', 'version'))) {
            return [];
        }

        $groups = [];

        foreach (array_filter($mapping->attributes, static fn (AttributeMapping $attribute): bool => MappingKind::ReferenceCollection === $attribute->kind) as $attribute) {
            $groups[strtolower((string) $attribute->path)][] = $attribute;
        }

        return array_filter($groups, static fn (array $collections): bool => self::takesSteps($collections[0]->definition) && array_all($collections, static fn (AttributeMapping $collection): bool => $collection->writable && $store->supportsDeltas($mapping, $collection)));
    }

    /**
     * @param list<ValuesStep> $steps
     *
     * @return list<ValuesChange> the steps that added or removed an element that no later step changes back
     *
     * @throws ScimException 400 invalidValue for an id of an Add or Replace step that no target store knows
     */
    public function apply(ResourceMapping $mapping, CollectionDeltaStore $store, object $object, array $steps): array
    {
        $collections = $this->collections($mapping);
        $elements = new DeferredRemovals($mapping, $store, $object);
        $changes = [];

        foreach ($steps as $step) {
            $stepCollections = $collections[strtolower((string) $step->path)];
            $changes[] = $this->step(new DeltaStep($elements, $stepCollections, array_map($this->references->target(...), $stepCollections), $step->type), DocumentValues::referenceIds($step->values), $step->path);
        }

        $elements->flush();

        return $this->net($changes);
    }

    /**
     * Remove then add of an element, or add then remove, is no change. The last change of an element stays.
     *
     * @param list<ValuesChange> $changes
     *
     * @return list<ValuesChange>
     */
    private function net(array $changes): array
    {
        $last = $this->lastChanges($changes);
        $net = [];

        foreach ($changes as $index => $change) {
            $added = $this->kept($change, $change->added, $index.'+', $last);
            $removed = $this->kept($change, $change->removed, $index.'-', $last);

            if ([] !== $added || [] !== $removed) {
                $net[] = new ValuesChange($change->path, $added, $removed);
            }
        }

        return $net;
    }

    /**
     * An element changes between absent and present, so an even number of changes cancels out.
     *
     * @param list<ValuesChange> $changes
     *
     * @return array<string, string|null> the step and side of the last change of each element; null: no net change
     */
    private function lastChanges(array $changes): array
    {
        $last = [];

        foreach ($changes as $index => $change) {
            foreach ([...$this->keys($change, $change->added, $index.'+'), ...$this->keys($change, $change->removed, $index.'-')] as $key => $at) {
                $last[$key] = isset($last[$key]) ? null : $at;
            }
        }

        return $last;
    }

    /**
     * @param list<array<string, mixed>> $elements
     *
     * @return array<string, string> $at by element key
     */
    private function keys(ValuesChange $change, array $elements, string $at): array
    {
        return array_fill_keys(array_map(static fn (array $element): string => self::key($change, $element), $elements), $at);
    }

    /**
     * @param list<array<string, mixed>> $elements
     * @param array<string, string|null> $last
     *
     * @return list<array<string, mixed>>
     */
    private function kept(ValuesChange $change, array $elements, string $at, array $last): array
    {
        return array_values(array_filter($elements, static fn (array $element): bool => $at === ($last[self::key($change, $element)] ?? null)));
    }

    /**
     * @param array<string, mixed> $element
     */
    private static function key(ValuesChange $change, array $element): string
    {
        return strtolower((string) $change->path).' '.json_encode($element, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string|null> $types the type of each id of the step
     */
    private function step(DeltaStep $step, array $types, AttributePath $path): ValuesChange
    {
        $routes = [];

        foreach ($types as $id => $type) {
            $typed = $step->typedIndex($type);
            $routes[] = [(string) $id, $typed, $this->canonicals($step, (string) $id, $typed)];
        }

        $present = $step->present($routes);
        $added = PatchOperationType::Remove === $step->type ? [] : $step->add(array_values(array_filter($routes, static fn (array $route): bool => !$step->contains($present, $route[2]))));
        $removed = $step->remove($present, $routes);

        return new ValuesChange($path, $step->elements($added), $step->elements($removed));
    }

    /**
     * @return array<int, string> the id in the spelling of the target of each collection that it can name
     */
    private function canonicals(DeltaStep $step, string $id, ?int $typed): array
    {
        $canonicals = [];

        foreach (null === $typed ? $step->targets : [$typed => $step->targets[$typed]] as $index => $target) {
            $canonical = $this->canonical($target, $id);

            if (null !== $canonical) {
                $canonicals[$index] = $canonical;
            }
        }

        return $canonicals;
    }

    private function canonical(ResourceMapping $target, string $id): ?string
    {
        $converter = $this->converters->get($target->id->converter ?? IdConverter::class);
        \assert($converter instanceof ValueConverter);
        $context = new ConversionContext($target, $target->id);

        try {
            $canonical = $converter->toScim($converter->fromScim($id, $context), $context);
        } catch (ConversionFailed) {
            return null;
        }

        return \is_string($canonical) ? $canonical : null;
    }

    /**
     * As the library DeltaSplitter, so that a declared attribute never fails the PATCH.
     */
    private static function takesSteps(AttributeDefinition $definition): bool
    {
        return $definition->multiValued
            && AttributeType::Complex === $definition->type
            && Mutability::ReadWrite === $definition->mutability
            && !$definition->required
            && $definition->subAttribute('value') instanceof AttributeDefinition
            && !$definition->subAttribute('primary') instanceof AttributeDefinition;
    }
}

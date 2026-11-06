<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Object;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\CursorPage;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Query\ListResult;
use TacticMedia\ScimServer\Repository\CursorPaginatedRepository;
use TacticMedia\ScimServer\Repository\DeltaRepository;
use TacticMedia\ScimServer\Repository\PatchResult;
use TacticMedia\ScimServer\Repository\ResourcePatch;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingKind;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Mapping\SoftDelete;
use TacticMedia\ScimServerBundle\Query\ObjectQuery;
use TacticMedia\ScimServerBundle\Query\QueryPlanner;
use TacticMedia\ScimServerBundle\Validation\ObjectValidator;

/**
 * The library repository of one mapped resource type, over the ObjectStore of its mapping.
 */
final readonly class ObjectResourceRepository implements CursorPaginatedRepository, DeltaRepository
{
    private MemberValues $values;

    private References $references;

    private CollectionDeltas $deltas;

    private Revival $revival;

    /**
     * @param ServiceLocator<mixed> $stores     ObjectStore services by class name
     * @param ServiceLocator<mixed> $converters ValueConverter services by class name
     */
    public function __construct(
        private string $name,
        private MappingRegistry $mappings,
        private QueryPlanner $planner,
        private DocumentMapper $mapper,
        private Instantiator $instantiator,
        ServiceLocator $stores,
        ServiceLocator $converters,
        private ?ClockInterface $clock = null,
        private ObjectValidator $validator = new ObjectValidator(),
    ) {
        $this->values = new MemberValues($converters);
        $this->references = new References($mappings, $stores, $this->values);
        $this->deltas = new CollectionDeltas($this->references, $converters);
        $this->revival = new Revival($planner, $mappings, $this->references, $this->values, $this->deltas);
    }

    #[\Override]
    public function get(string $id, array $attributes = [], array $excludedAttributes = []): ?array
    {
        $mapping = $this->mappings->get($this->name);
        $object = $this->references->store($mapping)->find($mapping, $id);

        if (null === $object) {
            return null;
        }

        $projection = $this->planner->plan(new ListQuery(count: 0, attributes: $attributes, excludedAttributes: $excludedAttributes), $mapping);
        \assert($projection instanceof ObjectQuery, 'A query without filter is never constant false.');

        return $this->mapper->toDocument($this->rendering($mapping, $this->rendered($mapping, $projection->load)), $object);
    }

    #[\Override]
    public function list(ListQuery $query): ListResult
    {
        $mapping = $this->mappings->get($this->name);
        $plan = $this->planner->plan($query, $mapping);

        if (false === $plan) {
            return new ListResult([], 0);
        }

        $load = $this->rendered($mapping, $plan->load);
        $page = $this->references->store($mapping)->query($mapping, new ObjectQuery($plan->filter, $plan->sortBy, $plan->sortOrder, $plan->startIndex, $plan->count, $load));
        $rendering = $this->rendering($mapping, $load);

        return new ListResult(array_map(fn (object $object): array => $this->mapper->toDocument($rendering, $object), $page->objects), $page->totalResults);
    }

    /**
     * Null when the store of the mapping is not a {@see CursorPaginatedObjectStore}.
     */
    #[\Override]
    public function listAfter(ListQuery $query, ?array $after): ?CursorPage
    {
        $mapping = $this->mappings->get($this->name);
        $store = $this->references->store($mapping);

        if (!$store instanceof CursorPaginatedObjectStore) {
            return null;
        }

        $plan = $this->planner->plan($query, $mapping);

        if (false === $plan) {
            return new CursorPage([], 0, null);
        }

        $load = $this->rendered($mapping, $plan->load);
        $page = $store->queryAfter($mapping, new ObjectQuery($plan->filter, $plan->sortBy, $plan->sortOrder, $plan->startIndex, $plan->count, $load), $after);
        $rendering = $this->rendering($mapping, $load);

        return $page instanceof CursorObjectPage ? new CursorPage(array_map(fn (object $object): array => $this->mapper->toDocument($rendering, $object), $page->objects), $page->totalResults, $page->next) : null;
    }

    #[\Override]
    public function create(array $document): array
    {
        $mapping = $this->mappings->get($this->name);
        $store = $this->references->store($mapping);

        $object = $store->transactional($mapping, function () use ($mapping, $store, $document): object {
            $deleted = $this->revival->find($mapping, $store, $document);

            if (null !== $deleted) {
                return $this->revive($mapping, $store, $deleted, $document);
            }

            $object = $this->instantiator->create($mapping, $document);
            $this->mapper->write($mapping, $document, $object, true);
            $now = $this->now();
            $this->stamp($mapping, $object, 'created', $now);
            // RFC 7643 section 3.1: lastModified of a new resource equals created, which the constructor may have set.
            $this->stamp($mapping, $object, 'lastModified', $this->created($mapping, $object) ?? $now);
            $this->validator->validate($mapping, $object);
            $store->insert($mapping, $object);

            return $object;
        });

        return $this->mapper->toDocument($mapping, $object);
    }

    #[\Override]
    public function replace(string $id, array $document, ?string $version = null): array
    {
        $mapping = $this->mappings->get($this->name);
        $store = $this->references->store($mapping);

        $object = $store->transactional($mapping, function () use ($mapping, $store, $id, $document, $version): object {
            $object = $store->find($mapping, $id) ?? throw $this->missing($id, $version);
            $before = $this->mapper->toDocument($mapping, $object);
            $this->mapper->write($mapping, $document, $object, false);

            if ($this->mapper->toDocument($mapping, $object) !== $before) {
                $this->stamp($mapping, $object, 'lastModified', $this->now());
            }

            $this->validator->validate($mapping, $object);
            $store->update($mapping, $object, $this->memberVersion($mapping, $version));

            return $object;
        });

        return $this->mapper->toDocument($mapping, $object);
    }

    #[\Override]
    public function delete(string $id, ?string $version = null): void
    {
        $mapping = $this->mappings->get($this->name);
        $store = $this->references->store($mapping);

        $store->transactional($mapping, function () use ($mapping, $store, $id, $version): void {
            $this->remove($mapping, $store, $store->find($mapping, $id) ?? throw $this->missing($id, $version), $this->memberVersion($mapping, $version));
        });
    }

    /**
     * The writable ReferenceCollection attributes of a store that implements {@see CollectionDeltaStore}.
     */
    #[\Override]
    public function deltaAttributes(): array
    {
        return array_values(array_map(static fn (array $collections): AttributePath => $collections[0]->path, $this->deltas->collections($this->mappings->get($this->name))));
    }

    #[\Override]
    public function patch(string $id, ResourcePatch $patch): PatchResult
    {
        $mapping = $this->mappings->get($this->name);
        $store = $this->references->store($mapping);
        \assert($store instanceof CollectionDeltaStore, 'The server calls patch() only for deltaAttributes().');
        $deltas = array_merge(...array_values($this->deltas->collections($mapping)));
        $light = $this->rendering($mapping, array_values(array_filter($mapping->attributes, static fn (AttributeMapping $attribute): bool => self::isRelation($attribute) && !\in_array($attribute, $deltas, true))));

        [$object, $changes] = $store->transactional($mapping, function () use ($mapping, $light, $store, $id, $patch): array {
            $version = $this->memberVersion($mapping, $patch->version);
            $object = $store->lock($mapping, $id, $version) ?? throw $this->missing($id, $patch->version);
            $before = $this->mapper->toDocument($light, $object);

            if (null !== $patch->document) {
                $this->mapper->write($light, $patch->document, $object, false);
            }

            $changes = $this->deltas->apply($mapping, $store, $object, $patch->steps);

            if ([] !== $changes || $this->mapper->toDocument($light, $object) !== $before) {
                $this->stamp($mapping, $object, 'lastModified', $this->now());
                $this->validator->validate($mapping, $object);
                $store->update($mapping, $object, $version);
            }

            return [$object, $changes];
        });

        return new PatchResult($this->mapper->toDocument($light, $object), $changes);
    }

    /**
     * A soft delete is an update, so the links of other objects to the object stay.
     */
    private function remove(ResourceMapping $mapping, ObjectStore $store, object $object, mixed $version): void
    {
        if (!$mapping->softDelete instanceof SoftDelete) {
            $store->delete($mapping, $object, $version);

            return;
        }

        $mapping->softDelete->delete($object, $this->instant());
        $store->update($mapping, $object, $version);
    }

    /**
     * Restores a soft-deleted object, removes its links ({@see Revival::reset()}) and writes $document onto it as onto
     * a new object. It keeps its id and meta.created.
     *
     * @param array<string, mixed> $document
     */
    private function revive(ResourceMapping $mapping, ObjectStore $store, object $object, array $document): object
    {
        // The nested write makes the restore visible to the queries that find the links.
        $store->transactional($mapping, static function () use ($mapping, $store, $object): void {
            $mapping->softDelete?->restore($object);
            $store->update($mapping, $object);
        });
        $now = $this->now();

        foreach ($this->revival->reset($mapping, $object) as [$owner, $other]) {
            $this->stamp($owner, $other, 'lastModified', $now);
            $this->references->store($owner)->update($owner, $other);
        }

        $this->mapper->write($mapping, $document, $object, true);
        $this->stamp($mapping, $object, 'lastModified', $now);
        $this->validator->validate($mapping, $object);
        $store->update($mapping, $object);

        return $object;
    }

    /**
     * The server derives the ETag from the returned document unless it has meta.version, so only a mapping of
     * meta.version may leave out relations that the response does not render.
     *
     * @param list<AttributeMapping> $load the relations that the response renders
     *
     * @return list<AttributeMapping>
     */
    private function rendered(ResourceMapping $mapping, array $load): array
    {
        $version = new AttributePath($mapping->resourceType->schema->id, 'meta', 'version');

        return [] === $mapping->attributesAt($version) ? array_values(array_filter($mapping->attributes, self::isRelation(...))) : $load;
    }

    /**
     * @param list<AttributeMapping> $relations
     */
    private function rendering(ResourceMapping $mapping, array $relations): ResourceMapping
    {
        $attributes = array_values(array_filter($mapping->attributes, static fn (AttributeMapping $attribute): bool => !self::isRelation($attribute) || \in_array($attribute, $relations, true)));

        return \count($attributes) === \count($mapping->attributes)
            ? $mapping
            : $mapping->withAttributes($attributes);
    }

    private static function isRelation(AttributeMapping $attribute): bool
    {
        return \in_array($attribute->kind, [MappingKind::Reference, MappingKind::ReferenceCollection, MappingKind::ElementCollection], true);
    }

    private function stamp(ResourceMapping $mapping, object $object, string $metaAttribute, string $instant): void
    {
        foreach ($this->metaMappings($mapping, $metaAttribute) as $attribute) {
            if ($attribute->writable) {
                $this->values->set($attribute, $object, $this->values->fromScim($mapping, $attribute, $instant, $object));
            }
        }
    }

    private function created(ResourceMapping $mapping, object $object): ?string
    {
        $attribute = $this->metaMappings($mapping, 'created')[0] ?? null;
        $created = null === $attribute ? null : $this->values->toScim($mapping, $attribute, $object);

        return \is_string($created) ? $created : null;
    }

    /**
     * @return list<AttributeMapping>
     */
    private function metaMappings(ResourceMapping $mapping, string $metaAttribute): array
    {
        return $mapping->attributesAt(new AttributePath($mapping->resourceType->schema->id, 'meta', $metaAttribute));
    }

    private function memberVersion(ResourceMapping $mapping, ?string $version): mixed
    {
        $attribute = $this->metaMappings($mapping, 'version')[0] ?? null;

        return null === $version || null === $attribute ? null : $this->values->fromScim($mapping, $attribute, $version, null);
    }

    private function now(): string
    {
        return $this->instant()->format('Y-m-d\TH:i:sp');
    }

    private function instant(): \DateTimeImmutable
    {
        return ($this->clock?->now() ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * The server found the resource before it called replace() or delete(), and RFC 7232 section 3.1 fails If-Match
     * for a resource that no longer exists.
     */
    private function missing(string $id, ?string $version): ScimException
    {
        return null === $version ? ScimException::notFound(\sprintf('Resource %s not found.', $id)) : ScimException::preconditionFailed('Failed to update. Resource has changed on the server.');
    }
}

<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Object;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Query\ObjectQuery;

/**
 * Loads and stores the objects of mapped classes. Ids are SCIM ids: the store converts them to and from member
 * values with the id mapping of the ResourceMapping.
 */
interface ObjectStore
{
    /**
     * Null for an unknown or malformed id, and for an object that #[ScimSoftDelete] marks as deleted.
     */
    public function find(ResourceMapping $mapping, string $id): ?object;

    /**
     * @param list<string> $ids SCIM ids
     *
     * @return array<string, object> keyed by SCIM id; unknown, malformed and soft-deleted ids are absent
     */
    public function findMany(ResourceMapping $mapping, array $ids): array;

    /**
     * Never called with a filter that is constant false. Never returns a soft-deleted object.
     *
     * @throws ScimException 400 invalidFilter for a plan node that the store cannot evaluate
     */
    public function query(ResourceMapping $mapping, ObjectQuery $query): ObjectPage;

    /**
     * Runs one write. insert(), update() and delete() calls inside $write become durable when $write returns.
     * Nothing is stored when $write throws.
     *
     * @template T
     *
     * @param \Closure(): T $write
     *
     * @return T
     *
     * @throws ScimException 409 uniqueness when the storage rejects a duplicate, 409 when it rejects a delete
     */
    public function transactional(ResourceMapping $mapping, \Closure $write): mixed;

    /** Called inside transactional() for a new object. */
    public function insert(ResourceMapping $mapping, object $object): void;

    /**
     * Called inside transactional() for a changed object that find() or findMany() returned.
     *
     * @param mixed $version the member value of meta.version that the stored object must still have; null: no condition
     *
     * @throws ScimException 412 when the stored object has another version
     */
    public function update(ResourceMapping $mapping, object $object, mixed $version = null): void;

    /**
     * Called inside transactional() for an object that find() returned.
     *
     * @param mixed $version as for update()
     *
     * @throws ScimException 412 when the stored object has another version
     */
    public function delete(ResourceMapping $mapping, object $object, mixed $version = null): void;
}

<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Doctrine\Orm;

use Doctrine\ORM\EntityManagerInterface;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;

/**
 * Increments the #[ORM\Version] field of an object with one DQL UPDATE, which compares the required version and takes
 * the row lock in one step. The flush UPDATE alone checks only the in-memory version and runs only for a column
 * change, so it misses a change of a to-many association and a delete.
 *
 * @internal
 */
final class OrmVersioning
{
    /**
     * Nothing for a class without #[ORM\Version].
     *
     * @param mixed $version the member value that the row must have; null: no condition
     *
     * @throws ScimException 412 when the row no longer has $version, 404 when the row is gone and $version is null
     */
    public static function increment(EntityManagerInterface $manager, ResourceMapping $mapping, object $object, mixed $version): void
    {
        $metadata = $manager->getClassMetadata($mapping->class);

        if (!$metadata->isVersioned) {
            return;
        }

        $field = (string) $metadata->versionField;
        $id = $mapping->id->member;
        $idValue = $metadata->getFieldValue($object, $id);
        $condition = null === $version ? '' : \sprintf(' AND o.%s = :version', $field);
        $increment = $manager->createQuery(\sprintf('UPDATE %s o SET o.%2$s = o.%2$s + 1 WHERE o.%3$s = :id%4$s', $mapping->class, $field, $id, $condition))
            ->setParameter('id', $idValue, $metadata->getTypeOfField($id));

        if (null !== $version) {
            $increment->setParameter('version', $version, $metadata->getTypeOfField($field));
        }

        if (1 !== $increment->execute()) {
            throw null === $version ? ScimException::notFound('The resource was deleted by another request.') : ScimException::preconditionFailed('Failed to update. Resource has changed on the server.');
        }

        // The row lock of the UPDATE holds until the commit, so the flush UPDATE finds this version.
        $current = $manager->createQuery(\sprintf('SELECT o.%s FROM %s o WHERE o.%s = :id', $field, $mapping->class, $id))->setParameter('id', $idValue, $metadata->getTypeOfField($id));
        $metadata->setFieldValue($object, $field, $current->getSingleScalarResult());
    }
}

<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Doctrine\Orm;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Parameter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TacticMedia\ScimServerBundle\Converter\ConversionContext;
use TacticMedia\ScimServerBundle\Converter\ConversionFailed;
use TacticMedia\ScimServerBundle\Converter\IdConverter;
use TacticMedia\ScimServerBundle\Converter\ValueConverter;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;

/**
 * The CollectionDeltaStore methods of {@see OrmObjectStore} for a ManyToMany owning side: DQL reads, so that Doctrine
 * SQL filters apply and soft-deleted objects stay hidden, and DBAL writes on the join table, which bypass adders,
 * removers and lifecycle events.
 *
 * @internal
 */
final readonly class OrmCollectionDeltas
{
    private const int ID_CHUNK = 500;

    /**
     * @param ServiceLocator<ValueConverter> $converters keyed by class name
     */
    public function __construct(
        private MappingRegistry $mappings,
        private ServiceLocator $converters,
    ) {
    }

    /**
     * A second-level cache region would keep the old elements.
     */
    public function supports(EntityManagerInterface $manager, ResourceMapping $mapping, AttributeMapping $collection): bool
    {
        $metadata = $manager->getClassMetadata($mapping->class);
        $association = !$collection->method && $metadata->hasAssociation($collection->member) ? $metadata->getAssociationMapping($collection->member) : null;

        return $metadata->isVersioned
            && $association instanceof ManyToManyOwningSideMapping
            && null === $association->cache
            && $this->referencesId($association->joinTable->joinColumns, $metadata, $mapping)
            && $this->referencesId($association->joinTable->inverseJoinColumns, $manager->getClassMetadata($association->targetEntity), $this->target($collection));
    }

    public function lock(EntityManagerInterface $manager, ResourceMapping $mapping, mixed $id, mixed $version): ?object
    {
        $metadata = $manager->getClassMetadata($mapping->class);
        $versionField = (string) $metadata->versionField;
        $condition = (null === $version ? '' : \sprintf(' AND r.%s = :version', $versionField)).$this->notDeleted($mapping, 'r');
        $query = $manager->createQuery(\sprintf('SELECT r FROM %s r WHERE r.%s = :id%s', $mapping->class, $mapping->id->member, $condition))
            ->setParameter('id', $id, $metadata->getTypeOfField($mapping->id->member))
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE);

        if (null !== $version) {
            $query->setParameter('version', $version, $metadata->getTypeOfField($versionField));
        }

        $object = $query->getOneOrNullResult();

        return \is_object($object) ? $object : null;
    }

    /**
     * @param list<string>|null $among
     *
     * @return list<string>
     */
    public function elementIds(EntityManagerInterface $manager, ResourceMapping $mapping, object $object, AttributeMapping $collection, ?array $among): array
    {
        $target = $this->target($collection);
        $metadata = $manager->getClassMetadata($mapping->class);
        $dql = \sprintf('SELECT t.%s AS scim_id FROM %s o JOIN o.%s t WHERE o.%s = :owner%s', $target->id->member, $mapping->class, $collection->member, $mapping->id->member, $this->notDeleted($target, 't'));
        $owner = new Parameter('owner', $metadata->getFieldValue($object, $mapping->id->member), $metadata->getTypeOfField($mapping->id->member));

        if (null === $among) {
            return array_map(fn (mixed $value): string => $this->scimId($target, $value), $this->column($manager->createQuery($dql)->setParameters(new ArrayCollection([$owner]))));
        }

        $type = $manager->getClassMetadata($this->association($manager, $mapping, $collection)->targetEntity)->getTypeOfField($target->id->member);

        return $this->present($among, $target, $type, fn (ArrayCollection $parameters, string $placeholders): array => $this->column($manager->createQuery(\sprintf('%s AND t.%s IN (%s)', $dql, $target->id->member, $placeholders))->setParameters(new ArrayCollection([$owner, ...$parameters]))));
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string> the unknown ids
     */
    public function addElements(EntityManagerInterface $manager, ResourceMapping $mapping, object $object, AttributeMapping $collection, array $ids): array
    {
        $target = $this->target($collection);
        [$table, $ownerColumn, $targetColumn, $owner, $ownerType, $targetType] = $this->joinTable($manager, $mapping, $object, $collection);
        $dql = \sprintf('SELECT t.%1$s AS scim_id FROM %2$s t WHERE t.%1$s IN (%%s)%3$s', $target->id->member, $this->association($manager, $mapping, $collection)->targetEntity, $this->notDeleted($target, 't'));
        $known = $this->present($ids, $target, $targetType, fn (ArrayCollection $parameters, string $placeholders): array => $this->column($manager->createQuery(\sprintf($dql, $placeholders))->setParameters($parameters)));

        foreach (array_chunk($this->convert($target, $known), self::ID_CHUNK) as $chunk) {
            $manager->getConnection()->executeStatement(
                \sprintf('INSERT INTO %s (%s, %s) VALUES %s', $table, $ownerColumn, $targetColumn, implode(', ', array_fill(0, \count($chunk), '(?, ?)'))),
                array_merge(...array_map(static fn (mixed $value): array => [$owner, $value], $chunk)),
                array_merge(...array_fill(0, \count($chunk), [$ownerType, $targetType])),
            );
        }

        return array_values(array_diff($ids, $known));
    }

    /**
     * @param list<string> $ids
     */
    public function removeElements(EntityManagerInterface $manager, ResourceMapping $mapping, object $object, AttributeMapping $collection, array $ids): void
    {
        [$table, $ownerColumn, $targetColumn, $owner, $ownerType, $targetType] = $this->joinTable($manager, $mapping, $object, $collection);

        foreach (array_chunk($this->convert($this->target($collection), $ids), self::ID_CHUNK) as $chunk) {
            $manager->getConnection()->executeStatement(
                \sprintf('DELETE FROM %s WHERE %s = ? AND %s IN (%s)', $table, $ownerColumn, $targetColumn, implode(', ', array_fill(0, \count($chunk), '?'))),
                [$owner, ...$chunk],
                [$ownerType, ...array_fill(0, \count($chunk), $targetType)],
            );
        }
    }

    /**
     * @param list<JoinColumnMapping> $columns
     * @param ClassMetadata<object>   $metadata
     */
    private function referencesId(array $columns, ClassMetadata $metadata, ResourceMapping $mapping): bool
    {
        return 1 === \count($columns) && [$mapping->id->member] === $metadata->getIdentifierFieldNames() && $metadata->getColumnName($mapping->id->member) === $columns[0]->referencedColumnName;
    }

    /**
     * @return array{string, string, string, mixed, string, string} the quoted join table, owner column and target
     *                                                              column, the owner id value, and the Doctrine types
     *                                                              of the owner and the target ids
     */
    private function joinTable(EntityManagerInterface $manager, ResourceMapping $mapping, object $object, AttributeMapping $collection): array
    {
        $metadata = $manager->getClassMetadata($mapping->class);
        $association = $this->association($manager, $mapping, $collection);
        $targetMetadata = $manager->getClassMetadata($association->targetEntity);
        $platform = $manager->getConnection()->getDatabasePlatform();
        $quotes = $manager->getConfiguration()->getQuoteStrategy();

        return [
            $quotes->getJoinTableName($association, $metadata, $platform),
            $quotes->getJoinColumnName($association->joinTable->joinColumns[0], $metadata, $platform),
            $quotes->getJoinColumnName($association->joinTable->inverseJoinColumns[0], $targetMetadata, $platform),
            $metadata->getFieldValue($object, $mapping->id->member),
            (string) $metadata->getTypeOfField($mapping->id->member),
            (string) $targetMetadata->getTypeOfField($this->target($collection)->id->member),
        ];
    }

    /**
     * The ids of $ids that $query finds, in the order of $ids. $query gets the typed parameters and the placeholders
     * of one chunk of id member values, and returns the values that it finds.
     *
     * @param list<string>                                                   $ids
     * @param \Closure(ArrayCollection<int, Parameter>, string): list<mixed> $query
     *
     * @return list<string>
     */
    private function present(array $ids, ResourceMapping $target, ?string $type, \Closure $query): array
    {
        $converted = $this->converted($target, $ids);
        $found = [];

        foreach (array_chunk(array_column($converted, 1), self::ID_CHUNK) as $values) {
            $parameters = new ArrayCollection(array_map(static fn (int $index, mixed $value): Parameter => new Parameter('scim_id'.$index, $value, $type), array_keys($values), $values));

            foreach ($query($parameters, implode(', ', $parameters->map(static fn (Parameter $parameter): string => ':'.$parameter->getName())->toArray())) as $value) {
                $found[(string) $value] = true;
            }
        }

        return array_column(array_filter($converted, static fn (array $pair): bool => isset($found[(string) $pair[1]])), 0);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<array{string, mixed}> each id that converts, with its id member value
     */
    private function converted(ResourceMapping $target, array $ids): array
    {
        $converter = $this->converters->get($target->id->converter ?? IdConverter::class);
        $context = new ConversionContext($target, $target->id);
        $converted = [];

        foreach ($ids as $id) {
            try {
                $converted[] = [$id, $converter->fromScim($id, $context)];
            } catch (ConversionFailed) {
                continue;
            }
        }

        return $converted;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<mixed>
     */
    private function convert(ResourceMapping $target, array $ids): array
    {
        return array_column($this->converted($target, $ids), 1);
    }

    private function scimId(ResourceMapping $target, mixed $value): string
    {
        return (string) $this->converters->get($target->id->converter ?? IdConverter::class)->toScim($value, new ConversionContext($target, $target->id));
    }

    /**
     * Array hydration converts the selected id through its Doctrine type, scalar hydration does not.
     *
     * @return list<mixed>
     */
    private function column(Query $query): array
    {
        return array_column($query->getArrayResult(), 'scim_id');
    }

    private function association(EntityManagerInterface $manager, ResourceMapping $mapping, AttributeMapping $collection): ManyToManyOwningSideMapping
    {
        $association = $manager->getClassMetadata($mapping->class)->getAssociationMapping($collection->member);

        return $association instanceof ManyToManyOwningSideMapping ? $association : throw new \LogicException(\sprintf('%s::$%s is not the owning side of a ManyToMany association.', $mapping->class, $collection->member));
    }

    /**
     * " AND <the soft-delete guard of $alias>", or "".
     */
    private function notDeleted(ResourceMapping $mapping, string $alias): string
    {
        $guard = OrmQueryTranslator::notDeleted($mapping, $alias);

        return null === $guard ? '' : ' AND '.$guard;
    }

    private function target(AttributeMapping $collection): ResourceMapping
    {
        return $this->mappings->get((string) $collection->targetType);
    }
}

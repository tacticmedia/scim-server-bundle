<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Doctrine\Orm;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\Query\Parameter;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServerBundle\Converter\ConversionContext;
use TacticMedia\ScimServerBundle\Converter\ConversionFailed;
use TacticMedia\ScimServerBundle\Converter\IdConverter;
use TacticMedia\ScimServerBundle\Converter\ValueConverter;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingException;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Object\CollectionDeltaStore;
use TacticMedia\ScimServerBundle\Object\CursorObjectPage;
use TacticMedia\ScimServerBundle\Object\CursorPaginatedObjectStore;
use TacticMedia\ScimServerBundle\Object\ObjectPage;
use TacticMedia\ScimServerBundle\Object\SoftDeleteStore;
use TacticMedia\ScimServerBundle\Query\ObjectQuery;
use TacticMedia\ScimServerBundle\Query\Plan\Node;

/**
 * The object store for Doctrine ORM entities. Reads use DQL, so Doctrine SQL filters apply, and skip soft-deleted
 * objects. The first use of each mapping checks it against the Doctrine metadata.
 */
final class OrmObjectStore implements CursorPaginatedObjectStore, CollectionDeltaStore, SoftDeleteStore
{
    private const int ID_CHUNK = 500;

    /** @var \WeakMap<ResourceMapping, true> */
    private \WeakMap $checked;

    private readonly OrmCollectionDeltas $deltas;

    private readonly OrmQueryTranslator $translator;

    /**
     * @param ServiceLocator<ValueConverter> $converters keyed by class name
     */
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly MappingRegistry $mappings,
        private readonly ServiceLocator $converters,
    ) {
        $this->checked = new \WeakMap();
        $this->deltas = new OrmCollectionDeltas($mappings, $converters);
        $this->translator = new OrmQueryTranslator($mappings);
    }

    #[\Override]
    public function find(ResourceMapping $mapping, string $id): ?object
    {
        return $this->findMany($mapping, [$id])[$id] ?? null;
    }

    /**
     * @param list<string> $ids
     */
    #[\Override]
    public function findMany(ResourceMapping $mapping, array $ids): array
    {
        $manager = $this->manager($mapping);
        $converted = array_values(array_filter(array_map(fn (string $id): ?array => $this->convert($mapping, $id), $ids)));
        $metadata = $manager->getClassMetadata($mapping->class);
        $byValue = [];

        foreach (array_chunk(array_column($converted, 1), self::ID_CHUNK) as $values) {
            foreach ($this->byIds($manager, $mapping, $values) as $object) {
                $byValue[$this->key($metadata->getFieldValue($object, $mapping->id->member))] = $object;
            }
        }

        $found = [];

        foreach ($converted as [$id, $value]) {
            if (isset($byValue[$this->key($value)])) {
                $found[$id] = $byValue[$this->key($value)];
            }
        }

        return $found;
    }

    #[\Override]
    public function query(ResourceMapping $mapping, ObjectQuery $query): ObjectPage
    {
        $manager = $this->manager($mapping);
        $context = new OrmQueryContext($manager, $mapping->class);
        $builder = OrmQueryTranslator::rootQuery($manager, $mapping);

        if ($query->filter instanceof Node) {
            $builder->andWhere($this->translator->condition($query->filter, $context))->setParameters($context->parameters());
        }

        $total = max(0, (int) (clone $builder)->select('COUNT('.OrmQueryContext::ROOT.')')->getQuery()->getSingleScalarResult());

        foreach ($this->translator->orderBy($query->sortBy, $query->sortOrder, $context) as [$expression, $direction]) {
            $builder->addOrderBy($expression, $direction);
        }

        foreach ($context->joins() as [$association, $alias, $condition]) {
            $builder->leftJoin($association, $alias, Join::WITH, $condition);
        }

        $objects = 0 === $query->count || $total < $query->startIndex
            ? []
            : $this->objects($builder->setFirstResult($query->startIndex - 1)->setMaxResults($query->count)->getQuery()->getResult());
        $this->preload($manager, $mapping, $query->load, $objects);

        return new ObjectPage($objects, $total);
    }

    /**
     * Keyset over the order of query(); null for a sort member whose type {@see OrmKeyset} does not support.
     */
    #[\Override]
    public function queryAfter(ResourceMapping $mapping, ObjectQuery $query, ?array $after): ?CursorObjectPage
    {
        $manager = $this->manager($mapping);
        $page = new OrmKeyset($this->translator, $this->converters)->page($manager, $mapping, $query, $after);
        $this->preload($manager, $mapping, $query->load, $page->objects ?? []);

        return $page;
    }

    #[\Override]
    public function findDeleted(ResourceMapping $mapping, Node $filter): ?object
    {
        $guard = OrmQueryTranslator::notDeleted($mapping, OrmQueryContext::ROOT);

        if (null === $guard) {
            return null;
        }

        $manager = $this->manager($mapping);
        $context = new OrmQueryContext($manager, $mapping->class);
        $builder = $manager->createQueryBuilder()->select(OrmQueryContext::ROOT)->from($mapping->class, OrmQueryContext::ROOT)
            ->where(\sprintf('NOT (%s)', $guard))
            ->andWhere($this->translator->condition($filter, $context))
            ->setParameters($context->parameters())
            ->orderBy(OrmQueryContext::ROOT.'.'.$mapping->id->member)
            ->setMaxResults(1);

        return $this->objects($builder->getQuery()->getResult())[0] ?? null;
    }

    /**
     * @throws ScimException 409 uniqueness for a unique constraint violation, 409 for a foreign key violation or a
     *                       failed Doctrine version check
     */
    #[\Override]
    public function transactional(ResourceMapping $mapping, \Closure $write): mixed
    {
        $manager = $this->manager($mapping);

        try {
            return $manager->wrapInTransaction(static fn (): mixed => $write());
        } catch (\Throwable $throwable) {
            $filters = OrmFilters::enabled($manager);
            $this->doctrine->resetManager(array_find(array_keys($this->doctrine->getManagerNames()), fn (string $name): bool => $this->doctrine->getManager($name) === $manager));
            OrmFilters::enable($this->manager($mapping), $filters);

            throw match (true) {
                $throwable instanceof UniqueConstraintViolationException => ScimException::uniqueness('One or more of the attribute values are already in use or are reserved.', $throwable),
                $throwable instanceof ForeignKeyConstraintViolationException => ScimException::conflict('The resource is referenced by another resource, or refers to one that does not exist.'),
                $throwable instanceof OptimisticLockException => ScimException::conflict('The resource changed during the request. Retry the request.'),
                default => $throwable,
            };
        }
    }

    #[\Override]
    public function insert(ResourceMapping $mapping, object $object): void
    {
        $this->manager($mapping)->persist($object);
    }

    /**
     * @throws ScimException 412 when the row no longer has $version, 404 when the row is gone and $version is null
     */
    #[\Override]
    public function update(ResourceMapping $mapping, object $object, mixed $version = null): void
    {
        OrmVersioning::increment($this->manager($mapping), $mapping, $object, $version);
    }

    /**
     * @throws ScimException 412 when the row no longer has $version
     */
    #[\Override]
    public function delete(ResourceMapping $mapping, object $object, mixed $version = null): void
    {
        $manager = $this->manager($mapping);

        if (null !== $version) {
            OrmVersioning::increment($manager, $mapping, $object, $version);
        }

        $manager->remove($object);
    }

    #[\Override]
    public function supportsDeltas(ResourceMapping $mapping, AttributeMapping $collection): bool
    {
        return $this->deltas->supports($this->manager($mapping), $mapping, $collection);
    }

    #[\Override]
    public function lock(ResourceMapping $mapping, string $id, mixed $version = null): ?object
    {
        $converted = $this->convert($mapping, $id);

        return null === $converted ? null : $this->deltas->lock($this->manager($mapping), $mapping, $converted[1], $version);
    }

    #[\Override]
    public function elementIds(ResourceMapping $mapping, object $object, AttributeMapping $collection, ?array $among = null): array
    {
        return $this->deltas->elementIds($this->manager($mapping), $mapping, $object, $collection, $among);
    }

    #[\Override]
    public function addElements(ResourceMapping $mapping, object $object, AttributeMapping $collection, array $ids): array
    {
        return $this->deltas->addElements($this->manager($mapping), $mapping, $object, $collection, $ids);
    }

    #[\Override]
    public function removeElements(ResourceMapping $mapping, object $object, AttributeMapping $collection, array $ids): void
    {
        $this->deltas->removeElements($this->manager($mapping), $mapping, $object, $collection, $ids);
    }

    /**
     * Runs the first-use checks now, for debug:scim.
     *
     * @throws MappingException when Doctrine ORM does not manage the class, or its metadata does not fit the mapping
     */
    public function check(ResourceMapping $mapping): void
    {
        $this->manager($mapping);
    }

    /**
     * @throws MappingException when Doctrine ORM does not manage the class, or its metadata does not fit the mapping
     */
    private function manager(ResourceMapping $mapping): EntityManagerInterface
    {
        $manager = $this->doctrine->getManagerForClass($mapping->class);

        if (!$manager instanceof EntityManagerInterface) {
            throw MappingException::inClass($mapping->class, 'Doctrine ORM does not manage the class. Map it with #[ORM\Entity], or set #[ScimResource(store: ...)].');
        }

        if (!isset($this->checked[$mapping])) {
            OrmMappingChecks::check($mapping, $manager->getClassMetadata($mapping->class), $this->mappings);
            $this->checked[$mapping] = OrmQueryChecks::check($mapping, $manager, $this->translator);
        }

        return $manager;
    }

    /**
     * @return array{string, mixed}|null the SCIM id and the id member value; null when $id is malformed
     */
    private function convert(ResourceMapping $mapping, string $id): ?array
    {
        try {
            $value = $this->converters->get($mapping->id->converter ?? IdConverter::class)->fromScim($id, new ConversionContext($mapping, $mapping->id));
        } catch (ConversionFailed) {
            return null;
        }

        return null === $value ? null : [$id, $value];
    }

    /**
     * @param list<mixed> $values at most ID_CHUNK id member values
     * @param string|null $fetch  an association of the class to fetch with the objects
     *
     * @return list<object>
     */
    private function byIds(EntityManagerInterface $manager, ResourceMapping $mapping, array $values, ?string $fetch = null): array
    {
        $root = OrmQueryContext::ROOT;
        $builder = OrmQueryTranslator::rootQuery($manager, $mapping);
        $type = $manager->getClassMetadata($mapping->class)->getTypeOfField($mapping->id->member);
        $builder->setParameters(new ArrayCollection(array_map(static fn (int $index, mixed $value): Parameter => new Parameter('scim_id'.$index, $value, $type), array_keys($values), $values)));

        if (null !== $fetch) {
            $builder->addSelect('x')->leftJoin($root.'.'.$fetch, 'x');
        }

        $placeholders = implode(', ', array_map(static fn (int $index): string => ':scim_id'.$index, array_keys($values)));

        return $this->objects($builder->andWhere(\sprintf('%s.%s IN (%s)', $root, $mapping->id->member, $placeholders))->getQuery()->getResult());
    }

    /**
     * Fills the relations of $objects that are already managed, with one query per relation and chunk.
     *
     * @param list<AttributeMapping> $relations
     * @param list<object>           $objects
     */
    private function preload(EntityManagerInterface $manager, ResourceMapping $mapping, array $relations, array $objects): void
    {
        $metadata = $manager->getClassMetadata($mapping->class);

        foreach (array_chunk($objects, self::ID_CHUNK) as $chunk) {
            $ids = array_map(static fn (object $object): mixed => $metadata->getFieldValue($object, $mapping->id->member), $chunk);

            foreach ($relations as $relation) {
                $this->byIds($manager, $mapping, $ids, $relation->member);
            }
        }
    }

    /**
     * @return list<object>
     */
    private function objects(mixed $result): array
    {
        return array_values(array_filter(\is_array($result) ? $result : [], \is_object(...)));
    }

    /**
     * Doctrine compares identifiers by their string form as well.
     */
    private function key(mixed $value): string
    {
        return (string) $value;
    }
}

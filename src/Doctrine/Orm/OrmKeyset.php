<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Doctrine\Orm;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServerBundle\Converter\ConversionContext;
use TacticMedia\ScimServerBundle\Converter\ConversionFailed;
use TacticMedia\ScimServerBundle\Converter\IdConverter;
use TacticMedia\ScimServerBundle\Converter\ValueConverter;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Object\CursorObjectPage;
use TacticMedia\ScimServerBundle\Query\ObjectQuery;
use TacticMedia\ScimServerBundle\Query\Plan\Node;
use TacticMedia\ScimServerBundle\Query\Planning\MemberConditions;

/**
 * Keyset pages over the order of {@see OrmQueryTranslator::orderBy()}. The position holds the database value of each
 * order item of the last object, so that the next page compares under the rules of the database, and the SCIM id.
 *
 * @internal
 */
final readonly class OrmKeyset
{
    /**
     * Types whose database values are JSON scalars that compare equal to the stored value. Not smallfloat: a 4-byte
     * float is not equal to its decimal text.
     */
    private const array SCALAR_TYPES = [
        Types::ASCII_STRING, Types::BIGINT, Types::BOOLEAN, Types::DATE_MUTABLE, Types::DATE_IMMUTABLE,
        Types::DATETIME_MUTABLE, Types::DATETIME_IMMUTABLE, Types::DATETIME_UTC_MUTABLE, Types::DATETIME_UTC_IMMUTABLE,
        Types::ENUM, Types::FLOAT, Types::GUID, Types::INTEGER, Types::SMALLINT, Types::STRING, Types::TEXT,
        Types::TIME_MUTABLE, Types::TIME_IMMUTABLE, 'date_point', 'day_point', 'time_point',
    ];
    private const string KEY = 'scim_k';

    /**
     * @param ServiceLocator<ValueConverter> $converters keyed by class name
     */
    public function __construct(
        private OrmQueryTranslator $translator,
        private ServiceLocator $converters,
    ) {
    }

    /**
     * @param list<int|float|string|bool|null>|null $after
     *
     * @return CursorObjectPage|null null when a member of the order has a type outside SCALAR_TYPES
     *
     * @throws ScimException invalidCursor for a position of another query, invalidFilter and invalidValue from the translator
     */
    public function page(EntityManagerInterface $manager, ResourceMapping $mapping, ObjectQuery $query, ?array $after): ?CursorObjectPage
    {
        $metadata = $manager->getClassMetadata($mapping->class);
        $types = $this->types($metadata, $query);

        if (null === $types) {
            return null;
        }

        $context = new OrmQueryContext($manager, $mapping->class);
        $builder = OrmQueryTranslator::rootQuery($manager, $mapping);

        if ($query->filter instanceof Node) {
            $builder->andWhere($this->translator->condition($query->filter, $context))->setParameters($context->parameters());
        }

        $total = max(0, (int) (clone $builder)->select('COUNT('.OrmQueryContext::ROOT.')')->getQuery()->getSingleScalarResult());

        if (0 === $query->count) {
            return new CursorObjectPage([], $total, null);
        }

        $items = $this->translator->orderBy($query->sortBy, $query->sortOrder, $context);
        $this->order($builder, $items);

        if (null !== $after) {
            $builder->andWhere($this->after($items, $this->decode($manager, $mapping, $after, $types), [...$types, $metadata->getTypeOfField($mapping->id->member)], $context));
        }

        $rows = $this->rows($builder->setParameters($context->parameters())->setMaxResults($query->count + 1)->getQuery()->getResult());
        $objects = array_values(array_filter(array_column(\array_slice($rows, 0, $query->count), 0), \is_object(...)));

        return new CursorObjectPage($objects, $total, \count($rows) > $query->count ? $this->position($manager, $mapping, $types, $rows[$query->count - 1]) : null);
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @return list<string>|null the types of the order items before the id: the presence key and the value key
     */
    private function types(ClassMetadata $metadata, ObjectQuery $query): ?array
    {
        $memberTypes = array_values(array_filter(
            array_map(static fn (AttributeMapping $member): ?string => $metadata->getTypeOfField($member->member), $query->sortBy),
            static fn (?string $type): bool => \in_array($type, self::SCALAR_TYPES, true),
        ));

        return match (true) {
            \count($memberTypes) < \count($query->sortBy) => null,
            [] === $memberTypes => [],
            default => [Types::INTEGER, array_all($query->sortBy, MemberConditions::holdsString(...)) ? Types::STRING : $memberTypes[0]],
        };
    }

    /**
     * @param list<array{string, 'ASC'|'DESC'}> $items
     */
    private function order(QueryBuilder $builder, array $items): void
    {
        foreach ($items as [$expression, $direction]) {
            $builder->addOrderBy($expression, $direction);
        }

        foreach (\array_slice($items, 0, -1) as $index => [$expression]) {
            $builder->addSelect(\sprintf('%s AS %s%d', $expression, self::KEY, $index));
        }
    }

    /**
     * (A > a) OR (A = a AND B > b) OR (A = a AND B = b AND id > i), with < for a descending item. A null value occurs
     * only after the presence key, so the presence key implies its equality, and it has no successor.
     *
     * @param list<array{string, 'ASC'|'DESC'}> $items
     * @param list<mixed>                       $values the PHP value of each item
     * @param list<string|null>                 $types  the type of each item
     */
    private function after(array $items, array $values, array $types, OrmQueryContext $context): string
    {
        $equal = [];
        $terms = [];

        foreach ($items as $index => [$expression, $direction]) {
            if (null === $values[$index]) {
                continue;
            }

            $parameter = $context->parameter($values[$index], $types[$index]);
            $terms[] = '('.implode(' AND ', [...$equal, \sprintf('%s %s %s', $expression, 'DESC' === $direction ? '<' : '>', $parameter)]).')';
            $equal[] = \sprintf('%s = %s', $expression, $parameter);
        }

        return '('.implode(' OR ', $terms).')';
    }

    /**
     * @param list<int|float|string|bool|null> $after
     * @param list<string>                     $types
     *
     * @return list<mixed> the PHP value of each item, the id last
     *
     * @throws ScimException invalidCursor
     */
    private function decode(EntityManagerInterface $manager, ResourceMapping $mapping, array $after, array $types): array
    {
        $id = array_pop($after);
        $platform = $manager->getConnection()->getDatabasePlatform();

        try {
            $values = \count($after) === \count($types) ? array_map(static fn (mixed $value, string $type): mixed => null === $value ? null : Type::getType($type)->convertToPHPValue($value, $platform), $after, $types) : throw ScimException::invalidCursor();

            return [...$values, $this->idConverter($mapping)->fromScim($id, new ConversionContext($mapping, $mapping->id)) ?? throw ScimException::invalidCursor()];
        } catch (ConversionException|ConversionFailed) {
            throw ScimException::invalidCursor();
        }
    }

    /**
     * @param list<string>            $types
     * @param array<array-key, mixed> $row   the object at 0, the values of the order items at KEY<N>
     *
     * @return list<int|float|string|bool|null>
     */
    private function position(EntityManagerInterface $manager, ResourceMapping $mapping, array $types, array $row): array
    {
        $platform = $manager->getConnection()->getDatabasePlatform();
        $object = \is_object($row[0] ?? null) ? $row[0] : throw new \UnexpectedValueException('The row has no object.');
        $id = $this->idConverter($mapping)->toScim($manager->getClassMetadata($mapping->class)->getFieldValue($object, $mapping->id->member), new ConversionContext($mapping, $mapping->id, $object));

        return [...array_map(fn (int $index, string $type): int|float|string|bool|null => $this->databaseValue(Type::getType($type), $row[self::KEY.$index] ?? null, $platform), array_keys($types), $types), $this->scalar($id)];
    }

    /**
     * A raw value of an expression, or the PHP value of a bare field, as the database value of $type.
     */
    private function databaseValue(Type $type, mixed $value, AbstractPlatform $platform): int|float|string|bool|null
    {
        $value = $value instanceof \BackedEnum ? $value->value : $value;

        return null === $value ? null : $this->scalar($type->convertToDatabaseValue($type->convertToPHPValue($value, $platform), $platform));
    }

    private function idConverter(ResourceMapping $mapping): ValueConverter
    {
        return $this->converters->get($mapping->id->converter ?? IdConverter::class);
    }

    private function scalar(mixed $value): int|float|string|bool|null
    {
        return null === $value || \is_scalar($value) ? $value : throw new \UnexpectedValueException(\sprintf('A position value must be a scalar, %s given.', get_debug_type($value)));
    }

    /**
     * @return list<array<array-key, mixed>> a result row of an object without order values is the object
     */
    private function rows(mixed $result): array
    {
        return array_values(array_map(static fn (mixed $row): array => \is_array($row) ? $row : [$row], \is_array($result) ? $result : []));
    }
}

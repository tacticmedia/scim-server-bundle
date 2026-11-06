<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Doctrine\Orm;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ToManyAssociationMapping;
use Doctrine\ORM\Query\QueryException;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Query\SortOrder;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\ElementMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingException;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Query\Plan\AllOf;
use TacticMedia\ScimServerBundle\Query\Plan\Compare;
use TacticMedia\ScimServerBundle\Query\Plan\IsPresent;

/**
 * Element collections must be to-many associations ordered by the element identifier, with Doctrine fields for the
 * sub-attributes. A DQL expression must parse in a filter and in an ORDER BY.
 *
 * @internal
 */
final class OrmQueryChecks
{
    /**
     * @throws MappingException with every error of the mapping
     */
    public static function check(ResourceMapping $mapping, EntityManagerInterface $manager, OrmQueryTranslator $translator): true
    {
        $errors = [];

        foreach ($mapping->attributes as $attribute) {
            $errors = [...$errors, ...match (true) {
                $attribute->element instanceof ElementMapping => self::elementErrors($mapping, $attribute, $attribute->element, $manager),
                null !== $attribute->dqlExpression => self::expressionErrors($mapping, $attribute, $manager, $translator),
                default => [],
            }];
        }

        return [] === $errors ? true : throw new MappingException($errors);
    }

    /**
     * @return list<string>
     */
    private static function elementErrors(ResourceMapping $mapping, AttributeMapping $collection, ElementMapping $element, EntityManagerInterface $manager): array
    {
        $metadata = $manager->getClassMetadata($mapping->class);
        $member = $collection->member;
        $association = $metadata->hasAssociation($member) ? $metadata->getAssociationMapping($member) : null;

        if (!$association instanceof ToManyAssociationMapping || !is_a($association->targetEntity, $element->class, true)) {
            return [\sprintf('%s::$%s: "%s" lists %s objects, but $%2$s is not a to-many Doctrine association to %4$s. Map $%2$s with #[ORM\OneToMany] or #[ORM\ManyToMany] to %4$s.', $mapping->class, $member, $collection->declaredPath, $element->class)];
        }

        $elementMetadata = $manager->getClassMetadata($association->targetEntity);
        $identifier = $elementMetadata->getIdentifierFieldNames();
        $ordered = $identifier === array_keys($association->orderBy()) && array_all($association->orderBy(), self::ascending(...));

        return [
            ...$ordered ? [] : [\sprintf("%s::$%s: \"%s\" needs one element order for reads, ETags and sortBy. Add #[ORM\\OrderBy(['%s' => 'ASC'])] to $%2\$s.", $mapping->class, $member, $collection->declaredPath, $identifier[0] ?? 'id')],
            ...self::fieldErrors($element, $elementMetadata),
        ];
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @return list<string>
     */
    private static function fieldErrors(ElementMapping $element, ClassMetadata $metadata): array
    {
        $errors = [];

        foreach ($element->attributes as $attribute) {
            $problem = OrmMappingChecks::fieldProblem($attribute->member, $metadata);

            if (null !== $problem) {
                $errors[] = \sprintf('%s::%s: "%s" %s', $element->class, $attribute->label(), $attribute->declaredPath, $problem);
            }
        }

        return $errors;
    }

    /**
     * A string, or a SortDirection case of PHP 8.6 or its polyfill.
     */
    private static function ascending(\UnitEnum|string $direction): bool
    {
        return \in_array(strtolower($direction instanceof \UnitEnum ? $direction->name : $direction), ['asc', 'ascending'], true);
    }

    /**
     * @return list<string>
     */
    private static function expressionErrors(ResourceMapping $mapping, AttributeMapping $computed, EntityManagerInterface $manager, OrmQueryTranslator $translator): array
    {
        $context = new OrmQueryContext($manager, $mapping->class);

        try {
            $condition = $translator->condition(new AllOf([new IsPresent($computed), new Compare($computed, ComparisonOperator::Equal, '')]), $context);
            $order = implode(', ', array_map(static fn (array $item): string => $item[0], $translator->orderBy([$computed], SortOrder::Ascending, $context)));
            $manager->createQuery(\sprintf('SELECT r FROM %s r WHERE %s ORDER BY %s', $mapping->class, $condition, $order))->getSQL();
        } catch (QueryException $queryException) {
            return [\sprintf("%s::%s: \"%s\" has a #[ScimDqlExpression] that Doctrine cannot use: %s Write a DQL scalar expression with {alias} before each field, for example CONCAT({alias}.firstName, ' ', {alias}.lastName).", $mapping->class, $computed->label(), $computed->declaredPath, $queryException->getMessage())];
        }

        return [];
    }
}

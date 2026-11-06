<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Doctrine\Orm;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Query\SortOrder;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingKind;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Query\Plan\AllOf;
use TacticMedia\ScimServerBundle\Query\Plan\AnyOf;
use TacticMedia\ScimServerBundle\Query\Plan\Compare;
use TacticMedia\ScimServerBundle\Query\Plan\Exists;
use TacticMedia\ScimServerBundle\Query\Plan\IsPresent;
use TacticMedia\ScimServerBundle\Query\Plan\Negate;
use TacticMedia\ScimServerBundle\Query\Plan\Node;
use TacticMedia\ScimServerBundle\Query\Planning\FilterRejection;
use TacticMedia\ScimServerBundle\Query\Planning\MemberConditions;

/**
 * Translates a plan tree and a sort key to DQL. A comparison holds only for a present value: it is guarded with
 * IS NOT NULL on a nullable column and with <> '' on a string. Thus every condition is true or false, never unknown,
 * and NOT gives true for an absent value, as the library does. A related object of a mapping with #[ScimSoftDelete]
 * exists only while it is not soft-deleted.
 */
final readonly class OrmQueryTranslator
{
    public function __construct(
        private MappingRegistry $mappings,
    ) {
    }

    /**
     * SELECT r FROM <class> r, without the soft-deleted objects.
     */
    public static function rootQuery(EntityManagerInterface $manager, ResourceMapping $mapping): QueryBuilder
    {
        $builder = $manager->createQueryBuilder()->select(OrmQueryContext::ROOT)->from($mapping->class, OrmQueryContext::ROOT);
        $guard = self::notDeleted($mapping, OrmQueryContext::ROOT);

        return null === $guard ? $builder : $builder->where($guard);
    }

    /**
     * The DQL condition that $alias is not soft-deleted; null for a mapping without #[ScimSoftDelete].
     */
    public static function notDeleted(ResourceMapping $mapping, string $alias): ?string
    {
        $softDelete = $mapping->softDelete;

        return match (true) {
            !$softDelete instanceof \TacticMedia\ScimServerBundle\Mapping\SoftDelete => null,
            null === $softDelete->dateClass => \sprintf('%s.%s = false', $alias, $softDelete->property),
            default => \sprintf('%s.%s IS NULL', $alias, $softDelete->property),
        };
    }

    /**
     * @throws ScimException invalidFilter for a node or an operand that Doctrine cannot evaluate
     */
    public function condition(Node $node, OrmQueryContext $context, string $alias = OrmQueryContext::ROOT): string
    {
        return match (true) {
            $node instanceof AllOf => $this->join($node->nodes, ' AND ', $context, $alias),
            $node instanceof AnyOf => $this->join($node->nodes, ' OR ', $context, $alias),
            $node instanceof Negate => 'NOT ('.$this->condition($node->node, $context, $alias).')',
            $node instanceof IsPresent => $this->isPresent($node->operand, $context, $alias),
            $node instanceof Compare => $this->compare($node, $context, $alias),
            $node instanceof Exists => $this->exists($node, $context, $alias),
            default => throw ScimException::invalidFilter(\sprintf('The plan node %s is not supported by the Doctrine ORM store.', $node::class)),
        };
    }

    /**
     * ORDER BY items of the root alias: absent values last ascending and first descending, then the value of the
     * first present member of $sortBy, then the id ascending for stable pages. Without $sortBy: the id ascending. An
     * absent string, null or "", has the value "", so absent values tie and the id orders them.
     *
     * @param list<AttributeMapping> $sortBy an ElementCollection first: joins the element that sorts, see {@see OrmQueryContext::joins()}
     *
     * @return list<array{string, 'ASC'|'DESC'}>
     *
     * @throws ScimException invalidValue when a member of $sortBy is not a Doctrine field
     */
    public function orderBy(array $sortBy, SortOrder $order, OrmQueryContext $context): array
    {
        $id = [OrmQueryContext::ROOT.'.'.$context->metadata(OrmQueryContext::ROOT)->getSingleIdentifierFieldName(), 'ASC'];

        if ([] === $sortBy) {
            return [$id];
        }

        [$alias, $members] = MappingKind::ElementCollection === $sortBy[0]->kind ? [$this->joinKeyElement($sortBy[0], $context), [$sortBy[1] ?? $sortBy[0]]] : [OrmQueryContext::ROOT, $sortBy];
        $direction = SortOrder::Descending === $order ? 'DESC' : 'ASC';
        $keys = array_map(fn (AttributeMapping $member): array => $this->sortKey($member, $context, $alias), $members);

        return [
            ['CASE WHEN '.implode(' OR ', array_column($keys, 0)).' THEN 0 ELSE 1 END', $direction],
            [$this->firstPresent($keys, array_all($members, MemberConditions::holdsString(...))), $direction],
            $id,
        ];
    }

    /**
     * Joins the element whose member sorts the owner: the primary element with the lowest identifier, else the
     * element with the lowest identifier. That is the primary element, else the first element, of a collection
     * ordered by its identifier, which the first-use checks require.
     *
     * @return string the alias of the element
     */
    private function joinKeyElement(AttributeMapping $collection, OrmQueryContext $context): string
    {
        $metadata = $context->metadata(OrmQueryContext::ROOT);
        $primary = $collection->element?->attributeAt($collection->path->withSubAttribute('primary'));
        $class = $metadata->getAssociationTargetClass($collection->member);

        return $context->join(OrmQueryContext::ROOT.'.'.$collection->member, $class, static function (string $alias) use ($metadata, $collection, $primary, $class, $context): string {
            $number = $context->subquery($class);
            $id = $context->metadata($alias)->getSingleIdentifierFieldName();
            $before = \sprintf('t%d.%s < %s.%2$s', $number, $id, $alias);

            if ($primary instanceof AttributeMapping) {
                $rank = static fn (string $element): string => \sprintf('CASE WHEN %s.%s = true THEN 0 ELSE 1 END', $element, $primary->member);
                $before = \sprintf('(%1$s < %2$s OR (%1$s = %2$s AND %3$s))', $rank('t'.$number), $rank($alias), $before);
            }

            return \sprintf('NOT EXISTS (SELECT 1 FROM %1$s o%2$d JOIN o%2$d.%3$s t%2$d WHERE o%2$d = %4$s AND %5$s)', $metadata->getName(), $number, $collection->member, OrmQueryContext::ROOT, $before);
        });
    }

    /**
     * @param list<Node> $nodes
     */
    private function join(array $nodes, string $operator, OrmQueryContext $context, string $alias): string
    {
        return '('.implode($operator, array_map(fn (Node $node): string => $this->condition($node, $context, $alias), $nodes)).')';
    }

    private function exists(Exists $node, OrmQueryContext $context, string $alias): string
    {
        $metadata = $context->metadata($alias);
        $member = $node->relation->member;

        if ($node->relation->method || !$metadata->hasAssociation($member)) {
            throw FilterRejection::notFilterable($node->relation->path);
        }

        $related = $metadata->getAssociationTargetClass($member);
        $number = $context->subquery($related);
        $from = $metadata->isCollectionValuedAssociation($member)
            ? \sprintf('%1$s o%2$d JOIN o%2$d.%3$s t%2$d WHERE o%2$d = %4$s', $metadata->getName(), $number, $member, $alias)
            : \sprintf('%1$s t%2$d WHERE t%2$d = %3$s.%4$s', $related, $number, $alias, $member);
        $guard = null === $node->relation->targetType ? null : self::notDeleted($this->mappings->get($node->relation->targetType), 't'.$number);
        $condition = $node->condition instanceof Node ? $this->condition($node->condition, $context, 't'.$number) : null;

        return \sprintf('EXISTS (SELECT 1 FROM %s%s)', $from, implode('', array_map(static fn (string $part): string => ' AND '.$part, array_filter([$guard, $condition]))));
    }

    private function isPresent(AttributeMapping $operand, OrmQueryContext $context, string $alias): string
    {
        $field = $this->field($operand, $context, $alias) ?? throw FilterRejection::notFilterable($operand->path);

        return MemberConditions::holdsString($operand) ? \sprintf("(%s IS NOT NULL AND %1\$s <> '')", $field) : $field.' IS NOT NULL';
    }

    private function compare(Compare $node, OrmQueryContext $context, string $alias): string
    {
        $operand = $node->operand;
        $field = $this->field($operand, $context, $alias) ?? throw FilterRejection::notFilterable($operand->path);
        $fold = $this->foldsCase($operand);
        $comparison = \sprintf('%s %s %s', $this->fold($field, $fold), $this->operator($node->operator), $this->fold($this->parameter($node, $context, $alias), $fold));
        $comparison .= $node->operator->isSubstring() ? " ESCAPE '!'" : '';
        $guards = array_keys(array_filter([
            $field.' IS NOT NULL' => null !== $operand->dqlExpression || $context->metadata($alias)->isNullable($operand->member),
            $field." <> ''" => MemberConditions::holdsString($operand),
        ]));

        return [] === $guards ? $comparison : '('.implode(' AND ', [...$guards, $comparison]).')';
    }

    private function parameter(Compare $node, OrmQueryContext $context, string $alias): string
    {
        if (!$node->operator->isSubstring()) {
            return $context->parameter($node->value, $context->metadata($alias)->getTypeOfField($node->operand->member));
        }

        $value = \is_string($node->value) ? strtr($node->value, ['!' => '!!', '%' => '!%', '_' => '!_']) : throw ScimException::invalidFilter(\sprintf("The operator '%s' needs a string value for '%s'.", $node->operator->value, $node->operand->path));

        return $context->parameter(match ($node->operator) {
            ComparisonOperator::StartsWith => $value.'%',
            ComparisonOperator::EndsWith => '%'.$value,
            default => '%'.$value.'%',
        }, Types::STRING);
    }

    private function operator(ComparisonOperator $operator): string
    {
        return match ($operator) {
            ComparisonOperator::Equal => '=',
            ComparisonOperator::GreaterThan => '>',
            ComparisonOperator::GreaterThanOrEqual => '>=',
            ComparisonOperator::LessThan => '<',
            ComparisonOperator::LessThanOrEqual => '<=',
            ComparisonOperator::Contains, ComparisonOperator::StartsWith, ComparisonOperator::EndsWith => 'LIKE',
            ComparisonOperator::NotEqual => throw ScimException::invalidFilter("The operator 'ne' is not supported in a plan. Use Negate."),
        };
    }

    /**
     * The DQL expression with {alias} replaced, else "<alias>.<member>" when the operand is a Doctrine field, else null.
     */
    private function field(AttributeMapping $operand, OrmQueryContext $context, string $alias): ?string
    {
        return match (true) {
            null !== $operand->dqlExpression => str_replace('{alias}', $alias, $operand->dqlExpression),
            !$operand->method && $context->metadata($alias)->hasField($operand->member) => $alias.'.'.$operand->member,
            default => null,
        };
    }

    private function fold(string $expression, bool $fold): string
    {
        return $fold ? 'LOWER('.$expression.')' : $expression;
    }

    private function foldsCase(AttributeMapping $operand): bool
    {
        return \in_array($operand->definition->type, [AttributeType::String, AttributeType::Reference], true) && !$operand->definition->caseExact;
    }

    /**
     * @return array{string, string} the presence condition and the value of one sort member
     *
     * @throws ScimException invalidValue when $member is not a Doctrine field
     */
    private function sortKey(AttributeMapping $member, OrmQueryContext $context, string $alias): array
    {
        $field = $this->field($member, $context, $alias) ?? throw ScimException::invalidValue(\sprintf("sortBy '%s' is not supported.", $member->path));

        return [$this->isPresent($member, $context, $alias), $this->fold($field, $this->foldsCase($member))];
    }

    /**
     * The value of the first present key. Without one: "" for strings, else the value of the last key, which is null.
     *
     * @param non-empty-list<array{string, string}> $keys
     */
    private function firstPresent(array $keys, bool $strings): string
    {
        $cases = $strings ? $keys : \array_slice($keys, 0, -1);
        $else = $strings ? "''" : array_last($keys)[1];

        return [] === $cases ? $else : 'CASE '.implode(' ', array_map(static fn (array $key): string => \sprintf('WHEN %s THEN %s', ...$key), $cases)).' ELSE '.$else.' END';
    }
}

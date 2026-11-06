<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Query\Planning;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Comparison;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Filter\LogicalAnd;
use TacticMedia\ScimServer\Filter\LogicalOr;
use TacticMedia\ScimServer\Filter\Not;
use TacticMedia\ScimServer\Filter\Present;
use TacticMedia\ScimServer\Filter\ValuePathFilter;
use TacticMedia\ScimServerBundle\Query\Plan\Node;

/**
 * Plans the logical operators of a filter and rewrites ne and null comparisons, as FilterEvaluator reads them.
 *
 * @internal
 */
final class FilterWalker
{
    /**
     * @param \Closure(Comparison|Present): (Node|bool) $leaf      gets no ne and no null comparison
     * @param \Closure(ValuePathFilter): (Node|bool)    $valuePath
     * @param (\Closure(Comparison): (Node|bool))|null  $notEqual  gets the eq comparison of an ne with a value; null: not(eq)
     *
     * @throws ScimException invalidFilter
     */
    public static function plan(Filter $filter, \Closure $leaf, \Closure $valuePath, ?\Closure $notEqual = null): Node|bool
    {
        $plan = static fn (Filter $operand): Node|bool => self::plan($operand, $leaf, $valuePath, $notEqual);

        return match (true) {
            $filter instanceof LogicalAnd => Fold::allOf([$plan($filter->left), $plan($filter->right)]),
            $filter instanceof LogicalOr => Fold::anyOf([$plan($filter->left), $plan($filter->right)]),
            $filter instanceof Not => Fold::not($plan($filter->filter)),
            $filter instanceof Comparison => self::comparison($filter, $leaf, $notEqual ?? static fn (Comparison $equal): Node|bool => Fold::not($leaf($equal))),
            $filter instanceof Present => $leaf($filter),
            $filter instanceof ValuePathFilter => $valuePath($filter),
            default => throw ScimException::invalidFilter(\sprintf('The filter node %s is not supported.', $filter::class)),
        };
    }

    /**
     * @param \Closure(Comparison|Present): (Node|bool) $leaf
     * @param \Closure(Comparison): (Node|bool)         $notEqual
     */
    private static function comparison(Comparison $comparison, \Closure $leaf, \Closure $notEqual): Node|bool
    {
        $present = new Present($comparison->path);

        return match (true) {
            null !== $comparison->value && ComparisonOperator::NotEqual === $comparison->operator => $notEqual(new Comparison($comparison->path, ComparisonOperator::Equal, $comparison->value)),
            null !== $comparison->value => $leaf($comparison),
            ComparisonOperator::Equal === $comparison->operator => Fold::not($leaf($present)),
            ComparisonOperator::NotEqual === $comparison->operator => $leaf($present),
            default => false,
        };
    }
}

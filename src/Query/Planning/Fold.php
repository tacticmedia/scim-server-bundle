<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Query\Planning;

use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Query\Plan\AllOf;
use TacticMedia\ScimServerBundle\Query\Plan\AnyOf;
use TacticMedia\ScimServerBundle\Query\Plan\Exists;
use TacticMedia\ScimServerBundle\Query\Plan\Negate;
use TacticMedia\ScimServerBundle\Query\Plan\Node;

/**
 * Builds plan nodes with constant folding. A bool is a constant condition and never enters a node.
 *
 * @internal
 */
final class Fold
{
    /**
     * @param list<Node|bool> $nodes
     */
    public static function allOf(array $nodes): Node|bool
    {
        return self::join($nodes, false, static fn (array $kept): Node => new AllOf($kept));
    }

    /**
     * @param list<Node|bool> $nodes
     */
    public static function anyOf(array $nodes): Node|bool
    {
        return self::join($nodes, true, static fn (array $kept): Node => new AnyOf($kept));
    }

    public static function not(Node|bool $node): Node|bool
    {
        return \is_bool($node) ? !$node : new Negate($node);
    }

    public static function exists(AttributeMapping $relation, Node|bool $condition): Node|bool
    {
        return match ($condition) {
            false => false,
            true => new Exists($relation),
            default => new Exists($relation, $condition),
        };
    }

    /**
     * @param list<Node|bool>                      $nodes
     * @param bool                                 $absorbing the constant that decides the result: false for AllOf, true for AnyOf
     * @param \Closure(non-empty-list<Node>): Node $join      called with two or more nodes
     */
    private static function join(array $nodes, bool $absorbing, \Closure $join): Node|bool
    {
        $kept = array_values(array_filter($nodes, static fn (Node|bool $node): bool => $node instanceof Node));

        return match (true) {
            \in_array($absorbing, $nodes, true) => $absorbing,
            [] === $kept => !$absorbing,
            1 === \count($kept) => $kept[0],
            default => $join($kept),
        };
    }
}

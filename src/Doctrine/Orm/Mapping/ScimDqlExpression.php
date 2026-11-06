<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Doctrine\Orm\Mapping;

/**
 * The DQL scalar expression that equals the value of a method mapping, so that the Doctrine ORM store can filter and
 * sort by it. {alias} stands for the alias of the entity, for example "CONCAT({alias}.firstName, ' ', {alias}.lastName)".
 * The store checks the expression on first use. It cannot check that the expression and the method give one value.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class ScimDqlExpression
{
    public function __construct(
        public string $dql,
    ) {
    }
}

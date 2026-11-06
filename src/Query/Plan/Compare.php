<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Query\Plan;

use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;

/**
 * The member value compares to $value. False when the member is null or, for a string, "". $operand is a Field or a
 * Slot. Case-insensitive when $operand->definition is a string or reference attribute with caseExact false.
 */
final readonly class Compare implements Node
{
    /**
     * @param ComparisonOperator $operator never NotEqual
     * @param mixed              $value    never null: the member value from ValueConverter::fromScim(), a BackedEnum as its backing value, a dateTime in whole seconds; the string literal for co, sw and ew, and for gt, ge, lt and le on a string member without converter
     */
    public function __construct(
        public AttributeMapping $operand,
        public ComparisonOperator $operator,
        public mixed $value,
    ) {
    }
}

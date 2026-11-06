<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Query\Planning;

use Symfony\Component\DependencyInjection\ServiceLocator;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Comparison;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Filter\Present;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServerBundle\Converter\ConversionContext;
use TacticMedia\ScimServerBundle\Converter\ConversionFailed;
use TacticMedia\ScimServerBundle\Converter\IdConverter;
use TacticMedia\ScimServerBundle\Converter\NativeConverter;
use TacticMedia\ScimServerBundle\Converter\ValueConverter;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingKind;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Query\Plan\Compare;
use TacticMedia\ScimServerBundle\Query\Plan\IsPresent;
use TacticMedia\ScimServerBundle\Query\Plan\Node;

/**
 * Conditions on one Field or Slot member, with the filter literal converted to a member value.
 *
 * @internal
 */
final readonly class MemberConditions
{
    /**
     * @param ServiceLocator<ValueConverter> $converters
     */
    public function __construct(
        private ServiceLocator $converters,
    ) {
    }

    public function of(ResourceMapping $mapping, AttributeMapping $member, Comparison|Present $leaf): Node|bool
    {
        return match (true) {
            Mutability::WriteOnly === $member->definition->mutability => throw FilterRejection::notFilterable($leaf->path),
            $leaf instanceof Present => new IsPresent($member),
            $leaf->operator->isSubstring() => $this->substring($member, $leaf),
            default => $this->compare($mapping, $member, $leaf->operator, $leaf->value),
        };
    }

    /**
     * The member is a string or a string-backed enum.
     */
    public static function holdsString(AttributeMapping $member): bool
    {
        $type = (string) $member->phpType;

        return 'string' === $type || (enum_exists($type) && 'string' === (string) new \ReflectionEnum($type)->getBackingType());
    }

    /**
     * gt, ge, lt, le and sortBy compare SCIM strings, so an id that is not a string supports none of them.
     */
    public static function canOrder(ResourceMapping $mapping, AttributeMapping $member): bool
    {
        return $member !== $mapping->id || 'string' === $member->phpType;
    }

    public static function canSort(ResourceMapping $mapping, AttributeMapping $member): bool
    {
        return (MappingKind::Slot === $member->kind || self::isValue($member))
            && Mutability::WriteOnly !== $member->definition->mutability
            && self::canOrder($mapping, $member);
    }

    /**
     * A Field, or a Computed mapping with a DQL expression: one value of the object.
     */
    public static function isValue(AttributeMapping $member): bool
    {
        return MappingKind::Field === $member->kind || null !== $member->dqlExpression;
    }

    /**
     * gt, ge, lt and le compare a native string member with the literal itself, as the library compares SCIM strings,
     * and fail on an id that is not a string. Otherwise false when the literal converts to no member value: a
     * ConversionFailed or null.
     *
     * @throws ScimException invalidFilter
     */
    public function compare(ResourceMapping $mapping, AttributeMapping $member, ComparisonOperator $operator, mixed $literal): Compare|false
    {
        $ordering = ComparisonOperator::Equal !== $operator;

        return match (true) {
            $ordering && !self::canOrder($mapping, $member) => throw FilterRejection::operator($operator, $member->path),
            $ordering && null === $member->converter && \is_string($literal) && self::holdsString($member) => new Compare($member, $operator, $literal),
            default => $this->converted($mapping, $member, $operator, $literal),
        };
    }

    private function converted(ResourceMapping $mapping, AttributeMapping $member, ComparisonOperator $operator, mixed $literal): Compare|false
    {
        $converter = $this->converters->get($member->converter ?? ($member === $mapping->id ? IdConverter::class : NativeConverter::class));

        try {
            $value = $converter->fromScim($literal, new ConversionContext($mapping, $member));
        } catch (ConversionFailed) {
            return false;
        }

        return null === $value ? false : $this->comparison($member, $operator, $value);
    }

    private function comparison(AttributeMapping $member, ComparisonOperator $operator, mixed $value): Compare|false
    {
        return match (true) {
            null === $member->converter && ($value instanceof \DateTimeImmutable || $value instanceof \DateTime) && '000000' !== $value->format('u') => $this->nextSecondBound($member, $operator, $value),
            default => new Compare($member, $operator, $value instanceof \BackedEnum ? $value->value : $value),
        };
    }

    /**
     * NativeConverter renders whole seconds, so no rendered value equals $value, and the next whole second bounds the
     * others.
     */
    private function nextSecondBound(AttributeMapping $member, ComparisonOperator $operator, \DateTimeImmutable|\DateTime $value): Compare|false
    {
        $next = (clone $value)->setTime((int) $value->format('G'), (int) $value->format('i'), (int) $value->format('s') + 1);

        return match ($operator) {
            ComparisonOperator::Equal => false,
            ComparisonOperator::GreaterThan, ComparisonOperator::GreaterThanOrEqual => new Compare($member, ComparisonOperator::GreaterThanOrEqual, $next),
            default => new Compare($member, ComparisonOperator::LessThan, $next),
        };
    }

    private function substring(AttributeMapping $member, Comparison $leaf): Compare
    {
        return self::holdsString($member) && \is_string($leaf->value)
            ? new Compare($member, $leaf->operator, $leaf->value)
            : throw FilterRejection::operator($leaf->operator, $leaf->path);
    }
}

<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

use TacticMedia\ScimServer\Filter\ValueCoercion;
use TacticMedia\ScimServer\Schema\AttributeType;

/**
 * Native PHP types that a member needs without a converter, per SCIM type.
 *
 * @internal
 */
final class PhpTypes
{
    private const array EXPECTED = [
        'boolean' => 'bool',
        'integer' => 'int',
        'decimal' => 'float',
        'dateTime' => '\DateTimeImmutable',
        'complex' => 'sub-attribute paths',
    ];

    /**
     * "string" or "int" for a backed enum, else null.
     */
    public static function backing(string $phpType): ?string
    {
        return is_subclass_of($phpType, \BackedEnum::class) ? (string) new \ReflectionEnum($phpType)->getBackingType() : null;
    }

    /**
     * The case values of a string-backed enum, else [].
     *
     * @return list<string>
     */
    public static function canonicalValues(?string $phpType): array
    {
        if (null === $phpType || !is_subclass_of($phpType, \BackedEnum::class)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (\BackedEnum $case): int|string => $case->value, $phpType::cases()), \is_string(...)));
    }

    public static function fits(AttributeType $type, ?string $phpType): bool
    {
        $backing = null === $phpType ? null : self::backing($phpType);

        return match ($type) {
            AttributeType::String, AttributeType::Reference, AttributeType::Binary => 'string' === $phpType || 'string' === $backing,
            AttributeType::Boolean => 'bool' === $phpType,
            AttributeType::Integer => 'int' === $phpType || 'int' === $backing,
            AttributeType::Decimal => \in_array($phpType, ['float', 'int'], true),
            AttributeType::DateTime => null !== $phpType && is_a($phpType, \DateTimeInterface::class, true),
            AttributeType::Complex => false,
        };
    }

    public static function expected(AttributeType $type): string
    {
        return self::EXPECTED[$type->value] ?? 'string';
    }

    public static function scimType(?string $phpType): AttributeType
    {
        return match (true) {
            null === $phpType => AttributeType::String,
            'bool' === $phpType => AttributeType::Boolean,
            'int' === $phpType, 'int' === self::backing($phpType) => AttributeType::Integer,
            'float' === $phpType => AttributeType::Decimal,
            is_a($phpType, \DateTimeInterface::class, true) => AttributeType::DateTime,
            default => AttributeType::String,
        };
    }

    /**
     * Whether a SCIM value of $type can be $value: the form of default: and of a document value.
     */
    public static function isScimValue(AttributeType $type, string|int|float|bool $value): bool
    {
        return match ($type) {
            AttributeType::Boolean => \is_bool($value),
            AttributeType::Integer => \is_int($value),
            AttributeType::Decimal => \is_int($value) || \is_float($value),
            AttributeType::DateTime => ValueCoercion::toInstant($value) instanceof \DateTimeImmutable,
            default => \is_string($value),
        };
    }
}

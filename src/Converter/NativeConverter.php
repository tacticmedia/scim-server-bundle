<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Converter;

use TacticMedia\ScimServer\Filter\ValueCoercion;

/**
 * Strings, numbers, booleans, DateTimeInterface and backed enums without an application converter.
 */
final readonly class NativeConverter implements ValueConverter
{
    #[\Override]
    public function toScim(mixed $value, ConversionContext $context): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:sp'),
            $value instanceof \BackedEnum => $value->value,
            default => $value,
        };
    }

    #[\Override]
    public function fromScim(mixed $value, ConversionContext $context): mixed
    {
        $attribute = $context->attribute;
        $phpType = (string) $attribute->phpType;

        return match (true) {
            null === $value, '' === $value && $attribute->nullable && 'string' === $phpType => null,
            \is_int($value) && 'float' === $phpType => (float) $value,
            \is_float($value) && 'int' === $phpType => $this->integer($value),
            is_a($phpType, \DateTimeInterface::class, true) => $this->instant($value, $phpType),
            is_subclass_of($phpType, \BackedEnum::class) => $this->enumCase($phpType, $value, $attribute->definition->caseExact),
            default => $value,
        };
    }

    private function integer(float $value): int
    {
        // (float) PHP_INT_MAX is 2 ** 63, which is outside the int range.
        return floor($value) === $value && $value >= \PHP_INT_MIN && $value < \PHP_INT_MAX ? (int) $value : throw new ConversionFailed('must be an integer.');
    }

    /**
     * @param class-string<\DateTimeInterface> $phpType
     */
    private function instant(mixed $value, string $phpType): \DateTimeInterface
    {
        $instant = ValueCoercion::toInstant($value)?->setTimezone(new \DateTimeZone('UTC')) ?? throw new ConversionFailed('must be an xsd:dateTime.');

        return is_a($phpType, \DateTimeImmutable::class, true) || is_a($phpType, \DateTime::class, true) ? $phpType::createFromInterface($instant) : $instant;
    }

    /**
     * @param class-string<\BackedEnum> $enum
     */
    private function enumCase(string $enum, mixed $value, bool $caseExact): \BackedEnum
    {
        $cases = $enum::cases();
        $folded = static fn (\BackedEnum $case): bool => !$caseExact && \is_string($value) && \is_string($case->value) && 0 === strcasecmp($case->value, $value);

        return array_find($cases, static fn (\BackedEnum $case): bool => $case->value === $value)
            ?? array_find($cases, $folded)
            ?? throw new ConversionFailed(\sprintf('must be one of %s.', implode(', ', array_map(static fn (\BackedEnum $case): string => (string) $case->value, $cases))));
    }
}

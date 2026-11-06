<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Converter;

use Symfony\Component\Uid\AbstractUid;

/**
 * The id of a resource: an AbstractUid, an int with the optional idPrefix of the resource, or a string.
 */
final readonly class IdConverter implements ValueConverter
{
    #[\Override]
    public function toScim(mixed $value, ConversionContext $context): ?string
    {
        return match (true) {
            null === $value, \is_string($value) => $value,
            \is_int($value) => $context->resource->idPrefix.$value,
            $value instanceof AbstractUid => (string) $value,
            default => throw new \UnexpectedValueException(\sprintf('The id of %s must be a string, an int or a %s, %s given.', $context->resource->class, AbstractUid::class, get_debug_type($value))),
        };
    }

    #[\Override]
    public function fromScim(mixed $value, ConversionContext $context): mixed
    {
        $phpType = (string) $context->attribute->phpType;

        return match (true) {
            null === $value => null,
            !\is_string($value) => throw new ConversionFailed('must be a string.'),
            'int' === $phpType => $this->integer($value, (string) $context->resource->idPrefix),
            is_a($phpType, AbstractUid::class, true) => $phpType::isValid($value) ? $phpType::fromString($value) : throw new ConversionFailed(\sprintf('must be a valid %s.', $phpType)),
            default => $value,
        };
    }

    private function integer(string $value, string $prefix): int
    {
        $digits = str_starts_with($value, $prefix) ? substr($value, \strlen($prefix)) : '';
        $id = (int) $digits;

        // The round trip rejects signs, leading zeros, spaces, fractions and values above PHP_INT_MAX.
        return $id > 0 && (string) $id === $digits ? $id : throw new ConversionFailed('' === $prefix ? 'must be a positive integer.' : \sprintf('must be "%s" followed by a positive integer.', $prefix));
    }
}

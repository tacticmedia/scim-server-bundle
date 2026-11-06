<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Converter;

/**
 * An int version, such as a Doctrine #[ORM\Version] column, as the weak entity tag W/"<n>". The default converter of
 * an int member at meta.version.
 */
final readonly class VersionConverter implements ValueConverter
{
    #[\Override]
    public function toScim(mixed $value, ConversionContext $context): ?string
    {
        return match (true) {
            null === $value => null,
            \is_int($value) => \sprintf('W/"%d"', $value),
            default => throw new \UnexpectedValueException(\sprintf('The version of %s must be an int, %s given.', $context->resource->class, get_debug_type($value))),
        };
    }

    #[\Override]
    public function fromScim(mixed $value, ConversionContext $context): ?int
    {
        if (null === $value) {
            return null;
        }

        $digits = \is_string($value) && 1 === preg_match('/\A(?:W\/)?"(\d+)"\z/', $value, $matches) ? $matches[1] : '';
        $version = (int) $digits;

        // The round trip rejects leading zeros and values above PHP_INT_MAX.
        return (string) $version === $digits ? $version : throw new ConversionFailed('must be an entity tag of an integer, for example W/"7".');
    }
}

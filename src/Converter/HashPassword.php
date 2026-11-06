<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Converter;

use Symfony\Component\PasswordHasher\Exception\InvalidPasswordException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * Hashes the writeOnly password with the hasher that the security configuration selects for the class of the object.
 */
final readonly class HashPassword implements ValueConverter
{
    public function __construct(
        private UserPasswordHasherInterface $hasher,
    ) {
    }

    #[\Override]
    public function toScim(mixed $value, ConversionContext $context): never
    {
        throw new \LogicException('The password is writeOnly and is never read.');
    }

    #[\Override]
    public function fromScim(mixed $value, ConversionContext $context): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_string($value) || !$context->object instanceof PasswordAuthenticatedUserInterface) {
            throw new ConversionFailed(\sprintf('must be a string, and HashPassword needs an object that implements %s.', PasswordAuthenticatedUserInterface::class));
        }

        try {
            return $this->hasher->hashPassword($context->object, $value);
        } catch (InvalidPasswordException $invalidPasswordException) {
            throw new ConversionFailed(PasswordHasherInterface::MAX_PASSWORD_LENGTH < \strlen($value) ? \sprintf('must be at most %d bytes.', PasswordHasherInterface::MAX_PASSWORD_LENGTH) : $invalidPasswordException->getMessage(), $invalidPasswordException->getCode(), previous: $invalidPasswordException);
        }
    }
}

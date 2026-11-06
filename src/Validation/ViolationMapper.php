<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Validation;

use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;

/**
 * Validator violations as a SCIM error. Each violation names the declared path of the member at the start of its
 * property path; a violation of the class or of an unmapped member names none.
 */
final readonly class ViolationMapper
{
    /**
     * @return ScimException 409 uniqueness with the UniqueEntity violations, else 400 invalidValue with all violations
     */
    public static function exception(ResourceMapping $mapping, ConstraintViolationListInterface $violations): ScimException
    {
        $violations = iterator_to_array($violations, false);
        // instanceof does not load a class, so the check holds without symfony/doctrine-bridge.
        $unique = array_filter($violations, static fn (ConstraintViolationInterface $violation): bool => $violation->getConstraint() instanceof UniqueEntity);

        return [] === $unique
            ? ScimException::invalidValue(self::detail($mapping, $violations))
            : ScimException::uniqueness(self::detail($mapping, $unique));
    }

    /**
     * @param array<ConstraintViolationInterface> $violations
     */
    private static function detail(ResourceMapping $mapping, array $violations): string
    {
        return implode(' ', array_map(static fn (ConstraintViolationInterface $violation): string => self::line($mapping, $violation), $violations));
    }

    private static function line(ResourceMapping $mapping, ConstraintViolationInterface $violation): string
    {
        $member = (string) preg_replace('/[.\[].*$/s', '', $violation->getPropertyPath());
        $attribute = array_find($mapping->attributes, static fn (AttributeMapping $attribute): bool => !$attribute->method && $attribute->member === $member);

        return (null === $attribute ? '' : \sprintf('Attribute "%s": ', $attribute->declaredPath)).$violation->getMessage();
    }
}

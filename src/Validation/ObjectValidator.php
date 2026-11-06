<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Validation;

use Symfony\Component\Validator\Validator\ValidatorInterface;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;

/**
 * Validates an object in the validationGroups of its mapping before the store writes it.
 */
final readonly class ObjectValidator
{
    /**
     * @param ValidatorInterface|null $validator null without symfony/validator or with framework.validation disabled
     */
    public function __construct(
        private ?ValidatorInterface $validator = null,
    ) {
    }

    /**
     * @throws ScimException 400 invalidValue, or 409 uniqueness for a UniqueEntity violation
     */
    public function validate(ResourceMapping $mapping, object $object): void
    {
        if (null === $mapping->validationGroups) {
            return;
        }

        $validator = $this->validator ?? throw new \LogicException(\sprintf('%s: validationGroups: needs the validator service. Install symfony/validator and enable framework.validation.', $mapping->class));
        $violations = $validator->validate($object, groups: $mapping->validationGroups);

        if (0 !== $violations->count()) {
            throw ViolationMapper::exception($mapping, $violations);
        }
    }
}

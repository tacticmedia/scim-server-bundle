<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

/**
 * Each error names the class, the member and the SCIM path, and gives one fix.
 */
final class MappingException extends \LogicException
{
    /**
     * @param non-empty-list<string> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(1 === \count($errors) ? $errors[0] : \sprintf("%d SCIM mapping errors:\n- %s", \count($errors), implode("\n- ", $errors)));
    }

    /**
     * @param class-string $class
     */
    public static function inClass(string $class, string $error): self
    {
        return new self([$class.': '.$error]);
    }

    /**
     * @param list<self> $exceptions
     */
    public static function merge(array $exceptions): ?self
    {
        $errors = array_merge(...array_map(static fn (self $exception): array => $exception->errors, $exceptions));

        return [] === $errors ? null : new self($errors);
    }
}

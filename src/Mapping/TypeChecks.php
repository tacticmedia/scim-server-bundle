<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\AbstractUid;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\SchemaUrn;
use TacticMedia\ScimServerBundle\Converter\HashPassword;
use TacticMedia\ScimServerBundle\Converter\ValueConverter;

/**
 * The member type must fit the mapping kind and the SCIM type, unless a converter converts it.
 *
 * @internal
 */
final class TypeChecks
{
    /**
     * @param \ReflectionClass<object>          $class      the class with #[ScimResource]
     * @param AttributeDefinition               $definition the compiled definition of the mapping
     * @param class-string<ValueConverter>|null $converter  the converter of the mapping
     */
    public static function check(Member $member, MappingKind $kind, ResolvedPath $path, ?string $targetType, \ReflectionClass $class, AttributeDefinition $definition, ?string $converter): void
    {
        if (null === $converter) {
            self::checkNative($member, $kind, $path, $targetType);
        } elseif (!self::implements($converter, ValueConverter::class)) {
            throw $member->fail(\sprintf('has converter: %s, which does not implement %s.', $converter, ValueConverter::class));
        } elseif (self::implements($converter, HashPassword::class)) {
            self::checkHashPassword($member, $class, $definition->mutability);
        }
    }

    /**
     * Runtime check of a class-string that only PHPDoc types.
     */
    public static function implements(string $class, string $interface): bool
    {
        return is_a($class, $interface, true);
    }

    /**
     * {@see HashPassword::toScim()} throws, so only a mapping that is never read can use it.
     *
     * @param \ReflectionClass<object> $class
     */
    private static function checkHashPassword(Member $member, \ReflectionClass $class, Mutability $mutability): void
    {
        $problem = match (true) {
            Mutability::WriteOnly !== $mutability => \sprintf('uses HashPassword, so it must be writeOnly, like password, but it is %s. Remove converter:, or set mutability: Mutability::WriteOnly on an application attribute.', $mutability->value),
            !self::implements($class->getName(), PasswordAuthenticatedUserInterface::class) => \sprintf('uses HashPassword, so %s must implement %s.', $class->getName(), PasswordAuthenticatedUserInterface::class),
            default => null,
        };

        if (null !== $problem) {
            throw $member->fail($problem);
        }
    }

    private static function checkNative(Member $member, MappingKind $kind, ResolvedPath $path, ?string $targetType): void
    {
        if (SchemaUrn::User->value === $path->attribute->schema->id && 'password' === $path->attribute->attribute->name) {
            throw $member->fail('must be hashed: set converter: HashPassword::class.');
        }

        $problem = match ($kind) {
            MappingKind::Field, MappingKind::Slot => self::valueProblem($member, $path),
            MappingKind::Reference => self::referenceProblem($member, (string) $targetType),
            MappingKind::ReferenceCollection, MappingKind::ElementCollection => self::collectionProblem($member),
            MappingKind::Computed => self::computedProblem($member, $path),
        };

        if (null !== $problem) {
            throw $member->fail($problem);
        }
    }

    private static function valueProblem(Member $member, ResolvedPath $path): ?string
    {
        return $path->isId() ? self::idProblem($member) : self::scalarProblem($member, $path);
    }

    /**
     * An untyped or union return type, and a whole complex or multi-valued attribute, are not checked.
     */
    private static function computedProblem(Member $member, ResolvedPath $path): ?string
    {
        $whole = $path->attribute->attribute->multiValued || AttributeType::Complex === $path->attribute->definition()->type;

        return null === $member->phpType() || $whole ? null : self::valueProblem($member, $path);
    }

    private static function idProblem(Member $member): ?string
    {
        $phpType = (string) $member->phpType();

        return \in_array($phpType, ['string', 'int'], true) || is_a($phpType, AbstractUid::class, true)
            ? null
            : \sprintf('needs a string, an int or a %s member, the member is %s.', AbstractUid::class, $member->typeName());
    }

    private static function scalarProblem(Member $member, ResolvedPath $path): ?string
    {
        $type = $path->attribute->definition()->type;

        return PhpTypes::fits($type, $member->phpType())
            ? null
            : \sprintf('is %s, the member is %s. Use %s or set converter:.', $type->value, $member->typeName(), PhpTypes::expected($type));
    }

    private static function referenceProblem(Member $member, string $targetType): ?string
    {
        $phpType = (string) $member->phpType();
        $resource = class_exists($phpType) ? new \ReflectionClass($phpType)->getAttributes(ScimResource::class)[0] ?? null : null;

        return $resource?->newInstance()->name === $targetType
            ? null
            : \sprintf("refers to %s, but the member is %s, which has no #[ScimResource('%1\$s')]. Type it with the class that maps %1\$s.", $targetType, $member->typeName());
    }

    private static function collectionProblem(Member $member): ?string
    {
        $phpType = (string) $member->phpType();

        return \in_array($phpType, ['array', 'iterable'], true) || is_a($phpType, \Traversable::class, true)
            ? null
            : \sprintf('is multi-valued, the member is %s. Use a Doctrine Collection, array or iterable.', $member->typeName());
    }
}

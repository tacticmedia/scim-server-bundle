<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Doctrine\Orm;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingException;
use TacticMedia\ScimServerBundle\Mapping\MappingKind;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Mapping\SoftDelete;

/**
 * The SCIM mapping of a class must fit its Doctrine metadata. Computed mappings are exempt; {@see OrmQueryChecks}
 * checks element collections and DQL expressions.
 *
 * @internal
 */
final class OrmMappingChecks
{
    private const array MUTABLE_DATE_TYPES = [Types::DATE_MUTABLE, Types::DATETIME_MUTABLE, Types::DATETIMETZ_MUTABLE, Types::TIME_MUTABLE, 'datetime_utc'];

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @throws MappingException with every error of the mapping
     */
    public static function check(ResourceMapping $mapping, ClassMetadata $metadata, MappingRegistry $mappings): true
    {
        $errors = array_values(array_filter([
            self::idError($mapping, $metadata),
            ...array_map(static fn (AttributeMapping $attribute): ?string => self::attributeError($mapping, $attribute, $metadata, $mappings), $mapping->attributes),
            ...array_map(static fn (AttributeMapping $attribute): ?string => self::versionError($mapping, $attribute, $metadata), $mapping->attributesAt(new AttributePath($mapping->resourceType->schema->id, 'meta', 'version'))),
            self::softDeleteError($mapping, $metadata),
        ]));

        return [] === $errors ? true : throw new MappingException($errors);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private static function idError(ResourceMapping $mapping, ClassMetadata $metadata): ?string
    {
        $identifier = $metadata->getIdentifierFieldNames();

        return [$mapping->id->member] === $identifier ? null : \sprintf('%s: "id" maps %s, but the Doctrine identifier is %s. Map "id" to the single Doctrine identifier field.', $mapping->class, $mapping->id->label(), [] === $identifier ? 'not defined' : '$'.implode(', $', $identifier));
    }

    /**
     * OrmObjectStore makes If-Match atomic with the version column, and a datetime version changes once per second.
     *
     * @param ClassMetadata<object> $metadata
     */
    private static function versionError(ResourceMapping $mapping, AttributeMapping $attribute, ClassMetadata $metadata): ?string
    {
        $fits = $attribute->member === $metadata->versionField && OrmVersioning::hasIntegerVersion($metadata);

        return $fits ? null : \sprintf('%s::%s: "%s" needs an integer #[ORM\Version] field, but %2$s is not one. Map "meta.version" to an int property with #[ORM\Version] and #[ORM\Column(type: Types::INTEGER)].', $mapping->class, $attribute->label(), $attribute->declaredPath);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private static function softDeleteError(ResourceMapping $mapping, ClassMetadata $metadata): ?string
    {
        $softDelete = $mapping->softDelete;
        $problem = $softDelete instanceof SoftDelete ? self::fieldProblem($softDelete->property, $metadata) ?? self::dateProblem($softDelete, $metadata) : null;

        return null === $problem ? null : \sprintf('%s::$%s: #[ScimSoftDelete] %s', $mapping->class, $softDelete->property, $problem);
    }

    /**
     * A mutable Doctrine date type converts only a DateTime.
     *
     * @param ClassMetadata<object> $metadata
     */
    private static function dateProblem(SoftDelete $softDelete, ClassMetadata $metadata): ?string
    {
        $type = (string) $metadata->getTypeOfField($softDelete->property);
        $mutable = \in_array($type, self::MUTABLE_DATE_TYPES, true) && is_a((string) $softDelete->dateClass, \DateTimeImmutable::class, true);

        return $mutable ? \sprintf('writes a DateTimeImmutable, but $%s is a %s column, which takes a DateTime. Type $%1$s as ?\DateTime, or map it to the %2$s_immutable type.', $softDelete->property, $type) : null;
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private static function attributeError(ResourceMapping $mapping, AttributeMapping $attribute, ClassMetadata $metadata, MappingRegistry $mappings): ?string
    {
        $problem = match ($attribute->kind) {
            MappingKind::Computed, MappingKind::ElementCollection => null,
            MappingKind::Field, MappingKind::Slot => self::fieldProblem($attribute->member, $metadata),
            MappingKind::Reference => self::associationProblem($attribute, $metadata, $mappings, true),
            MappingKind::ReferenceCollection => self::associationProblem($attribute, $metadata, $mappings, false),
        };

        return null === $problem ? null : \sprintf('%s::%s: "%s" %s', $mapping->class, $attribute->label(), $attribute->declaredPath, $problem);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    public static function fieldProblem(string $member, ClassMetadata $metadata): ?string
    {
        return match (true) {
            $metadata->hasField($member) => null,
            $metadata->hasAssociation($member) => \sprintf('needs a Doctrine field, but $%s is an association. Map a column, or map the association to a reference attribute.', $member),
            default => \sprintf('needs a Doctrine field, but $%s is not mapped by Doctrine, so writes to it are lost. Add #[ORM\Column] to $%1$s.', $member),
        };
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private static function associationProblem(AttributeMapping $attribute, ClassMetadata $metadata, MappingRegistry $mappings, bool $single): ?string
    {
        $member = $attribute->member;
        $target = $mappings->get((string) $attribute->targetType)->class;
        $fits = $metadata->hasAssociation($member)
            && $single === $metadata->isSingleValuedAssociation($member)
            && is_a($metadata->getAssociationTargetClass($member), $target, true);

        return match (true) {
            !$fits => \sprintf(
                $single
                    ? 'refers to %s, but $%s is not a to-one Doctrine association to %s. Map $%2$s with #[ORM\ManyToOne] or #[ORM\OneToOne] to %3$s.'
                    : 'refers to %s, but $%s is not a to-many Doctrine association to %s. Map $%2$s with #[ORM\ManyToMany] or #[ORM\OneToMany] to %3$s.',
                $attribute->targetType,
                $member,
                $target,
            ),
            $single && Mutability::ReadOnly !== $attribute->definition->mutability && !$metadata->getAssociationMapping($member)->isOwningSide() => \sprintf('refers to %s, but $%s is the inverse side of a Doctrine association, so writes to it are lost. Map "%s" to a #[ORM\ManyToOne], or to the owning side of a #[ORM\OneToOne].', $attribute->targetType, $member, $attribute->declaredPath),
            default => null,
        };
    }
}

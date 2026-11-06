<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Doctrine\Orm;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Query\Planning\MemberConditions;

/**
 * Doctrine types whose columns hold less than the PHP type of their member. PostgreSQL rejects a value that the column
 * cannot hold, so such a value matches no row without a query.
 *
 * @internal
 */
final class OrmTypes
{
    private const array INTEGER_RANGES = [Types::SMALLINT => [-32768, 32767], Types::INTEGER => [-2147483648, 2147483647]];
    private const string GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    public static function holds(?string $type, mixed $value): bool
    {
        [$minimum, $maximum] = self::INTEGER_RANGES[(string) $type] ?? [\PHP_INT_MIN, \PHP_INT_MAX];

        return match (true) {
            Types::GUID === $type && \is_string($value) => 1 === preg_match(self::GUID, $value),
            \is_int($value) => $value >= $minimum && $value <= $maximum,
            default => true,
        };
    }

    /**
     * A string member in a character column, where "" is a value and LOWER() applies. A guid column is a uuid column
     * on PostgreSQL.
     *
     * @param ClassMetadata<object> $metadata
     */
    public static function holdsText(AttributeMapping $member, ClassMetadata $metadata): bool
    {
        return MemberConditions::holdsString($member) && Types::GUID !== $metadata->getTypeOfField($member->member);
    }
}

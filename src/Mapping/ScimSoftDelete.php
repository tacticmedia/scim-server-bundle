<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

/**
 * Marks the property that soft-deletes the object: a bool (true: deleted) or a nullable DateTimeInterface (set:
 * deleted). DELETE sets it, and reads skip the object.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class ScimSoftDelete
{
    /**
     * @param bool $revive false: a create stores a new object; true: a create whose unique attributes match a
     *                     soft-deleted object restores that object, removes every link to it and from it, and writes
     *                     the document onto it
     */
    public function __construct(
        public bool $revive = false,
    ) {
    }
}

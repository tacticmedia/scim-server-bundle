<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\Returned;
use TacticMedia\ScimServer\Schema\Uniqueness;
use TacticMedia\ScimServerBundle\Converter\ValueConverter;

/**
 * Maps a property or a public method to a SCIM path, for example "name.givenName", "emails[type eq "work"].value"
 * or "urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:department". A method mapping is read-only.
 *
 * The characteristic arguments ($type to $description) are allowed only for attributes of a schema that the
 * application defines; $uniqueness also for attributes of RFC 7643 schemas, except id, externalId and meta. Null
 * means "not set". The server checks $uniqueness with a filter, so it needs a single-valued attribute that is not
 * complex, mapped by a property that is not writeOnly.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final readonly class ScimAttribute
{
    /**
     * @param class-string<ValueConverter>|null $converter
     * @param bool                              $primary         marks the slot group whose element has "primary": true
     * @param string|int|float|bool|null        $default         SCIM value written when a create or replace omits the attribute
     * @param class-string|null                 $elementClass    on a collection property of a whole multi-valued attribute: the class whose properties map the sub-attributes
     * @param list<string>|null                 $canonicalValues
     */
    public function __construct(
        public string $path,
        public ?string $converter = null,
        public bool $primary = false,
        public string|int|float|bool|null $default = null,
        public ?string $elementClass = null,
        public ?AttributeType $type = null,
        public ?bool $required = null,
        public ?bool $caseExact = null,
        public ?Mutability $mutability = null,
        public ?Returned $returned = null,
        public ?Uniqueness $uniqueness = null,
        public ?array $canonicalValues = null,
        public ?string $description = null,
    ) {
    }
}

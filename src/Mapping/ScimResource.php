<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

use TacticMedia\ScimServerBundle\Object\ObjectFactory;
use TacticMedia\ScimServerBundle\Object\ObjectStore;

/**
 * Maps the class to the SCIM resource type $name. Without $schema, User and Group use their RFC 7643 core schema.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class ScimResource
{
    /**
     * @param string|null                      $endpoint         null: "/" . $name . "s"
     * @param list<ScimExtension>              $extensions       schema extensions that the application defines
     * @param class-string<ObjectFactory>|null $factory
     * @param class-string<ObjectStore>|null   $store            null: the Doctrine ORM store
     * @param string|null                      $idPrefix         only for an int id: "u" gives the SCIM id "u42"; must not end in a digit
     * @param list<string>|null                $validationGroups the Validator groups of each write; null: no validation
     * @param bool                             $deltas           false: a PATCH of members loads the collection and calls the adder and remover
     */
    public function __construct(
        public string $name,
        public ?string $endpoint = null,
        public ?string $schema = null,
        public array $extensions = [],
        public ?string $factory = null,
        public ?string $store = null,
        public ?string $idPrefix = null,
        public string $description = '',
        public ?array $validationGroups = null,
        public bool $deltas = true,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Filter\FilterParser;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\CoreSchemas;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Returned;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServer\Schema\SchemaExtension;
use TacticMedia\ScimServer\Schema\Uniqueness;
use TacticMedia\ScimServer\SchemaUrn;
use TacticMedia\ScimServerBundle\Schema\AttributeDefinitions;

/**
 * Builds the resource type with every schema that one class may map: the RFC 7643 schemas in full, and the schemas
 * that the application defines with one attribute per mapping.
 *
 * @internal
 */
final readonly class AvailableSchemas
{
    /**
     * @param ResourceType $resourceType every schema that the class may map
     * @param list<string> $appUrns      lowercase URNs of the schemas that the application defines
     */
    private function __construct(
        public ResourceType $resourceType,
        public array $appUrns,
    ) {
    }

    /**
     * @param \ReflectionClass<object> $class
     * @param list<Member>             $members
     */
    public static function of(\ReflectionClass $class, ScimResource $resource, string $endpoint, array $members): self
    {
        $coreUrn = $resource->schema ?? self::defaultSchema($resource->name) ?? throw MappingException::inClass($class->getName(), \sprintf("resource type \"%s\" is not defined by RFC 7643. Set #[ScimResource('%1\$s', schema: '<core schema URN>')].", $resource->name));
        $rfcCore = self::rfcSchema($coreUrn);
        $extensions = self::declaredExtensions($class, $resource, $coreUrn);
        $appUrns = [...($rfcCore instanceof Schema ? [] : [strtolower($coreUrn)]), ...array_keys($extensions)];
        $definitions = self::definitions($members, $appUrns, $rfcCore instanceof Schema ? null : strtolower($coreUrn));
        $core = $rfcCore ?? new Schema($coreUrn, $resource->name, $resource->description, $definitions[strtolower($coreUrn)] ?? []);
        $enterprise = $rfcCore?->id === SchemaUrn::User->value ? [new SchemaExtension(CoreSchemas::enterpriseUser())] : [];

        return new self(
            new ResourceType($resource->name, $endpoint, $core, [...$enterprise, ...self::appExtensions($class, $extensions, $definitions)], $resource->description),
            $appUrns,
        );
    }

    /**
     * The RFC 7643 core schema URN of the resource type $name, else null.
     *
     * @internal
     */
    public static function defaultSchema(string $name): ?string
    {
        return match ($name) {
            'User' => SchemaUrn::User->value,
            'Group' => SchemaUrn::Group->value,
            default => null,
        };
    }

    private static function rfcSchema(string $urn): ?Schema
    {
        return match (strtolower($urn)) {
            strtolower(SchemaUrn::User->value) => CoreSchemas::user(),
            strtolower(SchemaUrn::Group->value) => CoreSchemas::group(),
            default => null,
        };
    }

    /**
     * @param \ReflectionClass<object> $class
     *
     * @return array<string, ScimExtension> keyed by lowercase URN
     */
    private static function declaredExtensions(\ReflectionClass $class, ScimResource $resource, string $coreUrn): array
    {
        $taken = [strtolower($coreUrn), ...array_map(static fn (SchemaUrn $urn): string => strtolower($urn->value), SchemaUrn::cases())];
        $extensions = [];

        foreach ($resource->extensions as $extension) {
            $urn = strtolower($extension->urn);

            if (\in_array($urn, $taken, true) || isset($extensions[$urn])) {
                throw MappingException::inClass($class->getName(), \sprintf('extension %s is the core schema, an RFC 7643 schema, or declared twice. Remove it from extensions:.', $extension->urn));
            }

            $extensions[$urn] = $extension;
        }

        return $extensions;
    }

    /**
     * @param \ReflectionClass<object>                 $class
     * @param array<string, ScimExtension>             $extensions
     * @param array<string, list<AttributeDefinition>> $definitions
     *
     * @return list<SchemaExtension>
     */
    private static function appExtensions(\ReflectionClass $class, array $extensions, array $definitions): array
    {
        return array_map(
            static fn (string $urn, ScimExtension $extension): SchemaExtension => new SchemaExtension(
                new Schema($extension->urn, $extension->name, $extension->description, $definitions[$urn] ?? throw MappingException::inClass($class->getName(), \sprintf('extension %s has no mapped attribute. Map one or remove it from extensions:.', $extension->urn))),
                $extension->required,
            ),
            array_keys($extensions),
            $extensions,
        );
    }

    /**
     * @param list<Member> $members
     * @param list<string> $appUrns
     *
     * @return array<string, list<AttributeDefinition>> keyed by lowercase URN
     */
    private static function definitions(array $members, array $appUrns, ?string $appCoreUrn): array
    {
        $definitions = [];

        foreach ($members as $member) {
            $path = self::plainPath($member);
            $urn = $path instanceof AttributePath ? self::appUrn($path, $appCoreUrn) : null;

            if ($path instanceof AttributePath && \in_array($urn, $appUrns, true)) {
                $definitions[$urn] = self::add($definitions[$urn] ?? [], $path, $member);
            }
        }

        return array_map(array_values(...), $definitions);
    }

    private static function plainPath(Member $member): ?AttributePath
    {
        try {
            $patchPath = new FilterParser()->parsePatchPath($member->attribute->path);
        } catch (ScimException) {
            return null;
        }

        return $patchPath->valueFilter instanceof Filter ? null : $patchPath->path;
    }

    private static function appUrn(AttributePath $path, ?string $appCoreUrn): ?string
    {
        $urn = null === $path->schemaUrn ? $appCoreUrn : strtolower($path->schemaUrn);
        $common = $urn === $appCoreUrn && \in_array(strtolower($path->attribute), PathResolver::COMMON, true);

        return $common ? null : $urn;
    }

    /**
     * @param array<string, AttributeDefinition> $definitions keyed by lowercase name
     *
     * @return array<string, AttributeDefinition>
     */
    private static function add(array $definitions, AttributePath $path, Member $member): array
    {
        $key = strtolower($path->attribute);

        if (null === $path->subAttribute) {
            $definitions[$key] ??= self::definition($member, $path->attribute);

            return $definitions;
        }

        $parent = $definitions[$key] ?? new AttributeDefinition($path->attribute, AttributeType::Complex);
        $definitions[$key] = AttributeType::Complex === $parent->type
            ? AttributeDefinitions::with($parent, subAttributes: [...$parent->subAttributes, self::definition($member, $path->subAttribute)])
            : $parent;

        return $definitions;
    }

    private static function definition(Member $member, string $name): AttributeDefinition
    {
        $attribute = $member->attribute;

        return new AttributeDefinition(
            $name,
            $attribute->type ?? (null === $attribute->converter ? PhpTypes::scimType($member->phpType()) : AttributeType::String),
            description: $attribute->description ?? '',
            required: $attribute->required ?? false,
            caseExact: $attribute->caseExact ?? false,
            mutability: $attribute->mutability ?? ($member->isMethod() ? Mutability::ReadOnly : Mutability::ReadWrite),
            returned: $attribute->returned ?? Returned::Default,
            uniqueness: $attribute->uniqueness ?? Uniqueness::None,
            canonicalValues: $attribute->canonicalValues ?? [],
        );
    }
}

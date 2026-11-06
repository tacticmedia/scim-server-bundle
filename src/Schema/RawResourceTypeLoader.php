<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Schema;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Filter\FilterParser;
use TacticMedia\ScimServer\Repository\ResourceRepository;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\CoreSchemas;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServer\Schema\SchemaExtension;
use TacticMedia\ScimServer\SchemaUrn;
use TacticMedia\ScimServerBundle\Attribute\AsResourceRepository;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\AvailableSchemas;
use TacticMedia\ScimServerBundle\Mapping\MappingException;
use TacticMedia\ScimServerBundle\Mapping\MappingKind;
use TacticMedia\ScimServerBundle\Mapping\PathResolver;

/**
 * The published resource type of a raw repository: the attributes that its #[AsResourceRepository] declares.
 */
final readonly class RawResourceTypeLoader
{
    public function __construct(
        private SchemaBuilder $schemaBuilder = new SchemaBuilder(),
        private FilterParser $parser = new FilterParser(),
    ) {
    }

    /**
     * @param class-string $class
     *
     * @throws MappingException
     */
    public function load(string $class): ResourceType
    {
        $reflection = new \ReflectionClass($class);
        $repository = ($reflection->getAttributes(AsResourceRepository::class)[0] ?? null)?->newInstance() ?? throw MappingException::inClass($class, 'the class has no #[AsResourceRepository] attribute.');

        if (!$reflection->implementsInterface(ResourceRepository::class)) {
            throw MappingException::inClass($class, \sprintf('#[AsResourceRepository] is on a class that does not implement %s.', ResourceRepository::class));
        }

        $available = $this->available($class, $repository);

        return $this->schemaBuilder->publish($available, array_map(fn (string $path): AttributeMapping => $this->attribute($class, $available, $path), $repository->attributes));
    }

    /**
     * @param class-string $class
     */
    private function available(string $class, AsResourceRepository $repository): ResourceType
    {
        $urn = $repository->schema ?? AvailableSchemas::defaultSchema($repository->name) ?? throw MappingException::inClass($class, \sprintf("resource type \"%s\" is not defined by RFC 7643. Set #[AsResourceRepository('%1\$s', schema: '<core schema URN>', schemas: [...])].", $repository->name));
        $core = array_find([CoreSchemas::user(), CoreSchemas::group(), ...$repository->schemas], static fn (Schema $schema): bool => 0 === strcasecmp($schema->id, $urn))
            ?? throw MappingException::inClass($class, \sprintf('schema: %s is not an RFC 7643 core schema. Add its Schema to schemas:.', $urn));
        $extensions = [
            ...(SchemaUrn::User->value === $core->id ? [CoreSchemas::enterpriseUser()] : []),
            ...array_filter($repository->schemas, static fn (Schema $schema): bool => $schema !== $core),
        ];

        return new ResourceType(
            $repository->name,
            AttributeLoader::endpoint($class, $repository->name, $repository->endpoint),
            $core,
            array_map(static fn (Schema $schema): SchemaExtension => new SchemaExtension($schema), $extensions),
            $repository->description,
        );
    }

    /**
     * A mapping that only SchemaBuilder reads: path, definition and value filter literal.
     *
     * @param class-string $class
     */
    private function attribute(string $class, ResourceType $available, string $path): AttributeMapping
    {
        try {
            $patchPath = $this->parser->parsePatchPath($path);
        } catch (ScimException $scimException) {
            throw MappingException::inClass($class, \sprintf('"%s" is not a valid path: %s', $path, $scimException->detail));
        }

        $type = $patchPath->valueFilter instanceof Filter ? PathResolver::typeLiteral($patchPath->valueFilter) ?? throw MappingException::inClass($class, \sprintf('"%s" has a value filter that is not allowed. Use [type eq "<value>"].', $path)) : null;
        $resolved = $available->resolve($patchPath->path) ?? throw MappingException::inClass($class, \sprintf('"%s" is not an attribute of %s.', $path, implode(' or ', array_map(static fn (Schema $schema): string => $schema->id, $available->schemas()))));

        return new AttributeMapping(
            path: $resolved->path(),
            declaredPath: $path,
            definition: $resolved->definition(),
            kind: $this->kind($type, $resolved->subAttribute),
            member: '',
            method: false,
            phpType: null,
            nullable: true,
            writable: false,
            required: false,
            type: $type,
        );
    }

    /**
     * SchemaBuilder publishes a whole element with a value filter, members[type eq "User"], like a ReferenceCollection:
     * every sub-attribute, and the literals as the canonical values of type.
     */
    private function kind(?string $type, ?AttributeDefinition $subAttribute): MappingKind
    {
        return match (true) {
            null === $type => MappingKind::Field,
            $subAttribute instanceof AttributeDefinition => MappingKind::Slot,
            default => MappingKind::ReferenceCollection,
        };
    }
}

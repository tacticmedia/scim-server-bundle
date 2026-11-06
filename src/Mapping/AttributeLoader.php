<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServerBundle\Object\ObjectFactory;
use TacticMedia\ScimServerBundle\Object\ObjectStore;
use TacticMedia\ScimServerBundle\Schema\SchemaBuilder;

/**
 * Reads #[ScimResource] and #[ScimAttribute] of one class by reflection and compiles them into a ResourceMapping.
 */
final readonly class AttributeLoader
{
    private const string ENDPOINT = '/^\/[A-Za-z][A-Za-z0-9._-]*$/D';
    private const array RESERVED_ENDPOINTS = ['/schemas', '/resourcetypes', '/serviceproviderconfig', '/bulk', '/me'];

    public function __construct(
        private SchemaBuilder $schemaBuilder = new SchemaBuilder(),
    ) {
    }

    /**
     * @param class-string $class
     *
     * @throws MappingException with every error of the class members, else with the first class error
     */
    public function load(string $class): ResourceMapping
    {
        $reflection = new \ReflectionClass($class);
        $resource = ($reflection->getAttributes(ScimResource::class)[0] ?? null)?->newInstance() ?? throw MappingException::inClass($class, 'the class has no #[ScimResource] attribute.');
        $members = Member::all($reflection);
        $schemas = AvailableSchemas::of($reflection, $resource, self::endpoint($class, $resource->name, $resource->endpoint), $members);
        [$id, $attributes] = $this->attributes($reflection, $schemas, $members);
        $attributes = ClassChecks::check($reflection, $schemas->resourceType, $attributes);
        self::checkOptions($reflection, $resource, $id);
        $softDelete = SoftDelete::compile($reflection);

        return new ResourceMapping(
            $class,
            $this->schemaBuilder->publish($schemas->resourceType, $attributes),
            $id,
            $attributes,
            ConstructorResolver::resolve($reflection, $resource, $attributes),
            $resource->factory,
            $resource->store,
            $resource->idPrefix,
            $resource->validationGroups,
            $resource->deltas,
            $softDelete,
        );
    }

    /**
     * The endpoint of a #[ScimResource] or an #[AsResourceRepository].
     *
     * @internal
     *
     * @param class-string $class
     */
    public static function endpoint(string $class, string $name, ?string $endpoint): string
    {
        $endpoint ??= '/'.$name.'s';

        return match (true) {
            1 !== preg_match(self::ENDPOINT, $endpoint) => throw MappingException::inClass($class, \sprintf('endpoint "%s" is not valid. Use "/" and one name, for example "/Devices".', $endpoint)),
            \in_array(strtolower($endpoint), self::RESERVED_ENDPOINTS, true) => throw MappingException::inClass($class, \sprintf('endpoint "%s" is reserved by RFC 7644. Set another endpoint:.', $endpoint)),
            default => $endpoint,
        };
    }

    /**
     * @param \ReflectionClass<object> $class
     * @param list<Member>             $members
     *
     * @return array{AttributeMapping, list<AttributeMapping>} the id mapping and all mappings
     */
    private function attributes(\ReflectionClass $class, AvailableSchemas $schemas, array $members): array
    {
        $compiler = new AttributeCompiler($class, $schemas);
        $attributes = [];
        $errors = [];

        foreach ($members as $member) {
            try {
                $attributes[] = $compiler->compile($member);
            } catch (MappingException $mappingException) {
                $errors[] = $mappingException;
            }
        }

        $merged = MappingException::merge($errors);

        if ($merged instanceof MappingException) {
            throw $merged;
        }

        return self::withId($class, $compiler, new AttributePath($schemas->resourceType->schema->id, 'id'), $attributes);
    }

    /**
     * @param \ReflectionClass<object> $class
     * @param list<AttributeMapping>   $attributes
     *
     * @return array{AttributeMapping, list<AttributeMapping>}
     */
    private static function withId(\ReflectionClass $class, AttributeCompiler $compiler, AttributePath $idPath, array $attributes): array
    {
        $id = array_find($attributes, static fn (AttributeMapping $mapping): bool => $mapping->path->equals($idPath));

        if ($id instanceof AttributeMapping) {
            return [$id, $attributes];
        }

        $id = $compiler->compile(new Member(self::idProperty($class), new ScimAttribute('id')));

        return [$id, [...$attributes, $id]];
    }

    /**
     * hasProperty() of a class does not see a private property of its parent.
     *
     * @param \ReflectionClass<object> $class
     */
    private static function idProperty(\ReflectionClass $class): \ReflectionProperty
    {
        for ($current = $class; $current instanceof \ReflectionClass; $current = $current->getParentClass() ?: null) {
            if ($current->hasProperty('id')) {
                return $current->getProperty('id');
            }
        }

        throw MappingException::inClass($class->getName(), 'map "id" or add a property $id.');
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private static function checkOptions(\ReflectionClass $class, ScimResource $resource, AttributeMapping $id): void
    {
        $problem = match (true) {
            null !== $resource->idPrefix && 'int' !== $id->phpType => \sprintf('idPrefix: needs an int id, %s is %s. Remove idPrefix: or use an int id.', $id->label(), $id->phpType ?? 'untyped'),
            1 === preg_match('/\d$/D', (string) $resource->idPrefix) => \sprintf('idPrefix: "%s" ends in a digit, so an id can equal the id of another prefix: "u1" + 5 = "u" + 15. End idPrefix: with a character that is not a digit.', $resource->idPrefix),
            null !== $resource->factory && !TypeChecks::implements($resource->factory, ObjectFactory::class) => \sprintf('factory: %s does not implement %s.', $resource->factory, ObjectFactory::class),
            null !== $resource->store && !TypeChecks::implements($resource->store, ObjectStore::class) => \sprintf('store: %s does not implement %s.', $resource->store, ObjectStore::class),
            [] === $resource->validationGroups => "validationGroups: [] names no group. Set validationGroups: ['Default'], or remove validationGroups: to turn the validation off.",
            default => null,
        };

        if (null !== $problem) {
            throw MappingException::inClass($class->getName(), $problem);
        }
    }
}

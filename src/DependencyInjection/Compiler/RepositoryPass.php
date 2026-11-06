<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServer\SchemaUrn;
use TacticMedia\ScimServer\Server\RegisteredResourceType;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\MappingException;
use TacticMedia\ScimServerBundle\Schema\RawResourceTypeLoader;

/**
 * Registers the repository service of each resource type, tagged {@see self::REPOSITORY_TAG}: an
 * ObjectResourceRepository per mapped class and an alias per raw repository. Then gives the server every type.
 * Runs after {@see MappingPass}.
 */
final readonly class RepositoryPass implements CompilerPassInterface
{
    public const string REPOSITORY_TAG = 'tacticmedia_scim_server.repository';
    public const string REPOSITORY_CLASSES_PARAMETER = 'tacticmedia_scim_server.repository_classes';
    public const string ID_PREFIX = 'tacticmedia_scim_server.repository.';

    public function __construct(
        private AttributeLoader $mappings = new AttributeLoader(),
        private RawResourceTypeLoader $repositories = new RawResourceTypeLoader(),
        private MeConfigurationCheck $me = new MeConfigurationCheck(),
    ) {
    }

    /**
     * @throws MappingException for a raw repository with an invalid declaration, a name or endpoint used twice, a
     *                          schema published differently by two types, or me options that select no resource
     */
    public function process(ContainerBuilder $container): void
    {
        $raw = $this->raw($container);
        $mapped = $this->mapped($container);
        $types = [...$mapped, ...$raw];
        $errors = [...$this->duplicates($types, 'name'), ...$this->duplicates($types, 'endpoint'), ...$this->schemaConflicts($types), ...$this->me->errors($container, array_column($raw, 1))];

        if ([] !== $errors) {
            throw new MappingException($errors);
        }

        foreach ($mapped as [, $type]) {
            $container->setDefinition(self::ID_PREFIX.strtolower($type->name), new ChildDefinition('tacticmedia_scim_server.object_repository'))
                ->replaceArgument(0, $type->name)
                ->addTag(self::REPOSITORY_TAG, ['name' => $type->name]);
        }

        $repositoryClasses = [];

        foreach ($raw as $id => [$class, $type]) {
            $container->setAlias(self::ID_PREFIX.strtolower($type->name), $id);
            $repositoryClasses[$type->name] = $class;
        }

        $container->setParameter(self::REPOSITORY_CLASSES_PARAMETER, $repositoryClasses);
        $published = array_column($types, 1);
        $container->getDefinition('tacticmedia_scim_server.server')->replaceArgument(0, array_map($this->registered(...), $published));
        $container->getDefinition('tacticmedia_scim_server.service_provider_config')->setArgument('$changePasswordSupported', array_any($published, $this->writesPassword(...)));
    }

    /**
     * @return array<string, array{class-string, ResourceType}> by service id
     */
    private function raw(ContainerBuilder $container): array
    {
        $types = [];

        foreach (array_keys($container->findTaggedServiceIds(self::REPOSITORY_TAG)) as $id) {
            $class = $container->getParameterBag()->resolveValue($container->getDefinition($id)->getClass() ?? $id);
            \assert(\is_string($class) && class_exists($class));
            $types[$id] = [$class, $this->repositories->load($class)];
        }

        return $types;
    }

    /**
     * @return list<array{class-string, ResourceType}>
     */
    private function mapped(ContainerBuilder $container): array
    {
        /** @var array<string, class-string> $classes */
        $classes = $container->getParameter(MappingPass::CLASSES_PARAMETER);

        return array_values(array_map(fn (string $class): array => [$class, $this->mappings->load($class)->resourceType], $classes));
    }

    /**
     * @param array<array{class-string, ResourceType}> $types
     * @param 'name'|'endpoint'                        $key
     *
     * @return list<string>
     */
    private function duplicates(array $types, string $key): array
    {
        $owners = [];

        foreach ($types as [$class, $type]) {
            $owners[strtolower($type->{$key})][] = $class;
        }

        return array_values(array_map(
            static fn (array $classes): string => \sprintf('%s use one resource type %s. Give each its own name and endpoint.', implode(' and ', $classes), $key),
            array_filter($owners, static fn (array $classes): bool => \count($classes) > 1),
        ));
    }

    /**
     * /Schemas lists each schema id once, so every type that publishes an id must publish the same schema.
     *
     * @param array<array{class-string, ResourceType}> $types
     *
     * @return list<string>
     */
    private function schemaConflicts(array $types): array
    {
        $published = [];

        foreach ($types as [$class, $type]) {
            foreach ($type->schemas() as $schema) {
                $published[strtolower($schema->id)][$class] = $schema;
            }
        }

        return array_values(array_map(
            static fn (array $schemas): string => \sprintf('%s publish the schema %s differently. Map the same attributes the same way in each, or give each its own schema URN.', implode(' and ', array_keys($schemas)), array_first($schemas)->id),
            array_filter($published, static fn (array $schemas): bool => \count(array_unique(array_map(static fn (Schema $schema): string => serialize($schema->toArray()), $schemas))) > 1),
        ));
    }

    private function registered(ResourceType $type): Definition
    {
        $published = new Definition(ResourceType::class, [$type->name])->setFactory([new Reference('tacticmedia_scim_server.published_resource_types'), 'get']);

        return new Definition(RegisteredResourceType::class, [$published, new Reference(self::ID_PREFIX.strtolower($type->name))]);
    }

    private function writesPassword(ResourceType $type): bool
    {
        return $type->resolve(new AttributePath(SchemaUrn::User->value, 'password')) instanceof \TacticMedia\ScimServer\Schema\ResolvedAttribute;
    }
}

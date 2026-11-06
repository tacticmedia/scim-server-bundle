<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\MappingException;
use TacticMedia\ScimServerBundle\Mapping\MappingSetChecks;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * Loads every class with the resource tag and every class of {@see self::RESOURCES_PARAMETER}, so that a mapping error
 * fails the container build, and stores the classes by resource type name in the parameter {@see self::CLASSES_PARAMETER}.
 */
final readonly class MappingPass implements CompilerPassInterface
{
    public const string RESOURCE_TAG = 'tacticmedia_scim_server.resource';
    public const string CLASSES_PARAMETER = 'tacticmedia_scim_server.resource_classes';
    public const string RESOURCES_PARAMETER = 'tacticmedia_scim_server.resources';

    public function __construct(
        private AttributeLoader $loader = new AttributeLoader(),
    ) {
    }

    /**
     * @throws MappingException with every error of every class
     */
    public function process(ContainerBuilder $container): void
    {
        /** @var list<string> $names */
        $names = $container->getParameter(self::RESOURCES_PARAMETER);
        [$configured, $errors] = $this->configured(array_map(static fn (string $name): string => ltrim($name, '\\'), $names));
        $mappings = [];

        foreach ($this->classes($container, $configured) as $class) {
            $container->addObjectResource($class);

            try {
                $mappings[] = $this->loader->load($class);
            } catch (MappingException $mappingException) {
                $errors = [...$errors, ...$mappingException->errors];
            }
        }

        $errors = [...$errors, ...MappingSetChecks::errors($mappings), ...$this->validatorErrors($container, $mappings)];

        if ([] !== $errors) {
            throw new MappingException($errors);
        }

        $container->setParameter(self::CLASSES_PARAMETER, array_column(array_map(static fn (ResourceMapping $mapping): array => [$mapping->name(), $mapping->class], $mappings), 1, 0));
    }

    /**
     * Without the validator service, the first write of a mapping with validationGroups would fail.
     *
     * @param list<ResourceMapping> $mappings
     *
     * @return list<string>
     */
    private function validatorErrors(ContainerBuilder $container, array $mappings): array
    {
        $validated = $container->hasDefinition('validator') || $container->hasAlias('validator') ? [] : array_filter($mappings, static fn (ResourceMapping $mapping): bool => null !== $mapping->validationGroups);

        return array_values(array_map(static fn (ResourceMapping $mapping): string => $mapping->class.': validationGroups: needs the validator service. Install symfony/validator and enable framework.validation, or remove validationGroups:.', $validated));
    }

    /**
     * @param list<string> $names
     *
     * @return array{list<class-string>, list<string>} the #[ScimResource] classes, and an error for each other name
     */
    private function configured(array $names): array
    {
        /** @var list<class-string> $classes */
        $classes = array_values(array_filter($names, static fn (string $name): bool => class_exists($name) && [] !== new \ReflectionClass($name)->getAttributes(ScimResource::class)));
        $errors = array_map(
            static fn (string $name): string => class_exists($name)
                ? $name.': the class has no #[ScimResource] attribute. Add #[ScimResource], or remove the class from tacticmedia_scim_server.resources.'
                : $name.': the class does not exist. Correct the class name in tacticmedia_scim_server.resources, or remove it.',
            array_values(array_diff($names, $classes)),
        );

        return [$classes, $errors];
    }

    /**
     * @param list<class-string> $configured
     *
     * @return list<class-string>
     */
    private function classes(ContainerBuilder $container, array $configured): array
    {
        $classes = [];

        foreach (array_keys($container->findTaggedResourceIds(self::RESOURCE_TAG, false)) as $id) {
            $class = $container->getParameterBag()->resolveValue($container->getDefinition($id)->getClass() ?? $id);
            \assert(\is_string($class) && class_exists($class));
            $classes[] = $class;
        }

        return array_values(array_unique([...$classes, ...$configured]));
    }
}

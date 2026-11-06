<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Comparison;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Filter\FilterResolver;
use TacticMedia\ScimServer\Filter\Present;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Query\Planning\ElementConditions;
use TacticMedia\ScimServerBundle\Query\Planning\MemberConditions;
use TacticMedia\ScimServerBundle\Query\Planning\RootConditions;
use TacticMedia\ScimServerBundle\Security\TokenMeResolver;

/**
 * The /Me lookup of the {@see TokenMeResolver} options at compile time: the resource type exists, and the attribute is
 * id, or the library and, for a mapped type, the query planner accept an eq filter on it.
 *
 * @internal
 */
final readonly class MeConfigurationCheck
{
    private const string RESOLVER = 'tacticmedia_scim_server.me_resolver';

    public function __construct(
        private AttributeLoader $loader = new AttributeLoader(),
        private FilterResolver $filters = new FilterResolver(),
    ) {
    }

    /**
     * @param list<ResourceType> $rawTypes
     *
     * @return list<string>
     */
    public function errors(ContainerBuilder $container, array $rawTypes): array
    {
        $resolver = $container->hasDefinition(self::RESOLVER) ? $container->getDefinition(self::RESOLVER) : null;

        if (TokenMeResolver::class !== $resolver?->getClass()) {
            return [];
        }

        /** @var array<string, class-string> $classes */
        $classes = $container->getParameter(MappingPass::CLASSES_PARAMETER);
        $mappings = new MappingRegistry($this->loader, $classes);
        $types = [...array_map(static fn (ResourceMapping $mapping): ResourceType => $mapping->resourceType, $mappings->all()), ...$rawTypes];

        $name = $resolver->getArgument(1);
        $attribute = $resolver->getArgument(2);
        \assert(\is_string($name) && \is_string($attribute));

        return $this->check($name, $attribute, $types, $mappings);
    }

    /**
     * @param list<ResourceType> $types
     *
     * @return list<string>
     */
    private function check(string $name, string $attribute, array $types, MappingRegistry $mappings): array
    {
        $type = array_find($types, static fn (ResourceType $type): bool => 0 === strcasecmp($type->name, $name));

        if (!$type instanceof ResourceType) {
            return [\sprintf('tacticmedia_scim_server.me.resource_type: no resource type is named "%s". Resource types: %s. Set one of them, or set me.resolver.', $name, implode(', ', array_map(static fn (ResourceType $type): string => $type->name, $types)))];
        }

        try {
            $this->lookUp($attribute, $type, $mappings);
        } catch (ScimException $scimException) {
            return [\sprintf('tacticmedia_scim_server.me.attribute: "%s" cannot select a %s: %s Set id or an attribute that supports eq, or set me.resolver.', $attribute, $type->name, $scimException->detail)];
        }

        return [];
    }

    /**
     * @throws ScimException invalidFilter
     */
    private function lookUp(string $attribute, ResourceType $type, MappingRegistry $mappings): void
    {
        if (0 === strcasecmp($attribute, 'id')) {
            return;
        }

        $path = AttributePath::parse($attribute) ?? throw ScimException::invalidFilter(\sprintf('"%s" is not an attribute path.', $attribute));
        $comparison = $this->filters->resolve(new Comparison($path, ComparisonOperator::Equal, '<user identifier>'), $type);
        \assert($comparison instanceof Comparison);

        if (\in_array($type->name, $mappings->names(), true)) {
            $this->plan($mappings->get($type->name), $comparison->path, $mappings);
        }
    }

    /**
     * pr fails where eq fails, and converts no literal, so it needs no converter service.
     *
     * @throws ScimException invalidFilter
     */
    private function plan(ResourceMapping $mapping, AttributePath $path, MappingRegistry $mappings): void
    {
        $members = new MemberConditions(new ServiceLocator([]));
        new RootConditions($mapping, $members, new ElementConditions($mapping, $mappings, $members))->plan(new Present($path));
    }
}

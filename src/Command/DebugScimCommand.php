<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServerBundle\Attribute\AsResourceRepository;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmObjectStore;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingException;
use TacticMedia\ScimServerBundle\Mapping\MappingKind;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Object\CollectionDeltas;
use TacticMedia\ScimServerBundle\Object\MemberValues;
use TacticMedia\ScimServerBundle\Object\References;
use TacticMedia\ScimServerBundle\Query\Planning\MemberConditions;
use TacticMedia\ScimServerBundle\Schema\PublishedResourceTypes;

#[AsCommand('debug:scim', 'Shows the SCIM resource types and their mapped paths, and runs the mapping checks.')]
final readonly class DebugScimCommand
{
    /**
     * @param array<string, class-string> $repositoryClasses raw repository class by resource type name
     * @param ServiceLocator<mixed>       $stores            ObjectStore services by class name
     * @param ServiceLocator<mixed>       $factories         ObjectFactory services by class name
     * @param ServiceLocator<mixed>       $converters        ValueConverter services by class name
     */
    public function __construct(
        private MappingRegistry $mappings,
        private PublishedResourceTypes $publishedTypes,
        private array $repositoryClasses,
        private ServiceLocator $stores,
        private ServiceLocator $factories,
        private ServiceLocator $converters,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('A resource type name. Without it: every resource type.')] ?string $name = null): int
    {
        $names = [...$this->mappings->names(), ...array_keys($this->repositoryClasses)];
        $selected = array_values(array_filter($names, static fn (string $candidate): bool => null === $name || 0 === strcasecmp($candidate, $name)));

        if ([] === $selected) {
            $io->error([] === $names
                ? "No resource type is registered. A #[ScimResource] class must be a service with autoconfigure: true. Remove exclude: '../src/Entity/' from services.yaml, or list the classes in tacticmedia_scim_server.resources."
                : \sprintf('No resource type is named "%s". Resource types: %s.', $name, implode(', ', $names)));

            return Command::FAILURE;
        }

        $errors = array_merge(...array_map(fn (string $type): array => $this->show($io, $type), $selected));

        return [] === $errors ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return list<string> the failed checks
     */
    private function show(SymfonyStyle $io, string $name): array
    {
        $io->section($name);
        $repositoryClass = $this->repositoryClasses[$name] ?? null;

        if (null !== $repositoryClass) {
            $this->showRaw($io, $repositoryClass, $this->publishedTypes->get($name));

            return [];
        }

        $mapping = $this->mappings->get($name);
        $errors = [...$this->missingServices($mapping), ...$this->storeErrors($mapping)];
        $io->definitionList(
            ['Endpoint' => $mapping->resourceType->endpoint],
            ['Class' => $mapping->class],
            ['Store' => $mapping->store ?? OrmObjectStore::class],
            ['Schemas' => $this->schemas($mapping->resourceType)],
            ['Validation' => null === $mapping->validationGroups ? 'off' : 'groups: '.implode(', ', $mapping->validationGroups)],
            ['Member deltas' => $this->memberDeltas($mapping, $errors)],
        );
        $io->table(['SCIM path', 'Member', 'Kind', 'Filter', 'Sort'], array_merge(...array_map(fn (AttributeMapping $attribute): array => $this->rows($mapping, $attribute), $mapping->attributes)));

        [] === $errors ? $io->success('The mapping checks pass.') : $io->error($errors);

        return $errors;
    }

    /**
     * @param class-string $class
     */
    private function showRaw(SymfonyStyle $io, string $class, ResourceType $type): void
    {
        $declaration = new \ReflectionClass($class)->getAttributes(AsResourceRepository::class)[0]->newInstance();
        $io->definitionList(
            ['Endpoint' => $type->endpoint],
            ['Class' => $class],
            ['Store' => 'none: a raw ResourceRepository decides filters and sorting'],
            ['Schemas' => $this->schemas($type)],
            ['Attributes' => implode("\n", $declaration->attributes)],
        );
    }

    /**
     * @return list<string>
     */
    private function missingServices(ResourceMapping $mapping): array
    {
        $services = [
            'store' => [$this->stores, [$mapping->store ?? OrmObjectStore::class]],
            'factory' => [$this->factories, array_filter([$mapping->factory])],
            'converter' => [$this->converters, array_unique(array_filter(array_map(static fn (AttributeMapping $attribute): ?string => $attribute->converter, $mapping->attributes)))],
        ];
        $errors = [];

        foreach ($services as $option => [$locator, $classes]) {
            foreach ($classes as $class) {
                if (!$locator->has($class)) {
                    $errors[] = $mapping->class.': '.$this->notAService($option, $class);
                }
            }
        }

        return $errors;
    }

    private function notAService(string $option, string $class): string
    {
        return OrmObjectStore::class === $class
            ? 'Doctrine ORM is not available. Install doctrine/orm and doctrine/doctrine-bundle, or set #[ScimResource(store: ...)].'
            : \sprintf('%s: %s is not a service. Register it as a service with autoconfigure: true.', $option, $class);
    }

    /**
     * @return list<string> the errors of the Doctrine metadata checks
     */
    private function storeErrors(ResourceMapping $mapping): array
    {
        $id = $mapping->store ?? OrmObjectStore::class;
        $store = $this->stores->has($id) ? $this->stores->get($id) : null;

        try {
            if ($store instanceof OrmObjectStore) {
                $store->check($mapping);
            }
        } catch (MappingException $mappingException) {
            return $mappingException->errors;
        }

        return [];
    }

    /**
     * The attributes whose PATCH changes the elements without a load of the collection.
     */
    /**
     * @param list<string> $errors the failed checks, which the deltas need to pass
     */
    private function memberDeltas(ResourceMapping $mapping, array $errors): string
    {
        $deltas = new CollectionDeltas(new References($this->mappings, $this->stores, new MemberValues($this->converters)), $this->converters);

        return match (true) {
            !$mapping->deltas => 'off: deltas: false',
            [] !== $errors => 'unknown: a check fails',
            default => implode("\n", array_map(static fn (array $collections): string => $collections[0]->path->attribute, $deltas->collections($mapping))) ?: 'none',
        };
    }

    private function schemas(ResourceType $type): string
    {
        return implode("\n", array_map(static fn (Schema $schema): string => $schema->id, $type->schemas()));
    }

    /**
     * The row of the mapping, then a row per member of its element class.
     *
     * @return list<list<string>>
     */
    private function rows(ResourceMapping $mapping, AttributeMapping $attribute): array
    {
        $element = $attribute->element;
        $prefix = $element instanceof \TacticMedia\ScimServerBundle\Mapping\ElementMapping ? new \ReflectionClass($element->class)->getShortName().'::' : '';

        return [
            $this->row($mapping, $attribute, ''),
            ...array_map(fn (AttributeMapping $member): array => $this->row($mapping, $member, $prefix), $element->attributes ?? []),
        ];
    }

    /**
     * @return list<string>
     */
    private function row(ResourceMapping $mapping, AttributeMapping $attribute, string $prefix): array
    {
        $readable = Mutability::WriteOnly !== $attribute->definition->mutability;
        $filter = $readable ? $this->operators($attribute, MemberConditions::canOrder($mapping, $attribute)) : 'no';
        $kind = $attribute->kind->name.(null === $attribute->dqlExpression ? '' : ' with DQL');

        return [$attribute->declaredPath, $prefix.$attribute->label(), $kind, $filter, MemberConditions::canSort($mapping, $attribute) ? 'yes' : 'no'];
    }

    /**
     * The operators that the planner and the library accept for the mapping.
     */
    private function operators(AttributeMapping $attribute, bool $canOrder): string
    {
        $type = $attribute->definition->type;

        return match (true) {
            MappingKind::Computed === $attribute->kind && null === $attribute->dqlExpression => 'no',
            MappingKind::ElementCollection === $attribute->kind => 'pr',
            \in_array($attribute->kind, [MappingKind::Reference, MappingKind::ReferenceCollection], true) => null === $attribute->type ? 'value eq, ne, pr' : 'value eq, ne, type, pr',
            !$canOrder, \in_array($type, [AttributeType::Boolean, AttributeType::Binary], true) => 'eq, ne, pr',
            \in_array($type, [AttributeType::String, AttributeType::Reference], true) && MemberConditions::holdsString($attribute) => 'all',
            default => 'eq, ne, gt, ge, lt, le, pr',
        };
    }
}

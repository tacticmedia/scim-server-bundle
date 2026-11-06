<?php

declare(strict_types=1);

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\DependencyInjection\TypedReference;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use TacticMedia\ScimServer\Server\ScimServer;
use TacticMedia\ScimServerBundle\Command\DebugScimCommand;
use TacticMedia\ScimServerBundle\Controller\ScimController;
use TacticMedia\ScimServerBundle\Converter\HashPassword;
use TacticMedia\ScimServerBundle\Converter\IdConverter;
use TacticMedia\ScimServerBundle\Converter\NativeConverter;
use TacticMedia\ScimServerBundle\Converter\ValueConverter;
use TacticMedia\ScimServerBundle\Converter\VersionConverter;
use TacticMedia\ScimServerBundle\DependencyInjection\Compiler\MappingPass;
use TacticMedia\ScimServerBundle\DependencyInjection\Compiler\RepositoryPass;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmObjectStore;
use TacticMedia\ScimServerBundle\EventListener\ExceptionListener;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Object\DocumentMapper;
use TacticMedia\ScimServerBundle\Object\Instantiator;
use TacticMedia\ScimServerBundle\Object\ObjectFactory;
use TacticMedia\ScimServerBundle\Object\ObjectResourceRepository;
use TacticMedia\ScimServerBundle\Object\ObjectStore;
use TacticMedia\ScimServerBundle\Query\QueryPlanner;
use TacticMedia\ScimServerBundle\Schema\PublishedResourceTypes;
use TacticMedia\ScimServerBundle\Schema\SchemaBuilder;
use TacticMedia\ScimServerBundle\Security\AuthenticationFailureHandler;
use TacticMedia\ScimServerBundle\Security\AuthorizationTokenExtractor;
use TacticMedia\ScimServerBundle\Security\StaticTokenHandler;
use TacticMedia\ScimServerBundle\Validation\ObjectValidator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\abstract_arg;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $container->services()
        ->set('tacticmedia_scim_server.schema_builder', SchemaBuilder::class)

        ->set('tacticmedia_scim_server.attribute_loader', AttributeLoader::class)
            ->args([service('tacticmedia_scim_server.schema_builder')])

        ->set('tacticmedia_scim_server.mapping_registry', MappingRegistry::class)
            ->args([service('tacticmedia_scim_server.attribute_loader'), param(MappingPass::CLASSES_PARAMETER)])
    ;

    // Converters, stores and factories are found by class name: the "key" tag attribute, else the service id, which is
    // the class name for a service that the application loads from a directory.
    $builder->registerForAutoconfiguration(ValueConverter::class)->addTag('tacticmedia_scim_server.value_converter');
    $builder->registerForAutoconfiguration(ObjectStore::class)->addTag('tacticmedia_scim_server.object_store');
    $builder->registerForAutoconfiguration(ObjectFactory::class)->addTag('tacticmedia_scim_server.object_factory');

    $container->services()
        ->set('tacticmedia_scim_server.converter.native', NativeConverter::class)
            ->tag('tacticmedia_scim_server.value_converter', ['key' => NativeConverter::class])

        ->set('tacticmedia_scim_server.converter.id', IdConverter::class)
            ->tag('tacticmedia_scim_server.value_converter', ['key' => IdConverter::class])

        ->set('tacticmedia_scim_server.converter.version', VersionConverter::class)
            ->tag('tacticmedia_scim_server.value_converter', ['key' => VersionConverter::class])

        // The hasher comes from symfony/security-bundle. Without it, HashPassword fails on first use, not the build.
        ->set('tacticmedia_scim_server.converter.hash_password', HashPassword::class)
            ->args([new TypedReference(UserPasswordHasherInterface::class, UserPasswordHasherInterface::class, ContainerInterface::RUNTIME_EXCEPTION_ON_INVALID_REFERENCE)])
            ->tag('tacticmedia_scim_server.value_converter', ['key' => HashPassword::class])

        ->set('tacticmedia_scim_server.converter_locator', ServiceLocator::class)
            ->args([tagged_iterator('tacticmedia_scim_server.value_converter', 'key')])
            ->tag('container.service_locator')

        ->set('tacticmedia_scim_server.store_locator', ServiceLocator::class)
            ->args([tagged_iterator('tacticmedia_scim_server.object_store', 'key')])
            ->tag('container.service_locator')

        ->set('tacticmedia_scim_server.factory_locator', ServiceLocator::class)
            ->args([tagged_iterator('tacticmedia_scim_server.object_factory', 'key')])
            ->tag('container.service_locator')
    ;

    $container->services()
        ->set('tacticmedia_scim_server.query_planner', QueryPlanner::class)
            ->args([service('tacticmedia_scim_server.mapping_registry'), service('tacticmedia_scim_server.converter_locator')])

        ->set('tacticmedia_scim_server.document_mapper', DocumentMapper::class)
            ->args([service('tacticmedia_scim_server.mapping_registry'), service('tacticmedia_scim_server.converter_locator'), service('tacticmedia_scim_server.store_locator')])

        ->set('tacticmedia_scim_server.instantiator', Instantiator::class)
            ->args([service('tacticmedia_scim_server.factory_locator'), service('tacticmedia_scim_server.document_mapper')])

        // RepositoryPass derives tacticmedia_scim_server.repository.<name> for each mapped resource type.
        ->set('tacticmedia_scim_server.object_repository', ObjectResourceRepository::class)
            ->abstract()
            ->args([
                abstract_arg('resource type name'),
                service('tacticmedia_scim_server.mapping_registry'),
                service('tacticmedia_scim_server.query_planner'),
                service('tacticmedia_scim_server.document_mapper'),
                service('tacticmedia_scim_server.instantiator'),
                service('tacticmedia_scim_server.store_locator'),
                service('tacticmedia_scim_server.converter_locator'),
                service('clock')->nullOnInvalid(),
                service('tacticmedia_scim_server.object_validator'),
            ])

        // Without symfony/validator there is no validator service; the class then loads without it.
        ->set('tacticmedia_scim_server.object_validator', ObjectValidator::class)
            ->args([service('validator')->nullOnInvalid()])

        ->set('tacticmedia_scim_server.published_resource_types', PublishedResourceTypes::class)
            ->args([service('tacticmedia_scim_server.mapping_registry'), param(RepositoryPass::REPOSITORY_CLASSES_PARAMETER)])

        ->set('tacticmedia_scim_server.server', ScimServer::class)
            ->args([
                abstract_arg('RegisteredResourceType of each repository, set by RepositoryPass'),
                service('tacticmedia_scim_server.service_provider_config'),
                service('event_dispatcher')->nullOnInvalid(),
            ])
    ;

    $container->services()
        ->set('tacticmedia_scim_server.controller', ScimController::class)
            ->args([service('tacticmedia_scim_server.server')])
            ->tag('controller.service_arguments')

        ->set('tacticmedia_scim_server.access_token_handler', StaticTokenHandler::class)
            ->args([param('tacticmedia_scim_server.access_tokens')])

        ->set('tacticmedia_scim_server.access_token_extractor', AuthorizationTokenExtractor::class)

        ->set('tacticmedia_scim_server.authentication_failure_handler', AuthenticationFailureHandler::class)

        // After the firewall listener (priority 1), which turns a missing token into an HttpException(401).
        ->set('tacticmedia_scim_server.exception_listener', ExceptionListener::class)
            ->tag('kernel.event_listener', ['event' => 'kernel.exception', 'priority' => -64])

        ->set('tacticmedia_scim_server.command.debug', DebugScimCommand::class)
            ->args([
                service('tacticmedia_scim_server.mapping_registry'),
                service('tacticmedia_scim_server.published_resource_types'),
                param(RepositoryPass::REPOSITORY_CLASSES_PARAMETER),
                service('tacticmedia_scim_server.store_locator'),
                service('tacticmedia_scim_server.factory_locator'),
                service('tacticmedia_scim_server.converter_locator'),
            ])
            ->tag('console.command')
    ;

    // A container without a kernel has no kernel.bundles.
    $bundles = $builder->getParameterBag()->all()['kernel.bundles'] ?? [];

    if (\is_array($bundles) && \in_array(DoctrineBundle::class, $bundles, true) && interface_exists(EntityManagerInterface::class)) {
        $container->services()
            ->set('tacticmedia_scim_server.object_store.orm', OrmObjectStore::class)
                ->args([service('doctrine'), service('tacticmedia_scim_server.mapping_registry'), service('tacticmedia_scim_server.converter_locator')])
                ->tag('tacticmedia_scim_server.object_store', ['key' => OrmObjectStore::class])
        ;
    }
};

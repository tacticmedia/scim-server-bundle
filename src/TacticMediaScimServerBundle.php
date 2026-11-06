<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use TacticMedia\ScimServer\Server\AuthenticationScheme;
use TacticMedia\ScimServer\Server\ServiceProviderConfig;
use TacticMedia\ScimServerBundle\Attribute\AsResourceRepository;
use TacticMedia\ScimServerBundle\DependencyInjection\Compiler\MappingPass;
use TacticMedia\ScimServerBundle\DependencyInjection\Compiler\RepositoryPass;
use TacticMedia\ScimServerBundle\EventListener\RateLimitListener;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;
use TacticMedia\ScimServerBundle\Security\TokenMeResolver;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * @phpstan-type RateLimitConfig array{enabled: bool, limit: int<1, max>, rate: array{interval: non-empty-string, amount: int<1, max>}, cache_pool: non-empty-string, limiter: non-empty-string|null}
 * @phpstan-type MeConfig array{enabled: bool, resource_type: non-empty-string, attribute: non-empty-string, resolver: non-empty-string|null}
 * @phpstan-type PaginationConfig array{cursor: array{enabled: bool, key: non-empty-string, timeout_seconds: int<1, max>|null}}
 */
final class TacticMediaScimServerBundle extends AbstractBundle
{
    public const string CURSOR_KEY_PARAMETER = 'tacticmedia_scim_server.cursor_key';

    #[\Override]
    protected string $extensionAlias = 'tacticmedia_scim_server';

    #[\Override]
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->import('../config/definition.php');
    }

    /**
     * @param array{resources: list<non-empty-string>, access_tokens: array<string, string>, max_results: int<1, max>, bulk: array{enabled: bool, max_operations: int<1, max>, max_payload_size_bytes: int<1, max>}, rate_limit: RateLimitConfig, me: MeConfig, pagination: PaginationConfig} $config
     */
    #[\Override]
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');
        $container->parameters()->set('tacticmedia_scim_server.access_tokens', $config['access_tokens']);
        $container->parameters()->set(MappingPass::RESOURCES_PARAMETER, $config['resources']);

        // RepositoryPass sets $changePasswordSupported.
        $container->services()
            ->set('tacticmedia_scim_server.service_provider_config', ServiceProviderConfig::class)
                ->args([
                    '$maxResults' => $config['max_results'],
                    '$authenticationSchemes' => [
                        new Definition(AuthenticationScheme::class, [
                            '$type' => 'oauthbearertoken',
                            '$name' => 'OAuth Bearer Token',
                            '$description' => 'Authentication with a bearer token in the Authorization header.',
                            '$specUri' => 'https://www.rfc-editor.org/rfc/rfc6750',
                            '$primary' => true,
                        ]),
                    ],
                    '$bulkSupported' => $config['bulk']['enabled'],
                    '$bulkMaxOperations' => $config['bulk']['max_operations'],
                    '$bulkMaxPayloadSizeBytes' => $config['bulk']['max_payload_size_bytes'],
                    '$cursorTimeoutSeconds' => $config['pagination']['cursor']['timeout_seconds'],
                ])
        ;

        $this->loadRateLimit($config['rate_limit'], $container);
        $this->loadMe($config['me'], $container);
        $this->loadPagination($config['pagination'], $container, $builder);

        $builder->registerAttributeForAutoconfiguration(ScimResource::class, static function (ChildDefinition $definition): void {
            $definition->addResourceTag(MappingPass::RESOURCE_TAG);
        });
        $builder->registerAttributeForAutoconfiguration(AsResourceRepository::class, static function (ChildDefinition $definition, AsResourceRepository $repository): void {
            $definition->addTag(RepositoryPass::REPOSITORY_TAG, ['name' => $repository->name]);
        });
    }

    #[\Override]
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new MappingPass());
        $container->addCompilerPass(new RepositoryPass(), priority: -1);
    }

    /**
     * @param RateLimitConfig $rateLimit
     */
    private function loadRateLimit(array $rateLimit, ContainerConfigurator $container): void
    {
        if (!$rateLimit['enabled']) {
            return;
        }

        $services = $container->services();

        if (null === $rateLimit['limiter']) {
            $services->set('tacticmedia_scim_server.rate_limiter', RateLimiterFactory::class)
                ->args([
                    ['id' => 'tacticmedia_scim_server', 'policy' => 'token_bucket', 'limit' => $rateLimit['limit'], 'rate' => $rateLimit['rate']],
                    inline_service(CacheStorage::class)->args([service($rateLimit['cache_pool'])]),
                    service('lock.factory')->nullOnInvalid(),
                ])
            ;
        }

        // After the firewall (priority 8), which sets the token.
        $services->set('tacticmedia_scim_server.rate_limit_listener', RateLimitListener::class)
            ->args([service($rateLimit['limiter'] ?? 'tacticmedia_scim_server.rate_limiter'), service('security.token_storage')->nullOnInvalid()])
            ->tag('kernel.event_listener', ['event' => 'kernel.request', 'priority' => 7])
        ;
    }

    /**
     * @param MeConfig $me
     */
    private function loadMe(array $me, ContainerConfigurator $container): void
    {
        if (!$me['enabled']) {
            return;
        }

        $services = $container->services();

        if (null === $me['resolver']) {
            $services->set('tacticmedia_scim_server.me_resolver', TokenMeResolver::class)
                ->args([service('security.token_storage'), $me['resource_type'], $me['attribute']])
            ;
        }

        $services->get('tacticmedia_scim_server.server')->arg(3, service($me['resolver'] ?? 'tacticmedia_scim_server.me_resolver'));
    }

    /**
     * @param PaginationConfig $pagination
     */
    private function loadPagination(array $pagination, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$pagination['cursor']['enabled']) {
            return;
        }

        // The default keeps kernel.secret unresolved until run time, so that a container without it still builds.
        $usesKernelSecret = '%kernel.secret%' === $pagination['cursor']['key'];

        if (!$usesKernelSecret) {
            $builder->setParameter(self::CURSOR_KEY_PARAMETER, $pagination['cursor']['key']);
            $builder->parameterCannotBeEmpty(self::CURSOR_KEY_PARAMETER, 'The tacticmedia_scim_server option pagination.cursor.key is empty. Give it a value, or set pagination.cursor: false.');
        }

        // A Parameter, not "%...%", so that the container checks an environment variable when it builds the server.
        $container->services()->get('tacticmedia_scim_server.server')
            ->arg('$cursorKey', new Parameter($usesKernelSecret ? 'kernel.secret' : self::CURSOR_KEY_PARAMETER))
            ->arg('$clock', service('clock')->nullOnInvalid())
        ;
    }
}

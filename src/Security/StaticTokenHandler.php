<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Security;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * The access token handler for the static tokens of the access_tokens option. The client name is the user
 * identifier.
 */
final readonly class StaticTokenHandler implements AccessTokenHandlerInterface
{
    public const string ROLE = 'ROLE_SCIM_CLIENT';

    /**
     * @param array<array-key, string> $tokens token by client name
     */
    public function __construct(
        #[\SensitiveParameter]
        private array $tokens,
    ) {
    }

    #[\Override]
    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $clients = array_keys(array_filter($this->tokens, static fn (string $token): bool => hash_equals($token, $accessToken)));
        $client = (string) ($clients[0] ?? throw new BadCredentialsException('Invalid credentials.'));

        return new UserBadge($client, static fn (string $identifier): InMemoryUser => new InMemoryUser($identifier, null, [self::ROLE]));
    }
}

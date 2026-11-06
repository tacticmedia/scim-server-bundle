<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use TacticMedia\ScimServer\Server\MeResolver;
use TacticMedia\ScimServer\Server\MeSubject;

/**
 * The /Me resource is the resource whose $attribute equals the user identifier of the security token.
 */
final readonly class TokenMeResolver implements MeResolver
{
    public function __construct(
        private TokenStorageInterface $tokens,
        private string $resourceType,
        private string $attribute,
    ) {
    }

    #[\Override]
    public function resolve(Request $request): ?MeSubject
    {
        $identifier = $this->tokens->getToken()?->getUserIdentifier();

        return null === $identifier ? null : new MeSubject($this->resourceType, $identifier, $this->attribute);
    }
}

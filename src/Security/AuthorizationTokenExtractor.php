<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessToken\AccessTokenExtractorInterface;

/**
 * Reads `Authorization: Bearer <token>` with the scheme in any case (RFC 9110 section 11.1), and the bare
 * `Authorization: <token>` of Okta "Header" authentication.
 */
final readonly class AuthorizationTokenExtractor implements AccessTokenExtractorInterface
{
    #[\Override]
    public function extractAccessToken(Request $request): ?string
    {
        return 1 === preg_match('/^(?:Bearer\s+)?([a-zA-Z0-9\-_+~\/.]+=*)$/iD', (string) $request->headers->get('Authorization'), $match) ? $match[1] : null;
    }
}

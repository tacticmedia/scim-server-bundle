<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use TacticMedia\ScimServerBundle\Http\ScimErrorResponse;

/**
 * Answers a rejected access token with a SCIM 401 error body, which Okta checks.
 */
final readonly class AuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    #[\Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ScimErrorResponse
    {
        return new ScimErrorResponse(Response::HTTP_UNAUTHORIZED, strtr($exception->getMessageKey(), $exception->getMessageData()), ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
    }
}

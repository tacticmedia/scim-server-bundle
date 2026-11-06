<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\EventListener;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use TacticMedia\ScimServerBundle\Controller\ScimController;
use TacticMedia\ScimServerBundle\Http\ScimErrorResponse;

/**
 * Limits the main requests of the SCIM route per authenticated client, else per client IP. It runs after the firewall,
 * so a request that fails authentication takes no token.
 */
final readonly class RateLimitListener
{
    public function __construct(
        private RateLimiterFactoryInterface $limiter,
        private ?TokenStorageInterface $tokens,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || ScimController::ROUTE !== $request->attributes->get('_route')) {
            return;
        }

        $key = $this->tokens?->getToken()?->getUserIdentifier() ?? $request->getClientIp() ?? '';
        $rateLimit = $this->limiter->create($key)->consume();

        if (!$rateLimit->isAccepted()) {
            // Okta waits 5 minutes on a Retry-After that is not a positive integer.
            $retryAfterSeconds = max(1, $rateLimit->getRetryAfter()->getTimestamp() - time());
            $event->setResponse(new ScimErrorResponse(Response::HTTP_TOO_MANY_REQUESTS, 'Too many requests.', ['Retry-After' => (string) $retryAfterSeconds]));
        }
    }
}

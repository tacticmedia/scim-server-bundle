<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\EventListener;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use TacticMedia\ScimServerBundle\Controller\ScimController;
use TacticMedia\ScimServerBundle\Http\ScimErrorResponse;

/**
 * Renders an exception on the SCIM route as a SCIM error: an HttpException with its status and headers, and with its
 * message below 500; anything else as a 500 without internals. The server itself answers every ScimException.
 */
final readonly class ExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (ScimController::ROUTE !== $event->getRequest()->attributes->get('_route')) {
            return;
        }

        $event->setResponse($exception instanceof HttpExceptionInterface ? $this->httpError($exception) : new ScimErrorResponse(Response::HTTP_INTERNAL_SERVER_ERROR, 'The server failed to process the request.'));
    }

    private function httpError(HttpExceptionInterface $exception): ScimErrorResponse
    {
        $status = $exception->getStatusCode();
        $challenge = Response::HTTP_UNAUTHORIZED === $status ? ['WWW-Authenticate' => 'Bearer'] : [];
        $detail = $status < Response::HTTP_INTERNAL_SERVER_ERROR && '' !== $exception->getMessage() ? $exception->getMessage() : Response::$statusTexts[$status] ?? 'Error';

        return new ScimErrorResponse($status, $detail, $exception->getHeaders() + $challenge);
    }
}

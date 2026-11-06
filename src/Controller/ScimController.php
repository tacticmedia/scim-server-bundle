<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use TacticMedia\ScimServer\Server\ScimServer;

/**
 * The controller of the one SCIM route: the server dispatches every endpoint below the route prefix.
 */
final readonly class ScimController
{
    public const string ROUTE = 'tacticmedia_scim_server';

    public function __construct(
        private ScimServer $server,
    ) {
    }

    public function __invoke(Request $request, string $path = ''): Response
    {
        return $this->server->handle($request, $this->basePath($request->getPathInfo(), $path));
    }

    /**
     * The router matches $path in the decoded path info, the server strips the base path from the raw path info. An
     * encoded "/" in the base path gives no raw base path at a segment boundary.
     */
    private function basePath(string $pathInfo, string $path): string
    {
        if ('' === $path) {
            return $pathInfo;
        }

        $decodedBasePath = substr(rawurldecode($pathInfo), 0, -\strlen($path));
        $basePath = implode('/', \array_slice(explode('/', $pathInfo), 0, substr_count($decodedBasePath, '/'))).'/';

        return rawurldecode($basePath) === $decodedBasePath ? $basePath : throw new NotFoundHttpException(\sprintf('The endpoint %s does not exist.', $pathInfo));
    }
}

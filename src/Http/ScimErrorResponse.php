<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Http;

use Symfony\Component\HttpFoundation\Response;
use TacticMedia\ScimServer\Exception\ScimException;

/**
 * A SCIM error body (RFC 7644 section 3.12) for the responses that the firewall and the kernel give instead of the
 * server.
 *
 * @internal
 */
final class ScimErrorResponse extends Response
{
    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(int $status, string $detail, array $headers = [])
    {
        parent::__construct(
            json_encode(new ScimException($status, $detail)->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/scim+json'] + $headers,
        );
    }
}

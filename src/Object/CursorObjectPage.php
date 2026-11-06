<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Object;

final readonly class CursorObjectPage
{
    /**
     * @param list<object>                          $objects      one page, in the requested order
     * @param int<0, max>                           $totalResults the number of matches on all pages
     * @param list<int|float|string|bool|null>|null $next         the position after the last object; null when no object follows
     */
    public function __construct(
        public array $objects,
        public int $totalResults,
        public ?array $next,
    ) {
    }
}

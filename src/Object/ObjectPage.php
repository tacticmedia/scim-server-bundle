<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Object;

final readonly class ObjectPage
{
    /**
     * @param list<object> $objects      one page, in the requested order
     * @param int<0, max>  $totalResults the number of matches on all pages
     */
    public function __construct(
        public array $objects,
        public int $totalResults,
    ) {
    }
}

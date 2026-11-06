<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Object;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Query\ObjectQuery;

/**
 * An object store that pages by position (keyset) for RFC 9865 cursors. Without it, cursors hold an offset.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9865
 */
interface CursorPaginatedObjectStore extends ObjectStore
{
    /**
     * Same filter, order and $load as query(); $query->startIndex is 1. The position is the store's own, for example the
     * sort values and the id of the last object. The server encrypts it into the cursor.
     *
     * @param list<int|float|string|bool|null>|null $after a CursorObjectPage::$next of this store for the same query; null: the first page
     *
     * @return CursorObjectPage|null null: no keyset for this query, the server pages by offset; decide it before reading the data, so that every page of a query decides the same
     *
     * @throws ScimException 400 invalidCursor for a position that the store cannot use, invalidFilter as query()
     */
    public function queryAfter(ResourceMapping $mapping, ObjectQuery $query, ?array $after): ?CursorObjectPage;
}

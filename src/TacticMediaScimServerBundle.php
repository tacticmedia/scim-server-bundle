<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class TacticMediaScimServerBundle extends AbstractBundle
{
    #[\Override]
    protected string $extensionAlias = 'tacticmedia_scim_server';
}

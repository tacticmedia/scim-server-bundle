<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Converter;

/**
 * On write: 400 invalidValue with the message. In a filter: the comparison matches nothing.
 */
final class ConversionFailed extends \RuntimeException
{
}

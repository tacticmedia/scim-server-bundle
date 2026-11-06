<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Converter;

/**
 * Converts one member value between PHP and SCIM. Implementations are services, found by class name.
 */
interface ValueConverter
{
    /**
     * Member value to SCIM value: a string, int, float, bool, null, or a list or array of these.
     */
    public function toScim(mixed $value, ConversionContext $context): mixed;

    /**
     * SCIM value to member value. On write the library normalized the value, and a nullable member never gets "".
     * Also called for constructor arguments and filter literals, then $context->object is null. A filter literal can
     * be "".
     *
     * @throws ConversionFailed when $value cannot become a member value
     */
    public function fromScim(mixed $value, ConversionContext $context): mixed;
}

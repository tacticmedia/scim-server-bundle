<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Object;

use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServerBundle\Converter\ConversionContext;
use TacticMedia\ScimServerBundle\Converter\ConversionFailed;
use TacticMedia\ScimServerBundle\Converter\IdConverter;
use TacticMedia\ScimServerBundle\Converter\NativeConverter;
use TacticMedia\ScimServerBundle\Converter\ValueConverter;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;

/**
 * Member access through symfony/property-access, and conversion with the converter of the mapping.
 *
 * @internal
 */
final readonly class MemberValues
{
    /**
     * @param ServiceLocator<mixed> $converters ValueConverter services by class name
     */
    public function __construct(
        private ServiceLocator $converters,
        private PropertyAccessorInterface $accessor = new PropertyAccessor(),
    ) {
    }

    public function get(AttributeMapping $attribute, object $object): mixed
    {
        return $attribute->method ? $object->{$attribute->member}() : $this->accessor->getValue($object, $attribute->member);
    }

    public function set(AttributeMapping $attribute, object $object, mixed $value): void
    {
        $this->accessor->setValue($object, $attribute->member, $value);
    }

    public function toScim(ResourceMapping $resource, AttributeMapping $attribute, object $object): mixed
    {
        return $this->converter($resource, $attribute)->toScim($this->get($attribute, $object), new ConversionContext($resource, $attribute, $object));
    }

    /**
     * @throws ScimException 400 invalidValue when the converter fails
     */
    public function fromScim(ResourceMapping $resource, AttributeMapping $attribute, mixed $value, ?object $object): mixed
    {
        if ('' === $value && $attribute->nullable) {
            return null;
        }

        try {
            return $this->converter($resource, $attribute)->fromScim($value, new ConversionContext($resource, $attribute, $object));
        } catch (ConversionFailed $conversionFailed) {
            throw ScimException::invalidValue(\sprintf('Attribute "%s": %s', $attribute->declaredPath, $conversionFailed->getMessage()));
        }
    }

    private function converter(ResourceMapping $resource, AttributeMapping $attribute): ValueConverter
    {
        $class = $attribute->converter ?? ($attribute === $resource->id ? IdConverter::class : NativeConverter::class);
        $converter = $this->converters->get($class);

        return $converter instanceof ValueConverter ? $converter : throw new \LogicException(\sprintf('The converter service "%s" does not implement %s.', $class, ValueConverter::class));
    }
}

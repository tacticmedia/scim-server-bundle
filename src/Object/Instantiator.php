<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Object;

use Symfony\Component\DependencyInjection\ServiceLocator;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;

/**
 * Creates the object for a new document: through the ObjectFactory of the mapping, else through the constructor
 * with named arguments. The caller then writes the document onto it with {@see DocumentMapper::write()}.
 */
final readonly class Instantiator
{
    /**
     * @param ServiceLocator<mixed> $factories ObjectFactory services by class name
     */
    public function __construct(
        private ServiceLocator $factories,
        private DocumentMapper $mapper,
    ) {
    }

    /**
     * @param array<string, mixed> $document normalized by the library
     *
     * @throws ScimException 400 invalidValue when a constructor argument cannot be converted
     */
    public function create(ResourceMapping $mapping, array $document): object
    {
        return null === $mapping->factory ? new ($mapping->class)(...$this->arguments($mapping, $document)) : $this->factory($mapping->factory)->create($document);
    }

    /**
     * A null value for an optional parameter is omitted, so PHP uses the parameter default.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function arguments(ResourceMapping $mapping, array $document): array
    {
        $arguments = [];

        foreach ($mapping->constructorParameters as $parameter) {
            $value = null === $parameter->attribute ? null : $this->mapper->valueOf($mapping, $parameter->attribute, $document);

            if (null !== $value || !$parameter->optional) {
                $arguments[$parameter->name] = $value;
            }
        }

        return $arguments;
    }

    private function factory(string $class): ObjectFactory
    {
        $factory = $this->factories->get($class);

        return $factory instanceof ObjectFactory ? $factory : throw new \LogicException(\sprintf('The factory service %s does not implement %s.', $class, ObjectFactory::class));
    }
}

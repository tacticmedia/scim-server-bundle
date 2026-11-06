<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

/**
 * The mappings of the classes that MappingPass discovered. Each class is loaded on first use and kept in memory.
 */
final class MappingRegistry
{
    /** @var array<class-string, ResourceMapping> */
    private array $loaded = [];

    /**
     * @param array<string, class-string> $classes class by resource type name, in discovery order
     */
    public function __construct(
        private readonly AttributeLoader $loader,
        private readonly array $classes,
    ) {
    }

    /**
     * @return list<string> resource type names in discovery order
     */
    public function names(): array
    {
        return array_keys($this->classes);
    }

    /**
     * @throws MappingException when no class maps $name
     */
    public function get(string $name): ResourceMapping
    {
        $class = $this->classes[$name] ?? throw new MappingException([\sprintf('No class maps the resource type "%s". Mapped: %s.', $name, implode(', ', $this->names()))]);

        return $this->loaded[$class] ??= $this->loader->load($class);
    }

    /**
     * The mapping of $class or of its nearest mapped parent class, for example of a Doctrine proxy.
     *
     * @param class-string $class
     *
     * @throws MappingException when neither $class nor a parent is mapped
     */
    public function forClass(string $class): ResourceMapping
    {
        for ($current = $class; false !== $current; $current = get_parent_class($current)) {
            $name = array_search($current, $this->classes, true);

            if (false !== $name) {
                return $this->get($name);
            }
        }

        throw new MappingException([\sprintf('%s is not mapped by #[ScimResource].', $class)]);
    }

    /**
     * @return list<ResourceMapping> in discovery order
     */
    public function all(): array
    {
        return array_map($this->get(...), $this->names());
    }
}

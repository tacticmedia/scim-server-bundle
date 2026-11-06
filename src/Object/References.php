<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Object;

use Doctrine\ORM\EntityNotFoundException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmObjectStore;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;

/**
 * Targets of Reference and ReferenceCollection mappings: their mapping, SCIM id, display value and store.
 *
 * @internal
 */
final readonly class References
{
    /**
     * @param ServiceLocator<mixed> $stores ObjectStore services by class name
     */
    public function __construct(
        private MappingRegistry $mappings,
        private ServiceLocator $stores,
        private MemberValues $values,
    ) {
    }

    public function target(AttributeMapping $reference): ResourceMapping
    {
        return $this->mappings->get((string) $reference->targetType);
    }

    public function idOf(ResourceMapping $target, object $object): string
    {
        $id = $this->values->toScim($target, $target->id, $object);

        return \is_string($id) ? $id : throw new \UnexpectedValueException(\sprintf('A %s has no id, so a reference cannot name it.', $target->class));
    }

    /**
     * The value of a reference attribute: the id, and the displayName (or else userName) of the target under the
     * display sub-attribute of $reference.
     *
     * @return array<string, mixed>
     */
    public function element(AttributeMapping $reference, ResourceMapping $target, object $object): array
    {
        $displayKey = ($reference->definition->subAttribute('displayName') ?? $reference->definition->subAttribute('display'))?->name;
        $display = null === $displayKey ? null : $this->display($target, $object);

        return ['value' => $this->idOf($target, $object)] + (null === $display ? [] : [(string) $displayKey => $display]);
    }

    /**
     * False for a target that Doctrine cannot load, for example a row that an SQL filter hides.
     */
    public function isDeleted(ResourceMapping $target, object $object): bool
    {
        try {
            return $target->softDelete?->isDeleted($object) ?? false;
        } catch (EntityNotFoundException) {
            return false;
        }
    }

    /**
     * In id order, so that the document of a write equals the document of the next read, and so does its ETag.
     * Without the soft-deleted elements, so that a write keeps them.
     *
     * @return array<string, object> the elements of the collection by SCIM id
     */
    public function current(AttributeMapping $collection, ResourceMapping $target, object $owner): array
    {
        $value = $this->values->get($collection, $owner);
        $elements = [];

        foreach (is_iterable($value) ? $value : [] as $element) {
            if (!$element instanceof $target->class) {
                throw new \UnexpectedValueException(\sprintf('%s::$%s contains a %s, but "%s" refers to %s.', $owner::class, $collection->member, get_debug_type($element), $collection->declaredPath, $target->class));
            }

            $elements[$this->idOf($target, $element)] = $element;
        }

        ksort($elements, \SORT_STRING);

        return array_filter($elements, fn (object $element): bool => !$this->isDeleted($target, $element));
    }

    /**
     * @param list<string> $ids SCIM ids
     *
     * @return array<string, object> by SCIM id; unknown ids are absent
     */
    public function find(ResourceMapping $target, array $ids): array
    {
        return [] === $ids ? [] : $this->store($target)->findMany($target, $ids);
    }

    public function store(ResourceMapping $mapping): ObjectStore
    {
        $store = $this->stores->get($mapping->store ?? OrmObjectStore::class);

        return $store instanceof ObjectStore ? $store : throw new \LogicException(\sprintf('The store service of %s does not implement %s.', $mapping->class, ObjectStore::class));
    }

    /**
     * @throws ScimException 400 invalidValue when the target store does not know $id
     */
    public function resolve(AttributeMapping $reference, ResourceMapping $target, string $id): object
    {
        return $this->find($target, [$id])[$id] ?? throw self::unknown($reference, [$target], [$id]);
    }

    /**
     * @param list<ResourceMapping> $targets
     * @param list<string>          $ids
     */
    public static function unknown(AttributeMapping $reference, array $targets, array $ids): ScimException
    {
        return ScimException::invalidValue(\sprintf('Attribute "%s": "%s" is not the id of a %s.', $reference->path->attribute, implode('", "', $ids), implode(' or ', array_map(static fn (ResourceMapping $target): string => $target->name(), $targets))));
    }

    private function display(ResourceMapping $target, object $object): mixed
    {
        foreach (['displayName', 'userName'] as $name) {
            $attribute = $target->attributesAt(new AttributePath($target->resourceType->schema->id, $name))[0] ?? null;
            $display = null === $attribute ? null : $this->values->toScim($target, $attribute, $object);

            if (null !== $display && '' !== $display) {
                return $display;
            }
        }

        return null;
    }
}

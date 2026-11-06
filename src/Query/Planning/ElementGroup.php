<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Query\Planning;

use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingKind;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Query\Plan\IsPresent;
use TacticMedia\ScimServerBundle\Query\Plan\Node;

/**
 * The members of one element: a Slot group of a multi-valued attribute, or the Fields of a single-valued complex
 * attribute.
 *
 * @internal
 */
final readonly class ElementGroup
{
    /**
     * @param non-empty-list<AttributeMapping> $members
     */
    private function __construct(
        public array $members,
        public ?string $type,
        public bool $primary,
    ) {
    }

    /**
     * @return list<self> declaration order
     */
    public static function of(ResourceMapping $mapping, AttributePath $path): array
    {
        $mappings = $mapping->attributesOf($path);
        $slots = array_filter($mappings, static fn (AttributeMapping $member): bool => MappingKind::Slot === $member->kind);
        $fields = array_values(array_filter($mappings, static fn (AttributeMapping $member): bool => MemberConditions::isValue($member) && null !== $member->path->subAttribute));

        if ([] === $slots) {
            return [] === $fields ? [] : [new self($fields, null, false)];
        }

        $groups = [];

        foreach ($slots as $slot) {
            $groups[strtolower((string) $slot->type)][] = $slot;
        }

        return array_values(array_map(static fn (array $members): self => new self($members, $members[0]->type, $members[0]->primary), $groups));
    }

    /**
     * The read renders the element: a member that is not writeOnly has a value.
     */
    public function exists(): Node|bool
    {
        $rendered = array_filter($this->members, static fn (AttributeMapping $member): bool => Mutability::WriteOnly !== $member->definition->mutability);

        return Fold::anyOf(array_values(array_map(static fn (AttributeMapping $member): IsPresent => new IsPresent($member), $rendered)));
    }

    /**
     * @return array{type: string|null, primary?: true} the type and primary sub-attributes that the read renders
     */
    public function element(): array
    {
        return $this->primary ? ['type' => $this->type, 'primary' => true] : ['type' => $this->type];
    }

    public function member(AttributePath $path): ?AttributeMapping
    {
        return array_find($this->members, static fn (AttributeMapping $member): bool => $member->path->equals($path));
    }
}

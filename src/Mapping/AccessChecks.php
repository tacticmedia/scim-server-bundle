<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyReadInfo;
use Symfony\Component\PropertyInfo\PropertyWriteInfo;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\Mutability;

/**
 * Read and write access of a member, and what it receives when a document omits the attribute.
 *
 * @internal
 */
final readonly class AccessChecks
{
    /**
     * @param \ReflectionClass<object> $class
     */
    public function __construct(
        private \ReflectionClass $class,
        private ReflectionExtractor $extractor = new ReflectionExtractor(),
    ) {
    }

    /**
     * @return array{bool, string|null, string|null} writable, adder, remover
     */
    public function access(Member $member, MappingKind $kind, AttributeDefinition $definition): array
    {
        if ($member->reflection instanceof \ReflectionMethod) {
            return $this->methodAccess($member, $member->reflection);
        }

        $this->checkReadable($member, $definition);
        $access = $this->writeAccess($member, $kind);

        return $access[0] || !$this->needsWrite($definition->mutability, $member->name()) ? $access : throw $member->fail(\sprintf($this->isCollection($kind) ? 'is %s, but $%s has no adder and remover. Add add<Element>() and remove<Element>() methods for $%2$s.' : 'is %s, but $%s has no setter. Add a setter or make $%2$s public.', $definition->mutability->value, $member->name()));
    }

    public function checkDefault(Member $member, MappingKind $kind, ResolvedPath $path, AttributeDefinition $definition): void
    {
        $default = $member->attribute->default;

        $problem = match (true) {
            null === $default => $this->nullProblem($member, $kind, $path, $definition),
            !\in_array($kind, [MappingKind::Field, MappingKind::Slot], true) => 'has default:, which applies only to a field or a slot. Remove default:.',
            Mutability::ReadOnly === $definition->mutability => 'is readOnly, so default: has no effect. Remove default:.',
            !$path->isOptional() => 'is required, so default: has no effect. Remove default:.',
            !PhpTypes::isScimValue($definition->type, $default) => \sprintf('is %s, so default: must be a %1$s value.', $definition->type->value),
            default => null,
        };

        if (null !== $problem) {
            throw $member->fail($problem);
        }
    }

    /**
     * @return array{false, null, null}
     */
    private function methodAccess(Member $member, \ReflectionMethod $method): array
    {
        return $method->isPublic() && 0 === $method->getNumberOfRequiredParameters()
            ? [false, null, null]
            : throw $member->fail('is mapped by a method that is not public or needs arguments. Make it public without required parameters.');
    }

    private function checkReadable(Member $member, AttributeDefinition $definition): void
    {
        if (Mutability::WriteOnly !== $definition->mutability && !$this->extractor->getReadInfo($this->class->getName(), $member->name()) instanceof PropertyReadInfo) {
            throw $member->fail(\sprintf('is returned, but $%s is not readable. Add a getter or make $%1$s public.', $member->name()));
        }
    }

    /**
     * @return array{bool, string|null, string|null}
     */
    private function writeAccess(Member $member, MappingKind $kind): array
    {
        $write = $this->extractor->getWriteInfo($this->class->getName(), $member->name(), ['enable_constructor_extraction' => false]);

        return match (true) {
            !$this->isCollection($kind) => [\in_array($write?->getType(), [PropertyWriteInfo::TYPE_METHOD, PropertyWriteInfo::TYPE_PROPERTY], true) && PropertyWriteInfo::VISIBILITY_PUBLIC === $write->getVisibility(), null, null],
            PropertyWriteInfo::TYPE_ADDER_AND_REMOVER === $write?->getType() => [true, $write->getAdderInfo()->getName(), $write->getRemoverInfo()->getName()],
            default => [false, null, null],
        };
    }

    private function isCollection(MappingKind $kind): bool
    {
        return \in_array($kind, [MappingKind::ReferenceCollection, MappingKind::ElementCollection], true);
    }

    private function needsWrite(Mutability $mutability, string $member): bool
    {
        return match ($mutability) {
            Mutability::ReadWrite, Mutability::WriteOnly => true,
            Mutability::Immutable => !array_any($this->class->getConstructor()?->getParameters() ?? [], static fn (\ReflectionParameter $parameter): bool => $parameter->getName() === $member),
            Mutability::ReadOnly => false,
        };
    }

    private function nullProblem(Member $member, MappingKind $kind, ResolvedPath $path, AttributeDefinition $definition): ?string
    {
        $writesNull = \in_array($kind, [MappingKind::Field, MappingKind::Slot, MappingKind::Reference], true)
            && \in_array($definition->mutability, [Mutability::ReadWrite, Mutability::Immutable], true)
            && $path->isOptional();

        return $writesNull && !$member->allowsNull()
            ? \sprintf('is optional, so a create or replace without it writes null, but $%s is not nullable. Set default: or make $%1$s nullable.', $member->name())
            : null;
    }
}

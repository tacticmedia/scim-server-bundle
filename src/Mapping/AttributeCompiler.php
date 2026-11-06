<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\Uniqueness;
use TacticMedia\ScimServerBundle\Converter\ValueConverter;
use TacticMedia\ScimServerBundle\Converter\VersionConverter;
use TacticMedia\ScimServerBundle\Doctrine\Orm\Mapping\ScimDqlExpression;
use TacticMedia\ScimServerBundle\Schema\AttributeDefinitions;

/**
 * Compiles one member of one class into an AttributeMapping.
 *
 * @internal
 */
final readonly class AttributeCompiler
{
    private PathResolver $resolver;

    private AccessChecks $checks;

    /**
     * @param \ReflectionClass<object> $class
     */
    public function __construct(
        private \ReflectionClass $class,
        AvailableSchemas $schemas,
    ) {
        $this->resolver = new PathResolver($schemas->resourceType, $schemas->appUrns);
        $this->checks = new AccessChecks($class);
    }

    public function compile(Member $member): AttributeMapping
    {
        $path = $this->resolver->resolve($member);
        $this->checkCharacteristics($member, $path);
        [$kind, $targetType] = KindResolver::resolve($member, $path);
        [$element, $elementDefinition] = MappingKind::ElementCollection === $kind ? ElementCompiler::compile($member, $path, $this->class) : [null, null];
        $definition = $elementDefinition ?? $this->definition($member, $kind, $path);
        $converter = $member->attribute->converter ?? $this->defaultConverter($member, $path);
        TypeChecks::check($member, $kind, $path, $targetType, $this->class, $definition, $converter);
        $this->checkUniqueness($member, $kind, $path, $definition);
        [$writable, $adder, $remover] = $this->checks->access($member, $kind, $definition);
        $this->checks->checkDefault($member, $kind, $path, $definition);

        if ($member->attribute->primary && MappingKind::Slot !== $kind) {
            throw $member->fail('has primary:, which applies only to a slot (attribute[type eq "<type>"].sub-attribute). Remove primary:.');
        }

        return new AttributeMapping(
            path: $path->attribute->path(),
            declaredPath: $member->attribute->path,
            definition: $definition,
            kind: $kind,
            member: $member->name(),
            method: $member->isMethod(),
            phpType: $member->phpType(),
            nullable: $member->allowsNull(),
            writable: $writable,
            required: !$path->isOptional() && Mutability::ReadOnly !== $definition->mutability,
            adder: $adder,
            remover: $remover,
            converter: $converter,
            type: $path->type,
            primary: $member->attribute->primary,
            targetType: $targetType,
            default: $member->attribute->default,
            element: $element,
            dqlExpression: $this->dqlExpression($member, $path),
        );
    }

    private function dqlExpression(Member $member, ResolvedPath $path): ?string
    {
        $expression = $member->reflection instanceof \ReflectionMethod ? ($member->reflection->getAttributes(ScimDqlExpression::class)[0] ?? null)?->newInstance() : null;
        $whole = $path->attribute->attribute->multiValued || AttributeType::Complex === $path->attribute->definition()->type;

        return match (true) {
            null === $expression => null,
            $whole => throw $member->fail('has #[ScimDqlExpression], which needs a single-valued attribute that is not complex. Remove #[ScimDqlExpression].'),
            default => $expression->dql,
        };
    }

    /**
     * @return class-string<ValueConverter>|null
     */
    private function defaultConverter(Member $member, ResolvedPath $path): ?string
    {
        return $path->common && 'version' === $path->attribute->subAttribute?->name && 'int' === $member->phpType() ? VersionConverter::class : null;
    }

    private function checkCharacteristics(Member $member, ResolvedPath $path): void
    {
        $attribute = $member->attribute;
        $set = array_keys(array_filter([
            'type' => $attribute->type,
            'required' => $attribute->required,
            'caseExact' => $attribute->caseExact,
            'mutability' => $attribute->mutability,
            'returned' => $attribute->returned,
            'uniqueness' => $path->common ? $attribute->uniqueness : null,
            'canonicalValues' => $attribute->canonicalValues,
            'description' => $attribute->description,
        ], static fn (mixed $value): bool => null !== $value));

        if (!$path->appDefined && [] !== $set) {
            throw $member->fail(\sprintf('is defined by RFC 7643, so its characteristics are fixed. Remove %s:.', implode(':, ', $set)));
        }

        if ($member->isMethod() && !\in_array($attribute->mutability, [null, Mutability::ReadOnly], true)) {
            throw $member->fail('is mapped by a method, so it is readOnly. Remove mutability:.');
        }
    }

    /**
     * The server checks uniqueness with an "eq" filter on a single-valued simple value.
     */
    private function checkUniqueness(Member $member, MappingKind $kind, ResolvedPath $path, AttributeDefinition $definition): void
    {
        $attribute = $path->attribute->attribute;

        $problem = match (true) {
            Uniqueness::None === $definition->uniqueness, $path->isId() => null,
            $path->attribute->subAttribute instanceof AttributeDefinition, $attribute->multiValued, AttributeType::Complex === $attribute->type => 'has uniqueness:, which the server checks only on a single-valued attribute that is not complex. Remove uniqueness:.',
            MappingKind::Computed === $kind, Mutability::WriteOnly === $definition->mutability => 'is unique, so the server checks it with a filter, but a method or writeOnly mapping cannot be filtered. Map a property that is not writeOnly, or remove uniqueness:.',
            default => null,
        };

        if (null !== $problem) {
            throw $member->fail($problem);
        }
    }

    private function definition(Member $member, MappingKind $kind, ResolvedPath $path): AttributeDefinition
    {
        $uniqueness = $member->attribute->uniqueness;
        $definition = $uniqueness instanceof Uniqueness ? AttributeDefinitions::with($path->attribute->definition(), uniqueness: $uniqueness) : $path->attribute->definition();
        $enumValues = null === $member->attribute->converter && [] === $definition->canonicalValues ? PhpTypes::canonicalValues($member->phpType()) : [];

        return match (true) {
            MappingKind::Computed === $kind => AttributeDefinitions::with($definition, mutability: Mutability::ReadOnly),
            [] !== $enumValues => AttributeDefinitions::with($definition, canonicalValues: $enumValues),
            default => $definition,
        };
    }
}

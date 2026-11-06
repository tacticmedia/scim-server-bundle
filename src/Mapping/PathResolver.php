<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Comparison;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Filter\FilterParser;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\CoreSchemas;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Schema;

/**
 * Resolves a mapping path against the schemas available to one class: the core schema with the common attributes
 * first, then the extensions. An unqualified name must match in exactly one extension.
 *
 * @internal
 */
final readonly class PathResolver
{
    public const array COMMON = ['id', 'externalid', 'meta'];
    private const array SERVER_OWNED = ['meta', 'meta.resourcetype', 'meta.location'];

    /**
     * @param list<string> $appUrns lowercase URNs of the schemas that the application defines
     */
    public function __construct(
        private ResourceType $available,
        private array $appUrns,
        private FilterParser $parser = new FilterParser(),
    ) {
    }

    public function resolve(Member $member): ResolvedPath
    {
        try {
            $patchPath = $this->parser->parsePatchPath($member->attribute->path);
        } catch (ScimException $scimException) {
            throw $member->fail('is not a valid path: '.$scimException->detail);
        }

        $type = $patchPath->valueFilter instanceof Filter ? self::typeLiteral($patchPath->valueFilter) ?? throw $member->fail('has a value filter that is not allowed in a mapping. Use [type eq "<value>"].') : null;
        $attribute = $this->attribute($member, $patchPath->path);

        if (\in_array(strtolower($this->name($attribute->path())), self::SERVER_OWNED, true) || '$ref' === $attribute->subAttribute?->name) {
            throw $member->fail('is set by the server and cannot be mapped.');
        }

        $common = $attribute->schema === $this->available->schema && \in_array(strtolower($attribute->attribute->name), self::COMMON, true);

        return new ResolvedPath($attribute, $type, !$common && \in_array(strtolower($attribute->schema->id), $this->appUrns, true), $common);
    }

    private function name(AttributePath $path): string
    {
        return $path->attribute.(null === $path->subAttribute ? '' : '.'.$path->subAttribute);
    }

    /**
     * The literal of a [type eq "..."] value filter, else null.
     *
     * @internal
     */
    public static function typeLiteral(Filter $filter): ?string
    {
        $isTypeEquals = $filter instanceof Comparison && ComparisonOperator::Equal === $filter->operator && 0 === strcasecmp((string) $filter->path->subAttribute, 'type');

        return $isTypeEquals && \is_string($filter->value) ? $filter->value : null;
    }

    private function attribute(Member $member, AttributePath $path): ResolvedAttribute
    {
        if (null !== $path->schemaUrn) {
            return $this->available->resolve($path) ?? throw $this->unknown($member, $path);
        }

        $candidates = array_values(array_filter(array_map(
            fn (Schema $schema): ?ResolvedAttribute => $this->available->resolve(new AttributePath($schema->id, $path->attribute, $path->subAttribute)),
            $this->available->schemas(),
        )));

        return match (\count($candidates)) {
            0 => throw $this->unknown($member, $path),
            1 => $candidates[0],
            default => $candidates[0]->schema === $this->available->schema ? $candidates[0] : throw $member->fail(\sprintf('is defined by %s. Qualify it with one of these schema URNs.', implode(' and ', array_map(static fn (ResolvedAttribute $candidate): string => $candidate->schema->id, $candidates)))),
        };
    }

    private function unknown(Member $member, AttributePath $path): MappingException
    {
        $schemas = array_map(static fn (Schema $schema): string => $schema->id, $this->available->schemas());

        if (null !== $path->schemaUrn && !\in_array(strtolower($path->schemaUrn), array_map(strtolower(...), $schemas), true)) {
            return $member->fail(\sprintf('uses the schema %s, which is not declared. Add new ScimExtension(\'%1$s\', ...) to #[ScimResource(extensions: [...])].', $path->schemaUrn));
        }

        return $member->fail(\sprintf('is not an attribute of %s.%s', implode(' or ', $schemas), $this->suggestion($path)));
    }

    private function suggestion(AttributePath $path): string
    {
        $wanted = strtolower($this->name($path));
        $distances = [];
        $attributes = [...array_merge(...array_map(static fn (Schema $schema): array => $schema->attributes, $this->available->schemas())), ...CoreSchemas::commonAttributes()];

        foreach ($attributes as $attribute) {
            foreach ([$attribute->name, ...array_map(static fn (AttributeDefinition $sub): string => $attribute->name.'.'.$sub->name, $attribute->subAttributes)] as $candidate) {
                $distances[$candidate] = levenshtein($wanted, strtolower($candidate));
            }
        }

        asort($distances);
        $best = array_key_first($distances);

        return null !== $best && $distances[$best] <= 3 ? \sprintf(' Did you mean "%s"?', $best) : '';
    }
}

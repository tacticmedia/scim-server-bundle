<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Doctrine\Orm;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Parameter;

/**
 * The aliases, joins and parameters of one translation: root alias "r", subquery aliases "o<N>" and "t<N>", join
 * aliases "s<N>", parameters ":scim_p<N>".
 */
final class OrmQueryContext
{
    public const string ROOT = 'r';

    /** @var array<string, ClassMetadata<object>> by alias */
    private array $metadata;

    /** @var ArrayCollection<int, Parameter> */
    private readonly ArrayCollection $parameters;

    private int $subqueries = 0;

    /** @var list<array{class-string, string, string}> class, alias, condition */
    private array $joins = [];

    /**
     * @param class-string $rootClass
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        string $rootClass,
    ) {
        $this->metadata = [self::ROOT => $entityManager->getClassMetadata($rootClass)];
        $this->parameters = new ArrayCollection();
    }

    /**
     * @return ClassMetadata<object>
     */
    public function metadata(string $alias): ClassMetadata
    {
        return $this->metadata[$alias] ?? throw new \LogicException(\sprintf('The alias "%s" is not defined.', $alias));
    }

    /**
     * Reserves the aliases o<N> and t<N>; t<N> is an entity of $relatedClass.
     *
     * @param class-string $relatedClass
     *
     * @return int N
     */
    public function subquery(string $relatedClass): int
    {
        $number = $this->subqueries++;
        $this->metadata['t'.$number] = $this->entityManager->getClassMetadata($relatedClass);

        return $number;
    }

    /**
     * Reserves the alias s<N> for an entity of $relatedClass, joined to the root with LEFT JOIN $relatedClass WITH the
     * condition that $condition gives for the alias.
     *
     * @param class-string             $relatedClass
     * @param \Closure(string): string $condition
     *
     * @return string the alias, "s<N>"
     */
    public function join(string $relatedClass, \Closure $condition): string
    {
        $alias = 's'.\count($this->joins);
        $this->metadata[$alias] = $this->entityManager->getClassMetadata($relatedClass);
        $this->joins[] = [$relatedClass, $alias, $condition($alias)];

        return $alias;
    }

    /**
     * @return list<array{class-string, string, string}> class, alias, condition
     */
    public function joins(): array
    {
        return $this->joins;
    }

    /**
     * @param string|null $type a Doctrine type name; null lets Doctrine infer it
     *
     * @return string the placeholder, ":scim_p<N>"
     */
    public function parameter(mixed $value, ?string $type): string
    {
        $name = 'scim_p'.$this->parameters->count();
        $this->parameters->add(new Parameter($name, $value, $type));

        return ':'.$name;
    }

    /**
     * @return ArrayCollection<int, Parameter>
     */
    public function parameters(): ArrayCollection
    {
        return $this->parameters;
    }
}

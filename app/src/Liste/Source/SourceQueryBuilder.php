<?php

declare(strict_types=1);

namespace App\Liste\Source;

use App\Liste\ColonnesTriables;
use App\Liste\PageListe;
use App\Liste\ParametresListe;
use App\Liste\SourceListe;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * Adaptateur pour une liste servie par un QueryBuilder Doctrine ORM (schéma app).
 * Le QueryBuilder de base est fourni par un repository, donc déjà contraint par
 * les filtres actifs (dont le cloisonnement tenant) : l'adaptateur n'y touche pas,
 * il ajoute seulement recherche (LIKE bindé), tri (liste blanche) et LIMIT/OFFSET.
 * Le tri vient d'une clé logique déjà validée en amont ; jamais un nom SQL brut.
 *
 * @template T of object
 */
final class SourceQueryBuilder implements SourceListe
{
    /**
     * @param \Closure(): QueryBuilder $base            fabrique un QueryBuilder frais à chaque appel
     * @param array<string, string>    $tris            clé logique → « alias.champ »
     * @param list<string>             $champsRecherche « alias.champ » cherchables (LIKE insensible à la casse)
     * @param string|null              $departage       « alias.champ » de départage pour un ordre stable
     */
    public function __construct(
        private readonly \Closure $base,
        private readonly ColonnesTriables $colonnes,
        private readonly array $tris,
        private readonly array $champsRecherche = [],
        private readonly ?string $departage = null,
    ) {
    }

    /**
     * @return PageListe<T>
     */
    public function interroger(ParametresListe $parametres): PageListe
    {
        $qb = ($this->base)();

        if ($parametres->aUneRecherche() && [] !== $this->champsRecherche) {
            $clauses = array_map(
                static fn (string $champ): string => \sprintf('LOWER(%s) LIKE :recherche', $champ),
                $this->champsRecherche,
            );
            $qb->andWhere('('.implode(' OR ', $clauses).')')
                ->setParameter('recherche', '%'.mb_strtolower($parametres->recherche()).'%');
        }

        $qb->orderBy($this->tris[$parametres->tri()], $parametres->direction()->versSql());
        if (null !== $this->departage) {
            $qb->addOrderBy($this->departage, 'DESC');
        }

        $qb->setFirstResult($parametres->offset())->setMaxResults($parametres->parPage());

        $paginator = new Paginator($qb, false);
        $total = \count($paginator);
        /** @var list<T> $elements */
        $elements = iterator_to_array($paginator->getIterator(), false);

        return PageListe::de($elements, $parametres->page(), $parametres->parPage(), $total);
    }

    public function colonnes(): ColonnesTriables
    {
        return $this->colonnes;
    }
}

<?php

declare(strict_types=1);

namespace App\Liste\Source;

use App\Liste\ColonnesTriables;
use App\Liste\PageListe;
use App\Liste\ParametresListe;
use App\Liste\SourceListe;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Adaptateur pour une liste servie par une requête DBAL (schéma « data », lecture
 * seule). Recherche (ILIKE bindé), tri (expression SQL choisie dans la liste
 * blanche, jamais l'entrée utilisateur) et LIMIT/OFFSET sont poussés en base —
 * indispensable sur la volumétrie France. Le COUNT est fait à part.
 *
 * @template T
 */
final class SourceDbal implements SourceListe
{
    /**
     * @param string                            $select          liste des colonnes projetées
     * @param string                            $from            fragment « table [JOIN …] » (littéral de code)
     * @param array<string, string>             $tris            clé logique → expression SQL d'ordre
     * @param list<string>                      $champsRecherche expressions SQL cherchables (ILIKE)
     * @param \Closure(array<string, mixed>): T $mapper          ligne SQL → objet du domaine
     * @param string|null                       $departage       expression SQL de départage (ordre stable)
     */
    public function __construct(
        private readonly Connection $connexion,
        private readonly string $select,
        private readonly string $from,
        private readonly ColonnesTriables $colonnes,
        private readonly array $tris,
        private readonly array $champsRecherche,
        private readonly \Closure $mapper,
        private readonly ?string $departage = null,
    ) {
    }

    /**
     * @return PageListe<T>
     */
    public function interroger(ParametresListe $parametres): PageListe
    {
        $where = '';
        $params = [];
        if ($parametres->aUneRecherche() && [] !== $this->champsRecherche) {
            $clauses = array_map(
                static fn (string $champ): string => \sprintf('%s ILIKE :recherche', $champ),
                $this->champsRecherche,
            );
            $where = ' WHERE ('.implode(' OR ', $clauses).')';
            $params['recherche'] = '%'.$parametres->recherche().'%';
        }

        $ordre = $this->tris[$parametres->tri()].' '.$parametres->direction()->versSql();
        if (null !== $this->departage) {
            $ordre .= ', '.$this->departage;
        }

        $compte = $this->connexion->fetchOne('SELECT COUNT(*) FROM '.$this->from.$where, $params);
        $total = is_numeric($compte) ? (int) $compte : 0;

        $sql = \sprintf('SELECT %s FROM %s%s ORDER BY %s LIMIT :limite OFFSET :decalage', $this->select, $this->from, $where, $ordre);
        $lignes = $this->connexion->fetchAllAssociative(
            $sql,
            [...$params, 'limite' => $parametres->parPage(), 'decalage' => $parametres->offset()],
            ['limite' => ParameterType::INTEGER, 'decalage' => ParameterType::INTEGER],
        );

        $elements = array_map($this->mapper, $lignes);

        return PageListe::de($elements, $parametres->page(), $parametres->parPage(), $total);
    }

    public function colonnes(): ColonnesTriables
    {
        return $this->colonnes;
    }
}

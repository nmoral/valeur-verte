<?php

declare(strict_types=1);

namespace App\Liste;

/**
 * Port d'une source de liste : sait renvoyer une page de résultats pour des
 * paramètres donnés (tri/recherche/pagination) et déclarer ses colonnes triables.
 * Trois adaptateurs l'implémentent (mémoire, QueryBuilder ORM, DBAL data) ; chaque
 * liste de l'app fournit une source concrète branchée sur l'adaptateur adéquat.
 */
interface SourceListe
{
    /**
     * @return PageListe<mixed>
     */
    public function interroger(ParametresListe $parametres): PageListe;

    public function colonnes(): ColonnesTriables;
}

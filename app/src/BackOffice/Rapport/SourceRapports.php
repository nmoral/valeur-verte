<?php

declare(strict_types=1);

namespace App\BackOffice\Rapport;

use App\Liste\AsListeSource;
use App\Liste\ColonnesTriables;
use App\Liste\PageListe;
use App\Liste\ParametresListe;
use App\Liste\Source\SourceQueryBuilder;
use App\Liste\SourceListe;
use Doctrine\ORM\QueryBuilder;

/**
 * Source de liste des rapports & leads du pro (§9.2) : lecture ORM cloisonnée par
 * tenant (le filtre SQL s'applique au QueryBuilder), recherche sur la référence et
 * l'adresse, tri par date/référence/adresse/valeur, les plus récents d'abord.
 */
#[AsListeSource('rapports')]
final class SourceRapports implements SourceListe
{
    public function __construct(
        private readonly RapportRepository $repository,
    ) {
    }

    public function interroger(ParametresListe $parametres): PageListe
    {
        return $this->adaptateur()->interroger($parametres);
    }

    public function colonnes(): ColonnesTriables
    {
        return ColonnesTriables::de('date', 'reference', 'adresse', 'valeur');
    }

    /**
     * @return SourceQueryBuilder<Rapport>
     */
    private function adaptateur(): SourceQueryBuilder
    {
        /** @var SourceQueryBuilder<Rapport> $adaptateur */
        $adaptateur = new SourceQueryBuilder(
            fn (): QueryBuilder => $this->repository->createQueryBuilder('r'),
            $this->colonnes(),
            [
                'date' => 'r.creeLe',
                'reference' => 'r.reference',
                'adresse' => 'r.adresse',
                'valeur' => 'r.valeurVerteEstimee',
            ],
            ['r.reference', 'r.adresse'],
            'r.id',
        );

        return $adaptateur;
    }
}

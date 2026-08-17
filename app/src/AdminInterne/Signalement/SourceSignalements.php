<?php

declare(strict_types=1);

namespace App\AdminInterne\Signalement;

use App\Liste\AsListeSource;
use App\Liste\ColonnesTriables;
use App\Liste\PageListe;
use App\Liste\ParametresListe;
use App\Liste\Source\SourceQueryBuilder;
use App\Liste\SourceListe;
use Doctrine\ORM\QueryBuilder;

/**
 * Source de liste des signalements (#49) : lecture ORM (schéma app), recherche
 * sur l'adresse et le message, tri par date ou adresse, les plus récents d'abord
 * par défaut.
 */
#[AsListeSource('signalements')]
final class SourceSignalements implements SourceListe
{
    public function __construct(
        private readonly SignalementRepository $repository,
    ) {
    }

    public function interroger(ParametresListe $parametres): PageListe
    {
        return $this->adaptateur()->interroger($parametres);
    }

    public function colonnes(): ColonnesTriables
    {
        return ColonnesTriables::de('date', 'adresse');
    }

    /**
     * @return SourceQueryBuilder<Signalement>
     */
    private function adaptateur(): SourceQueryBuilder
    {
        /** @var SourceQueryBuilder<Signalement> $adaptateur */
        $adaptateur = new SourceQueryBuilder(
            fn (): QueryBuilder => $this->repository->createQueryBuilder('s'),
            $this->colonnes(),
            ['date' => 's.signaleLe', 'adresse' => 's.adresse'],
            ['s.adresse', 's.message'],
            's.id',
        );

        return $adaptateur;
    }
}

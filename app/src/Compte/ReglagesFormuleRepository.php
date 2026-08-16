<?php

declare(strict_types=1);

namespace App\Compte;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Réglages des formules (#61/#65), source de vérité éditable des paramètres
 * d'essai. Tant qu'une formule n'est pas réglée, ses défauts (portés par l'enum)
 * sont servis ; un réglage prend ensuite le relais, formule par formule.
 *
 * @extends ServiceEntityRepository<ReglageFormule>
 */
class ReglagesFormuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReglageFormule::class);
    }

    public function pour(Formule $formule): ParametresFormule
    {
        return $this->findOneBy(['formule' => $formule])?->parametres()
            ?? $formule->parametresParDefaut();
    }

    public function enregistrer(Formule $formule, ParametresFormule $parametres): void
    {
        $manager = $this->getEntityManager();
        $existant = $this->findOneBy(['formule' => $formule]);
        if (null === $existant) {
            $manager->persist(ReglageFormule::depuis($formule, $parametres));
        } else {
            $existant->mettreAJour($parametres);
        }
        $manager->flush();
    }
}

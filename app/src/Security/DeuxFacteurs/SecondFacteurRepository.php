<?php

declare(strict_types=1);

namespace App\Security\DeuxFacteurs;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SecondFacteur>
 */
class SecondFacteurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecondFacteur::class);
    }

    public function enregistrer(SecondFacteur $facteur): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($facteur);
        $manager->flush();
    }

    /**
     * Le facteur qui protège réellement le compte aujourd'hui.
     */
    public function actifPourUtilisateur(int $userId): ?SecondFacteur
    {
        foreach ($this->findBy(['userId' => $userId]) as $facteur) {
            if ($facteur->estActif()) {
                return $facteur;
            }
        }

        return null;
    }

    /**
     * Le facteur scanné mais pas encore confirmé, s'il y en a un. Il coexiste
     * avec l'actif le temps d'un réenrôlement.
     */
    public function enAttentePourUtilisateur(int $userId): ?SecondFacteur
    {
        return $this->findOneBy(['userId' => $userId, 'activeLe' => null]);
    }

    /**
     * Active le facteur en attente et **supprime** tous les autres : c'est la
     * confirmation, et elle seule, qui condamne l'ancien téléphone.
     */
    public function promouvoir(SecondFacteur $enAttente, \DateTimeImmutable $le): void
    {
        $manager = $this->getEntityManager();
        foreach ($this->findBy(['userId' => $enAttente->userId()]) as $autre) {
            if ($autre !== $enAttente) {
                $manager->remove($autre);
            }
        }

        $enAttente->activer($le);
        $manager->persist($enAttente);
        $manager->flush();
    }
}

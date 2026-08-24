<?php

declare(strict_types=1);

namespace App\Security\DeuxFacteurs;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JetonEnrolement>
 */
class JetonEnrolementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JetonEnrolement::class);
    }

    public function enregistrer(JetonEnrolement $jeton): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($jeton);
        $manager->flush();
    }

    public function trouverParEmpreinte(string $empreinte): ?JetonEnrolement
    {
        return $this->findOneBy(['empreinte' => $empreinte]);
    }

    /**
     * Un nouveau lien révoque les précédents : deux liens vivants, ce sont deux
     * enrôlements concurrents pour un compte qui n'en veut qu'un.
     */
    public function invaliderPourUtilisateur(int $userId, \DateTimeImmutable $maintenant): void
    {
        foreach ($this->findBy(['userId' => $userId, 'utiliseLe' => null]) as $jeton) {
            $jeton->marquerUtilise($maintenant);
        }
        $this->getEntityManager()->flush();
    }
}

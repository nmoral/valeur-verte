<?php

declare(strict_types=1);

namespace App\Security\MotDePasseOublie;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JetonReinitialisation>
 */
class JetonReinitialisationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JetonReinitialisation::class);
    }

    public function enregistrer(JetonReinitialisation $jeton): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($jeton);
        $manager->flush();
    }

    public function trouverParEmpreinte(string $empreinte): ?JetonReinitialisation
    {
        return $this->findOneBy(['empreinte' => $empreinte]);
    }

    /**
     * Invalide (consomme) tous les jetons encore vivants de l'utilisateur : une
     * nouvelle demande rend le lien précédent inopérant. Un seul jeton actif à la
     * fois par compte.
     */
    public function invaliderPourUtilisateur(int $userId, \DateTimeImmutable $maintenant): void
    {
        $encoreVivants = $this->findBy(['userId' => $userId, 'utiliseLe' => null]);
        foreach ($encoreVivants as $jeton) {
            $jeton->marquerUtilise($maintenant);
        }
        $this->getEntityManager()->flush();
    }
}

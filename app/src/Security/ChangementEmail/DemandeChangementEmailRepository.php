<?php

declare(strict_types=1);

namespace App\Security\ChangementEmail;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DemandeChangementEmail>
 */
class DemandeChangementEmailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DemandeChangementEmail::class);
    }

    public function enregistrer(DemandeChangementEmail $demande): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($demande);
        $manager->flush();
    }

    public function trouverParEmpreinte(string $empreinte): ?DemandeChangementEmail
    {
        return $this->findOneBy(['empreinte' => $empreinte]);
    }

    /**
     * Consomme les demandes encore vivantes du compte : une nouvelle demande rend
     * le lien précédent inopérant. Un seul changement en attente à la fois — sinon
     * deux liens concurrents désigneraient deux adresses différentes, et la
     * dernière boîte relevée gagnerait, ce qui n'est pas une intention exprimée.
     */
    public function invaliderPourUtilisateur(int $userId, \DateTimeImmutable $maintenant): void
    {
        $encoreVivantes = $this->findBy(['userId' => $userId, 'confirmeeLe' => null]);
        foreach ($encoreVivantes as $demande) {
            $demande->marquerConfirmee($maintenant);
        }
        $this->getEntityManager()->flush();
    }
}

<?php

declare(strict_types=1);

namespace App\Security\DeuxFacteurs;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CodeDeSecours>
 */
class CodeDeSecoursRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CodeDeSecours::class);
    }

    /**
     * Remplace le jeu du compte : générer de nouveaux codes révoque les anciens.
     * Les précédents sont **supprimés** et non marqués utilisés — un code révoqué
     * n'a pas servi, et le compteur de codes restants doit rester lisible.
     *
     * @param list<CodeDeSecours> $codes
     */
    public function remplacerLeJeu(int $userId, array $codes): void
    {
        $manager = $this->getEntityManager();
        foreach ($this->findBy(['userId' => $userId]) as $ancien) {
            $manager->remove($ancien);
        }
        foreach ($codes as $code) {
            $manager->persist($code);
        }
        $manager->flush();
    }

    public function utilisableParEmpreinte(int $userId, string $empreinte): ?CodeDeSecours
    {
        $code = $this->findOneBy(['userId' => $userId, 'empreinte' => $empreinte]);

        return null !== $code && $code->estUtilisable() ? $code : null;
    }

    public function compterUtilisables(int $userId): int
    {
        return \count($this->findBy(['userId' => $userId, 'utiliseLe' => null]));
    }

    public function enregistrer(CodeDeSecours $code): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($code);
        $manager->flush();
    }
}

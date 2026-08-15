<?php

declare(strict_types=1);

namespace App\BackOffice\Aide;

use App\BackOffice\GrilleCout\PeriodeValidite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;

/**
 * Versions successives du barème d'aides national (#26).
 *
 * Publier ne modifie rien en place : la version courante est **clôturée** à la date
 * d'effet de la nouvelle, et ses valeurs restent lisibles (§7 — aucun `UPDATE`
 * destructif). Un rapport émis sous l'ancien barème reste ainsi reproductible.
 *
 * Tant que rien n'a été publié, le barème national par défaut est servi — même
 * principe que la grille de coûts (ONB-3, « un rapport avant configuration »).
 *
 * @extends ServiceEntityRepository<BaremeAidesEnregistre>
 */
class BaremeAidesRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($registry, BaremeAidesEnregistre::class);
    }

    public function courant(): BaremeAides
    {
        return $this->enVigueurLe($this->clock->now());
    }

    public function enVigueurLe(\DateTimeImmutable $instant): BaremeAides
    {
        foreach ($this->versionsRecentesDAbord() as $version) {
            $bareme = $version->versBareme();
            if ($bareme->periode()->estActiveLe($instant)) {
                return $bareme;
            }
        }

        return BaremeAides::parDefautNational(PeriodeValidite::depuis($instant));
    }

    public function publier(BaremeAides $nouveau): void
    {
        $dateEffet = $nouveau->periode()->valideDu();
        $courante = $this->derniereVersion();

        if (null !== $courante && $dateEffet < $courante->valideDu()) {
            throw new \InvalidArgumentException('La date d\'effet ne peut pas précéder celle du barème en vigueur : rétro-dater changerait le reste à charge des rapports déjà émis.');
        }

        $courante?->cloturerLe($dateEffet);

        $manager = $this->getEntityManager();
        $manager->persist(BaremeAidesEnregistre::depuis($nouveau));
        $manager->flush();
    }

    /**
     * @return list<BaremeAidesEnregistre>
     */
    private function versionsRecentesDAbord(): array
    {
        return $this->findBy([], ['valideDu' => 'DESC', 'id' => 'DESC']);
    }

    private function derniereVersion(): ?BaremeAidesEnregistre
    {
        return $this->findOneBy([], ['valideDu' => 'DESC', 'id' => 'DESC']);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Compte;

use App\AdminInterne\Compte\CompteurRapportsConsommes;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\Tests\BackOffice\Rapport\InstantaneRapportMother;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : le compteur est la source unique du nombre de rapports
 * consommés par compte (tenant). Il lit en DBAL, indépendamment du filtre
 * multi-tenant, pour être réutilisable côté artisan (#108) et widget (#38).
 */
final class CompteurRapportsConsommesTest extends KernelTestCase
{
    public function testCompteLesRapportsConsommesParTenant(): void
    {
        self::bootKernel();
        $rapports = $this->rapportRepository();
        $rapports->enregistrer($this->rapportPour('2026-A1', 1));
        $rapports->enregistrer($this->rapportPour('2026-A2', 1));
        $rapports->enregistrer($this->rapportPour('2026-B1', 2));

        $compteur = new CompteurRapportsConsommes($this->connexion());

        self::assertSame(2, $compteur->pour(1));
        self::assertSame(1, $compteur->pour(2));
        self::assertSame(0, $compteur->pour(99));
        self::assertSame([1 => 2, 2 => 1], $compteur->parTenant());
    }

    private function rapportPour(string $reference, int $tenantId): Rapport
    {
        $rapport = Rapport::creer(
            $reference,
            new \DateTimeImmutable('2026-08-01 09:00:00'),
            Source::Audit,
            InstantaneRapportMother::creer(adresse: 'Baziège'),
            '/rapports/'.$reference.'.pdf',
        );
        $rapport->setTenantId($tenantId);

        return $rapport;
    }

    private function rapportRepository(): RapportRepository
    {
        $repository = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repository);

        return $repository;
    }

    private function connexion(): \Doctrine\DBAL\Connection
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager->getConnection();
    }
}

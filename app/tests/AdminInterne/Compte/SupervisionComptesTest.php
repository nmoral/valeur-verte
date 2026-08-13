<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Compte;

use App\AdminInterne\Compte\CompteurRapportsConsommes;
use App\AdminInterne\Compte\SeuilEssai;
use App\AdminInterne\Compte\SeuilEssaiRepository;
use App\AdminInterne\Compte\SupervisionComptes;
use App\BackOffice\Entreprise\Entreprise;
use App\BackOffice\Entreprise\EntrepriseRepository;
use App\BackOffice\Entreprise\FicheEntreprise;
use App\BackOffice\Entreprise\Siret;
use App\BackOffice\Entreprise\StatutRge;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\Tenant\TenantRepository;
use App\Tests\BackOffice\Rapport\InstantaneRapportMother;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : la vue de pilotage assemble, par compte, l'identité, le
 * compteur (source unique) et le statut d'essai dérivé du seuil courant.
 */
final class SupervisionComptesTest extends KernelTestCase
{
    public function testAssembleIdentiteCompteurEtStatutParCompte(): void
    {
        self::bootKernel();
        $this->seuils()->enregistrer(SeuilEssai::de(2));

        $t1 = $this->tenants()->nouveau()->id();
        $t2 = $this->tenants()->nouveau()->id();
        self::assertNotNull($t1);
        self::assertNotNull($t2);

        $this->seedEntreprise('SARL Toiture Occitane', $t1);
        $this->seedRapport('2026-A1', $t1);
        $this->seedRapport('2026-A2', $t1);
        $this->seedRapport('2026-A3', $t1);
        $this->seedRapport('2026-B1', $t2);

        $comptes = $this->supervision()->comptes();

        self::assertCount(2, $comptes);
        [$c1, $c2] = $comptes;

        self::assertSame($t1, $c1->tenantId());
        self::assertSame('SARL Toiture Occitane', $c1->identite());
        self::assertSame(3, $c1->rapportsConsommes());
        self::assertTrue($c1->statut()->seuilAtteint());

        self::assertSame($t2, $c2->tenantId());
        self::assertSame('Compte #'.$t2, $c2->identite());
        self::assertSame(1, $c2->rapportsConsommes());
        self::assertTrue($c2->statut()->enEssai());
    }

    private function supervision(): SupervisionComptes
    {
        return new SupervisionComptes(
            $this->tenants(),
            new CompteurRapportsConsommes($this->manager()->getConnection()),
            $this->seuils(),
            $this->manager()->getConnection(),
        );
    }

    private function seedEntreprise(string $raisonSociale, int $tenantId): void
    {
        $repository = self::getContainer()->get(EntrepriseRepository::class);
        self::assertInstanceOf(EntrepriseRepository::class, $repository);
        $entreprise = Entreprise::depuisFiche(FicheEntreprise::creer(
            Siret::depuis('12345678901234'),
            $raisonSociale,
            '12 rue des Lilas, 31000 Toulouse',
            '43.29A',
            '6 à 9 salariés',
            StatutRge::Rge,
            ['Isolation des murs'],
        ));
        $entreprise->setTenantId($tenantId);
        $repository->enregistrer($entreprise);
    }

    private function seedRapport(string $reference, int $tenantId): void
    {
        $repository = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repository);
        $rapport = Rapport::creer(
            $reference,
            new \DateTimeImmutable('2026-08-01 09:00:00'),
            Source::Audit,
            InstantaneRapportMother::creer(adresse: 'Baziège'),
            '/rapports/'.$reference.'.pdf',
        );
        $rapport->setTenantId($tenantId);
        $repository->enregistrer($rapport);
    }

    private function tenants(): TenantRepository
    {
        $repository = self::getContainer()->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $repository);

        return $repository;
    }

    private function seuils(): SeuilEssaiRepository
    {
        $repository = $this->manager()->getRepository(SeuilEssai::class);
        self::assertInstanceOf(SeuilEssaiRepository::class, $repository);

        return $repository;
    }

    private function manager(): EntityManagerInterface
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Compte;

use App\AdminInterne\Compte\SupervisionComptes;
use App\BackOffice\Entreprise\Entreprise;
use App\BackOffice\Entreprise\EntrepriseRepository;
use App\BackOffice\Entreprise\FicheEntreprise;
use App\BackOffice\Entreprise\Siret;
use App\BackOffice\Entreprise\StatutRge;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\Compte\Compte;
use App\Compte\CompteRepository;
use App\Compte\Formule;
use App\Compte\ParametresFormule;
use App\Compte\ReglagesFormuleRepository;
use App\Tenant\TenantRepository;
use App\Tests\BackOffice\Rapport\InstantaneRapportMother;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : la vue de pilotage assemble, par compte, l'identité, la
 * formule et l'état d'essai (temps ET quota) — même modèle que le suivi artisan.
 */
final class SupervisionComptesTest extends KernelTestCase
{
    public function testAssembleIdentiteFormuleEtEtatDEssaiParCompte(): void
    {
        self::bootKernel();
        // Quota Classique ramené à 2 pour le test.
        $this->reglages()->enregistrer(Formule::Classique, new ParametresFormule(60, 2, null));

        $t1 = $this->tenants()->nouveau()->id();
        $t2 = $this->tenants()->nouveau()->id();
        self::assertNotNull($t1);
        self::assertNotNull($t2);
        $this->comptes()->enregistrer(Compte::creer($t1, new \DateTimeImmutable()));
        $this->comptes()->enregistrer(Compte::creer($t2, new \DateTimeImmutable()));

        $this->seedEntreprise('SARL Toiture Occitane', $t1);
        $this->seedRapport('2026-A1', $t1);
        $this->seedRapport('2026-A2', $t1);
        $this->seedRapport('2026-A3', $t1);
        $this->seedRapport('2026-B1', $t2);

        $comptes = $this->supervision()->comptes();

        self::assertCount(2, $comptes);
        [$c1, $c2] = $comptes;

        self::assertSame('SARL Toiture Occitane', $c1->identite());
        self::assertSame(Formule::Classique, $c1->formule());
        self::assertSame(3, $c1->essai()->rapportsConsommes());
        self::assertTrue($c1->essai()->termine(), 'Quota 2 dépassé (3 rapports) → essai terminé.');

        self::assertSame('Compte #'.$t2, $c2->identite());
        self::assertSame(1, $c2->essai()->rapportsConsommes());
        self::assertTrue($c2->essai()->enEssai());
    }

    public function testLeBilanPorteLEtatDeSuspension(): void
    {
        self::bootKernel();
        $t1 = $this->tenants()->nouveau()->id();
        $t2 = $this->tenants()->nouveau()->id();
        self::assertNotNull($t1);
        self::assertNotNull($t2);

        $suspendu = Compte::creer($t1, new \DateTimeImmutable());
        $suspendu->suspendre(new \DateTimeImmutable('2026-08-20 10:00:00'));
        $this->comptes()->enregistrer($suspendu);
        $this->comptes()->enregistrer(Compte::creer($t2, new \DateTimeImmutable()));

        [$c1, $c2] = $this->supervision()->comptes();

        self::assertTrue($c1->suspendu(), 'Le compte suspendu doit être signalé.');
        self::assertFalse($c2->suspendu());
    }

    private function supervision(): SupervisionComptes
    {
        $supervision = self::getContainer()->get(SupervisionComptes::class);
        self::assertInstanceOf(SupervisionComptes::class, $supervision);

        return $supervision;
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

    private function comptes(): CompteRepository
    {
        $repository = self::getContainer()->get(CompteRepository::class);
        self::assertInstanceOf(CompteRepository::class, $repository);

        return $repository;
    }

    private function reglages(): ReglagesFormuleRepository
    {
        $repository = self::getContainer()->get(ReglagesFormuleRepository::class);
        self::assertInstanceOf(ReglagesFormuleRepository::class, $repository);

        return $repository;
    }
}

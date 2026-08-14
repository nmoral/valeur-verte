<?php

declare(strict_types=1);

namespace App\Tests\Compte;

use App\BackOffice\Rapport\Contact;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\Compte\Compte;
use App\Compte\CompteRepository;
use App\Compte\SuiviCompte;
use App\Tenant\TenantContext;
use App\Tests\BackOffice\Rapport\InstantaneRapportMother;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Suivi d'essai côté artisan (#108) : pour le tenant courant, l'état d'essai
 * (rapports/jours restants) et le bilan d'usage (rapports générés, contacts
 * capturés) — le chiffre qui fait la vente à la place du commercial.
 */
final class SuiviCompteTest extends KernelTestCase
{
    private const TENANT = 9500;

    public function testIlDonneLEssaiEtLeBilanDuTenantCourant(): void
    {
        self::bootKernel();
        $this->comptes()->enregistrer(Compte::creer(self::TENANT, new \DateTimeImmutable()));
        $this->seedRapport('2026-B1', avecContact: true);
        $this->seedRapport('2026-B2', avecContact: true);
        $this->seedRapport('2026-B3', avecContact: false);
        $this->tenantCourant(self::TENANT);

        $suivi = $this->suivi();

        $essai = $suivi->essaiCourant();
        self::assertNotNull($essai);
        // Classique : quota 10, 3 consommés → 7 restants ; essai non expiré (compte neuf).
        self::assertSame(7, $essai->rapportsRestants());
        self::assertTrue($essai->enEssai());

        $bilan = $suivi->bilanCourant();
        self::assertNotNull($bilan);
        self::assertSame(3, $bilan->rapportsGeneres());
        self::assertSame(2, $bilan->contactsCaptures());
    }

    public function testSansCompteAucunSuivi(): void
    {
        self::bootKernel();
        $this->tenantCourant(999999);

        self::assertNull($this->suivi()->essaiCourant());
        self::assertNull($this->suivi()->bilanCourant());
    }

    private function suivi(): SuiviCompte
    {
        $suivi = self::getContainer()->get(SuiviCompte::class);
        self::assertInstanceOf(SuiviCompte::class, $suivi);

        return $suivi;
    }

    private function comptes(): CompteRepository
    {
        $repository = self::getContainer()->get(CompteRepository::class);
        self::assertInstanceOf(CompteRepository::class, $repository);

        return $repository;
    }

    private function tenantCourant(int $tenantId): void
    {
        $contexte = self::getContainer()->get(TenantContext::class);
        self::assertInstanceOf(TenantContext::class, $contexte);
        $contexte->definir($tenantId);
    }

    private function seedRapport(string $reference, bool $avecContact): void
    {
        $repository = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repository);
        $contact = $avecContact ? Contact::creer('Mme Client', 'client@example.com', null) : null;
        $rapport = Rapport::creer(
            $reference,
            new \DateTimeImmutable('2026-08-01 09:00:00'),
            Source::Audit,
            InstantaneRapportMother::creer(adresse: 'Baziège'),
            '/rapports/'.$reference.'.pdf',
            $contact,
        );
        $rapport->setTenantId(self::TENANT);
        $repository->enregistrer($rapport);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Compte\Export;

use App\AdminInterne\JournalAudit\JournalAuditEnMemoire;
use App\BackOffice\Bouquet\BouquetRepository;
use App\BackOffice\Branding\BrandingRepository;
use App\BackOffice\Entreprise\EntrepriseRepository;
use App\BackOffice\GrilleCout\GrilleCoutRepository;
use App\BackOffice\Rapport\RapportRepository;
use App\Compte\Compte;
use App\Compte\CompteRepository;
use App\Compte\Export\ExportDonneesCompte;
use App\Compte\Export\GenererExportCompte;
use App\Security\UserRepository;
use App\Tenant\TenantRepository;
use App\Widget\DemandeLeadRepository;
use App\Widget\PagePubliqueRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Intégration : générer un export (#309) rend le document et **trace** l'acte
 * au journal d'audit (`rgpd.export_genere`) — la trace qui, côté admin, vaut
 * non-silence de l'accès inter-tenant.
 */
final class GenererExportCompteTest extends KernelTestCase
{
    public function testGenererRendLeDocumentEtTraceLActe(): void
    {
        self::bootKernel();
        $journal = new JournalAuditEnMemoire();
        $clock = new MockClock(new \DateTimeImmutable('2026-08-25 12:00:00'));
        $generer = new GenererExportCompte($this->assembleur($clock), $journal, $clock);

        $tenantId = $this->repo(TenantRepository::class)->nouveau()->id();
        self::assertNotNull($tenantId);
        $this->repo(CompteRepository::class)->enregistrer(
            Compte::creer($tenantId, new \DateTimeImmutable('2026-01-01')),
        );

        $document = $generer->pour($tenantId, 'admin@example.com');

        self::assertSame($tenantId, $document['tenant_id']);

        $evenement = $journal->evenementsRecents()[0];
        self::assertSame('rgpd.export_genere', $evenement->action());
        self::assertSame('compte#'.$tenantId, $evenement->cible());
        self::assertSame('admin@example.com', $evenement->acteur());
        self::assertSame('json', $evenement->contexte()['format']);
    }

    private function assembleur(MockClock $clock): ExportDonneesCompte
    {
        return new ExportDonneesCompte(
            $this->repo(CompteRepository::class),
            $this->repo(UserRepository::class),
            $this->repo(EntrepriseRepository::class),
            $this->repo(GrilleCoutRepository::class),
            $this->repo(BouquetRepository::class),
            $this->repo(BrandingRepository::class),
            $this->repo(PagePubliqueRepository::class),
            $this->repo(RapportRepository::class),
            $this->repo(DemandeLeadRepository::class),
            $clock,
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function repo(string $classe): object
    {
        $service = self::getContainer()->get($classe);
        self::assertInstanceOf($classe, $service);

        return $service;
    }
}

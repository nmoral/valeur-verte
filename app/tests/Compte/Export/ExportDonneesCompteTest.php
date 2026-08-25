<?php

declare(strict_types=1);

namespace App\Tests\Compte\Export;

use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Bouquet\BouquetRepository;
use App\BackOffice\Branding\BrandingRepository;
use App\BackOffice\Entreprise\Entreprise;
use App\BackOffice\Entreprise\EntrepriseRepository;
use App\BackOffice\Entreprise\FicheEntreprise;
use App\BackOffice\Entreprise\Siret;
use App\BackOffice\Entreprise\StatutRge;
use App\BackOffice\GrilleCout\GrilleCout;
use App\BackOffice\GrilleCout\GrilleCoutRepository;
use App\BackOffice\GrilleCout\PeriodeValidite;
use App\BackOffice\Rapport\Contact;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\Compte\Compte;
use App\Compte\CompteRepository;
use App\Compte\Export\ExportDonneesCompte;
use App\Security\User;
use App\Security\UserRepository;
use App\Tenant\TenantRepository;
use App\Tests\BackOffice\Rapport\InstantaneRapportMother;
use App\Widget\DemandeLead;
use App\Widget\DemandeLeadRepository;
use App\Widget\PagePublique;
use App\Widget\PagePubliqueRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Intégration : l'export d'un compte (#309, RGPD accès/portabilité) rassemble
 * tout le périmètre du tenant — compte, utilisateur(s), fiche entreprise,
 * réglages (grille, bouquets, marque, page publique), rapports et leads — sans
 * déborder sur un autre tenant. Miroir en lecture de l'effacement (#308).
 */
final class ExportDonneesCompteTest extends KernelTestCase
{
    private ExportDonneesCompte $export;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->export = new ExportDonneesCompte(
            $this->repo(CompteRepository::class),
            $this->repo(UserRepository::class),
            $this->repo(EntrepriseRepository::class),
            $this->repo(GrilleCoutRepository::class),
            $this->repo(BouquetRepository::class),
            $this->repo(BrandingRepository::class),
            $this->repo(PagePubliqueRepository::class),
            $this->repo(RapportRepository::class),
            $this->repo(DemandeLeadRepository::class),
            new MockClock(new \DateTimeImmutable('2026-08-25 12:00:00')),
        );
    }

    public function testExporteToutLePerimetreDuTenant(): void
    {
        $tenantId = $this->semerTenantComplet('victime');

        $document = $this->export->exporter($tenantId);

        self::assertSame(ExportDonneesCompte::SCHEMA, $document['schema']);
        self::assertSame('2026-08-25T12:00:00+00:00', $document['genere_le']);
        self::assertSame($tenantId, $document['tenant_id']);

        $compte = $document['compte'];
        self::assertIsArray($compte);
        self::assertSame('classique', $compte['formule']);

        $utilisateurs = $document['utilisateurs'];
        self::assertIsArray($utilisateurs);
        self::assertCount(1, $utilisateurs);
        $premierUser = $utilisateurs[0];
        self::assertIsArray($premierUser);
        self::assertSame('victime@example.com', $premierUser['email']);

        $entreprise = $document['entreprise'];
        self::assertIsArray($entreprise);
        self::assertSame('SARL victime', $entreprise['raison_sociale']);
        self::assertSame('rge', $entreprise['statut_rge']);

        $reglages = $document['reglages'];
        self::assertIsArray($reglages);
        self::assertIsArray($reglages['grille_couts']);
        self::assertCount(7, $reglages['grille_couts'], 'les sept postes de la grille');
        $bouquets = $reglages['bouquets'];
        self::assertIsArray($bouquets);
        self::assertNotEmpty($bouquets);
        self::assertIsArray($bouquets[0]);
        self::assertSame(Bouquet::isolationSeule()->nom(), $bouquets[0]['nom']);
        self::assertIsArray($reglages['marque']);
        $page = $reglages['page_publique'];
        self::assertIsArray($page);
        self::assertSame('agence-victime', $page['slug']);

        $rapports = $document['rapports'];
        self::assertIsArray($rapports);
        self::assertCount(1, $rapports);
        $rapport = $rapports[0];
        self::assertIsArray($rapport);
        self::assertSame('REF-victime', $rapport['reference']);
        self::assertSame('audit', $rapport['source']);
        $contact = $rapport['contact'];
        self::assertIsArray($contact);
        self::assertSame('Client Victime', $contact['nom']);
        self::assertIsArray($rapport['instantane']);

        $leads = $document['leads_widget'];
        self::assertIsArray($leads);
        self::assertCount(1, $leads);
        self::assertIsArray($leads[0]);
        self::assertSame('client-victime@example.com', $leads[0]['contact_email']);
    }

    public function testNeFuitPasLesDonneesDunAutreTenant(): void
    {
        $victime = $this->semerTenantComplet('victime');
        $this->semerTenantComplet('temoin');

        $document = $this->export->exporter($victime);

        $utilisateurs = $document['utilisateurs'];
        self::assertIsArray($utilisateurs);
        self::assertSame(['victime@example.com'], array_column($utilisateurs, 'email'));
        $rapports = $document['rapports'];
        self::assertIsArray($rapports);
        self::assertSame(['REF-victime'], array_column($rapports, 'reference'));
    }

    public function testExporteUnTenantVideSansEchouer(): void
    {
        $tenantId = $this->repo(TenantRepository::class)->nouveau()->id();
        self::assertNotNull($tenantId);

        $document = $this->export->exporter($tenantId);

        self::assertNull($document['compte']);
        self::assertNull($document['entreprise']);
        self::assertSame([], $document['utilisateurs']);
        self::assertSame([], $document['rapports']);
        self::assertSame([], $document['leads_widget']);

        $reglages = $document['reglages'];
        self::assertIsArray($reglages);
        self::assertNull($reglages['grille_couts']);
        self::assertNull($reglages['page_publique']);
        self::assertSame([], $reglages['bouquets']);
        // La marque retombe sur le défaut, jamais nulle.
        self::assertIsArray($reglages['marque']);
    }

    private function semerTenantComplet(string $slug): int
    {
        $tenantId = $this->repo(TenantRepository::class)->nouveau()->id();
        self::assertNotNull($tenantId);
        $nom = ucfirst($slug);

        $this->repo(CompteRepository::class)->enregistrer(
            Compte::creer($tenantId, new \DateTimeImmutable('2026-01-01')),
        );

        $entreprise = Entreprise::depuisFiche(FicheEntreprise::creer(
            Siret::depuis('12345678901234'),
            'SARL '.$slug,
            '12 rue des Lilas, 31000 Toulouse',
            '43.29A',
            '6 à 9 salariés',
            StatutRge::Rge,
            ['Isolation des murs'],
        ));
        $entreprise->setTenantId($tenantId);
        $this->repo(EntrepriseRepository::class)->enregistrer($entreprise);

        $user = new User($slug.'@example.com');
        $user->rattacherAuTenant($tenantId);
        $this->repo(UserRepository::class)->enregistrer($user);

        $this->repo(GrilleCoutRepository::class)->provisionner(
            $tenantId,
            GrilleCout::parDefautNationale(PeriodeValidite::depuis(new \DateTimeImmutable('2026-01-01'))),
        );
        $this->repo(BouquetRepository::class)->provisionner($tenantId, Bouquet::isolationSeule());
        $this->repo(BrandingRepository::class)->provisionner($tenantId);
        $this->repo(PagePubliqueRepository::class)->enregistrer(
            PagePublique::creer($tenantId, 'jeton-'.$slug, 'agence-'.$slug),
        );

        $rapport = Rapport::creer(
            'REF-'.$slug,
            new \DateTimeImmutable('2026-08-01 09:00:00'),
            Source::Audit,
            InstantaneRapportMother::creer(adresse: 'Baziège'),
            '/rapports/'.$slug.'.pdf',
            Contact::creer('Client '.$nom, 'contact-'.$slug.'@example.com', '0600000000'),
        );
        $rapport->setTenantId($tenantId);
        $this->repo(RapportRepository::class)->enregistrer($rapport);

        $this->repo(DemandeLeadRepository::class)->enregistrer(DemandeLead::creer(
            $tenantId,
            'empreinte-'.$slug,
            new \DateTimeImmutable('2026-12-31 00:00:00'),
            'Client '.$nom,
            'client-'.$slug.'@example.com',
            ['adresse' => 'Baziège'],
            new \DateTimeImmutable('2026-08-01 09:00:00'),
        ));

        return $tenantId;
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

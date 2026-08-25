<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Compte;

use App\AdminInterne\Compte\EffacementCompte;
use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAuditEnMemoire;
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
use App\Compte\EmpreinteEffacement;
use App\Compte\ReglagesFormuleRepository;
use App\Compte\TraceEffacementRepository;
use App\Security\AdresseEmail;
use App\Security\User;
use App\Security\UserRepository;
use App\Tenant\TenantRepository;
use App\Tests\BackOffice\Rapport\InstantaneRapportMother;
use App\Widget\DemandeLead;
use App\Widget\DemandeLeadRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Intégration : l'effacement définitif d'un compte (#308, RGPD) supprime tout le
 * périmètre du tenant — compte, fiche entreprise, utilisateur(s), rapports, leads
 * et l'espace lui-même — sans déborder sur un autre tenant, et trace l'acte au
 * journal d'audit (qui, lui, n'est jamais effacé).
 */
final class EffacementCompteTest extends KernelTestCase
{
    private const SECRET = 'secret-test-332';

    private JournalAuditEnMemoire $journal;
    private EmpreinteEffacement $empreintes;
    private EffacementCompte $effacement;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->journal = new JournalAuditEnMemoire();
        $this->empreintes = new EmpreinteEffacement(self::SECRET);
        $this->effacement = new EffacementCompte(
            $this->repo(RapportRepository::class),
            $this->repo(DemandeLeadRepository::class),
            $this->repo(EntrepriseRepository::class),
            $this->repo(UserRepository::class),
            $this->repo(CompteRepository::class),
            $this->repo(TenantRepository::class),
            $this->journal,
            new MockClock(new \DateTimeImmutable('2026-08-23 10:00:00')),
            30,
            $this->repo(TraceEffacementRepository::class),
            $this->empreintes,
            $this->repo(ReglagesFormuleRepository::class),
            $this->connection(),
        );
    }

    public function testEffacerEcritLaTraceDansLaMemeTransactionSansAdresseEnClair(): void
    {
        // « victime@example.com » : la forme canonique est identique (pas d'alias,
        // domaine hors Gmail). Le compte est créé le 2026-01-01 en formule Classique
        // (60 jours d'essai), avec 1 rapport produit.
        $tenantId = $this->semerTenantComplet('victime');

        $this->effacement->effacer($tenantId, 'admin@example.com');
        $this->viderIdentityMap();

        $empreinte = $this->empreintes->pour(AdresseEmail::depuis('victime@example.com')->canonique());
        $trace = $this->repo(TraceEffacementRepository::class)->parEmpreinte($empreinte);

        self::assertNotNull($trace, 'une trace doit être écrite à l\'effacement');
        self::assertSame(1, $trace->rapportsConsommes());
        self::assertEquals(new \DateTimeImmutable('2026-03-02 00:00:00'), $trace->finEssai());
        self::assertSame($empreinte, $trace->empreinte());
        self::assertStringNotContainsString('victime@example.com', $trace->empreinte());
    }

    public function testReeffacerLaMemeAdresseMetAJourLaTraceSansCasser(): void
    {
        // Compte effacé, adresse libérée, réinscrite sur un nouveau tenant, puis
        // ré-effacée : la trace (unique par empreinte) est mise à jour, pas dupliquée.
        $premier = $this->semerTenantComplet('victime');
        $this->effacement->effacer($premier, 'admin@example.com');
        $this->viderIdentityMap();

        $second = $this->semerTenantComplet('victime');
        $this->effacement->effacer($second, 'admin@example.com');
        $this->viderIdentityMap();

        $empreinte = $this->empreintes->pour(AdresseEmail::depuis('victime@example.com')->canonique());
        $lignes = $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM trace_effacement WHERE empreinte = ?',
            [$empreinte],
        );
        self::assertIsNumeric($lignes);
        self::assertSame(1, (int) $lignes, 'une seule trace par empreinte');
    }

    public function testEffacerSupprimeToutLePerimetreDuTenantEtLaisseLesAutresIntacts(): void
    {
        $victime = $this->semerTenantComplet('victime');
        $temoin = $this->semerTenantComplet('temoin');

        $this->effacement->effacer($victime, 'admin@example.com');
        $this->viderIdentityMap();

        foreach (['compte', 'entreprise', 'utilisateur', 'rapport', 'demande_lead'] as $table) {
            self::assertSame(0, $this->compter($table, $victime), "$table doit être vide pour le tenant effacé");
            self::assertSame(1, $this->compter($table, $temoin), "$table doit rester pour le tenant témoin");
        }
        self::assertSame(0, $this->compterTenant($victime), 'l\'espace du tenant effacé doit disparaître');
        self::assertSame(1, $this->compterTenant($temoin), 'l\'espace du tenant témoin doit rester');

        $actions = array_map(
            static fn (EvenementAudit $e): string => $e->action(),
            $this->journal->evenementsRecents(),
        );
        self::assertContains('compte.efface', $actions);
        self::assertSame('compte#'.$victime, $this->journal->evenementsRecents()[0]->cible());
        self::assertSame('admin@example.com', $this->journal->evenementsRecents()[0]->acteur());
    }

    public function testEffacerLesComptesExigiblesNeToucheQueCeuxHorsDelaiDeGrace(): void
    {
        // Grâce de 30 j, horloge au 2026-08-23 → borne d'exigibilité au 2026-07-24.
        $horsDelai = $this->semerCompteAvecDemande(new \DateTimeImmutable('2026-07-14 09:00:00'));
        $dansDelai = $this->semerCompteAvecDemande(new \DateTimeImmutable('2026-08-13 09:00:00'));
        $sansDemande = $this->repo(TenantRepository::class)->nouveau()->id();
        self::assertNotNull($sansDemande);
        $this->repo(CompteRepository::class)->enregistrer(Compte::creer($sansDemande, new \DateTimeImmutable('2026-01-01')));

        $bilan = $this->effacement->effacerLesComptesExigibles('systeme');
        $this->viderIdentityMap();

        self::assertSame(1, $bilan->comptesEffaces());
        self::assertSame(0, $this->compter('compte', $horsDelai), 'le compte hors délai de grâce doit être effacé');
        self::assertSame(1, $this->compter('compte', $dansDelai), 'le compte encore dans le délai de grâce doit rester');
        self::assertSame(1, $this->compter('compte', $sansDemande), 'un compte sans demande ne doit jamais être effacé');

        $actions = array_map(
            static fn (EvenementAudit $e): string => $e->action(),
            $this->journal->evenementsRecents(),
        );
        self::assertSame(['compte.efface'], $actions);
        self::assertSame('compte#'.$horsDelai, $this->journal->evenementsRecents()[0]->cible());
    }

    private function semerCompteAvecDemande(\DateTimeImmutable $demandeLe): int
    {
        $tenantId = $this->repo(TenantRepository::class)->nouveau()->id();
        self::assertNotNull($tenantId);
        $compte = Compte::creer($tenantId, new \DateTimeImmutable('2026-01-01'));
        $compte->demanderSuppression($demandeLe);
        $this->repo(CompteRepository::class)->enregistrer($compte);

        return $tenantId;
    }

    private function semerTenantComplet(string $slug): int
    {
        $tenantId = $this->repo(TenantRepository::class)->nouveau()->id();
        self::assertNotNull($tenantId);

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

        $rapport = Rapport::creer(
            'REF-'.$slug,
            new \DateTimeImmutable('2026-08-01 09:00:00'),
            Source::Audit,
            InstantaneRapportMother::creer(adresse: 'Baziège'),
            '/rapports/'.$slug.'.pdf',
        );
        $rapport->setTenantId($tenantId);
        $this->repo(RapportRepository::class)->enregistrer($rapport);

        $this->repo(DemandeLeadRepository::class)->enregistrer(DemandeLead::creer(
            $tenantId,
            'empreinte-'.$slug,
            new \DateTimeImmutable('2026-12-31 00:00:00'),
            'Client '.$slug,
            'client-'.$slug.'@example.com',
            ['adresse' => 'Baziège'],
            new \DateTimeImmutable('2026-08-01 09:00:00'),
        ));

        return $tenantId;
    }

    private function compter(string $table, int $tenantId): int
    {
        $valeur = $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM '.$table.' WHERE tenant_id = ?',
            [$tenantId],
        );
        self::assertIsNumeric($valeur);

        return (int) $valeur;
    }

    private function compterTenant(int $tenantId): int
    {
        $valeur = $this->connection()->fetchOne('SELECT COUNT(*) FROM tenant WHERE id = ?', [$tenantId]);
        self::assertIsNumeric($valeur);

        return (int) $valeur;
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

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function viderIdentityMap(): void
    {
        $this->entityManager()->clear();
    }
}

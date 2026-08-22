<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Tenant\TenantRepository;
use App\Widget\EvenementWidget;
use App\Widget\EvenementWidgetRepository;
use App\Widget\PagePublique;
use App\Widget\TypeEvenementWidget;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Comptage des événements d'une page sur une fenêtre glissante (#268, épic #6).
 *
 * C'est la mesure brute de l'anti-abus **réactif** : combien d'estimations, de
 * leads et de rejets une page a produits depuis telle borne. La détection de pics
 * s'y appuie, donc le comptage doit être strictement borné à la page visée et à la
 * fenêtre — un pic voisin ou un trafic d'hier ne doivent jamais s'y mêler.
 */
final class ComptageEvenementsWidgetTest extends KernelTestCase
{
    private const string SESSION = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    public function testLesEvenementsSontComptesParType(): void
    {
        self::bootKernel();
        $page = $this->creerPage();
        $this->journaliser($page, TypeEvenementWidget::EstimationAffichee, '2026-08-22 10:00:00');
        $this->journaliser($page, TypeEvenementWidget::EstimationAffichee, '2026-08-22 10:01:00');
        $this->journaliser($page, TypeEvenementWidget::LeadSoumis, '2026-08-22 10:02:00');

        $comptes = $this->evenements()->compterParTypeDepuis($page->token(), new \DateTimeImmutable('2026-08-22 09:00:00'));

        self::assertSame(2, $comptes[TypeEvenementWidget::EstimationAffichee->value] ?? 0);
        self::assertSame(1, $comptes[TypeEvenementWidget::LeadSoumis->value] ?? 0);
    }

    public function testLesEvenementsAnterieursALaFenetreNeComptentPas(): void
    {
        self::bootKernel();
        $page = $this->creerPage();
        $this->journaliser($page, TypeEvenementWidget::EstimationAffichee, '2026-08-22 08:59:59');
        $this->journaliser($page, TypeEvenementWidget::EstimationAffichee, '2026-08-22 09:00:01');

        $comptes = $this->evenements()->compterParTypeDepuis($page->token(), new \DateTimeImmutable('2026-08-22 09:00:00'));

        self::assertSame(1, $comptes[TypeEvenementWidget::EstimationAffichee->value] ?? 0);
    }

    public function testLeComptageNeMelangePasLesPages(): void
    {
        self::bootKernel();
        $page = $this->creerPage();
        $voisine = $this->creerPage();
        $this->journaliser($page, TypeEvenementWidget::EstimationAffichee, '2026-08-22 10:00:00');
        $this->journaliser($voisine, TypeEvenementWidget::EstimationAffichee, '2026-08-22 10:00:00');
        $this->journaliser($voisine, TypeEvenementWidget::EstimationAffichee, '2026-08-22 10:00:30');

        $comptes = $this->evenements()->compterParTypeDepuis($page->token(), new \DateTimeImmutable('2026-08-22 09:00:00'));

        self::assertSame(1, $comptes[TypeEvenementWidget::EstimationAffichee->value] ?? 0);
    }

    public function testUnePageSansTraficNeCompteRien(): void
    {
        self::bootKernel();
        $page = $this->creerPage();

        self::assertSame([], $this->evenements()->compterParTypeDepuis($page->token(), new \DateTimeImmutable('2026-08-22 09:00:00')));
    }

    /**
     * Les rejets des contrôles préventifs (#43) sont journalisés comme des étapes à
     * part entière : c'est d'eux que se lit un taux d'échec anormal. Aucun n'est
     * déclarable depuis le navigateur — sinon on fabriquerait de fausses alertes.
     */
    public function testLesRejetsAntiAbusSontDesEvenementsNonDeclarablesParLeVisiteur(): void
    {
        $rejets = [
            TypeEvenementWidget::PlafondAtteint,
            TypeEvenementWidget::PiegeDeclenche,
            TypeEvenementWidget::TurnstileEchoue,
        ];

        foreach ($rejets as $rejet) {
            self::assertTrue($rejet->estRejet(), $rejet->value.' doit compter comme un rejet');
            self::assertFalse($rejet->estDeclarableParLeVisiteur(), $rejet->value.' ne doit pas être déclarable par le visiteur');
        }

        self::assertFalse(TypeEvenementWidget::EstimationAffichee->estRejet());
    }

    private function journaliser(PagePublique $page, TypeEvenementWidget $type, string $quand): void
    {
        $this->evenements()->enregistrer(EvenementWidget::survenu(
            $page->tenantIdProprietaire(),
            $page->token(),
            self::SESSION,
            $type,
            new \DateTimeImmutable($quand),
        ));
    }

    private function evenements(): EvenementWidgetRepository
    {
        $registry = static::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return new EvenementWidgetRepository($registry);
    }

    private function creerPage(): PagePublique
    {
        $tenants = static::getContainer()->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $page = PagePublique::creer($tenantId, bin2hex(random_bytes(5)), 'agence-durand');
        $em->persist($page);
        $em->flush();

        return $page;
    }
}

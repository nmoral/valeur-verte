<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Tenant\TenantRepository;
use App\Widget\EvenementWidgetRepository;
use App\Widget\JournalParcoursWidget;
use App\Widget\PagePublique;
use App\Widget\SurveillanceAntiAbusWidget;
use App\Widget\TypeEvenementWidget;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Journal du parcours widget (#90, épic #6) : la brique de collecte.
 *
 * Chaque étape franchie sur une page publique laisse un événement, rattaché à la
 * page (son token), à la visite (identifiant de session anonyme) et au tenant
 * propriétaire — c'est ce rattachement qui rendra possibles, plus tard, le funnel
 * du pro (#5) et l'analytics interne (#7). L'**abandon** ne s'écrit pas : il se
 * lit comme l'absence de l'événement suivant.
 *
 * L'horodatage vient de l'horloge applicative (jamais de `new DateTime` interne),
 * et la charge utile reste minimale et non personnelle.
 */
final class JournalParcoursWidgetTest extends KernelTestCase
{
    private const string SESSION = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    public function testUneEtapeFranchieLaisseUnEvenementRattacheALaPageEtAuTenant(): void
    {
        self::bootKernel();
        [$page, $tenantId] = $this->creerPage();
        $horloge = new MockClock(new \DateTimeImmutable('2026-08-20 14:30:00'));
        $journal = new JournalParcoursWidget($this->evenements(), $horloge, $this->surveillance());

        $journal->enregistrer($page, self::SESSION, TypeEvenementWidget::WidgetCharge);

        $evenements = $this->evenements()->pourSession(self::SESSION);
        self::assertCount(1, $evenements);
        self::assertSame($page->token(), $evenements[0]->token());
        self::assertSame(self::SESSION, $evenements[0]->sessionId());
        self::assertSame(TypeEvenementWidget::WidgetCharge, $evenements[0]->type());
        self::assertSame($tenantId, $evenements[0]->getTenantId());
        self::assertEquals(new \DateTimeImmutable('2026-08-20 14:30:00'), $evenements[0]->survenuLe());
        self::assertSame([], $evenements[0]->donnees());
    }

    public function testLesEtapesDUneMemeVisiteSeLisentDansLOrdreDuParcours(): void
    {
        self::bootKernel();
        [$page] = $this->creerPage();
        $horloge = new MockClock(new \DateTimeImmutable('2026-08-20 14:30:00'));
        $journal = new JournalParcoursWidget($this->evenements(), $horloge, $this->surveillance());

        $journal->enregistrer($page, self::SESSION, TypeEvenementWidget::WidgetCharge);
        $horloge->sleep(12);
        $journal->enregistrer($page, self::SESSION, TypeEvenementWidget::EstimationAffichee, ['disponible' => true]);

        $types = array_map(
            static fn ($evenement) => $evenement->type(),
            $this->evenements()->pourSession(self::SESSION),
        );
        self::assertSame(
            [TypeEvenementWidget::WidgetCharge, TypeEvenementWidget::EstimationAffichee],
            $types,
        );
    }

    public function testLaChargeUtileMinimaleEstConservee(): void
    {
        self::bootKernel();
        [$page] = $this->creerPage();
        $journal = new JournalParcoursWidget($this->evenements(), new MockClock(), $this->surveillance());

        $journal->enregistrer($page, self::SESSION, TypeEvenementWidget::EstimationAffichee, ['disponible' => false]);

        self::assertSame(['disponible' => false], $this->evenements()->pourSession(self::SESSION)[0]->donnees());
    }

    public function testLesVisitesNeSeMelangentPas(): void
    {
        self::bootKernel();
        [$page] = $this->creerPage();
        $journal = new JournalParcoursWidget($this->evenements(), new MockClock(), $this->surveillance());
        $autreSession = '0f1e2d3c4b5a69788796a5b4c3d2e1f0';

        $journal->enregistrer($page, self::SESSION, TypeEvenementWidget::WidgetCharge);
        $journal->enregistrer($page, $autreSession, TypeEvenementWidget::WidgetCharge);

        self::assertCount(1, $this->evenements()->pourSession(self::SESSION));
        self::assertCount(1, $this->evenements()->pourSession($autreSession));
    }

    /**
     * La surveillance anti-abus (#268) est branchée sur la collecte : le journal ne
     * se construit plus sans elle. Ici on prend celle du conteneur — ses seuils de
     * production laissent passer les quelques événements de ces scénarios.
     */
    private function surveillance(): SurveillanceAntiAbusWidget
    {
        $surveillance = static::getContainer()->get(SurveillanceAntiAbusWidget::class);
        self::assertInstanceOf(SurveillanceAntiAbusWidget::class, $surveillance);

        return $surveillance;
    }

    private function evenements(): EvenementWidgetRepository
    {
        $registry = static::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return new EvenementWidgetRepository($registry);
    }

    /**
     * @return array{0: PagePublique, 1: int}
     */
    private function creerPage(): array
    {
        $conteneur = static::getContainer();

        $tenants = $conteneur->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);

        $em = $conteneur->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $page = PagePublique::creer($tenantId, bin2hex(random_bytes(5)), 'agence-durand');
        $em->persist($page);
        $em->flush();

        return [$page, $tenantId];
    }
}

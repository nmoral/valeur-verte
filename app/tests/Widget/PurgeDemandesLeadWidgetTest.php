<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Tenant\TenantRepository;
use App\Widget\DemandeLead;
use App\Widget\DemandeLeadRepository;
use App\Widget\PurgeDemandesLeadWidget;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Rétention des demandes de lead du widget (#292, épic #6).
 *
 * `demande_lead` (#42) porte des données **nominatives** — nom, email, et
 * l'instantané figé qui contient l'adresse du bien. Une demande dont l'opt-in
 * n'a jamais été confirmé ne repose sur **aucun consentement confirmé** : passé
 * un délai de grâce après son expiration, elle est supprimée. Une demande
 * confirmée, elle, a déjà été matérialisée en Rapport (#37) qui porte la même
 * donnée : la ligne d'origine ne se conserve qu'un court délai de garde.
 *
 * Une demande **encore utilisable** n'est jamais touchée : la purge ne doit pas
 * casser un opt-in en cours de route.
 */
final class PurgeDemandesLeadWidgetTest extends KernelTestCase
{
    private const string MAINTENANT = '2026-08-20 12:00:00';
    private const int GRACE_JOURS = 7;
    private const int GARDE_JOURS = 30;

    public function testUneDemandeExpireeNonConfirmeeAuDelaDeLaGraceEstSupprimee(): void
    {
        self::bootKernel();
        $empreinte = $this->demandeExpirantLe('2026-08-01 09:00:00');

        $supprimees = $this->purge()->executer();

        self::assertSame(1, $supprimees);
        self::assertNull($this->demandes()->trouverParEmpreinte($empreinte));
    }

    public function testUneDemandeExpireeDansLeDelaiDeGraceEstConservee(): void
    {
        self::bootKernel();
        $empreinte = $this->demandeExpirantLe('2026-08-19 09:00:00');

        $supprimees = $this->purge()->executer();

        self::assertSame(0, $supprimees);
        self::assertNotNull($this->demandes()->trouverParEmpreinte($empreinte));
    }

    public function testLaBorneDeGraceEstExacte(): void
    {
        // Juste à l'intérieur de la grâce : conservée. Une seconde plus tôt : purgée.
        self::bootKernel();
        $limite = new \DateTimeImmutable(self::MAINTENANT.' -'.self::GRACE_JOURS.' days');
        $gardee = $this->demandeExpirantLe($limite->modify('+1 second')->format('Y-m-d H:i:s'));
        $purgee = $this->demandeExpirantLe($limite->modify('-1 second')->format('Y-m-d H:i:s'));

        self::assertSame(1, $this->purge()->executer());
        self::assertNotNull($this->demandes()->trouverParEmpreinte($gardee));
        self::assertNull($this->demandes()->trouverParEmpreinte($purgee));
    }

    public function testUneDemandeEncoreUtilisableNEstJamaisSupprimee(): void
    {
        // L'opt-in est en cours : le visiteur peut encore cliquer son lien.
        self::bootKernel();
        $empreinte = $this->demandeExpirantLe('2026-08-21 09:00:00');

        self::assertSame(0, $this->purge()->executer());
        self::assertNotNull($this->demandes()->trouverParEmpreinte($empreinte));
    }

    public function testUneDemandeConfirmeeAuDelaDeLaGardeEstSupprimee(): void
    {
        // Le lead vit désormais dans un Rapport qui porte la même donnée : passé la
        // garde, la ligne d'origine est un doublon nominatif sans usage.
        self::bootKernel();
        $empreinte = $this->demandeConfirmeeLe('2026-07-01 09:00:00');

        $supprimees = $this->purge()->executer();

        self::assertSame(1, $supprimees);
        self::assertNull($this->demandes()->trouverParEmpreinte($empreinte));
    }

    public function testUneDemandeConfirmeeDansLeDelaiDeGardeEstConservee(): void
    {
        self::bootKernel();
        $empreinte = $this->demandeConfirmeeLe('2026-08-15 09:00:00');

        $supprimees = $this->purge()->executer();

        self::assertSame(0, $supprimees);
        self::assertNotNull($this->demandes()->trouverParEmpreinte($empreinte));
    }

    public function testLaBorneDeGardeEstExacte(): void
    {
        self::bootKernel();
        $limite = new \DateTimeImmutable(self::MAINTENANT.' -'.self::GARDE_JOURS.' days');
        $gardee = $this->demandeConfirmeeLe($limite->modify('+1 second')->format('Y-m-d H:i:s'));
        $purgee = $this->demandeConfirmeeLe($limite->modify('-1 second')->format('Y-m-d H:i:s'));

        self::assertSame(1, $this->purge()->executer());
        self::assertNotNull($this->demandes()->trouverParEmpreinte($gardee));
        self::assertNull($this->demandes()->trouverParEmpreinte($purgee));
    }

    private function purge(): PurgeDemandesLeadWidget
    {
        return new PurgeDemandesLeadWidget(
            $this->demandes(),
            new MockClock(new \DateTimeImmutable(self::MAINTENANT)),
            self::GRACE_JOURS,
            self::GARDE_JOURS,
        );
    }

    /**
     * Enregistre une demande non confirmée expirant à l'instant donné, et rend
     * l'empreinte qui permet de la retrouver.
     */
    private function demandeExpirantLe(string $expiration): string
    {
        $conteneur = static::getContainer();

        $tenants = $conteneur->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);

        $empreinte = hash('sha256', bin2hex(random_bytes(16)));
        $expireLe = new \DateTimeImmutable($expiration);

        $em = $conteneur->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist(DemandeLead::creer(
            $tenantId,
            $empreinte,
            $expireLe,
            'Martin',
            'martin@exemple.fr',
            [],
            $expireLe->modify('-1 day'),
        ));
        $em->flush();

        return $empreinte;
    }

    /**
     * Enregistre une demande déjà confirmée à l'instant donné (expiration dans la
     * foulée de sa création, comme en production), et rend son empreinte.
     */
    private function demandeConfirmeeLe(string $confirmation): string
    {
        $confirmeLe = new \DateTimeImmutable($confirmation);
        $empreinte = $this->demandeExpirantLe($confirmeLe->modify('+1 hour')->format('Y-m-d H:i:s'));

        $demande = $this->demandes()->trouverParEmpreinte($empreinte);
        self::assertNotNull($demande);
        $demande->marquerUtilise($confirmeLe);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->flush();

        return $empreinte;
    }

    private function demandes(): DemandeLeadRepository
    {
        $registry = static::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return new DemandeLeadRepository($registry);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Tenant\TenantRepository;
use App\Widget\EvenementWidget;
use App\Widget\EvenementWidgetRepository;
use App\Widget\PurgeEvenementsWidget;
use App\Widget\TypeEvenementWidget;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Rétention courte des événements de parcours (#90, épic #6).
 *
 * La collecte est déclarée pour une finalité de mesure d'audience et de
 * conversion : les événements ne sont pas conservés indéfiniment. Passé le délai
 * de rétention, ils sont **supprimés** — la purge est le pendant obligatoire de
 * la collecte, pas une option d'exploitation.
 */
final class PurgeEvenementsWidgetTest extends KernelTestCase
{
    private const int RETENTION_JOURS = 90;
    private const string MAINTENANT = '2026-08-20 12:00:00';

    public function testLesEvenementsAuDelaDeLaRetentionSontSupprimes(): void
    {
        self::bootKernel();
        $session = $this->session();
        $this->evenementSurvenuLe($session, '2026-05-01 09:00:00');

        $supprimes = $this->purge()->executer();

        self::assertSame(1, $supprimes);
        self::assertCount(0, $this->evenements()->pourSession($session));
    }

    public function testLesEvenementsDansLaFenetreDeRetentionSontConserves(): void
    {
        self::bootKernel();
        $session = $this->session();
        $this->evenementSurvenuLe($session, '2026-08-19 09:00:00');

        $supprimes = $this->purge()->executer();

        self::assertSame(0, $supprimes);
        self::assertCount(1, $this->evenements()->pourSession($session));
    }

    public function testLaBorneDeRetentionEstExacte(): void
    {
        // Juste à l'intérieur de la fenêtre : conservé. Une seconde plus tôt : purgé.
        self::bootKernel();
        $limite = new \DateTimeImmutable(self::MAINTENANT.' -'.self::RETENTION_JOURS.' days');
        $garde = $this->session();
        $purge = $this->session();
        $this->evenementSurvenuLe($garde, $limite->modify('+1 second')->format('Y-m-d H:i:s'));
        $this->evenementSurvenuLe($purge, $limite->modify('-1 second')->format('Y-m-d H:i:s'));

        self::assertSame(1, $this->purge()->executer());
        self::assertCount(1, $this->evenements()->pourSession($garde));
        self::assertCount(0, $this->evenements()->pourSession($purge));
    }

    public function testLaCommandeDePurgeSExecuteEtRendCompte(): void
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find('app:widget:purger-evenements'));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('événement', $tester->getDisplay());
    }

    private function purge(): PurgeEvenementsWidget
    {
        return new PurgeEvenementsWidget(
            $this->evenements(),
            new MockClock(new \DateTimeImmutable(self::MAINTENANT)),
            self::RETENTION_JOURS,
        );
    }

    private function evenementSurvenuLe(string $session, string $instant): void
    {
        $conteneur = static::getContainer();

        $tenants = $conteneur->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);

        $em = $conteneur->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist(EvenementWidget::survenu(
            $tenantId,
            bin2hex(random_bytes(5)),
            $session,
            TypeEvenementWidget::WidgetCharge,
            new \DateTimeImmutable($instant),
        ));
        $em->flush();
    }

    private function session(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function evenements(): EvenementWidgetRepository
    {
        $registry = static::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return new EvenementWidgetRepository($registry);
    }
}

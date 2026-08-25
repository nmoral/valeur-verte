<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoPresence;

use App\AdminInterne\SeoAudit\SourceUrls;
use App\AdminInterne\SeoPresence\CollecteurPresenceExterne;
use App\AdminInterne\SeoPresence\Command\CollecterPresenceSeoCommand;
use App\AdminInterne\SeoPresence\EtatIndexation;
use App\AdminInterne\SeoPresence\MetriquesRecherche;
use App\AdminInterne\SeoPresence\PageSpeed;
use App\AdminInterne\SeoPresence\RapportPresenceRepository;
use App\AdminInterne\SeoPresence\SearchConsole;
use App\AdminInterne\SeoPresence\WebVitals;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

final class CollecterPresenceSeoCommandTest extends KernelTestCase
{
    private function repository(): RapportPresenceRepository
    {
        self::bootKernel();
        $repository = static::getContainer()->get(RapportPresenceRepository::class);
        self::assertInstanceOf(RapportPresenceRepository::class, $repository);

        return $repository;
    }

    private function source(): SourceUrls
    {
        return new class implements SourceUrls {
            public function urls(): array
            {
                return ['https://valeur-verte.fr/'];
            }
        };
    }

    public function testCollecteConfigureePersisteUnSnapshot(): void
    {
        $repository = $this->repository();
        $sc = new class implements SearchConsole {
            public function metriques(\DateTimeImmutable $debut, \DateTimeImmutable $fin, int $maxRequetes = 10): MetriquesRecherche
            {
                return new MetriquesRecherche(100, 2000, 0.05, 6.5, []);
            }

            public function indexation(string $url): EtatIndexation
            {
                return new EtatIndexation($url, 'Submitted and indexed');
            }
        };
        $ps = new class implements PageSpeed {
            public function mesurer(string $url): WebVitals
            {
                return new WebVitals($url, 2000, 0.05, 180, 90);
            }
        };
        $collecteur = new CollecteurPresenceExterne($sc, $ps, $this->source(), new MockClock('2026-08-25 01:00:00'));

        $command = new CollecterPresenceSeoCommand($collecteur, $repository, new MockClock('2026-08-25 01:00:00'));
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));
        $dernier = $repository->dernier();
        self::assertNotNull($dernier);
        self::assertTrue($dernier->estConfigure());
        self::assertSame(100, $dernier->clics());
    }

    public function testCollecteNonConfigureeNePersisteRien(): void
    {
        $repository = $this->repository();
        $sc = new class implements SearchConsole {
            public function metriques(\DateTimeImmutable $debut, \DateTimeImmutable $fin, int $maxRequetes = 10): ?MetriquesRecherche
            {
                return null;
            }

            public function indexation(string $url): ?EtatIndexation
            {
                return null;
            }
        };
        $ps = new class implements PageSpeed {
            public function mesurer(string $url): ?WebVitals
            {
                return null;
            }
        };
        $collecteur = new CollecteurPresenceExterne($sc, $ps, $this->source(), new MockClock('2026-08-25 01:00:00'));

        $command = new CollecterPresenceSeoCommand($collecteur, $repository, new MockClock('2026-08-25 01:00:00'));
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('non configur', mb_strtolower($tester->getDisplay()));
        self::assertNull($repository->dernier());
    }
}

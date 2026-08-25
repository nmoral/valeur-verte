<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoPresence;

use App\AdminInterne\SeoAudit\SourceUrls;
use App\AdminInterne\SeoPresence\CollecteurPresenceExterne;
use App\AdminInterne\SeoPresence\EtatIndexation;
use App\AdminInterne\SeoPresence\MetriquesRecherche;
use App\AdminInterne\SeoPresence\PageSpeed;
use App\AdminInterne\SeoPresence\RequeteTop;
use App\AdminInterne\SeoPresence\SearchConsole;
use App\AdminInterne\SeoPresence\WebVitals;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class CollecteurPresenceExterneTest extends TestCase
{
    /**
     * @param list<string> $urls
     */
    private function source(array $urls): SourceUrls
    {
        return new class($urls) implements SourceUrls {
            /** @param list<string> $urls */
            public function __construct(private array $urls)
            {
            }

            public function urls(): array
            {
                return $this->urls;
            }
        };
    }

    public function testAgregeLesTroisSourcesEnBornantLeNombreDUrls(): void
    {
        $sc = new class implements SearchConsole {
            public ?\DateTimeImmutable $debut = null;

            public function metriques(\DateTimeImmutable $debut, \DateTimeImmutable $fin, int $maxRequetes = 10): ?MetriquesRecherche
            {
                $this->debut = $debut;

                return new MetriquesRecherche(100, 2000, 0.05, 6.5, [new RequeteTop('vv', 80, 1000)]);
            }

            public function indexation(string $url): ?EtatIndexation
            {
                return new EtatIndexation($url, 'Submitted and indexed');
            }
        };
        $ps = new class implements PageSpeed {
            public function mesurer(string $url): ?WebVitals
            {
                return new WebVitals($url, 2000, 0.05, 180, 90);
            }
        };

        $collecteur = new CollecteurPresenceExterne($sc, $ps, $this->source(['https://a/', 'https://b/', 'https://c/']), new MockClock('2026-08-25 04:00:00'), 2);
        $rapport = $collecteur->collecter();

        self::assertTrue($rapport->configure);
        self::assertNotNull($rapport->recherche);
        self::assertSame(100, $rapport->recherche->clics);
        self::assertCount(2, $rapport->indexation); // borné à maxUrls = 2
        self::assertCount(2, $rapport->vitals);
        self::assertSame('https://a/', $rapport->indexation[0]->url);
        // Période : 28 jours finissant à la date d'exécution.
        self::assertEquals(new \DateTimeImmutable('2026-07-28 04:00:00'), $sc->debut);
    }

    public function testSansSourceConfigureeLeRapportEstNonConfigure(): void
    {
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

        $rapport = (new CollecteurPresenceExterne($sc, $ps, $this->source(['https://a/']), new MockClock('2026-08-25 04:00:00')))->collecter();

        self::assertFalse($rapport->configure);
        self::assertNull($rapport->recherche);
        self::assertSame([], $rapport->indexation);
        self::assertSame([], $rapport->vitals);
    }
}

<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoPresence;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Snapshot horodaté de présence externe (#346), sœur de {@see \App\AdminInterne\SeoAudit\RapportSeo}.
 * Le batch `app:seo:presence:fetch` en écrit un à chaque passage : agrégats de
 * recherche et compteurs dénormalisés en colonnes (courbes de tendance), détail
 * complet (requêtes, indexation, Core Web Vitals) figé en `instantane` (JSONB).
 * `configure` = false tant que les secrets ne sont pas provisionnés. Immuable, globale.
 *
 * @phpstan-type InstantanePresence array{configure: bool, recherche: array{clics: int, impressions: int, ctr: float, positionMoyenne: float, topRequetes: list<array{requete: string, clics: int, impressions: int}>}|null, indexation: list<array{url: string, couverture: string}>, vitals: list<array{url: string, lcpMs: int|null, cls: float|null, inpMs: int|null, scorePerformance: int|null}>}
 */
#[ORM\Entity(repositoryClass: RapportPresenceRepository::class)]
#[ORM\Table(name: 'rapport_presence')]
#[ORM\Index(name: 'idx_rapport_presence_execute_le', columns: ['execute_le'])]
class RapportPresence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'execute_le', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $executeLe;

    #[ORM\Column]
    private bool $configure;

    #[ORM\Column]
    private int $clics;

    #[ORM\Column]
    private int $impressions;

    #[ORM\Column(name: 'position_moyenne', nullable: true)]
    private ?float $positionMoyenne;

    #[ORM\Column(name: 'nb_urls')]
    private int $nbUrls;

    /**
     * @var InstantanePresence
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $instantane;

    /**
     * @param InstantanePresence $instantane
     */
    private function __construct(
        \DateTimeImmutable $executeLe,
        bool $configure,
        int $clics,
        int $impressions,
        ?float $positionMoyenne,
        int $nbUrls,
        array $instantane,
    ) {
        $this->executeLe = $executeLe;
        $this->configure = $configure;
        $this->clics = $clics;
        $this->impressions = $impressions;
        $this->positionMoyenne = $positionMoyenne;
        $this->nbUrls = $nbUrls;
        $this->instantane = $instantane;
    }

    public static function depuis(RapportPresenceExterne $rapport, \DateTimeImmutable $executeLe): self
    {
        $recherche = $rapport->recherche;

        return new self(
            $executeLe,
            $rapport->configure,
            null !== $recherche ? $recherche->clics : 0,
            null !== $recherche ? $recherche->impressions : 0,
            null !== $recherche ? $recherche->positionMoyenne : null,
            max(\count($rapport->indexation), \count($rapport->vitals)),
            self::instantaneDepuis($rapport),
        );
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function executeLe(): \DateTimeImmutable
    {
        return $this->executeLe;
    }

    public function estConfigure(): bool
    {
        return $this->configure;
    }

    public function clics(): int
    {
        return $this->clics;
    }

    public function impressions(): int
    {
        return $this->impressions;
    }

    public function positionMoyenne(): ?float
    {
        return $this->positionMoyenne;
    }

    public function nombreUrls(): int
    {
        return $this->nbUrls;
    }

    /**
     * @return InstantanePresence
     */
    public function instantane(): array
    {
        return $this->instantane;
    }

    /**
     * @return InstantanePresence
     */
    private static function instantaneDepuis(RapportPresenceExterne $rapport): array
    {
        $recherche = null;
        if (null !== $rapport->recherche) {
            $recherche = [
                'clics' => $rapport->recherche->clics,
                'impressions' => $rapport->recherche->impressions,
                'ctr' => $rapport->recherche->ctr,
                'positionMoyenne' => $rapport->recherche->positionMoyenne,
                'topRequetes' => array_map(
                    static fn (RequeteTop $r): array => ['requete' => $r->requete, 'clics' => $r->clics, 'impressions' => $r->impressions],
                    $rapport->recherche->topRequetes,
                ),
            ];
        }

        return [
            'configure' => $rapport->configure,
            'recherche' => $recherche,
            'indexation' => array_map(
                static fn (EtatIndexation $e): array => ['url' => $e->url, 'couverture' => $e->couverture],
                $rapport->indexation,
            ),
            'vitals' => array_map(
                static fn (WebVitals $v): array => ['url' => $v->url, 'lcpMs' => $v->lcpMs, 'cls' => $v->cls, 'inpMs' => $v->inpMs, 'scorePerformance' => $v->scorePerformance],
                $rapport->vitals,
            ),
        ];
    }
}

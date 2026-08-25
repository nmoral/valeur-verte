<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Snapshot horodaté d'un audit SEO (#345). Le batch `app:seo:audit` en écrit un à
 * chaque passage : les compteurs et le score sont dénormalisés en colonnes (courbes
 * de tendance sans désérialiser le JSONB), et le **détail complet** — par URL et par
 * règle — est figé dans `instantane` (JSONB). Donnée **globale** (pas de tenant) :
 * l'audit porte sur le site, pas sur un pro. Immuable une fois écrit.
 */
#[ORM\Entity(repositoryClass: RapportSeoRepository::class)]
#[ORM\Table(name: 'rapport_seo')]
#[ORM\Index(name: 'idx_rapport_seo_execute_le', columns: ['execute_le'])]
class RapportSeo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'execute_le', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $executeLe;

    #[ORM\Column(name: 'score_technique')]
    private int $scoreTechnique;

    #[ORM\Column(name: 'nb_urls')]
    private int $nbUrls;

    #[ORM\Column(name: 'nb_ok')]
    private int $nbOk;

    #[ORM\Column(name: 'nb_alerte')]
    private int $nbAlerte;

    #[ORM\Column(name: 'nb_erreur')]
    private int $nbErreur;

    /**
     * Détail complet de l'audit, figé (#345).
     *
     * @var array{pages: list<array{url: string, codeHttp: int, constats: list<array{regle: string, gravite: string, message: string}>}>, globaux: list<array{regle: string, gravite: string, message: string}>}
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $instantane;

    /**
     * @param array{pages: list<array{url: string, codeHttp: int, constats: list<array{regle: string, gravite: string, message: string}>}>, globaux: list<array{regle: string, gravite: string, message: string}>} $instantane
     */
    private function __construct(
        \DateTimeImmutable $executeLe,
        int $scoreTechnique,
        int $nbUrls,
        int $nbOk,
        int $nbAlerte,
        int $nbErreur,
        array $instantane,
    ) {
        $this->executeLe = $executeLe;
        $this->scoreTechnique = $scoreTechnique;
        $this->nbUrls = $nbUrls;
        $this->nbOk = $nbOk;
        $this->nbAlerte = $nbAlerte;
        $this->nbErreur = $nbErreur;
        $this->instantane = $instantane;
    }

    public static function depuis(RapportAuditSeo $rapport, \DateTimeImmutable $executeLe): self
    {
        return new self(
            $executeLe,
            $rapport->score(),
            $rapport->nombreUrls(),
            $rapport->compte(Gravite::Ok),
            $rapport->compte(Gravite::Alerte),
            $rapport->compte(Gravite::Erreur),
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

    public function scoreTechnique(): int
    {
        return $this->scoreTechnique;
    }

    public function nombreUrls(): int
    {
        return $this->nbUrls;
    }

    public function nombreOk(): int
    {
        return $this->nbOk;
    }

    public function nombreAlerte(): int
    {
        return $this->nbAlerte;
    }

    public function nombreErreur(): int
    {
        return $this->nbErreur;
    }

    /**
     * @return array{pages: list<array{url: string, codeHttp: int, constats: list<array{regle: string, gravite: string, message: string}>}>, globaux: list<array{regle: string, gravite: string, message: string}>}
     */
    public function instantane(): array
    {
        return $this->instantane;
    }

    /**
     * @return array{pages: list<array{url: string, codeHttp: int, constats: list<array{regle: string, gravite: string, message: string}>}>, globaux: list<array{regle: string, gravite: string, message: string}>}
     */
    private static function instantaneDepuis(RapportAuditSeo $rapport): array
    {
        $pages = [];
        foreach ($rapport->pages as $page) {
            $pages[] = [
                'url' => $page->url,
                'codeHttp' => $page->codeHttp,
                'constats' => self::serialiserConstats($page->constats),
            ];
        }

        return ['pages' => $pages, 'globaux' => self::serialiserConstats($rapport->constatsGlobaux)];
    }

    /**
     * @param list<Constat> $constats
     *
     * @return list<array{regle: string, gravite: string, message: string}>
     */
    private static function serialiserConstats(array $constats): array
    {
        return array_map(
            static fn (Constat $c): array => ['regle' => $c->regle, 'gravite' => $c->gravite->value, 'message' => $c->message],
            $constats,
        );
    }
}

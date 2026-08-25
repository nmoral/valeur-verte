<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoPresence\Google;

use App\AdminInterne\SeoPresence\EtatIndexation;
use App\AdminInterne\SeoPresence\MetriquesRecherche;
use App\AdminInterne\SeoPresence\RequeteTop;
use App\AdminInterne\SeoPresence\SearchConsole;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Search Console via l'API REST (#346), authentifiée par un jeton de compte de
 * service ({@see JetonAcces}). Deux appels : `searchAnalytics/query` (agrégats et
 * requêtes) et `urlInspection/index:inspect` (état d'indexation d'une URL).
 *
 * **Inerte si non configuré** (pas de jeton ⇒ compte de service absent, ou propriété
 * `siteUrl` vide) et **dégradation gracieuse** sur toute erreur (→ `null`) : la
 * présence externe ne fait jamais échouer la collecte.
 */
final readonly class SearchConsoleViaGoogleApi implements SearchConsole
{
    private const string BASE = 'https://searchconsole.googleapis.com/webmasters/v3/';
    private const string INSPECTION = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

    public function __construct(
        private JetonAcces $jetons,
        private HttpClientInterface $client,
        private string $siteUrl,
    ) {
    }

    public function metriques(\DateTimeImmutable $debut, \DateTimeImmutable $fin, int $maxRequetes = 10): ?MetriquesRecherche
    {
        $jeton = $this->jetonSiConfigure();
        if (null === $jeton) {
            return null;
        }

        $donnees = $this->poster(
            self::BASE.'sites/'.rawurlencode($this->siteUrl).'/searchAnalytics/query',
            $jeton,
            [
                'startDate' => $debut->format('Y-m-d'),
                'endDate' => $fin->format('Y-m-d'),
                'dimensions' => ['query'],
                'rowLimit' => 1000,
            ],
        );
        if (null === $donnees) {
            return null;
        }

        $rows = \is_array($donnees['rows'] ?? null) ? $donnees['rows'] : [];
        $clics = 0;
        $impressions = 0;
        $positionPonderee = 0.0;
        $requetes = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $c = $this->entier($row, 'clicks');
            $i = $this->entier($row, 'impressions');
            $keys = \is_array($row['keys'] ?? null) ? $row['keys'] : [];
            $requete = \is_string($keys[0] ?? null) ? $keys[0] : '';

            $clics += $c;
            $impressions += $i;
            $positionPonderee += $this->reel($row, 'position') * $i;
            $requetes[] = new RequeteTop($requete, $c, $i);
        }

        usort($requetes, static fn (RequeteTop $a, RequeteTop $b): int => $b->clics <=> $a->clics);

        return new MetriquesRecherche(
            $clics,
            $impressions,
            $impressions > 0 ? $clics / $impressions : 0.0,
            $impressions > 0 ? $positionPonderee / $impressions : 0.0,
            \array_slice($requetes, 0, max(1, $maxRequetes)),
        );
    }

    public function indexation(string $url): ?EtatIndexation
    {
        $jeton = $this->jetonSiConfigure();
        if (null === $jeton) {
            return null;
        }

        $donnees = $this->poster(self::INSPECTION, $jeton, ['inspectionUrl' => $url, 'siteUrl' => $this->siteUrl]);
        if (null === $donnees) {
            return null;
        }

        $resultat = \is_array($donnees['inspectionResult'] ?? null) ? $donnees['inspectionResult'] : [];
        $index = \is_array($resultat['indexStatusResult'] ?? null) ? $resultat['indexStatusResult'] : [];
        $couverture = \is_string($index['coverageState'] ?? null) ? $index['coverageState'] : 'inconnu';

        return new EtatIndexation($url, $couverture);
    }

    private function jetonSiConfigure(): ?string
    {
        if ('' === $this->siteUrl) {
            return null;
        }

        return $this->jetons->jeton();
    }

    /**
     * @param array<string, mixed> $corps
     *
     * @return array<mixed>|null
     */
    private function poster(string $url, string $jeton, array $corps): ?array
    {
        try {
            $reponse = $this->client->request('POST', $url, ['auth_bearer' => $jeton, 'json' => $corps]);
            if (200 !== $reponse->getStatusCode()) {
                return null;
            }

            return $reponse->toArray(false);
        } catch (ExceptionInterface) {
            return null;
        }
    }

    /**
     * @param array<mixed> $row
     */
    private function entier(array $row, string $cle): int
    {
        return is_numeric($row[$cle] ?? null) ? (int) $row[$cle] : 0;
    }

    /**
     * @param array<mixed> $row
     */
    private function reel(array $row, string $cle): float
    {
        return is_numeric($row[$cle] ?? null) ? (float) $row[$cle] : 0.0;
    }
}

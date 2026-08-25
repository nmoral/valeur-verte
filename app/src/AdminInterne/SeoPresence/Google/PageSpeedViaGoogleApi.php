<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoPresence\Google;

use App\AdminInterne\SeoPresence\PageSpeed;
use App\AdminInterne\SeoPresence\WebVitals;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Core Web Vitals via l'API PageSpeed Insights v5 (#346) — simple clé API, pas d'OAuth.
 * On lit les métriques **de laboratoire** (Lighthouse) : LCP, CLS, INP et score de
 * performance. **Inerte si la clé est vide** (patron {@see \App\Widget\VerificateurTurnstile}),
 * dégradation gracieuse sur toute erreur réseau/HTTP (→ `null`) : la présence externe
 * ne bloque jamais la collecte. Stratégie mobile (le ranking mobile fait foi).
 */
final readonly class PageSpeedViaGoogleApi implements PageSpeed
{
    private const string URL = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    public function __construct(
        private HttpClientInterface $client,
        private string $cleApi,
    ) {
    }

    public function mesurer(string $url): ?WebVitals
    {
        if ('' === $this->cleApi) {
            return null;
        }

        try {
            $reponse = $this->client->request('GET', self::URL, [
                'query' => [
                    'url' => $url,
                    'key' => $this->cleApi,
                    'strategy' => 'mobile',
                    'category' => 'performance',
                ],
            ]);
            if (200 !== $reponse->getStatusCode()) {
                return null;
            }
            $donnees = $reponse->toArray(false);
        } catch (ExceptionInterface) {
            return null;
        }

        $lighthouse = \is_array($donnees['lighthouseResult'] ?? null) ? $donnees['lighthouseResult'] : [];
        $audits = \is_array($lighthouse['audits'] ?? null) ? $lighthouse['audits'] : [];

        return new WebVitals(
            $url,
            $this->entier($audits, 'largest-contentful-paint'),
            $this->reel($audits, 'cumulative-layout-shift'),
            $this->entier($audits, 'interaction-to-next-paint'),
            $this->score($lighthouse),
        );
    }

    /**
     * @param array<mixed> $audits
     */
    private function entier(array $audits, string $cle): ?int
    {
        $valeur = $this->valeurNumerique($audits, $cle);

        return null === $valeur ? null : (int) round($valeur);
    }

    /**
     * @param array<mixed> $audits
     */
    private function reel(array $audits, string $cle): ?float
    {
        return $this->valeurNumerique($audits, $cle);
    }

    /**
     * @param array<mixed> $audits
     */
    private function valeurNumerique(array $audits, string $cle): ?float
    {
        $audit = $audits[$cle] ?? null;
        if (!\is_array($audit) || !is_numeric($audit['numericValue'] ?? null)) {
            return null;
        }

        return (float) $audit['numericValue'];
    }

    /**
     * @param array<mixed> $lighthouse
     */
    private function score(array $lighthouse): ?int
    {
        $categories = \is_array($lighthouse['categories'] ?? null) ? $lighthouse['categories'] : [];
        $performance = \is_array($categories['performance'] ?? null) ? $categories['performance'] : [];
        $score = $performance['score'] ?? null;

        return is_numeric($score) ? (int) round(((float) $score) * 100) : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Sitemap;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Construit la liste des URLs du sitemap par **découverte** du routeur (#340) :
 * toute route marquée `options: ['sitemap' => true]`, accessible en GET et sans
 * paramètre d'URL, est publiée en URL absolue. Ajouter une page publique
 * indexable = marquer sa route — aucune liste à tenir ici.
 *
 * Les familles de pages servies par une route à paramètre (les hubs
 * départementaux, #337) échappent à la découverte : elles sont énumérées par des
 * {@see SitemapUrlProvider}.
 */
final readonly class SitemapGenerator
{
    /**
     * @var array<string, SitemapRouteGuard> gardes indexés par nom de route
     */
    private array $gardes;

    /**
     * @param iterable<SitemapRouteGuard>  $gardes
     * @param iterable<SitemapUrlProvider> $providers
     */
    public function __construct(
        private RouterInterface $router,
        #[Autowire('%app.domaine%')]
        private string $domaine,
        #[Autowire('%app.sitemap.max_urls%')]
        private int $maxParPage,
        #[AutowireIterator('app.sitemap_guard')]
        iterable $gardes,
        #[AutowireIterator('app.sitemap_url_provider')]
        private iterable $providers,
    ) {
        $indexes = [];
        foreach ($gardes as $garde) {
            $indexes[$garde->routeName()] = $garde;
        }
        $this->gardes = $indexes;
    }

    /**
     * @return list<string> URLs canoniques absolues, triées, dédupliquées
     */
    public function urls(): array
    {
        return $this->sousContexteCanonique(fn (): array => $this->collecter());
    }

    /**
     * Nombre de fichiers sitemap enfants (#365) : les URLs découpées en pages d'au
     * plus ``maxParPage`` (borne sitemaps.org). Au moins un, même vide.
     */
    public function nombreDePages(): int
    {
        return max(1, (int) ceil(\count($this->urls()) / max(1, $this->maxParPage)));
    }

    /**
     * Les URLs de la n-ième page (1-indexée), ou une liste vide hors bornes.
     *
     * @return list<string>
     */
    public function page(int $n): array
    {
        $pages = array_chunk($this->urls(), max(1, $this->maxParPage));

        return $pages[$n - 1] ?? [];
    }

    /**
     * URLs absolues canoniques des sitemaps enfants (contenu du sitemap index).
     *
     * @return list<string>
     */
    public function sitemapsEnfants(): array
    {
        $nombre = $this->nombreDePages();

        return $this->sousContexteCanonique(function () use ($nombre): array {
            $urls = [];
            for ($n = 1; $n <= $nombre; ++$n) {
                $urls[] = $this->router->generate('sitemap_page', ['n' => $n], UrlGeneratorInterface::ABSOLUTE_URL);
            }

            return $urls;
        });
    }

    /**
     * Exécute un rendu d'URLs sous le contexte **canonique** (domaine principal,
     * HTTPS) puis restaure le contexte d'origine — un sitemap liste des URLs
     * canoniques, quel que soit le contexte entrant (http interne derrière le
     * proxy, host « localhost » d'une commande CLI).
     *
     * @param callable(): list<string> $rendu
     *
     * @return list<string>
     */
    private function sousContexteCanonique(callable $rendu): array
    {
        $contexteInitial = $this->router->getContext();
        $this->router->setContext(
            (clone $contexteInitial)
                ->setHost($this->domaine)
                ->setScheme('https')
                ->setHttpsPort(443),
        );

        try {
            return $rendu();
        } finally {
            $this->router->setContext($contexteInitial);
        }
    }

    /**
     * @return list<string>
     */
    private function collecter(): array
    {
        $urls = [];
        foreach ($this->router->getRouteCollection() as $nom => $route) {
            if (true !== $route->getOption('sitemap')) {
                continue;
            }

            // Une URL paramétrée ne peut pas être publiée telle quelle : les
            // endpoints à jeton/identifiant ne sont pas des pages indexables.
            if ([] !== $route->compile()->getPathVariables()) {
                continue;
            }

            $garde = $this->gardes[$nom] ?? null;
            if (null !== $garde && !$garde->estIndexable()) {
                continue;
            }

            $urls[] = $this->router->generate($nom, [], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        // Routes à paramètre (hubs départementaux, #337) : chaque provider énumère
        // les valeurs qui mènent à une page réelle. On génère sous le même contexte
        // canonique que ci-dessus.
        foreach ($this->providers as $provider) {
            foreach ($provider->routes() as [$nom, $parametres]) {
                $urls[] = $this->router->generate($nom, $parametres, UrlGeneratorInterface::ABSOLUTE_URL);
            }
        }

        // Ordre stable : le sitemap ne doit pas dépendre de l'ordre de déclaration
        // des routes (diff propre entre déploiements).
        sort($urls);

        return $urls;
    }
}

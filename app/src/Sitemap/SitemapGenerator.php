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
 */
final readonly class SitemapGenerator
{
    /**
     * @var array<string, SitemapRouteGuard> gardes indexés par nom de route
     */
    private array $gardes;

    /**
     * @param iterable<SitemapRouteGuard> $gardes
     */
    public function __construct(
        private RouterInterface $router,
        #[Autowire('%app.domaine%')]
        private string $domaine,
        #[AutowireIterator('app.sitemap_guard')]
        iterable $gardes,
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
        // Un sitemap liste les URLs **canoniques** : le domaine principal en
        // HTTPS, quel que soit le contexte de la requête entrante (http interne
        // derrière le proxy, host « localhost » d'une commande CLI). On génère
        // donc contre un contexte canonique, puis on restaure celui d'origine.
        $contexteInitial = $this->router->getContext();
        $this->router->setContext(
            (clone $contexteInitial)
                ->setHost($this->domaine)
                ->setScheme('https')
                ->setHttpsPort(443),
        );

        try {
            return $this->collecter();
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

            $methodes = $route->getMethods();
            if ([] !== $methodes && !\in_array('GET', $methodes, true)) {
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

        $urls = array_values(array_unique($urls));
        sort($urls);

        return $urls;
    }
}

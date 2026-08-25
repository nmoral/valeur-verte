<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit;

use App\Sitemap\SitemapGenerator;
use App\Widget\PagePubliqueRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Périmètre d'URLs à auditer (#345) : les pages canoniques indexables découvertes
 * par le sitemap ({@see SitemapGenerator}, #340/#352) **plus** les pages hébergées
 * marque blanche du widget (D9), qui portent l'essentiel de l'enjeu de référencement
 * mais sont servies sur des routes à jeton — absentes du sitemap par construction.
 *
 * Les URLs widget sont générées contre un contexte **canonique** (HTTPS, domaine
 * principal) comme le fait le sitemap : une commande CLI n'a pas de requête entrante
 * dont hériter.
 */
final readonly class CollecteurUrls implements SourceUrls
{
    public function __construct(
        private SitemapGenerator $sitemap,
        private PagePubliqueRepository $pagesPubliques,
        private RouterInterface $router,
        #[Autowire('%app.domaine%')]
        private string $domaine,
    ) {
    }

    /**
     * @return list<string> URLs absolues, dédupliquées, sitemap puis pages widget
     */
    public function urls(): array
    {
        $urls = $this->sitemap->urls();
        foreach ($this->urlsPagesWidget() as $url) {
            $urls[] = $url;
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return list<string>
     */
    private function urlsPagesWidget(): array
    {
        $contexteInitial = $this->router->getContext();
        $this->router->setContext(
            (clone $contexteInitial)
                ->setHost($this->domaine)
                ->setScheme('https')
                ->setHttpsPort(443),
        );

        try {
            $urls = [];
            foreach ($this->pagesPubliques->actives() as $page) {
                $urls[] = $this->router->generate(
                    'widget_page_publique',
                    ['token' => $page->token(), 'slug' => $page->slug()],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                );
            }

            return $urls;
        } finally {
            $this->router->setContext($contexteInitial);
        }
    }
}

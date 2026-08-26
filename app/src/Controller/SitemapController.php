<?php

declare(strict_types=1);

namespace App\Controller;

use App\Sitemap\SitemapGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Environment;

/**
 * Sitemap des pages publiques indexables (#340, #365). ``/sitemap.xml`` est un
 * **sitemap index** paginé : ~34 746 communes dépassent la limite d'un fichier
 * unique (50 000 URLs), l'index pointe vers des enfants ``/sitemap-{n}.xml`` d'au
 * plus 50 000 URLs chacun. Le rendu est mémoïsé (index et chaque enfant sous leur
 * propre clé) : les routes ne changent qu'au déploiement et l'état « millésime
 * publié » à une décision manuelle. Le cache HTTP partagé complète le cache
 * applicatif.
 */
final class SitemapController extends AbstractController
{
    private const DUREE = 3600;

    #[Route('/sitemap.xml', name: 'sitemap', host: '%app.domaine%', methods: ['GET'])]
    public function __invoke(
        SitemapGenerator $generateur,
        Environment $twig,
        #[Autowire(service: 'cache.sitemap')]
        CacheInterface $cache,
    ): Response {
        $xml = $cache->get('sitemap.index.xml', static function (ItemInterface $item) use ($generateur, $twig): string {
            $item->expiresAfter(self::DUREE);

            return $twig->render('sitemap/sitemapindex.xml.twig', ['sitemaps' => $generateur->sitemapsEnfants()]);
        });

        return $this->xml($xml);
    }

    #[Route('/sitemap-{n}.xml', name: 'sitemap_page', host: '%app.domaine%', requirements: ['n' => '\d+'], methods: ['GET'])]
    public function page(
        int $n,
        SitemapGenerator $generateur,
        Environment $twig,
        #[Autowire(service: 'cache.sitemap')]
        CacheInterface $cache,
    ): Response {
        $xml = $cache->get('sitemap.page.'.$n.'.xml', static function (ItemInterface $item) use ($n, $generateur, $twig): ?string {
            $item->expiresAfter(self::DUREE);
            $urls = $generateur->page($n);

            // Un numéro hors bornes n'est pas une page : on mémoïse l'absence pour
            // que le 404 soit servi sans requêter la base à chaque robot égaré.
            return [] === $urls ? null : $twig->render('sitemap/index.xml.twig', ['urls' => $urls]);
        });

        if (null === $xml) {
            throw $this->createNotFoundException(\sprintf('Sitemap enfant %d hors bornes.', $n));
        }

        return $this->xml($xml);
    }

    private function xml(string $corps): Response
    {
        $reponse = new Response($corps);
        $reponse->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        // La page ne dépend d'aucun état de session : on lève le « private » que le
        // noyau pose dès qu'une session existe, sinon le cache partagé serait
        // désactivé pour tout le monde. setSharedMaxAge marque déjà « public ».
        $reponse->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $reponse->setSharedMaxAge(self::DUREE);

        return $reponse;
    }
}
